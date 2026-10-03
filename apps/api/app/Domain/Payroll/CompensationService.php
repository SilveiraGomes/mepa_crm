<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Territorial\TerritorialActor;

/**
 * Compensation history (ADR 0021 D24): append-only, effective-dated lines per (employment, component).
 *
 * change(): from `starts_on` the component is worth `amount` (FIXED_AMOUNT / MANUAL) or simply applies (RATE_RULE /
 * BRACKET_RULE: the value comes only from an approved rule, never from the line). Under the employment row lock: the
 * current open line is CLOSED the day before (never edited), the new line opens. A starts_on on or before the current
 * line's start would rewrite the past -> COMPENSATION_RETROACTIVE / COMPENSATION_OVERLAP (two lines for one date are
 * also refused physically: UNIQUE (employment, component, starts_on) + the open-line guard).
 * stop(): the component stops applying after `ends_on` (closes the open line).
 * AOA only, at most 2 business decimals, DECIMAL(19,4) storage, never a float.
 */
final class CompensationService extends PayrollService
{
    public function change(int $user, int $session, string $employment, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($employment, $in): array {
            $guard->requires(PayrollCatalog::HR_COMPENSATION_MANAGE);
            $e = $this->employmentByPublicId($employment, true);
            $guard->unit(PayrollCatalog::HR_COMPENSATION_MANAGE, (int) $e->employing_unit_id);
            if ($e->status !== PayrollCatalog::EMPLOYMENT_ACTIVE) {
                throw new FinanceError('EMPLOYMENT_ENDED');
            }
            $component = $this->component($in['component'] ?? null);
            if ((int) $component->is_active !== 1) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'component']);
            }
            if (($in['currency'] ?? FinanceCatalog::CURRENCY) !== FinanceCatalog::CURRENCY) {
                throw new FinanceError('CURRENCY_NOT_SUPPORTED', [], ['field' => 'currency']);
            }
            $ruleBased = in_array($component->calculation_method, PayrollCatalog::RULE_METHODS, true);
            $amount = null;
            if ($ruleBased) {
                if (($in['amount'] ?? null) !== null) {
                    // The value of a rule-based component is never typed in: it comes from an approved rule (D25).
                    throw new FinanceError('AMOUNT_NOT_ALLOWED', [], ['field' => 'amount']);
                }
            } else {
                if (($in['amount'] ?? null) === null) {
                    throw new FinanceError('AMOUNT_REQUIRED', [], ['field' => 'amount']);
                }
                $amount = PayrollMoney::parse($in['amount']);
            }
            $startsOn = $this->date($in['starts_on'] ?? null, 'starts_on');
            if ($startsOn < (string) $e->starts_on || ($e->ends_on !== null && $startsOn > (string) $e->ends_on)) {
                throw new FinanceError('OUTSIDE_EMPLOYMENT', [], ['field' => 'starts_on']);
            }
            $reason = $this->text($in['reason'] ?? null, 'reason');
            $document = $this->rt->supportingDocument($guard, $actor, $in['source_document'] ?? null, (int) $e->employing_unit_id);
            $lines = $this->rt->db->table('employment_compensations')->where('employment_id', $e->id)->where('component_type_id', $component->id)->lockForUpdate()->get();
            foreach ($lines as $line) {
                if ((string) $line->starts_on === $startsOn) {
                    throw new FinanceError('COMPENSATION_OVERLAP', [(string) $component->code . '@' . $startsOn]);
                }
                if ((string) $line->starts_on > $startsOn || ($line->ends_on !== null && (string) $line->ends_on >= $startsOn)) {
                    // A change that reaches into an existing (open or closed) vigência would rewrite the past.
                    throw new FinanceError('COMPENSATION_RETROACTIVE', [(string) $component->code . '@' . $startsOn]);
                }
            }
            $now = $this->rt->ts();
            $open = $lines->first(fn ($l) => $l->ends_on === null);
            if ($open) {
                $this->rt->db->table('employment_compensations')->where('id', $open->id)->where('lock_version', $open->lock_version)
                    ->update(['ends_on' => self::dayBefore($startsOn), 'closed_by' => $actor->user, 'closed_at' => $now, 'lock_version' => $open->lock_version + 1]);
            }
            $this->rt->db->table('employment_compensations')->insert(['employment_id' => $e->id, 'component_type_id' => $component->id, 'amount' => $amount, 'starts_on' => $startsOn, 'ends_on' => null,
                'reason' => $reason, 'source_document_id' => $document?->id, 'created_by' => $actor->user, 'created_at' => $now, 'closed_by' => null, 'closed_at' => null, 'lock_version' => 0]);
            PayrollAudit::write($this->rt->db, $actor->user, 'hr.compensation_changed', 'employments', (int) $e->id, (int) $e->employing_unit_id,
                ['employment' => (string) $e->public_id, 'component' => (string) $component->code, 'starts_on' => $startsOn, 'previous_closed' => $open !== null, 'fields' => $ruleBased ? ['applicability'] : ['amount'],
                    'source_document' => $document?->public_id], $reason, $actor->session);
            return ['employment' => (string) $e->public_id, 'component' => (string) $component->code, 'starts_on' => $startsOn];
        });
    }

    public function stop(int $user, int $session, string $employment, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($employment, $in): array {
            $guard->requires(PayrollCatalog::HR_COMPENSATION_MANAGE);
            $e = $this->employmentByPublicId($employment, true);
            $guard->unit(PayrollCatalog::HR_COMPENSATION_MANAGE, (int) $e->employing_unit_id);
            $component = $this->component($in['component'] ?? null);
            $endsOn = $this->date($in['ends_on'] ?? null, 'ends_on');
            $reason = $this->text($in['reason'] ?? null, 'reason');
            $open = $this->rt->db->table('employment_compensations')->where('employment_id', $e->id)->where('component_type_id', $component->id)->whereNull('ends_on')->lockForUpdate()->first();
            if (!$open) {
                throw new FinanceError('COMPENSATION_NOT_OPEN');
            }
            if ($endsOn < (string) $open->starts_on) {
                throw new FinanceError('COMPENSATION_RETROACTIVE', [(string) $component->code . '@' . $endsOn]);
            }
            $this->rt->db->table('employment_compensations')->where('id', $open->id)->where('lock_version', $open->lock_version)
                ->update(['ends_on' => $endsOn, 'closed_by' => $actor->user, 'closed_at' => $this->rt->ts(), 'lock_version' => $open->lock_version + 1]);
            PayrollAudit::write($this->rt->db, $actor->user, 'hr.compensation_changed', 'employments', (int) $e->id, (int) $e->employing_unit_id,
                ['employment' => (string) $e->public_id, 'component' => (string) $component->code, 'ends_on' => $endsOn, 'fields' => ['ends_on']], $reason, $actor->session);
            return ['employment' => (string) $e->public_id, 'component' => (string) $component->code, 'ends_on' => $endsOn];
        });
    }
}
