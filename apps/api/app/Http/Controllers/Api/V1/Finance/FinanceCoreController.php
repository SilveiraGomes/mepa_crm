<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Finance\AccrualService;
use App\Domain\Finance\BankReconciliationService;
use App\Domain\Finance\BudgetService;
use App\Domain\Finance\FinanceAccountService;
use App\Domain\Finance\FinanceCoreQueryService;
use App\Domain\Finance\PeriodCloseService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceOutput;
use App\Http\Finance\FinanceServiceFactory;
use App\Http\Requests\Finance\AccountCloseRequest;
use App\Http\Requests\Finance\AccountListRequest;
use App\Http\Requests\Finance\AccountOpenRequest;
use App\Http\Requests\Finance\ActualVsBudgetRequest;
use App\Http\Requests\Finance\BudgetCreateRequest;
use App\Http\Requests\Finance\BudgetLinesRequest;
use App\Http\Requests\Finance\BudgetListRequest;
use App\Http\Requests\Finance\BudgetReviewRequest;
use App\Http\Requests\Finance\EmptyFinanceRequest;
use App\Http\Requests\Finance\FinanceReasonRequest;
use App\Http\Requests\Finance\LockVersionRequest;
use App\Http\Requests\Finance\PageRequest;
use App\Http\Requests\Finance\PeriodListRequest;
use App\Http\Requests\Finance\PeriodReopenRequest;
use App\Http\Requests\Finance\PeriodUnitRequest;
use App\Http\Requests\Finance\ReconciliationAdjustmentRequest;
use App\Http\Requests\Finance\ReconciliationCreateRequest;
use App\Http\Requests\Finance\ReconciliationListRequest;
use App\Http\Requests\Finance\ReconciliationMatchRequest;
use App\Http\Requests\Finance\ReconciliationUnmatchRequest;
use App\Http\Requests\Finance\SettlementRequest;
use App\Http\Requests\Finance\StatementCreateRequest;
use App\Http\Requests\Finance\StatementListRequest;
use App\Http\Requests\Finance\SubledgerCreateRequest;
use App\Http\Requests\Finance\SubledgerListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// P0.10-F1C Finance Core endpoints: financial accounts, receivables / payables / settlements, bank statements and
// reconciliation, budget, period closes. Explicit transition endpoints only (no generic status PATCH); targets are
// public ids; a replayed creation or transition answers 200 with the current detail and meta.replayed = true.
final class FinanceCoreController extends Controller
{
    public function __construct(private FinanceServiceFactory $factory)
    {
    }

    // ---- financial accounts ----------------------------------------------------------------------------------------------
    public function accounts(AccountListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->accounts($u, $s, $r->validated())); }
    public function account(EmptyFinanceRequest $r, string $account): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->account($u, $s, $account)); }
    public function accountHistory(PageRequest $r, string $account): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->accountHistory($u, $s, $account, $r->validated())); }

    public function openAccount(AccountOpenRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->factory->make(FinanceAccountService::class)->open($u, $s, $this->key($r), $r->validated());
        return $this->created($res, fn () => $this->q()->account($u, $s, $res['public_id']));
    }

    public function closeAccount(AccountCloseRequest $r, string $account): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->factory->make(FinanceAccountService::class)->close($u, $s, $account, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->account($u, $s, $account));
    }

    // ---- receivables / payables / settlements -----------------------------------------------------------------------
    public function receivables(SubledgerListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->subledgerList('receivables', $u, $s, $r->validated())); }
    public function payables(SubledgerListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->subledgerList('payables', $u, $s, $r->validated())); }
    public function receivable(EmptyFinanceRequest $r, string $receivable): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->subledger('receivables', $u, $s, $receivable)); }
    public function payable(EmptyFinanceRequest $r, string $payable): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->subledger('payables', $u, $s, $payable)); }
    public function settlement(EmptyFinanceRequest $r, string $settlement): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->settlement($u, $s, $settlement)); }

    public function recognizeReceivable(SubledgerCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->accrual()->recognizeReceivable($u, $s, $this->key($r), $r->validated());
        return $this->created($res, fn () => $this->q()->subledger('receivables', $u, $s, $res['public_id']));
    }

    public function recognizePayable(SubledgerCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->accrual()->recognizePayable($u, $s, $this->key($r), $r->validated());
        return $this->created($res, fn () => $this->q()->subledger('payables', $u, $s, $res['public_id']));
    }

    public function settleReceivable(SettlementRequest $r, string $receivable): JsonResponse { return $this->settle($r, 'receivables', $receivable); }
    public function settlePayable(SettlementRequest $r, string $payable): JsonResponse { return $this->settle($r, 'payables', $payable); }

    public function cancelReceivable(FinanceReasonRequest $r, string $receivable): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->accrual()->cancel('receivables', $u, $s, $receivable, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->subledger('receivables', $u, $s, $receivable));
    }

    public function cancelPayable(FinanceReasonRequest $r, string $payable): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->accrual()->cancel('payables', $u, $s, $payable, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->subledger('payables', $u, $s, $payable));
    }

    public function cancelSettlement(FinanceReasonRequest $r, string $settlement): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->accrual()->cancelSettlement($u, $s, $settlement, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->settlement($u, $s, $settlement));
    }

    // ---- bank statements / reconciliation -----------------------------------------------------------------------------
    public function statements(StatementListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->statements($u, $s, $r->validated())); }
    public function statement(EmptyFinanceRequest $r, string $statement): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->statement($u, $s, $statement)); }
    public function reconciliations(ReconciliationListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->reconciliations($u, $s, $r->validated())); }
    public function reconciliation(EmptyFinanceRequest $r, string $reconciliation): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->reconciliation($u, $s, $reconciliation)); }

    public function importStatement(StatementCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->bank()->importStatement($u, $s, $this->key($r), $r->validated());
        return $this->created($res, fn () => $this->q()->statement($u, $s, $res['public_id']));
    }

    public function openReconciliation(ReconciliationCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->bank()->open($u, $s, $this->key($r), $r->validated());
        return $this->created($res, fn () => $this->q()->reconciliation($u, $s, $res['public_id']));
    }

    public function match(ReconciliationMatchRequest $r, string $reconciliation): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->bank()->match($u, $s, $reconciliation, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->reconciliation($u, $s, $reconciliation));
    }

    public function unmatch(ReconciliationUnmatchRequest $r, string $reconciliation): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->bank()->unmatch($u, $s, $reconciliation, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->reconciliation($u, $s, $reconciliation));
    }

    /** FIN-D11.4: posts the difference in the first OPEN period; meta carries the new entry and its date. */
    public function adjustReconciliation(ReconciliationAdjustmentRequest $r, string $reconciliation): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->bank()->adjust($u, $s, $this->key($r), $reconciliation, $r->validated());
        $detail = $this->q()->reconciliation($u, $s, $reconciliation);
        FinanceOutput::assertSafe($detail);
        return response()->json(['data' => $detail, 'meta' => ['replayed' => $res['replayed'], 'adjustment_entry' => $res['public_id'], 'posted_on' => $res['posted_on']]],
            $res['replayed'] ? 200 : 201, ['Cache-Control' => 'no-store, private']);
    }

    public function closeReconciliation(LockVersionRequest $r, string $reconciliation): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->bank()->close($u, $s, $reconciliation, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->reconciliation($u, $s, $reconciliation));
    }

    // ---- budget -----------------------------------------------------------------------------------------------------------
    public function budgets(BudgetListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->budgets($u, $s, $r->validated())); }
    public function budget(EmptyFinanceRequest $r, string $budget): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->budget($u, $s, $budget)); }
    public function actualVsBudget(ActualVsBudgetRequest $r, string $budget): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->actualVsBudget($u, $s, $budget, $r->validated())); }

    public function createBudget(BudgetCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->budgetSvc()->create($u, $s, $this->key($r), $r->validated());
        return $this->created($res, fn () => $this->q()->budget($u, $s, $res['public_id']));
    }

    public function reviseBudget(EmptyFinanceRequest $r, string $budget): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->budgetSvc()->revise($u, $s, $this->key($r), $budget);
        return $this->created($res, fn () => $this->q()->budget($u, $s, $res['public_id']));
    }

    public function budgetLines(BudgetLinesRequest $r, string $budget): JsonResponse { return $this->budgetStep($r, $budget, 'replaceLines'); }
    public function submitBudget(LockVersionRequest $r, string $budget): JsonResponse { return $this->budgetStep($r, $budget, 'submit'); }
    public function returnBudget(FinanceReasonRequest $r, string $budget): JsonResponse { return $this->budgetStep($r, $budget, 'returnToDraft'); }
    public function reviewBudget(BudgetReviewRequest $r, string $budget): JsonResponse { return $this->budgetStep($r, $budget, 'review'); }
    public function approveBudget(LockVersionRequest $r, string $budget): JsonResponse { return $this->budgetStep($r, $budget, 'approve'); }
    public function cancelBudget(FinanceReasonRequest $r, string $budget): JsonResponse { return $this->budgetStep($r, $budget, 'cancel'); }

    // ---- periods ---------------------------------------------------------------------------------------------------------
    public function periods(PeriodListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->periods($u, $s, $r->validated())); }

    public function closePeriod(PeriodUnitRequest $r, string $period): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $this->factory->make(PeriodCloseService::class)->closeUnit($u, $s, $period, $r->validated());
        return $this->done(false, fn () => $this->q()->periods($u, $s, ['unit' => $r->validated()['unit'], 'year' => substr($period, 0, 4)]));
    }

    public function reopenPeriod(PeriodReopenRequest $r, string $period): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $this->factory->make(PeriodCloseService::class)->reopenUnit($u, $s, $period, $r->validated());
        return $this->done(false, fn () => $this->q()->periods($u, $s, ['unit' => $r->validated()['unit'], 'year' => substr($period, 0, 4)]));
    }

    public function closePeriodNationally(EmptyFinanceRequest $r, string $period): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $this->factory->make(PeriodCloseService::class)->closeNational($u, $s, $period);
        return response()->json(['data' => ['period' => $period, 'national_status' => 'CLOSED'], 'meta' => ['replayed' => false]], 200, ['Cache-Control' => 'no-store, private']);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------------

    private function settle(SettlementRequest $r, string $kind, string $target): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->accrual()->settle($kind, $u, $s, $this->key($r), $target, $r->validated());
        return $this->created($res, fn () => $this->q()->settlement($u, $s, $res['public_id']));
    }

    private function budgetStep(Request $r, string $budget, string $method): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $res = $this->budgetSvc()->{$method}($u, $s, $budget, $r->validated());
        return $this->done($res['replayed'], fn () => $this->q()->budget($u, $s, $budget));
    }

    private function created(array $res, callable $detail): JsonResponse
    {
        return $this->respond($detail(), $res['replayed'], $res['replayed'] ? 200 : 201);
    }

    private function done(bool $replayed, callable $detail): JsonResponse
    {
        return $this->respond($detail(), $replayed, 200);
    }

    private function respond(array $detail, bool $replayed, int $status): JsonResponse
    {
        FinanceOutput::assertSafe($detail);
        return response()->json(['data' => $detail, 'meta' => ['replayed' => $replayed]], $status, ['Cache-Control' => 'no-store, private']);
    }

    private function q(): FinanceCoreQueryService { return $this->factory->make(FinanceCoreQueryService::class); }
    private function accrual(): AccrualService { return $this->factory->make(AccrualService::class); }
    private function bank(): BankReconciliationService { return $this->factory->make(BankReconciliationService::class); }
    private function budgetSvc(): BudgetService { return $this->factory->make(BudgetService::class); }
    private function key(Request $r): string { return (string) $r->header('Idempotency-Key', ''); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
