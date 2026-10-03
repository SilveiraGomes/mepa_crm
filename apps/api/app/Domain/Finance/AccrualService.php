<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

/**
 * Accrual subledgers (ADR 0021 D01 full accrual, D05, D07, D11, D14; F1C). States are the F1A CHECK vocabulary,
 * confirmed for F1C: receivables / payables PENDING | RECOGNIZED | SETTLED | CANCELLED, settlements POSTED | CANCELLED.
 * Subledger entries are born POSTED (D07), so V1 recognises in the creating transaction; PENDING stays reserved (the
 * subledger rows carry no rubric column, so a PENDING row would have nowhere to keep it). Explicit transitions only:
 *
 *   recognise      (create) -> RECOGNIZED     FINANCE_MANAGE + FINANCE_POST   RECEIVABLE_RECOGNITION  Dr RECEIVABLES / Cr revenue
 *                                                                             PAYABLE_RECOGNITION     Dr expense | FIXED_ASSETS / Cr PAYABLES
 *   settle         RECOGNIZED -> RECOGNIZED | SETTLED (outstanding 0)          SETTLEMENT              Dr CASH|BANK / Cr RECEIVABLES
 *                  FINANCE_MANAGE + FINANCE_POST                                                       Dr PAYABLES / Cr CASH|BANK
 *   cancel settlement  POSTED -> CANCELLED; SETTLED -> RECOGNIZED     FINANCE_REVERSE   REVERSAL of the settlement entry (own flow, D11)
 *   cancel         RECOGNIZED (no live settlement) -> CANCELLED       FINANCE_REVERSE   REVERSAL of the recognition entry
 *
 * The revenue / expense is recognised ONCE (recognition); a settlement only moves cash against the receivable /
 * payable and never touches the result. Invariant 0 < settlement <= outstanding (outstanding = amount - Σ allocations of
 * POSTED settlements) is decided on a LOCKING read with the subledger row held FOR UPDATE (C10). Lock order: period
 * (+ unit close) FOR SHARE -> receivable / payable FOR UPDATE -> settlement FOR UPDATE -> financial account -> journal.
 */
final class AccrualService extends FinanceOperation
{
    public const OP_RECEIVABLE = 'FINANCE_RECEIVABLE_RECOGNIZE';
    public const OP_PAYABLE = 'FINANCE_PAYABLE_RECOGNIZE';
    public const OP_SETTLE = 'FINANCE_SETTLE';

    private const KINDS = [
        'receivables' => ['column' => 'receivable_id', 'direction' => 'RECEIPT', 'recognition' => 'RECEIVABLE_RECOGNITION', 'audit' => 'receivable'],
        'payables' => ['column' => 'payable_id', 'direction' => 'PAYMENT', 'recognition' => 'PAYABLE_RECOGNITION', 'audit' => 'payable'],
    ];

    /** @return array{public_id: string, replayed: bool} */
    public function recognizeReceivable(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->recognize('receivables', $user, $session, $clientKey, $in);
    }

    /** @return array{public_id: string, replayed: bool} */
    public function recognizePayable(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->recognize('payables', $user, $session, $clientKey, $in);
    }

    private function recognize(string $kind, int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($kind, $clientKey, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST);
            $payable = $kind === 'payables';
            $operation = $payable ? self::OP_PAYABLE : self::OP_RECEIVABLE;
            $hash = $this->payloadHash($operation, $in);
            if (($replay = $this->prior($actor, $operation, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $unit = (int) $this->byPublicId('organizational_units', $in['unit'] ?? null)->id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST], $unit);
            [$recognizedOn] = $this->rt->instantFor($in['recognized_on'] ?? null);
            $this->ledger->lockPostingPeriod($unit, $recognizedOn);
            $decision = $guard->all([FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST], $unit);
            $this->assertUnitActive($unit);

            $party = $this->party($actor, $in['party'] ?? null);
            $category = $this->category($in['category'] ?? null, $payable ? FinanceCatalog::PAYABLE_NATURES : FinanceCatalog::RECEIVABLE_NATURES);
            if (!$payable && in_array($category->code, FinanceCatalog::NON_RECEIVABLE_CATEGORIES, true)) {
                // D01.2: tithes, offerings and donations are recognised when received; in kind has its own flow (D04).
                throw new FinanceError('CATEGORY_NOT_RECEIVABLE');
            }
            $amount = Money::cents(is_string($in['amount'] ?? null) ? $in['amount'] : '');
            $dueOn = $this->date($in['due_on'] ?? null, 'due_on', true);
            $description = $this->text($in['description'] ?? null, 'description') ?? ($payable ? 'Valor a pagar' : 'Valor a receber');
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, $unit);
            if ($payable && ($document === null || !in_array($this->documentTypeCode($document), FinanceCatalog::PAYABLE_DOCUMENT_TYPES, true))) {
                // D14: a payable always carries its supporting document (catalog document_id NOT NULL).
                throw new FinanceError('PAYABLE_DOCUMENT_REQUIRED');
            }

            $claim = $this->claim($actor, $operation, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $publicId = (string) Str::ulid();
            $money = Money::format($amount);
            // The economic line is the rubric's own control account: a capitalisable investment debits FIXED_ASSETS,
            // never an expense (D-04A.12); the posting gate re-checks rubric <-> control account coherence.
            $lines = $payable
                ? [['ledger_account_id' => (int) $category->ledger_account_id, 'category' => (string) $category->code, 'debit' => $money], ['account' => 'PAYABLES', 'credit' => $money]]
                : [['account' => 'RECEIVABLES', 'debit' => $money], ['ledger_account_id' => (int) $category->ledger_account_id, 'category' => (string) $category->code, 'credit' => $money]];
            $entry = $this->ledger->postSubledgerEntry($actor->user, ($payable ? 'PR-' : 'RR-') . $publicId, [
                'unit_id' => $unit, 'entry_kind' => self::KINDS[$kind]['recognition'], 'entry_date' => $recognizedOn, 'description' => $description,
                'document_id' => $document?->id === null ? null : (int) $document->id, 'lines' => $lines]);
            $now = $this->rt->ts();
            $row = ['public_id' => $publicId, 'party_id' => $party, 'unit_id' => $unit, 'currency_id' => $this->currencyId(), 'amount' => $money, 'due_on' => $dueOn,
                'recognition_entry_id' => (int) $entry['id'], 'document_id' => $document?->id === null ? null : (int) $document->id, 'status' => 'RECOGNIZED', 'created_at' => $now, 'lock_version' => 0];
            if ($payable) {
                $row['workflow_instance_id'] = $this->workflowInstance($unit, $actor->user, $now);
            } else {
                $row['obligation_id'] = null;
            }
            $id = (int) $this->rt->db->table($kind)->insertGetId($row);
            $this->complete($claim['id'], $publicId);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.' . self::KINDS[$kind]['audit'] . '_recognized', $kind, $id, $decision->unit, FinanceAudit::correlation(), [
                self::KINDS[$kind]['audit'] => $publicId, 'entry' => $entry['public_id'], 'category' => (string) $category->code, 'document' => $document?->public_id,
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** Partial or total settlement of a RECOGNIZED receivable / payable. @return array{public_id: string, replayed: bool} */
    public function settle(string $kind, int $user, int $session, string $clientKey, string $publicId, array $in): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'kind']);
        }
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($kind, $clientKey, $publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST);
            $hash = $this->payloadHash(self::OP_SETTLE, ['target' => $kind . ':' . $publicId] + $in);
            if (($replay = $this->prior($actor, self::OP_SETTLE, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $peek = $this->byPublicId($kind, $publicId);
            $unit = (int) $peek->unit_id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST], $unit);
            [$settledOn, $settledAt] = $this->rt->instantFor($in['settled_on'] ?? null);
            $this->ledger->lockPostingPeriod($unit, $settledOn);
            $doc = $this->lockRow($kind, (int) $peek->id);
            $decision = $guard->all([FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST], $unit);
            if ($doc->status === 'SETTLED') {
                throw new FinanceError('ALREADY_SETTLED');
            }
            if ($doc->status !== 'RECOGNIZED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $doc->status]);
            }
            $this->assertLockVersion($doc, $in);
            if ($settledOn < (string) $this->rt->db->table('journal_entries')->where('id', $doc->recognition_entry_id)->value('entry_date')) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'settled_on', 'reason' => 'before_recognition']);
            }
            $account = $this->byPublicId('accounts', $in['account'] ?? null, 'update');
            if ((int) $account->unit_id !== $unit) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'accounts']);
            }
            if ($account->status !== 'OPEN') {
                throw new FinanceError('ACCOUNT_NOT_OPEN');
            }
            $amount = Money::cents(is_string($in['amount'] ?? null) ? $in['amount'] : '');
            $outstanding = Money::fromDecimal((string) $doc->amount) - $this->settledCents(self::KINDS[$kind]['column'], (int) $doc->id);
            if ($amount > $outstanding) {
                throw new FinanceError('OVER_SETTLEMENT');
            }
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, $unit);

            $claim = $this->claim($actor, self::OP_SETTLE, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $settlementPublic = (string) Str::ulid();
            $money = Money::format($amount);
            $treasury = ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$account->account_kind], 'financial_account_id' => (int) $account->id];
            // A settlement only exchanges cash for the receivable / payable: the result was recognised once already.
            $lines = $kind === 'receivables'
                ? [$treasury + ['debit' => $money], ['account' => 'RECEIVABLES', 'credit' => $money]]
                : [['account' => 'PAYABLES', 'debit' => $money], $treasury + ['credit' => $money]];
            $entry = $this->ledger->postSubledgerEntry($actor->user, 'ST-' . $settlementPublic, [
                'unit_id' => $unit, 'entry_kind' => 'SETTLEMENT', 'entry_date' => $settledOn, 'description' => ($kind === 'receivables' ? 'Recebimento de ' : 'Pagamento de ') . $doc->public_id,
                'document_id' => $document?->id === null ? null : (int) $document->id, 'lines' => $lines]);
            $now = $this->rt->ts();
            $settlementId = (int) $this->rt->db->table('settlements')->insertGetId(['public_id' => $settlementPublic, 'account_id' => (int) $account->id, 'currency_id' => $this->currencyId(),
                'amount' => $money, 'settled_at' => $settledAt, 'entry_id' => (int) $entry['id'], 'direction' => self::KINDS[$kind]['direction'], 'status' => 'POSTED', 'created_at' => $now, 'lock_version' => 0]);
            $this->rt->db->table('settlement_allocations')->insert(['settlement_id' => $settlementId, 'receivable_id' => $kind === 'receivables' ? (int) $doc->id : null,
                'payable_id' => $kind === 'payables' ? (int) $doc->id : null, 'amount' => $money, 'created_at' => $now, 'lock_version' => 0]);
            $remaining = $outstanding - $amount;
            $this->rt->db->table($kind)->where('id', $doc->id)->where('status', 'RECOGNIZED')->update(['status' => $remaining === 0 ? 'SETTLED' : 'RECOGNIZED', 'lock_version' => $doc->lock_version + 1]);
            if ($kind === 'payables' && $remaining === 0) {
                $this->workflow((int) $doc->workflow_instance_id, 'SETTLED', true);
            }
            $this->complete($claim['id'], $settlementPublic);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.settlement_posted', 'settlements', $settlementId, $decision->unit, FinanceAudit::correlation(), [
                'settlement' => $settlementPublic, 'entry' => $entry['public_id'], self::KINDS[$kind]['audit'] => (string) $doc->public_id, 'direction' => self::KINDS[$kind]['direction'],
                'fully_settled' => $remaining === 0,
            ], null, $actor->session);
            return ['public_id' => $settlementPublic, 'replayed' => false];
        });
    }

    /** POSTED -> CANCELLED by an own-flow REVERSAL; a SETTLED receivable / payable returns to RECOGNIZED. */
    public function cancelSettlement(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_REVERSE);
            $peek = $this->byPublicId('settlements', $publicId);
            $unit = (int) $this->rt->db->table('accounts')->where('id', $peek->account_id)->value('unit_id');
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_REVERSE], $unit);
            $today = $this->rt->today();
            $this->ledger->lockPostingPeriod($unit, $today);
            $allocation = $this->rt->db->table('settlement_allocations')->where('settlement_id', $peek->id)->first();
            $kind = $allocation->receivable_id !== null ? 'receivables' : 'payables';
            $doc = $this->lockRow($kind, (int) ($allocation->receivable_id ?? $allocation->payable_id));
            $settlement = $this->lockRow('settlements', (int) $peek->id);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_REVERSE, $unit);
            if ($settlement->status === 'CANCELLED') {
                return ['public_id' => (string) $settlement->public_id, 'replayed' => true];
            }
            $this->assertLockVersion($settlement, $in);
            $reason = $this->reason($in['reason'] ?? null);
            $reversal = $this->ledger->postSubledgerReversal($actor->user, 'SX-' . $settlement->public_id, (int) $settlement->entry_id, $reason, $today);
            $this->rt->db->table('settlements')->where('id', $settlement->id)->where('status', 'POSTED')->update(['status' => 'CANCELLED', 'lock_version' => $settlement->lock_version + 1]);
            if ($doc->status === 'SETTLED') {
                $this->rt->db->table($kind)->where('id', $doc->id)->where('status', 'SETTLED')->update(['status' => 'RECOGNIZED', 'lock_version' => $doc->lock_version + 1]);
                if ($kind === 'payables') {
                    $this->workflow((int) $doc->workflow_instance_id, 'RECOGNIZED', false);
                }
            } else {
                $this->rt->db->table($kind)->where('id', $doc->id)->update(['lock_version' => $doc->lock_version + 1]);
            }
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.settlement_cancelled', 'settlements', (int) $settlement->id, $decision->unit, FinanceAudit::correlation(), [
                'settlement' => (string) $settlement->public_id, self::KINDS[$kind]['audit'] => (string) $doc->public_id, 'entry' => $reversal['public_id'],
                'reversal_of' => (string) $this->rt->db->table('journal_entries')->where('id', $settlement->entry_id)->value('public_id'),
            ], $reason, $actor->session);
            return ['public_id' => (string) $settlement->public_id, 'replayed' => false];
        });
    }

    /** RECOGNIZED (no POSTED settlement) -> CANCELLED by an own-flow REVERSAL of the recognition entry. */
    public function cancel(string $kind, int $user, int $session, string $publicId, array $in): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'kind']);
        }
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($kind, $publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_REVERSE);
            $peek = $this->byPublicId($kind, $publicId);
            $unit = (int) $peek->unit_id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_REVERSE], $unit);
            $today = $this->rt->today();
            $this->ledger->lockPostingPeriod($unit, $today);
            $doc = $this->lockRow($kind, (int) $peek->id);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_REVERSE, $unit);
            if ($doc->status === 'CANCELLED') {
                return ['public_id' => (string) $doc->public_id, 'replayed' => true];
            }
            if ($doc->status !== 'RECOGNIZED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $doc->status]);
            }
            $this->assertLockVersion($doc, $in);
            if ($this->settledCents(self::KINDS[$kind]['column'], (int) $doc->id) > 0) {
                throw new FinanceError('HAS_SETTLEMENTS');
            }
            $reason = $this->reason($in['reason'] ?? null);
            $reversal = $this->ledger->postSubledgerReversal($actor->user, 'RX-' . $doc->public_id, (int) $doc->recognition_entry_id, $reason, $today);
            $this->rt->db->table($kind)->where('id', $doc->id)->where('status', 'RECOGNIZED')->update(['status' => 'CANCELLED', 'lock_version' => $doc->lock_version + 1]);
            if ($kind === 'payables') {
                $this->workflow((int) $doc->workflow_instance_id, 'CANCELLED', true);
            }
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.' . self::KINDS[$kind]['audit'] . '_cancelled', $kind, (int) $doc->id, $decision->unit, FinanceAudit::correlation(), [
                self::KINDS[$kind]['audit'] => (string) $doc->public_id, 'entry' => $reversal['public_id'],
            ], $reason, $actor->session);
            return ['public_id' => (string) $doc->public_id, 'replayed' => false];
        });
    }

    /**
     * Σ allocations of POSTED settlements for one receivable / payable, from a LOCKING read taken with the subledger row
     * held FOR UPDATE: after waiting for a competing settlement, its committed allocation is always seen (C10).
     */
    private function settledCents(string $column, int $id): int
    {
        $row = $this->rt->db->selectOne("SELECT COALESCE(SUM(a.amount), 0) AS s FROM settlement_allocations a JOIN settlements s ON s.id = a.settlement_id WHERE a.{$column} = ? AND s.status = 'POSTED' FOR SHARE", [$id]);
        return Money::fromDecimal((string) $row->s);
    }

    private function workflowInstance(int $unit, int $actor, string $now): int
    {
        $workflow = $this->rt->db->table('workflows')->where('code', 'FINANCE_PAYABLE')->where('version', FinanceCatalog::WORKFLOW_VERSION)->where('status', 'ACTIVE')->value('id');
        if ($workflow === null) {
            throw new FinanceError('CONFIG_MISSING', [], ['catalog' => 'workflows']);
        }
        return (int) $this->rt->db->table('workflow_instances')->insertGetId(['public_id' => (string) Str::ulid(), 'workflow_id' => (int) $workflow, 'unit_id' => $unit,
            'requested_by' => $actor, 'status' => 'RECOGNIZED', 'submitted_at' => $now, 'completed_at' => null, 'created_at' => $now, 'lock_version' => 0]);
    }

    private function workflow(int $instance, string $status, bool $final): void
    {
        $this->rt->db->table('workflow_instances')->where('id', $instance)->update(['status' => $status, 'completed_at' => $final ? $this->rt->ts() : null]);
    }
}
