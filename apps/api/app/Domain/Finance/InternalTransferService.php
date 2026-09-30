<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * Interunit transfers (ADR 0021 D10 + D-04A; F1B). States are the ADR's only: DRAFT -> SENT -> RECEIVED, DRAFT ->
 * CANCELLED, SENT -> CANCELLED (REVERSE_SEND = devolução). "In transit" (SENT without RECEIVE) and "reconciled"
 * (reconciled_at, F1B-D1) are DERIVED, never extra states. Every stage touches ONE unit and posts ONE journal entry
 * through LedgerPostingService (the only journal writer):
 *
 *   stage         unit          permissions (cumulative, D31)           entry
 *   request       origin        FINANCE_TRANSFER                        -
 *   send          origin        FINANCE_TRANSFER + FINANCE_POST         TRANSFER_SEND        Dr INTERUNIT_CLEARING_OUT / Cr CASH|BANK
 *   receive       destination   FINANCE_TRANSFER + FINANCE_POST         TRANSFER_RECEIVE     Dr CASH|BANK / Cr INTERUNIT_CLEARING_IN
 *   cancel        origin        FINANCE_TRANSFER (DRAFT only)           -
 *   reverse-send  origin        FINANCE_TRANSFER + FINANCE_POST         TRANSFER_REVERSE_SEND Dr CASH|BANK / Cr INTERUNIT_CLEARING_OUT
 *   reconcile     origin or destination  FINANCE_RECONCILE             - (verifies SEND <-> transfer <-> RECEIVE)
 *
 * The owner unit is ALWAYS read from the database under lock (origin = the origin account's unit; destination = the
 * transfer row's), never from the client. Lock order: period (+ unit close) of the stage unit -> transfer FOR UPDATE ->
 * financial account FOR UPDATE -> journal header / idempotency (LedgerPostingService). A second SEND / RECEIVE /
 * REVERSE_SEND / reconciliation of the same transfer is an idempotent no-op (never a second accounting effect);
 * transfer_postings UNIQUE (transfer, stage) is the physical backstop. RECEIVE amount = SEND amount, always.
 */
final class InternalTransferService
{
    public const OP_REQUEST = 'FINANCE_TRANSFER_REQUEST';

    private LedgerPostingService $ledger;

    public function __construct(private FinanceRuntime $rt)
    {
        $this->ledger = new LedgerPostingService($rt->db);
    }

    /** @return array{public_id: string, replayed: bool} */
    public function request(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_TRANSFER);
            if ($clientKey === '' || strlen($clientKey) > 64) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'idempotency_key']);
            }
            $hash = hash('sha256', json_encode([self::OP_REQUEST, $in], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
            $prior = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', self::OP_REQUEST)->where('client_key', $clientKey)->first();
            if ($prior !== null) {
                if (!hash_equals($prior->request_hash, $hash)) {
                    throw new FinanceError('IDEMPOTENCY_CONFLICT');
                }
                if ($prior->status === 'COMPLETED') {
                    return ['public_id' => (string) $prior->result_public_id, 'replayed' => true];
                }
            }
            $account = $this->accountByPublicId($in['origin_account'] ?? null, true);
            $origin = (int) $account->unit_id;
            $decision = $guard->unit(FinanceCatalog::PERMISSION_TRANSFER, $origin);
            $destination = $this->unitByPublicId($in['destination_unit'] ?? null);
            if ((int) $destination->id === $origin) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'destination_unit']);
            }
            if ($destination->status !== 'ACTIVE') {
                throw new FinanceError('DESTINATION_NOT_ACTIVE');
            }
            $this->assertAccountUsable($account);
            $amount = Money::cents((string) ($in['amount'] ?? ''));
            $purpose = $this->purpose($in['purpose'] ?? null);
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, $origin);
            $today = $this->rt->today();
            $period = $this->rt->db->table('accounting_periods')->where('code', substr($today, 0, 7))->first();
            if ($period === null) {
                throw new FinanceError('PERIOD_NOT_FOUND');
            }

            $now = $this->rt->ts();
            $this->rt->db->table('idempotency_requests')->insertOrIgnore(['actor_id' => $actor->user, 'operation' => self::OP_REQUEST, 'client_key' => $clientKey, 'request_hash' => $hash,
                'status' => 'PROCESSING', 'result_public_id' => null, 'expires_at' => null, 'created_at' => $now, 'lock_version' => 0]);
            $claim = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', self::OP_REQUEST)->where('client_key', $clientKey)->lockForUpdate()->first();
            if (!hash_equals($claim->request_hash, $hash)) {
                throw new FinanceError('IDEMPOTENCY_CONFLICT');
            }
            if ($claim->status === 'COMPLETED') {
                return ['public_id' => (string) $claim->result_public_id, 'replayed' => true];
            }
            $workflow = $this->rt->db->table('workflows')->where('code', 'FINANCE_INTERNAL_TRANSFER')->where('version', FinanceCatalog::WORKFLOW_VERSION)->where('status', 'ACTIVE')->value('id');
            if ($workflow === null) {
                throw new FinanceError('CONFIG_MISSING', [], ['catalog' => 'workflows']);
            }
            $instance = (int) $this->rt->db->table('workflow_instances')->insertGetId(['public_id' => (string) Str::ulid(), 'workflow_id' => (int) $workflow, 'unit_id' => $origin,
                'requested_by' => $actor->user, 'status' => 'DRAFT', 'submitted_at' => $now, 'completed_at' => null, 'created_at' => $now, 'lock_version' => 0]);
            $publicId = (string) Str::ulid();
            $id = (int) $this->rt->db->table('internal_transfers')->insertGetId([
                'public_id' => $publicId, 'origin_unit_id' => $origin, 'destination_unit_id' => (int) $destination->id, 'origin_account_id' => (int) $account->id,
                'destination_account_id' => null, 'currency_id' => (int) $account->currency_id, 'fund_id' => $this->generalFund(), 'category_id' => (int) $purpose->id,
                'period_id' => (int) $period->id, 'amount' => Money::format($amount), 'status' => 'DRAFT', 'sent_at' => null, 'received_at' => null, 'reconciled_at' => null,
                'cancel_reason' => null, 'document_id' => $document?->id === null ? null : (int) $document->id, 'workflow_instance_id' => $instance, 'created_at' => $now, 'lock_version' => 0,
            ]);
            $this->rt->db->table('idempotency_requests')->where('id', $claim->id)->update(['status' => 'COMPLETED', 'result_public_id' => $publicId]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.transfer_requested', 'internal_transfers', $id, $decision->unit, FinanceAudit::correlation(), [
                'transfer' => $publicId, 'origin' => $this->unitPublic($origin), 'destination' => (string) $destination->public_id, 'amount' => Money::format($amount),
                'purpose' => (string) $purpose->code, 'document' => $document?->public_id,
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** SEND: origin only. @return array{replayed: bool} */
    public function send(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST);
            $peek = $this->peek($publicId);
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], (int) $peek->origin_unit_id);
            [$sentOn, $sentAt] = $this->rt->instantFor($in['sent_on'] ?? null);
            $period = $this->ledger->lockPostingPeriod((int) $peek->origin_unit_id, $sentOn);
            $transfer = $this->lockTransfer((int) $peek->id);
            $decision = $guard->all([FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], (int) $transfer->origin_unit_id);
            if (in_array($transfer->status, ['SENT', 'RECEIVED'], true) && $this->posting((int) $transfer->id, 'SEND') !== null) {
                return ['replayed' => true];
            }
            $this->assertState($transfer, 'DRAFT', $in);
            $account = $this->rt->db->table('accounts')->where('id', $transfer->origin_account_id)->lockForUpdate()->first();
            $this->assertAccountUsable($account);
            $amount = Money::fromDecimal((string) $transfer->amount);
            if ($this->lockedBalance((int) $account->id) < $amount) {
                throw new FinanceError('INSUFFICIENT_FUNDS');
            }
            $purpose = $this->rt->db->table('financial_categories')->where('id', $transfer->category_id)->first();
            $entry = $this->ledger->postSubledgerEntry($actor->user, 'TS-' . $transfer->public_id, [
                'unit_id' => (int) $transfer->origin_unit_id, 'entry_kind' => 'TRANSFER_SEND', 'entry_date' => $sentOn,
                'description' => 'Transferência interna ' . $transfer->public_id . ' (envio)', 'lines' => [
                    ['account' => 'INTERUNIT_CLEARING_OUT', 'counterparty_unit_id' => (int) $transfer->destination_unit_id, 'category' => (string) $purpose->code, 'debit' => Money::format($amount)],
                    ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$account->account_kind], 'financial_account_id' => (int) $account->id, 'credit' => Money::format($amount)],
                ]]);
            $this->rt->db->table('internal_transfers')->where('id', $transfer->id)->where('status', 'DRAFT')->update(['status' => 'SENT', 'sent_at' => $sentAt, 'period_id' => (int) $period->id, 'lock_version' => $transfer->lock_version + 1]);
            $this->recordPosting((int) $transfer->id, 'SEND', (int) $entry['id']);
            $this->workflow((int) $transfer->workflow_instance_id, 'SENT', false);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.transfer_sent', 'internal_transfers', (int) $transfer->id, $decision->unit, FinanceAudit::correlation(), [
                'transfer' => (string) $transfer->public_id, 'entry' => $entry['public_id'], 'destination' => $this->unitPublic((int) $transfer->destination_unit_id), 'sent_on' => $sentOn,
            ], null, $actor->session);
            return ['replayed' => false];
        });
    }

    /** RECEIVE: destination only, amount = the SEND amount. @return array{replayed: bool, posted_on: ?string} */
    public function receive(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST);
            $peek = $this->peek($publicId);
            $destinationUnit = (int) $peek->destination_unit_id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], $destinationUnit);
            [$receivedOn, $receivedAt] = $this->rt->instantFor($in['received_on'] ?? null);
            $postOn = $this->postingDate($destinationUnit, $receivedOn);
            $this->ledger->lockPostingPeriod($destinationUnit, $postOn);
            $transfer = $this->lockTransfer((int) $peek->id);
            $decision = $guard->all([FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], (int) $transfer->destination_unit_id);
            if ($transfer->status === 'RECEIVED' && $this->posting((int) $transfer->id, 'RECEIVE') !== null) {
                return ['replayed' => true, 'posted_on' => null];
            }
            $this->assertState($transfer, 'SENT', $in);
            $amount = Money::fromDecimal((string) $transfer->amount);
            if (isset($in['amount']) && Money::cents((string) $in['amount']) !== $amount) {
                throw new FinanceError('AMOUNT_MISMATCH');
            }
            if ($receivedAt < $transfer->sent_at) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'received_on', 'reason' => 'before_send']);
            }
            $account = $this->accountByPublicId($in['destination_account'] ?? null, false, true);
            if ((int) $account->unit_id !== (int) $transfer->destination_unit_id) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'accounts']);
            }
            $this->assertAccountUsable($account);
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, (int) $transfer->destination_unit_id);
            $purpose = $this->rt->db->table('financial_categories')->where('id', $transfer->category_id)->first();
            $entry = $this->ledger->postSubledgerEntry($actor->user, 'TR-' . $transfer->public_id, [
                'unit_id' => (int) $transfer->destination_unit_id, 'entry_kind' => 'TRANSFER_RECEIVE', 'entry_date' => $postOn,
                'description' => 'Transferência interna ' . $transfer->public_id . ' (recepção)', 'document_id' => $document?->id === null ? null : (int) $document->id, 'lines' => [
                    ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$account->account_kind], 'financial_account_id' => (int) $account->id, 'debit' => Money::format($amount)],
                    ['account' => 'INTERUNIT_CLEARING_IN', 'counterparty_unit_id' => (int) $transfer->origin_unit_id, 'category' => (string) $purpose->code, 'credit' => Money::format($amount)],
                ]]);
            $this->rt->db->table('internal_transfers')->where('id', $transfer->id)->where('status', 'SENT')->update(['status' => 'RECEIVED', 'received_at' => $receivedAt,
                'destination_account_id' => (int) $account->id, 'lock_version' => $transfer->lock_version + 1]);
            $this->recordPosting((int) $transfer->id, 'RECEIVE', (int) $entry['id']);
            $this->workflow((int) $transfer->workflow_instance_id, 'RECEIVED', true);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.transfer_received', 'internal_transfers', (int) $transfer->id, $decision->unit, FinanceAudit::correlation(), [
                'transfer' => (string) $transfer->public_id, 'entry' => $entry['public_id'], 'origin' => $this->unitPublic((int) $transfer->origin_unit_id),
                'received_on' => $receivedOn, 'posted_on' => $postOn, 'document' => $document?->public_id,
            ], null, $actor->session);
            return ['replayed' => false, 'posted_on' => $postOn];
        });
    }

    /** DRAFT -> CANCELLED (origin, reason). */
    public function cancel(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_TRANSFER);
            $peek = $this->peek($publicId);
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_TRANSFER], (int) $peek->origin_unit_id);
            $transfer = $this->lockTransfer((int) $peek->id);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_TRANSFER, (int) $transfer->origin_unit_id);
            if ($transfer->status === 'CANCELLED' && $this->posting((int) $transfer->id, 'SEND') === null) {
                return ['replayed' => true];
            }
            $this->assertState($transfer, 'DRAFT', $in);
            $reason = $this->reason($in['reason'] ?? null);
            $this->rt->db->table('internal_transfers')->where('id', $transfer->id)->where('status', 'DRAFT')->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'lock_version' => $transfer->lock_version + 1]);
            $this->workflow((int) $transfer->workflow_instance_id, 'CANCELLED', true);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.transfer_cancelled', 'internal_transfers', (int) $transfer->id, $decision->unit, FinanceAudit::correlation(),
                ['transfer' => (string) $transfer->public_id, 'stage' => 'DRAFT'], $reason, $actor->session);
            return ['replayed' => false];
        });
    }

    /** SENT -> CANCELLED by REVERSE_SEND (origin, reason), only while NOT received (C9). */
    public function reverseSend(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST);
            $peek = $this->peek($publicId);
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], (int) $peek->origin_unit_id);
            $today = $this->rt->today();
            $this->ledger->lockPostingPeriod((int) $peek->origin_unit_id, $today);
            $transfer = $this->lockTransfer((int) $peek->id);
            $decision = $guard->all([FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], (int) $transfer->origin_unit_id);
            if ($transfer->status === 'CANCELLED' && $this->posting((int) $transfer->id, 'REVERSE_SEND') !== null) {
                return ['replayed' => true];
            }
            if ($transfer->status === 'RECEIVED') {
                // The destination already holds the funds: an isolated origin reversal would leave both sides claiming them.
                throw new FinanceError('ALREADY_RECEIVED');
            }
            $this->assertState($transfer, 'SENT', $in);
            $reason = $this->reason($in['reason'] ?? null);
            $send = $this->posting((int) $transfer->id, 'SEND');
            $account = $this->rt->db->table('accounts')->where('id', $transfer->origin_account_id)->lockForUpdate()->first();
            $amount = Money::format(Money::fromDecimal((string) $transfer->amount));
            $purpose = $this->rt->db->table('financial_categories')->where('id', $transfer->category_id)->first();
            $sendEntry = (string) $this->rt->db->table('journal_entries')->where('id', $send->entry_id)->value('public_id');
            $entry = $this->ledger->postSubledgerEntry($actor->user, 'TX-' . $transfer->public_id, [
                'unit_id' => (int) $transfer->origin_unit_id, 'entry_kind' => 'TRANSFER_REVERSE_SEND', 'entry_date' => $today, 'reason' => $reason, 'reversal_of' => $sendEntry,
                'description' => 'Transferência interna ' . $transfer->public_id . ' (devolução)', 'lines' => [
                    ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$account->account_kind], 'financial_account_id' => (int) $account->id, 'debit' => $amount],
                    ['account' => 'INTERUNIT_CLEARING_OUT', 'counterparty_unit_id' => (int) $transfer->destination_unit_id, 'category' => (string) $purpose->code, 'credit' => $amount],
                ]]);
            $this->rt->db->table('internal_transfers')->where('id', $transfer->id)->where('status', 'SENT')->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'lock_version' => $transfer->lock_version + 1]);
            $this->recordPosting((int) $transfer->id, 'REVERSE_SEND', (int) $entry['id']);
            $this->workflow((int) $transfer->workflow_instance_id, 'CANCELLED', true);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.transfer_cancelled', 'internal_transfers', (int) $transfer->id, $decision->unit, FinanceAudit::correlation(),
                ['transfer' => (string) $transfer->public_id, 'stage' => 'REVERSE_SEND', 'entry' => $entry['public_id'], 'reversal_of' => $sendEntry], $reason, $actor->session);
            return ['replayed' => false];
        });
    }

    /** Interunit reconciliation (F1B-D1): verify SEND <-> transfer <-> RECEIVE, then stamp reconciled_at once. */
    public function reconcile(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_RECONCILE);
            $peek = $this->peek($publicId);
            if (!$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_RECONCILE, (int) $peek->origin_unit_id) && !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_RECONCILE, (int) $peek->destination_unit_id)) {
                throw new FinanceError('OUT_OF_SCOPE', [], ['entity' => 'internal_transfers']);
            }
            $transfer = $this->lockTransfer((int) $peek->id);
            $side = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_RECONCILE, (int) $transfer->origin_unit_id) ? (int) $transfer->origin_unit_id : (int) $transfer->destination_unit_id;
            $decision = $guard->unit(FinanceCatalog::PERMISSION_RECONCILE, $side);
            if ($transfer->reconciled_at !== null) {
                return ['replayed' => true, 'mismatches' => []];
            }
            $this->assertState($transfer, 'RECEIVED', $in);
            $mismatches = self::pairing($this->rt->db, $transfer, true);
            if ($mismatches !== []) {
                throw new FinanceError('RECONCILIATION_MISMATCH', $mismatches);
            }
            $now = $this->rt->ts();
            $this->rt->db->table('internal_transfers')->where('id', $transfer->id)->whereNull('reconciled_at')->update(['reconciled_at' => max($now, (string) $transfer->received_at), 'lock_version' => $transfer->lock_version + 1]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.transfer_reconciled', 'internal_transfers', (int) $transfer->id, $decision->unit, FinanceAudit::correlation(), [
                'transfer' => (string) $transfer->public_id, 'side' => $side === (int) $transfer->origin_unit_id ? 'ORIGIN' : 'DESTINATION',
            ], null, $actor->session);
            return ['replayed' => false, 'mismatches' => []];
        });
    }

    /**
     * The interunit pairing (F1B §20): one POSTED SEND in the origin (Dr INTERUNIT_CLEARING_OUT cp=destination /
     * Cr the origin account, the transfer amount and purpose), one POSTED RECEIVE in the destination (Dr the destination
     * account / Cr INTERUNIT_CLEARING_IN cp=origin, same amount and purpose), no REVERSE_SEND. @return list<string>
     */
    public static function pairing(\Illuminate\Database\Connection $db, object $transfer, bool $lock = false): array
    {
        $out = [];
        $amount = Money::fromDecimal((string) $transfer->amount);
        $q = $db->table('transfer_postings')->where('transfer_id', $transfer->id);
        $postings = ($lock ? $q->sharedLock() : $q)->pluck('entry_id', 'posting_stage')->all();
        if (isset($postings['REVERSE_SEND'])) {
            $out[] = 'REVERSE_SEND_PRESENT';
        }
        foreach (['SEND' => [(int) $transfer->origin_unit_id, 'INTERUNIT_CLEARING_OUT', (int) $transfer->destination_unit_id, (int) $transfer->origin_account_id],
                  'RECEIVE' => [(int) $transfer->destination_unit_id, 'INTERUNIT_CLEARING_IN', (int) $transfer->origin_unit_id, (int) $transfer->destination_account_id]] as $stage => [$unit, $role, $counterparty, $account]) {
            if (!isset($postings[$stage])) {
                $out[] = $stage . '_MISSING';
                continue;
            }
            $eq = $db->table('journal_entries')->where('id', $postings[$stage]);
            $entry = ($lock ? $eq->sharedLock() : $eq)->first();
            if ($entry === null || $entry->status !== FinanceCatalog::POSTED || (int) $entry->unit_id !== $unit || $entry->entry_kind !== 'TRANSFER_' . $stage) {
                $out[] = $stage . '_ENTRY_INVALID';
                continue;
            }
            $lq = $db->table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.entry_id', $entry->id);
            $lines = ($lock ? $lq->sharedLock() : $lq)
                ->get(['a.system_role', 'l.counterparty_unit_id', 'l.financial_account_id', 'l.category_id', 'l.debit', 'l.credit'])->all();
            $clearing = array_values(array_filter($lines, fn ($l) => $l->system_role === $role));
            $cash = array_values(array_filter($lines, fn ($l) => in_array($l->system_role, FinanceCatalog::TREASURY_ROLES, true)));
            $clearingAmount = count($clearing) === 1 ? ($stage === 'SEND' ? Money::fromDecimal((string) $clearing[0]->debit) : Money::fromDecimal((string) $clearing[0]->credit)) : -1;
            $cashAmount = count($cash) === 1 ? ($stage === 'SEND' ? Money::fromDecimal((string) $cash[0]->credit) : Money::fromDecimal((string) $cash[0]->debit)) : -1;
            if (count($lines) !== 2 || $clearingAmount !== $amount || $cashAmount !== $amount || (int) $clearing[0]->counterparty_unit_id !== $counterparty
                || (int) $cash[0]->financial_account_id !== $account || (int) $clearing[0]->category_id !== (int) $transfer->category_id) {
                $out[] = $stage . '_LINES_MISMATCH';
            }
        }
        return $out;
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function peek(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->rt->db->table('internal_transfers')->where('public_id', $publicId)->first() : null;
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'internal_transfers']);
        }
        return $row;
    }

    /**
     * F-06: the stage unit of a transfer is immutable, so scope is decided on it BEFORE any lock or state/period check;
     * an actor without the stage side only ever sees the concealed 404 (never PERIOD_CLOSED, never the state).
     */
    private function preauthorize(TerritorialActor $actor, array $permissions, int $unit): void
    {
        foreach ($permissions as $permission) {
            $this->rt->authority->forUnit($actor, $permission, $unit);
        }
    }

    private function lockTransfer(int $id): object
    {
        return $this->rt->db->table('internal_transfers')->where('id', $id)->lockForUpdate()->first();
    }

    private function assertState(object $transfer, string $expected, array $in): void
    {
        if ($transfer->status !== $expected) {
            throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $transfer->status, 'expected' => $expected]);
        }
        if (array_key_exists('lock_version', $in) && $in['lock_version'] !== null && (int) $in['lock_version'] !== (int) $transfer->lock_version) {
            throw new FinanceError('STALE_WRITE');
        }
    }

    private function accountByPublicId(mixed $publicId, bool $share = false, bool $update = false): object
    {
        $query = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('accounts')->where('public_id', $publicId) : null;
        if ($query !== null && $update) {
            $query->lockForUpdate();
        } elseif ($query !== null && $share) {
            $query->sharedLock();
        }
        $row = $query?->first();
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'accounts']);
        }
        return $row;
    }

    private function assertAccountUsable(object $account): void
    {
        if ($account->status !== 'OPEN') {
            throw new FinanceError('ACCOUNT_NOT_OPEN');
        }
        if ($this->rt->db->table('currencies')->where('id', $account->currency_id)->value('code') !== FinanceCatalog::CURRENCY) {
            throw new FinanceError('CURRENCY_NOT_SUPPORTED');
        }
    }

    private function unitByPublicId(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->rt->db->table('organizational_units')->where('public_id', $publicId)->sharedLock()->first() : null;
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
        }
        return $row;
    }

    private function purpose(mixed $code): object
    {
        $row = is_string($code) && in_array($code, FinanceCatalog::TRANSFER_PURPOSES, true)
            ? $this->rt->db->table('financial_categories')->where('code', $code)->where('economic_nature', 'INTERNAL_TRANSFER')->where('status', 'ACTIVE')->first() : null;
        if ($row === null) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'purpose']);
        }
        return $row;
    }

    private function reason(mixed $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($reason) < 3) {
            throw new FinanceError('REASON_REQUIRED');
        }
        return $reason;
    }

    /**
     * D10: the RECEIVE entry date is the real reception date when its month is open for the destination; otherwise the
     * first day of the first later month open for the destination (received_at keeps the real date). Never the future.
     */
    private function postingDate(int $unit, string $receivedOn): string
    {
        if ($this->ledger->isPostingOpen($unit, $receivedOn)) {
            return $receivedOn;
        }
        $today = $this->rt->today();
        $cursor = new DateTimeImmutable(substr($receivedOn, 0, 7) . '-01', new DateTimeZone('UTC'));
        for ($i = 0; $i < 24; $i++) {
            $cursor = $cursor->modify('+1 month');
            $candidate = $cursor->format('Y-m-d');
            if ($candidate > $today) {
                break;
            }
            if ($this->ledger->isPostingOpen($unit, $candidate)) {
                return $candidate;
            }
        }
        throw new FinanceError('PERIOD_CLOSED');
    }

    private function posting(int $transferId, string $stage): ?object
    {
        // LOCKING read: after waiting on the transfer lock, a REPEATABLE READ snapshot could predate the competitor's
        // commit and miss its posting (a replay would then be refused as a state conflict).
        return $this->rt->db->table('transfer_postings')->where('transfer_id', $transferId)->where('posting_stage', $stage)->sharedLock()->first();
    }

    /**
     * Balance of a financial account read with a LOCKING read (latest committed POSTED lines), taken after the account
     * row is locked FOR UPDATE: every posting on the account locks it too, so the figure cannot move before commit.
     */
    private function lockedBalance(int $accountId): int
    {
        $row = $this->rt->db->selectOne("SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS b FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.financial_account_id = ? AND e.status = 'POSTED' FOR SHARE", [$accountId]);
        return Money::fromDecimal((string) $row->b);
    }

    private function recordPosting(int $transferId, string $stage, int $entryId): void
    {
        $this->rt->db->table('transfer_postings')->insert(['transfer_id' => $transferId, 'posting_stage' => $stage, 'entry_id' => $entryId, 'created_at' => $this->rt->ts(), 'lock_version' => 0]);
    }

    private function workflow(int $instance, string $status, bool $final): void
    {
        $this->rt->db->table('workflow_instances')->where('id', $instance)->update(['status' => $status, 'completed_at' => $final ? $this->rt->ts() : null]);
    }

    private function generalFund(): int
    {
        return (int) $this->rt->db->table('funds')->where('code', FinanceCatalog::FUND_GENERAL)->value('id');
    }

    private function unitPublic(int $unit): ?string
    {
        $value = $this->rt->db->table('organizational_units')->where('id', $unit)->value('public_id');
        return $value === null ? null : (string) $value;
    }
}
