<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Territorial\TerritorialActor;

/**
 * Payroll readiness of one employing unit for one service month (ADR 0021 D-04A.15), computed on the server from the
 * configuration only (never inferred by the UI). Two separate answers:
 *   configuration: READY | NOT_READY with objective codes
 *     NO_ACTIVE_EMPLOYMENT  no employment intersects the month
 *     MISSING_COMPENSATION  an employment has no EARNING line effective in the month
 *     INVALID_EFFECTIVITY   a compensation line starts before / runs after its employment, or two lines of one component
 *                           are effective on the same day
 *     MISSING_RULE          a rule-based component in use has no APPROVED rule for the month (PAYROLL_RULE_MISSING)
 *     AMBIGUOUS_RULE        two APPROVED rules apply to the same component in the month
 *     INVALID_RULE          the applicable rule is incomplete (rate / brackets / base)
 *     MISSING_DOCUMENT      the applicable rule's PAYROLL_RULE_SOURCE is not an active document
 *   production: ENABLED | DISABLED (PRODUCTION_DISABLED) — `payroll.production_enabled`, independent of configuration.
 * READY configuration with production DISABLED is a valid, explicit state. When configuration is READY the canonical
 * input_hash of a REGULAR run of the month is returned (D-04A.14 contract preview; no run is created).
 */
final class PayrollReadinessService extends PayrollService
{
    public function readiness(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $this->requiresAny($actor, PayrollCatalog::PAYROLL_MANAGE, PayrollCatalog::HR_COMPENSATION_VIEW);
            $unit = $this->unitByPublicId($in['unit'] ?? null);
            if (!$this->rt->authority->holdsOn($actor, PayrollCatalog::PAYROLL_MANAGE, (int) $unit->id) && !$this->rt->authority->holdsOn($actor, PayrollCatalog::HR_COMPENSATION_VIEW, (int) $unit->id)) {
                throw new FinanceError('OUT_OF_SCOPE');
            }
            [$period, $from, $to] = $this->month($in['period'] ?? null);
            return $this->evaluate((int) $unit->id, $period, $from, $to) + ['unit' => ['public_id' => (string) $unit->public_id, 'name' => (string) $unit->name]];
        });
    }

    /** The readiness of one unit/month (pure read; also used by the tests and, in F2B, by calculate). */
    public function evaluate(int $unit, string $period, string $from, string $to): array
    {
        $db = $this->rt->db;
        $issues = [];
        $employments = $db->table('employments')->where('employing_unit_id', $unit)->where('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))
            ->orderBy('public_id')->get(['id', 'public_id', 'starts_on', 'ends_on']);
        if ($employments->isEmpty()) {
            $issues[] = ['code' => 'NO_ACTIVE_EMPLOYMENT'];
        }
        $ruleComponents = [];
        foreach ($employments as $e) {
            $lines = $db->table('employment_compensations as c')->join('compensation_component_types as t', 't.id', '=', 'c.component_type_id')->where('c.employment_id', $e->id)
                ->where('c.starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('c.ends_on')->orWhere('c.ends_on', '>=', $from))
                ->orderBy('t.code')->orderBy('c.starts_on')->get(['t.id as component_id', 't.code', 't.nature', 't.calculation_method', 'c.amount', 'c.starts_on', 'c.ends_on']);
            if (!$lines->contains(fn ($l) => $l->nature === PayrollCatalog::EARNING)) {
                $issues[] = ['code' => 'MISSING_COMPENSATION', 'employment' => (string) $e->public_id];
            }
            foreach ($lines as $l) {
                if ((string) $l->starts_on < (string) $e->starts_on || ($e->ends_on !== null && ($l->ends_on === null || (string) $l->ends_on > (string) $e->ends_on))) {
                    $issues[] = ['code' => 'INVALID_EFFECTIVITY', 'employment' => (string) $e->public_id, 'component' => (string) $l->code];
                }
                if (in_array($l->calculation_method, PayrollCatalog::RULE_METHODS, true)) {
                    $ruleComponents[(int) $l->component_id] = (string) $l->code;
                } elseif ($l->amount === null) {
                    $issues[] = ['code' => 'INVALID_EFFECTIVITY', 'employment' => (string) $e->public_id, 'component' => (string) $l->code];
                }
            }
            foreach ($lines->groupBy('code') as $code => $group) {
                $sorted = $group->sortBy('starts_on')->values();
                for ($i = 1; $i < $sorted->count(); $i++) {
                    if ($sorted[$i - 1]->ends_on === null || (string) $sorted[$i - 1]->ends_on >= (string) $sorted[$i]->starts_on) {
                        $issues[] = ['code' => 'INVALID_EFFECTIVITY', 'employment' => (string) $e->public_id, 'component' => (string) $code];
                    }
                }
            }
        }
        $resolver = new PayrollRuleResolver($db);
        $rules = [];
        ksort($ruleComponents);
        foreach ($ruleComponents as $componentId => $code) {
            try {
                $rule = $resolver->forPeriod($componentId, $from, $to);
                $rules[] = ['component' => $code, 'rule' => ['code' => $rule['code'], 'version' => $rule['version']]];
            } catch (FinanceError $e) {
                $issues[] = ['code' => match ($e->reason) {
                    'PAYROLL_RULE_MISSING' => 'MISSING_RULE', 'PAYROLL_RULE_AMBIGUOUS' => 'AMBIGUOUS_RULE', 'PAYROLL_RULE_DOCUMENT_MISSING' => 'MISSING_DOCUMENT', default => 'INVALID_RULE',
                }, 'component' => $code, 'reason' => $e->reason];
            }
        }
        $issues = array_values(array_unique($issues, SORT_REGULAR));
        $ready = $issues === [];
        $production = PayrollProduction::fromConfig()->status();
        $hash = null;
        if ($ready) {
            $hash = PayrollInputHash::hash(PayrollInputHash::payload($db, $unit, $period, 'REGULAR', 1));
        }
        return [
            'period' => ['code' => $period, 'from' => $from, 'to' => $to],
            'configuration' => ['status' => $ready ? 'READY' : 'NOT_READY', 'issues' => $issues, 'employments' => $employments->count(), 'rules' => $rules],
            'production' => $production + ['issues' => $production['enabled'] ? [] : [['code' => 'PRODUCTION_DISABLED']]],
            'input_hash_preview' => $hash === null ? null : ['algorithm' => 'SHA-256', 'version' => PayrollCatalog::INPUT_HASH_VERSION, 'run_kind' => 'REGULAR', 'sequence' => 1, 'value' => $hash],
        ];
    }
}
