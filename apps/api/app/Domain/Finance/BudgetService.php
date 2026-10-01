<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

/**
 * Budget workflow (ADR 0021 D13; F1C). Identity: unit x YEAR period x fund x version (UNIQUE). States (CHECK):
 *
 *   create / revise  -> DRAFT                    FINANCE_BUDGET_MANAGE   (revise copies the APPROVED version into v+1)
 *   lines            DRAFT only                  FINANCE_BUDGET_MANAGE   (an approved budget is never overwritten)
 *   submit           DRAFT -> SUBMITTED          FINANCE_BUDGET_MANAGE   (submitted_by)
 *   return           SUBMITTED | REVIEWED -> DRAFT   FINANCE_BUDGET_APPROVE, reason
 *   review           SUBMITTED -> REVIEWED       FINANCE_BUDGET_APPROVE  (reviewed_by; may set approved amounts)
 *   approve          REVIEWED -> APPROVED        FINANCE_BUDGET_APPROVE, approver != submitter (backend + CHECK);
 *                    the previously APPROVED version becomes SUPERSEDED in the SAME transaction
 *   cancel           DRAFT -> CANCELLED          FINANCE_BUDGET_MANAGE, reason
 * "Em execução" is derived (APPROVED + current year). APPROVED -> CLOSED (year end) is not an F1C flow.
 * One APPROVED version at most: the generated approved_guard + UNIQUE is the physical backstop; the service locks every
 * version of (unit, year, fund) FOR UPDATE in id order before deciding (C4), so two approvals serialise: approving an
 * OLDER version than the approved one is refused (BUDGET_VERSION_OUTDATED), approving a newer one supersedes it.
 * A budget never writes the ledger. Money: DECIMAL(19,4) storage, 2 decimals of business scale.
 */
final class BudgetService extends FinanceOperation
{
    public const OP_CREATE = 'FINANCE_BUDGET_CREATE';
    public const OP_REVISE = 'FINANCE_BUDGET_REVISE';
    public const MAX_LINES = 200;

    /** @return array{public_id: string, replayed: bool} */
    public function create(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_BUDGET_MANAGE);
            $hash = $this->payloadHash(self::OP_CREATE, $in);
            if (($replay = $this->prior($actor, self::OP_CREATE, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $unit = (int) $this->byPublicId('organizational_units', $in['unit'] ?? null)->id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_BUDGET_MANAGE], $unit);
            $year = is_string($in['year'] ?? null) || is_int($in['year'] ?? null) ? $this->rt->db->table('accounting_periods')->where('code', (string) $in['year'])->where('period_kind', FinanceCatalog::PERIOD_YEAR)->first() : null;
            if ($year === null) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'year']);
            }
            $lines = $this->lines($in['lines'] ?? [], 'requested_amount');
            $fund = $this->generalFund();
            $versions = $this->versions($unit, (int) $year->id, $fund);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_BUDGET_MANAGE, $unit);
            $this->assertUnitActive($unit);

            $claim = $this->claim($actor, self::OP_CREATE, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $publicId = $this->insert($unit, (int) $year->id, $fund, (int) $versions->max('version') + 1, $lines);
            $this->complete($claim['id'], $publicId);
            $id = (int) $this->rt->db->table('budgets')->where('public_id', $publicId)->value('id');
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.budget_created', 'budgets', $id, $decision->unit, FinanceAudit::correlation(), [
                'budget' => $publicId, 'year' => (string) $year->code, 'version' => (int) $versions->max('version') + 1, 'lines' => count($lines),
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** New DRAFT version copied from the APPROVED one (nothing is overwritten). @return array{public_id: string, replayed: bool} */
    public function revise(int $user, int $session, string $clientKey, string $publicId): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_BUDGET_MANAGE);
            $hash = $this->payloadHash(self::OP_REVISE, ['budget' => $publicId]);
            if (($replay = $this->prior($actor, self::OP_REVISE, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $peek = $this->byPublicId('budgets', $publicId);
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_BUDGET_MANAGE], (int) $peek->unit_id);
            $versions = $this->versions((int) $peek->unit_id, (int) $peek->period_id, (int) $peek->fund_id);
            $budget = $versions->firstWhere('id', $peek->id);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_BUDGET_MANAGE, (int) $budget->unit_id);
            if ($budget->status !== 'APPROVED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $budget->status]);
            }
            $lines = [];
            foreach ($this->rt->db->table('budget_lines')->where('budget_id', $budget->id)->orderBy('id')->get() as $line) {
                $amount = Money::fromDecimal((string) $line->approved_amount);
                $lines[(int) $line->category_id] = ['requested' => $amount, 'approved' => $amount];
            }
            $claim = $this->claim($actor, self::OP_REVISE, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $version = (int) $versions->max('version') + 1;
            $newPublic = $this->insert((int) $budget->unit_id, (int) $budget->period_id, (int) $budget->fund_id, $version, $lines);
            $this->complete($claim['id'], $newPublic);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.budget_revised', 'budgets', (int) $this->rt->db->table('budgets')->where('public_id', $newPublic)->value('id'), $decision->unit,
                FinanceAudit::correlation(), ['budget' => $newPublic, 'revision_of' => (string) $budget->public_id, 'version' => $version], null, $actor->session);
            return ['public_id' => $newPublic, 'replayed' => false];
        });
    }

    /** Replace the lines of a DRAFT budget (requested amounts). */
    public function replaceLines(int $user, int $session, string $publicId, array $in): array
    {
        return $this->transition($user, $session, $publicId, $in, FinanceCatalog::PERMISSION_BUDGET_MANAGE, function (object $budget, TerritorialActor $actor) use ($in): array {
            if ($budget->status !== 'DRAFT') {
                throw new FinanceError('BUDGET_NOT_EDITABLE');
            }
            $lines = $this->lines($in['lines'] ?? [], 'requested_amount');
            $this->rt->db->table('budget_lines')->where('budget_id', $budget->id)->delete();
            $this->insertLines((int) $budget->id, $lines);
            return [[], 'finance.budget_lines_replaced', ['lines' => count($lines)], null];
        });
    }

    public function submit(int $user, int $session, string $publicId, array $in): array
    {
        return $this->transition($user, $session, $publicId, $in, FinanceCatalog::PERMISSION_BUDGET_MANAGE, function (object $budget, TerritorialActor $actor): array {
            $this->expect($budget, ['DRAFT'], 'SUBMITTED');
            if (!$this->rt->db->table('budget_lines')->where('budget_id', $budget->id)->exists()) {
                throw new FinanceError('BUDGET_EMPTY');
            }
            return [['status' => 'SUBMITTED', 'submitted_by' => $actor->user], 'finance.budget_submitted', [], null];
        });
    }

    public function returnToDraft(int $user, int $session, string $publicId, array $in): array
    {
        return $this->transition($user, $session, $publicId, $in, FinanceCatalog::PERMISSION_BUDGET_APPROVE, function (object $budget) use ($in): array {
            $this->expect($budget, ['SUBMITTED', 'REVIEWED'], 'DRAFT');
            $reason = $this->reason($in['reason'] ?? null);
            return [['status' => 'DRAFT', 'submitted_by' => null, 'reviewed_by' => null], 'finance.budget_returned', [], $reason];
        });
    }

    public function review(int $user, int $session, string $publicId, array $in): array
    {
        return $this->transition($user, $session, $publicId, $in, FinanceCatalog::PERMISSION_BUDGET_APPROVE, function (object $budget, TerritorialActor $actor) use ($in): array {
            $this->expect($budget, ['SUBMITTED'], 'REVIEWED');
            $approved = $this->lines($in['approved_lines'] ?? [], 'approved_amount');
            $existing = $this->rt->db->table('budget_lines')->where('budget_id', $budget->id)->pluck('id', 'category_id')->all();
            foreach ($approved as $category => $amounts) {
                if (!isset($existing[$category])) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'approved_lines']);
                }
                $this->rt->db->table('budget_lines')->where('id', $existing[$category])->update(['approved_amount' => Money::format($amounts['approved'])]);
            }
            return [['status' => 'REVIEWED', 'reviewed_by' => $actor->user], 'finance.budget_reviewed', ['adjusted_lines' => count($approved)], null];
        });
    }

    public function approve(int $user, int $session, string $publicId, array $in): array
    {
        return $this->transition($user, $session, $publicId, $in, FinanceCatalog::PERMISSION_BUDGET_APPROVE, function (object $budget, TerritorialActor $actor, $versions): array {
            if ($budget->status === 'APPROVED') {
                return ['replayed' => true];
            }
            $this->expect($budget, ['REVIEWED'], 'APPROVED');
            if ((int) $budget->submitted_by === $actor->user) {
                // D13 / D07 exception: the approver is never the submitter (also a physical CHECK).
                throw new FinanceError('SEGREGATION_REQUIRED');
            }
            $current = $versions->firstWhere('status', 'APPROVED');
            $superseded = null;
            if ($current !== null) {
                if ((int) $current->version > (int) $budget->version) {
                    throw new FinanceError('BUDGET_VERSION_OUTDATED');
                }
                $this->rt->db->table('budgets')->where('id', $current->id)->where('status', 'APPROVED')->update(['status' => 'SUPERSEDED', 'lock_version' => $current->lock_version + 1]);
                $superseded = (string) $current->public_id;
            }
            return [['status' => 'APPROVED', 'approved_by' => $actor->user, 'approved_at' => $this->rt->ts()], 'finance.budget_approved', ['superseded' => $superseded], null];
        });
    }

    public function cancel(int $user, int $session, string $publicId, array $in): array
    {
        return $this->transition($user, $session, $publicId, $in, FinanceCatalog::PERMISSION_BUDGET_MANAGE, function (object $budget) use ($in): array {
            $this->expect($budget, ['DRAFT'], 'CANCELLED');
            return [['status' => 'CANCELLED'], 'finance.budget_cancelled', [], $this->reason($in['reason'] ?? null)];
        });
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    /**
     * One explicit transition: permission first, scope on the budget unit before locks, every version of the same
     * (unit, year, fund) locked FOR UPDATE in id order (C4), then the step. $step returns [changes, audit action,
     * metadata, reason] or ['replayed' => true].
     */
    private function transition(int $user, int $session, string $publicId, array $in, string $permission, callable $step): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in, $permission, $step): array {
            $guard->requires($permission);
            $peek = $this->byPublicId('budgets', $publicId);
            $this->preauthorize($actor, [$permission], (int) $peek->unit_id);
            $versions = $this->versions((int) $peek->unit_id, (int) $peek->period_id, (int) $peek->fund_id);
            $budget = $versions->firstWhere('id', $peek->id);
            $decision = $guard->unit($permission, (int) $budget->unit_id);
            $result = $step($budget, $actor, $versions);
            if (isset($result['replayed'])) {
                return ['replayed' => true];
            }
            $this->assertLockVersion($budget, $in);
            [$changes, $action, $meta, $reason] = $result;
            $this->rt->db->table('budgets')->where('id', $budget->id)->where('status', $budget->status)->update($changes + ['lock_version' => $budget->lock_version + 1]);
            FinanceAudit::write($this->rt->db, $actor->user, $action, 'budgets', (int) $budget->id, $decision->unit, FinanceAudit::correlation(),
                ['budget' => (string) $budget->public_id, 'version' => (int) $budget->version, 'from' => (string) $budget->status, 'to' => $changes['status'] ?? (string) $budget->status] + $meta, $reason, $actor->session);
            return ['replayed' => false];
        });
    }

    private function expect(object $budget, array $from, string $to): void
    {
        if (!in_array($budget->status, $from, true)) {
            throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $budget->status, 'to' => $to]);
        }
    }

    /** Every version of (unit, YEAR period, fund) locked FOR UPDATE in id order: the C4 serialisation point. */
    private function versions(int $unit, int $period, int $fund): \Illuminate\Support\Collection
    {
        return $this->rt->db->table('budgets')->where('unit_id', $unit)->where('period_id', $period)->where('fund_id', $fund)->orderBy('id')->lockForUpdate()->get();
    }

    /** @return array<int, array{requested: int, approved: int}> category id => amounts (unique rubrics, budgetable natures) */
    private function lines(mixed $lines, string $amountKey): array
    {
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $amountKey === 'requested_amount' ? 'lines' : 'approved_lines']);
        }
        $out = [];
        foreach ($lines as $line) {
            $field = $amountKey === 'requested_amount' ? 'lines' : 'approved_lines';
            if (!is_array($line)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
            }
            $category = $this->category($line['category'] ?? null, FinanceCatalog::BUDGET_NATURES, $field);
            if (isset($out[(int) $category->id])) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => $field, 'reason' => 'duplicate_category']);
            }
            $amount = Money::cents(is_string($line[$amountKey] ?? null) ? $line[$amountKey] : '', true);
            $out[(int) $category->id] = ['requested' => $amount, 'approved' => $amount];
        }
        return $out;
    }

    private function insert(int $unit, int $period, int $fund, int $version, array $lines): string
    {
        $publicId = (string) Str::ulid();
        $id = (int) $this->rt->db->table('budgets')->insertGetId(['public_id' => $publicId, 'unit_id' => $unit, 'period_id' => $period, 'fund_id' => $fund, 'currency_id' => $this->currencyId(),
            'version' => $version, 'status' => 'DRAFT', 'submitted_by' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null, 'created_at' => $this->rt->ts(), 'lock_version' => 0]);
        $this->insertLines($id, $lines);
        return $publicId;
    }

    private function insertLines(int $budget, array $lines): void
    {
        $now = $this->rt->ts();
        foreach ($lines as $category => $amounts) {
            $this->rt->db->table('budget_lines')->insert(['budget_id' => $budget, 'category_id' => $category, 'requested_amount' => Money::format($amounts['requested']),
                'approved_amount' => Money::format($amounts['approved']), 'created_at' => $now, 'lock_version' => 0]);
        }
    }
}
