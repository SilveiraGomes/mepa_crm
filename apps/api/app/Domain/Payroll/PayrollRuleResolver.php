<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Files\FilesCatalog;
use App\Domain\Finance\FinanceError;
use Illuminate\Database\Connection;

/**
 * Statutory rule resolution (ADR 0021 D25 + D-04A.15), FAIL-CLOSED.
 *
 * For a component and a service period [from, to] the applicable rule is the ONE rule version with status APPROVED whose
 * vigência intersects the period:
 *   0 versions            -> PAYROLL_RULE_MISSING   (never zero, never an older rule outside its vigência, never a fallback)
 *   2+ versions           -> PAYROLL_RULE_AMBIGUOUS (two rules effective for the same component and period)
 *   incomplete parameters -> PAYROLL_RULE_INVALID   (FLAT without rate/base, BRACKET with gaps/overlap, no base)
 *   source not ACTIVE     -> PAYROLL_RULE_DOCUMENT_MISSING
 * DRAFT and RETIRED versions are never used. The resolved version (code, version) is what a run will record.
 */
final class PayrollRuleResolver
{
    public function __construct(private Connection $db)
    {
    }

    public function forPeriod(int $componentTypeId, string $from, string $to, bool $lock = false): array
    {
        $q = $this->db->table('payroll_rules')->where('component_type_id', $componentTypeId)->where('status', PayrollCatalog::RULE_APPROVED)
            ->where('starts_on', '<=', $to)->where(fn ($x) => $x->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->orderBy('starts_on')->orderBy('id');
        if ($lock) {
            $q->sharedLock();
        }
        $rules = $q->get();
        $component = (string) $this->db->table('compensation_component_types')->where('id', $componentTypeId)->value('code');
        if ($rules->isEmpty()) {
            throw new FinanceError('PAYROLL_RULE_MISSING', [$component], ['component' => $component, 'from' => $from, 'to' => $to]);
        }
        if ($rules->count() > 1) {
            throw new FinanceError('PAYROLL_RULE_AMBIGUOUS', $rules->map(fn ($r) => $r->code . '@' . $r->version)->all(), ['component' => $component]);
        }
        return $this->complete($rules->first(), $component);
    }

    public function onDate(int $componentTypeId, string $date): array
    {
        return $this->forPeriod($componentTypeId, $date, $date);
    }

    /** Parameters of one rule version, validated for completeness. */
    public function complete(object $rule, ?string $component = null): array
    {
        $component ??= (string) $this->db->table('compensation_component_types')->where('id', $rule->component_type_id)->value('code');
        $base = $this->db->table('payroll_rule_base_components as b')->join('compensation_component_types as c', 'c.id', '=', 'b.component_type_id')
            ->where('b.rule_id', $rule->id)->orderBy('c.code')->pluck('c.code')->all();
        $brackets = $this->db->table('payroll_rule_brackets')->where('rule_id', $rule->id)->orderBy('lower_bound')->get()
            ->map(fn ($b) => ['lower_bound' => PayrollMoney::fromStorage((string) $b->lower_bound), 'upper_bound' => PayrollMoney::fromStorage($b->upper_bound === null ? null : (string) $b->upper_bound),
                'rate' => (string) $b->rate, 'fixed_amount' => PayrollMoney::fromStorage((string) $b->fixed_amount), 'excess_over' => PayrollMoney::fromStorage((string) $b->excess_over)])->all();
        $item = (string) $rule->code . '@' . (int) $rule->version;
        if ($base === []) {
            throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'no_base_components']);
        }
        if ($rule->method === PayrollCatalog::FLAT_RATE && ($rule->rate === null || $brackets !== [])) {
            throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'flat_rate_parameters']);
        }
        if ($rule->method === PayrollCatalog::BRACKET) {
            self::assertBrackets($brackets, $item);
        }
        if ($rule->status === PayrollCatalog::RULE_APPROVED) {
            $doc = $rule->source_document_id === null ? null : $this->db->table('legal_documents as d')->join('legal_document_types as t', 't.id', '=', 'd.document_type_id')
                ->where('d.id', $rule->source_document_id)->first(['d.status', 't.code']);
            if (!$doc || $doc->status !== FilesCatalog::DOCUMENT_ACTIVE || $doc->code !== PayrollCatalog::RULE_SOURCE_DOCUMENT) {
                throw new FinanceError('PAYROLL_RULE_DOCUMENT_MISSING', [$item], ['reason' => 'source_document']);
            }
        }
        return ['id' => (int) $rule->id, 'code' => (string) $rule->code, 'version' => (int) $rule->version, 'component' => $component, 'method' => (string) $rule->method,
            'rate' => $rule->rate === null ? null : (string) $rule->rate, 'starts_on' => (string) $rule->starts_on, 'ends_on' => $rule->ends_on === null ? null : (string) $rule->ends_on,
            'status' => (string) $rule->status, 'base_components' => $base, 'brackets' => $brackets];
    }

    /** Brackets: start at 0, contiguous (upper == next lower), strictly increasing, only the last open-ended. */
    public static function assertBrackets(array $brackets, string $item = 'rule'): void
    {
        if ($brackets === []) {
            throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'no_brackets']);
        }
        if (bccomp((string) $brackets[0]['lower_bound'], '0', 4) !== 0) {
            throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'bracket_gap_at_zero']);
        }
        $last = count($brackets) - 1;
        foreach ($brackets as $i => $b) {
            if ($b['upper_bound'] === null && $i !== $last) {
                throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'open_bracket_not_last']);
            }
            if ($b['upper_bound'] !== null && bccomp((string) $b['upper_bound'], (string) $b['lower_bound'], 4) <= 0) {
                throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'bracket_not_increasing']);
            }
            if ($i < $last && ($b['upper_bound'] === null || bccomp((string) $b['upper_bound'], (string) $brackets[$i + 1]['lower_bound'], 4) !== 0)) {
                throw new FinanceError('PAYROLL_RULE_INVALID', [$item], ['reason' => 'bracket_gap_or_overlap']);
            }
        }
    }
}
