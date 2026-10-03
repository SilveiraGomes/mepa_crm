<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * Canonical input_hash contract of a payroll run (ADR 0021 D26 + D-04A.14), fixed in F2A and consumed by F2B.
 *
 * input_hash = SHA-256( canonical_json(payload) ), where canonical_json:
 *   - objects: keys sorted (byte order), recursively; lists keep their order and every list is built pre-sorted below;
 *   - scalars: strings, integers, booleans and null only (a float is refused: money and rates are decimal STRINGS);
 *   - JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, no whitespace.
 * Payload (version P010-PAYROLL-INPUT-V1):
 *   period {code, from, to} · run {kind, sequence} · unit (public_id)
 *   employments  [{public_id, unit, relationship_kind, starts_on, ends_on, status}] sorted by public_id
 *   compensations[{employment, component, amount (2 dec | null), starts_on, ends_on}] sorted by employment, component, starts_on
 *     (salary base, subsidies, 13.º, benefits, deductions/configured components: every line effective in the period)
 *   rules        [{code, version, component, method, rate, starts_on, ends_on, base_components[], brackets[]}] sorted by component
 *     (the APPROVED version applicable to every rule-based component in use; brackets/parameters complete)
 *   rounding     {mode HALF_UP, scale 2, unit COMPONENT_LINE, currency AOA}
 * Any change to any of these inputs changes the hash (one test per input class).
 */
final class PayrollInputHash
{
    public static function canonical(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', self::canonical($payload));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new FinanceError('INVARIANT_VIOLATION', [], ['reason' => 'float_in_payroll_input']);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $child) {
            $out[(string) $key] = self::normalize($child);
        }
        return (object) $out;
    }

    /**
     * The payload of a run of $runKind/$sequence for $unit and the service month $period (YYYY-MM), read from the
     * current configuration. Rule-based components in use without a single applicable APPROVED rule raise the resolver's
     * fail-closed error (no payload, no hash).
     */
    public static function payload(Connection $db, int $unit, string $period, string $runKind = 'REGULAR', int $sequence = 1, bool $lock = false): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1 || !in_array($runKind, PayrollCatalog::RUN_KINDS, true) || $sequence < 1) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'period']);
        }
        $from = $period . '-01';
        $to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $employments = $db->table('employments as e')->join('organizational_units as u', 'u.id', '=', 'e.employing_unit_id')->where('e.employing_unit_id', $unit)
            ->where('e.starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('e.ends_on')->orWhere('e.ends_on', '>=', $from))->orderBy('e.public_id')
            ->when($lock, fn ($q) => $q->sharedLock())->get(['e.id', 'e.public_id', 'e.relationship_kind', 'e.starts_on', 'e.ends_on', 'e.status', 'u.public_id as unit']);
        $ids = $employments->pluck('id')->map(fn ($v) => (int) $v)->all();
        $lines = $ids === [] ? collect() : $db->table('employment_compensations as c')->join('employments as e', 'e.id', '=', 'c.employment_id')
            ->join('compensation_component_types as t', 't.id', '=', 'c.component_type_id')->whereIn('c.employment_id', $ids)
            ->where('c.starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('c.ends_on')->orWhere('c.ends_on', '>=', $from))
            ->when($lock, fn ($q) => $q->sharedLock())->get(['e.public_id as employment', 't.id as component_id', 't.code as component', 't.calculation_method', 'c.amount', 'c.starts_on', 'c.ends_on']);
        $compensations = $lines->map(fn ($l) => ['employment' => (string) $l->employment, 'component' => (string) $l->component, 'amount' => PayrollMoney::fromStorage($l->amount === null ? null : (string) $l->amount),
            'starts_on' => (string) $l->starts_on, 'ends_on' => $l->ends_on === null ? null : (string) $l->ends_on])->sortBy(fn ($c) => $c['employment'] . '|' . $c['component'] . '|' . $c['starts_on'])->values()->all();
        $resolver = new PayrollRuleResolver($db);
        $rules = [];
        foreach ($lines->filter(fn ($l) => in_array($l->calculation_method, PayrollCatalog::RULE_METHODS, true))->unique('component_id')->sortBy('component') as $l) {
            if ($lock) {
                // F2B (C5): rule approval / retirement takes the component row FOR UPDATE; a locked payload read holds it
                // FOR SHARE so the applicable rule set cannot change under a calculation or an approval.
                $db->table('compensation_component_types')->where('id', (int) $l->component_id)->sharedLock()->first(['id']);
            }
            $rule = $resolver->forPeriod((int) $l->component_id, $from, $to, $lock);
            unset($rule['id'], $rule['status']);
            $rules[] = $rule;
        }
        return [
            'version' => PayrollCatalog::INPUT_HASH_VERSION,
            'period' => ['code' => $period, 'from' => $from, 'to' => $to],
            'run' => ['kind' => $runKind, 'sequence' => $sequence],
            'unit' => (string) $db->table('organizational_units')->where('id', $unit)->value('public_id'),
            'employments' => $employments->map(fn ($e) => ['public_id' => (string) $e->public_id, 'unit' => (string) $e->unit, 'relationship_kind' => (string) $e->relationship_kind,
                'starts_on' => (string) $e->starts_on, 'ends_on' => $e->ends_on === null ? null : (string) $e->ends_on, 'status' => (string) $e->status])->values()->all(),
            'compensations' => $compensations,
            'rules' => $rules,
            'rounding' => PayrollCatalog::ROUNDING,
        ];
    }
}
