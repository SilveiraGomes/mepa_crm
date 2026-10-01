<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;

/**
 * ADR 0021 D07/D11 + D-04A.13: the ONLY writer of journal_entries / journal_lines.
 *
 * Invariants enforced here, under locks, on the PERSISTED rows (never trusted from the UI):
 *  - an entry reaches POSTED only if Σdebit = Σcredit (exact integer cents, cross-checked with an SQL DECIMAL sum),
 *    total > 0, >= 2 lines (exact count for transfer stages), one owner unit for header and every line, currency AOA,
 *    MONTH period OPEN nationally and not CLOSED for the unit, entry_date inside the period and not in the future
 *    (Africa/Luanda), ledger accounts ACTIVE + postable, financial accounts OPEN, owned by the unit and posting to their
 *    CASH/BANK control account, rubric <-> control account coherence, kind shape (SHAPES) and I1 (transfer stages never
 *    touch INCOME/EXPENSE; INTERUNIT_CONTROL only moves through transfer stages);
 *  - POSTED is immutable: no method updates or deletes a POSTED header or any of its lines; lines can only be replaced
 *    while the header is DRAFT under its row lock. Correction = REVERSAL (exact inverse, reason, open period, at most one
 *    per entry: UNIQUE reversal_of_id). Subledger entries are reversed only by their own flow (SUBLEDGER_OWNED).
 * Physical backstops (CHECK / UNIQUE / composite FK) are in the P0.10 migrations; there are no triggers (04_database_constraints).
 *
 * Lock order (extends 04_database_constraints): national period FOR SHARE -> unit close FOR SHARE -> header FOR UPDATE ->
 * financial accounts FOR SHARE by id (their OPEN status decided on that locking read) -> idempotency claim. Period closes
 * take the period FOR UPDATE (FinancePeriods); an account close takes the account FOR UPDATE (FinanceAccountService).
 * Authorization (TerritorialAuthority FINANCE) is the application layer's job (F1B) and must run before these calls.
 */
final class LedgerPostingService
{
    public const OP_DRAFT = 'FINANCE_ENTRY_DRAFT';
    public const OP_SUBLEDGER = 'FINANCE_ENTRY_SUBLEDGER';
    public const OP_REVERSE = 'FINANCE_ENTRY_REVERSE';

    public function __construct(private readonly Connection $db)
    {
    }

    // ---- public API -------------------------------------------------------------------------------------------------

    /** D07: a user-prepared form (REVENUE, EXPENSE, ACCOUNT_TRANSFER, OPENING_BALANCE, ADJUSTMENT) saved as DRAFT. */
    public function createDraft(int $actor, string $clientKey, array $input): array
    {
        if (!in_array($input['entry_kind'] ?? null, FinanceCatalog::MANUAL_KINDS, true)) {
            throw new FinanceError('ENTRY_KIND_NOT_MANUAL');
        }
        return $this->create($actor, $clientKey, self::OP_DRAFT, $input, false);
    }

    /**
     * D07: subledger entries (contribution, payable/receivable recognition, settlement, transfer stages, payroll) are born
     * POSTED inside the subledger's own transaction, which must already be open (its locks come first).
     */
    public function postSubledgerEntry(int $actor, string $clientKey, array $input): array
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('SUBLEDGER_TRANSACTION_REQUIRED');
        }
        if (!in_array($input['entry_kind'] ?? null, FinanceCatalog::SUBLEDGER_KINDS, true)) {
            throw new FinanceError('ENTRY_KIND_NOT_SUBLEDGER');
        }
        return $this->create($actor, $clientKey, self::OP_SUBLEDGER, $input, true);
    }

    /**
     * D11 own-flow correction of a subledger entry (F1C: settlement cancellation, payable/receivable recognition
     * cancellation): a REVERSAL whose lines are the exact inverse of the POSTED target, dated in an OPEN period of the
     * same unit, born POSTED inside the subledger's transaction. The generic reverse() keeps refusing these targets
     * (SUBLEDGER_OWNED); only the kinds in FinanceCatalog::OWN_FLOW_REVERSIBLE_KINDS may be reversed here.
     */
    public function postSubledgerReversal(int $actor, string $clientKey, int $targetEntryId, string $reason, string $entryDate): array
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('SUBLEDGER_TRANSACTION_REQUIRED');
        }
        if (trim($reason) === '') {
            throw new FinanceError('REASON_REQUIRED');
        }
        $target = $this->db->table('journal_entries')->where('id', $targetEntryId)->first();
        if ($target === null || !in_array($target->entry_kind, FinanceCatalog::OWN_FLOW_REVERSIBLE_KINDS, true)) {
            throw new FinanceError('REVERSAL_TARGET_INVALID');
        }
        $hash = $this->hash(self::OP_SUBLEDGER, ['reversal_of' => (string) $target->public_id, 'reason' => $reason, 'date' => $entryDate]);
        if (($replay = $this->replay($actor, self::OP_SUBLEDGER, $clientKey, $hash)) !== null) {
            return $replay;
        }
        $period = $this->monthFor($entryDate);
        $this->lockPeriodForUnit((int) $period->id, (int) $target->unit_id);
        $target = $this->db->table('journal_entries')->where('id', $targetEntryId)->sharedLock()->first();
        if ($target->status !== FinanceCatalog::POSTED) {
            throw new FinanceError('ENTRY_NOT_POSTED');
        }
        if ($this->db->table('journal_entries')->where('reversal_of_id', $target->id)->sharedLock()->exists()) {
            throw new FinanceError('ALREADY_REVERSED');
        }
        $lines = [];
        foreach ($this->db->table('journal_lines')->where('entry_id', $target->id)->orderBy('line_number')->get() as $line) {
            $lines[] = ['ledger_account_id' => (int) $line->ledger_account_id, 'financial_account_id' => $line->financial_account_id === null ? null : (int) $line->financial_account_id,
                'counterparty_unit_id' => $line->counterparty_unit_id === null ? null : (int) $line->counterparty_unit_id, 'fund_id' => (int) $line->fund_id,
                'category_id' => $line->category_id === null ? null : (int) $line->category_id, 'debit' => Money::fromDecimal($line->credit), 'credit' => Money::fromDecimal($line->debit),
                'description' => $line->description];
        }
        try {
            return $this->persist($actor, $clientKey, self::OP_SUBLEDGER, $hash, [
                'unit_id' => (int) $target->unit_id, 'entry_kind' => FinanceCatalog::REVERSAL, 'entry_date' => $entryDate, 'period' => $period,
                'currency_id' => (int) $target->currency_id, 'description' => 'Anulação de ' . $target->reference, 'reason' => $reason,
                'reversal_of_id' => (int) $target->id, 'document_id' => null,
            ], $lines, true, true);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_journal_entries_reversal_of_id')) {
                throw new FinanceError('ALREADY_REVERSED');
            }
            throw $e;
        }
    }

    /** Replace every line of a DRAFT entry (the only line mutation that exists). */
    public function replaceDraftLines(int $actor, string $publicId, int $lockVersion, array $lines): void
    {
        $this->db->transaction(function () use ($actor, $publicId, $lockVersion, $lines): void {
            $entry = $this->lockedEntry($publicId, $lockVersion);
            if ($entry->status !== FinanceCatalog::DRAFT) {
                throw new FinanceError('ENTRY_NOT_EDITABLE');
            }
            $normalized = $this->normalizeLines((int) $entry->unit_id, $lines);
            $this->db->table('journal_lines')->where('entry_id', $entry->id)->delete();
            $this->insertLines((int) $entry->id, (int) $entry->unit_id, $normalized);
            $this->db->table('journal_entries')->where('id', $entry->id)->update(['lock_version' => $entry->lock_version + 1]);
            FinanceAudit::write($this->db, $actor, 'finance.entry_lines_replaced', 'journal_entries', (int) $entry->id, (int) $entry->unit_id, FinanceAudit::correlation(), ['entry' => $publicId]);
        });
    }

    public function submit(int $actor, string $publicId, int $lockVersion): void
    {
        $this->transition($actor, $publicId, $lockVersion, [FinanceCatalog::DRAFT], FinanceCatalog::SUBMITTED, 'finance.entry_submitted');
    }

    public function returnToDraft(int $actor, string $publicId, int $lockVersion): void
    {
        $this->transition($actor, $publicId, $lockVersion, [FinanceCatalog::SUBMITTED], FinanceCatalog::DRAFT, 'finance.entry_returned');
    }

    public function discard(int $actor, string $publicId, int $lockVersion): void
    {
        $this->transition($actor, $publicId, $lockVersion, [FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED], FinanceCatalog::DISCARDED, 'finance.entry_discarded');
    }

    /** DRAFT|SUBMITTED -> POSTED after the full validation of the persisted lines. "Approve" IS post (D07). */
    public function post(int $actor, string $publicId, int $lockVersion): void
    {
        $this->db->transaction(function () use ($actor, $publicId, $lockVersion): void {
            $peek = $this->db->table('journal_entries')->where('public_id', $publicId)->first();
            if ($peek === null) {
                throw new FinanceError('ENTRY_NOT_FOUND');
            }
            $this->lockPeriodForUnit((int) $peek->period_id, (int) $peek->unit_id);
            $entry = $this->lockedEntry($publicId, null);
            if ($entry->status === FinanceCatalog::POSTED) {
                throw new FinanceError('ALREADY_POSTED');
            }
            if ($entry->status === FinanceCatalog::DISCARDED) {
                throw new FinanceError('ENTRY_DISCARDED');
            }
            if ((int) $entry->lock_version !== $lockVersion) {
                throw new FinanceError('STALE_LOCK_VERSION');
            }
            $this->lockFinancialAccounts((int) $entry->id);
            $this->assertPostable((int) $entry->id);
            $updated = $this->db->table('journal_entries')->where('id', $entry->id)->whereIn('status', [FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED])
                ->update(['status' => FinanceCatalog::POSTED, 'posted_at' => $this->now(), 'posted_by' => $actor, 'lock_version' => $entry->lock_version + 1]);
            if ($updated !== 1) {
                throw new FinanceError('ALREADY_POSTED');
            }
            FinanceAudit::write($this->db, $actor, $entry->entry_kind === 'ADJUSTMENT' ? 'finance.adjustment_posted' : 'finance.entry_posted', 'journal_entries', (int) $entry->id, (int) $entry->unit_id, FinanceAudit::correlation(), ['entry' => $publicId]);
        });
    }

    /** D11: exact inverse of a POSTED, non-subledger, non-reversal entry, dated in an OPEN period of the same unit. */
    public function reverse(int $actor, string $clientKey, string $publicId, string $reason, string $entryDate): array
    {
        if (trim($reason) === '') {
            throw new FinanceError('REASON_REQUIRED');
        }
        return $this->db->transaction(function () use ($actor, $clientKey, $publicId, $reason, $entryDate): array {
            $original = $this->db->table('journal_entries')->where('public_id', $publicId)->first();
            if ($original === null) {
                throw new FinanceError('ENTRY_NOT_FOUND');
            }
            $hash = $this->hash(self::OP_REVERSE, ['entry' => $publicId, 'reason' => $reason, 'date' => $entryDate]);
            if (($replay = $this->replay($actor, self::OP_REVERSE, $clientKey, $hash)) !== null) {
                return $replay;
            }
            $period = $this->monthFor($entryDate);
            $this->lockPeriodForUnit((int) $period->id, (int) $original->unit_id);
            $original = $this->db->table('journal_entries')->where('id', $original->id)->lockForUpdate()->first();
            if ($original->status !== FinanceCatalog::POSTED) {
                throw new FinanceError('ENTRY_NOT_POSTED');
            }
            if ($original->entry_kind === FinanceCatalog::REVERSAL) {
                throw new FinanceError('CANNOT_REVERSE_REVERSAL');
            }
            if (in_array($original->entry_kind, FinanceCatalog::SUBLEDGER_KINDS, true)) {
                throw new FinanceError('SUBLEDGER_OWNED');
            }
            if ($this->db->table('journal_entries')->where('reversal_of_id', $original->id)->exists()) {
                throw new FinanceError('ALREADY_REVERSED');
            }
            $lines = [];
            foreach ($this->db->table('journal_lines')->where('entry_id', $original->id)->orderBy('line_number')->get() as $line) {
                $lines[] = ['ledger_account_id' => (int) $line->ledger_account_id, 'financial_account_id' => $line->financial_account_id === null ? null : (int) $line->financial_account_id,
                    'counterparty_unit_id' => $line->counterparty_unit_id === null ? null : (int) $line->counterparty_unit_id, 'fund_id' => (int) $line->fund_id,
                    'category_id' => $line->category_id === null ? null : (int) $line->category_id, 'debit' => Money::fromDecimal($line->credit), 'credit' => Money::fromDecimal($line->debit),
                    'description' => $line->description];
            }
            try {
                return $this->persist($actor, $clientKey, self::OP_REVERSE, $hash, [
                    'unit_id' => (int) $original->unit_id, 'entry_kind' => FinanceCatalog::REVERSAL, 'entry_date' => $entryDate, 'period' => $period,
                    'currency_id' => (int) $original->currency_id, 'description' => 'Estorno de ' . $original->reference, 'reason' => $reason,
                    'reversal_of_id' => (int) $original->id, 'document_id' => null,
                ], $lines, true);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_journal_entries_reversal_of_id')) {
                    throw new FinanceError('ALREADY_REVERSED');
                }
                throw $e;
            }
        });
    }

    // ---- creation ---------------------------------------------------------------------------------------------------

    private function create(int $actor, string $clientKey, string $operation, array $input, bool $post): array
    {
        $unitId = (int) ($input['unit_id'] ?? 0);
        $entryDate = (string) ($input['entry_date'] ?? '');
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            throw new FinanceError('DESCRIPTION_REQUIRED');
        }
        $currency = $this->db->table('currencies')->where('code', (string) ($input['currency'] ?? FinanceCatalog::CURRENCY))->first();
        if ($currency === null) {
            throw new FinanceError('CURRENCY_NOT_SUPPORTED');
        }
        $reversalOf = null;
        if (isset($input['reversal_of'])) {
            $reversalOf = $this->db->table('journal_entries')->where('public_id', (string) $input['reversal_of'])->value('id');
            if ($reversalOf === null) {
                throw new FinanceError('REVERSAL_TARGET_INVALID');
            }
        }
        $lines = $this->normalizeLines($unitId, (array) ($input['lines'] ?? []));
        $header = ['unit_id' => $unitId, 'entry_kind' => (string) $input['entry_kind'], 'entry_date' => $entryDate, 'currency_id' => (int) $currency->id,
            'description' => $description, 'reason' => isset($input['reason']) ? (string) $input['reason'] : null, 'reversal_of_id' => $reversalOf === null ? null : (int) $reversalOf,
            'document_id' => isset($input['document_id']) ? (int) $input['document_id'] : null];
        $hash = $this->hash($operation, ['header' => $header, 'lines' => $lines]);

        return $this->db->transaction(function () use ($actor, $clientKey, $operation, $hash, $header, $lines, $post, $entryDate): array {
            if (($replay = $this->replay($actor, $operation, $clientKey, $hash)) !== null) {
                return $replay;
            }
            $period = $this->monthFor($entryDate);
            $this->lockPeriodForUnit((int) $period->id, $header['unit_id']);
            return $this->persist($actor, $clientKey, $operation, $hash, $header + ['period' => $period], $lines, $post);
        });
    }

    /** Called with the period (and unit close) already share-locked. */
    private function persist(int $actor, string $clientKey, string $operation, string $hash, array $header, array $lines, bool $post, bool $ownFlowReversal = false): array
    {
        $accounts = array_values(array_unique(array_filter(array_column($lines, 'financial_account_id'))));
        $this->lockOpenAccounts($accounts);
        $claim = $this->claim($actor, $operation, $clientKey, $hash);
        if ($claim['replay'] !== null) {
            return $claim['replay'];
        }
        $now = $this->now();
        $publicId = (string) Str::ulid();
        $id = (int) $this->db->table('journal_entries')->insertGetId([
            'public_id' => $publicId, 'unit_id' => $header['unit_id'], 'period_id' => $header['period']->id, 'currency_id' => $header['currency_id'],
            'entry_kind' => $header['entry_kind'], 'entry_date' => $header['entry_date'], 'reference' => 'JE-' . $publicId, 'description' => $header['description'],
            'reason' => $header['reason'], 'status' => $post ? FinanceCatalog::POSTED : FinanceCatalog::DRAFT, 'submitted_at' => null, 'submitted_by' => null,
            'posted_at' => $post ? $now : null, 'posted_by' => $post ? $actor : null, 'created_by' => $actor, 'reversal_of_id' => $header['reversal_of_id'],
            'document_id' => $header['document_id'], 'idempotency_request_id' => $claim['id'], 'created_at' => $now, 'lock_version' => 0,
        ]);
        $this->insertLines($id, $header['unit_id'], $lines);
        if ($post) {
            $this->assertPostable($id, $ownFlowReversal);
        }
        $this->db->table('idempotency_requests')->where('id', $claim['id'])->update(['status' => 'COMPLETED', 'result_public_id' => $publicId]);
        $correlation = FinanceAudit::correlation();
        $action = match (true) {
            $header['entry_kind'] === FinanceCatalog::REVERSAL => 'finance.entry_reversed',
            $post => 'finance.entry_posted',
            default => 'finance.entry_created',
        };
        $meta = ['entry' => $publicId, 'kind' => $header['entry_kind']];
        if ($header['reversal_of_id'] !== null) {
            $meta['reversal_of'] = (string) $this->db->table('journal_entries')->where('id', $header['reversal_of_id'])->value('public_id');
        }
        FinanceAudit::write($this->db, $actor, $action, 'journal_entries', $id, $header['unit_id'], $correlation, $meta, $header['reason']);
        return ['id' => $id, 'public_id' => $publicId, 'replayed' => false];
    }

    /** @return list<array<string, mixed>> */
    private function normalizeLines(int $unitId, array $lines): array
    {
        $roles = FinanceCatalog::roleIds($this->db);
        $fund = (int) $this->db->table('funds')->where('code', FinanceCatalog::FUND_GENERAL)->value('id');
        $out = [];
        foreach ($lines as $line) {
            $ledger = isset($line['account']) ? ($roles[$line['account']] ?? null) : ($line['ledger_account_id'] ?? null);
            if ($ledger === null) {
                throw new FinanceError('LEDGER_ACCOUNT_NOT_FOUND');
            }
            $category = null;
            if (isset($line['category'])) {
                $category = $this->db->table('financial_categories')->where('code', (string) $line['category'])->value('id');
                if ($category === null) {
                    throw new FinanceError('CATEGORY_NOT_FOUND');
                }
            } elseif (isset($line['category_id'])) {
                $category = $line['category_id'];
            }
            $financial = $line['financial_account_id'] ?? null;
            if ($financial !== null) {
                $owner = $this->db->table('accounts')->where('id', $financial)->value('unit_id');
                if ($owner === null) {
                    throw new FinanceError('FINANCIAL_ACCOUNT_NOT_FOUND');
                }
                if ((int) $owner !== $unitId) {
                    throw new FinanceError('UNIT_MISMATCH');
                }
            }
            $debit = $line['debit'] ?? null;
            $credit = $line['credit'] ?? null;
            if (($debit === null) === ($credit === null)) {
                throw new FinanceError('LINE_AMOUNT_INVALID');
            }
            $out[] = [
                'ledger_account_id' => (int) $ledger, 'financial_account_id' => $financial === null ? null : (int) $financial,
                'counterparty_unit_id' => isset($line['counterparty_unit_id']) ? (int) $line['counterparty_unit_id'] : null,
                'fund_id' => (int) ($line['fund_id'] ?? $fund), 'category_id' => $category === null ? null : (int) $category,
                'debit' => $debit === null ? 0 : Money::cents($debit),
                'credit' => $credit === null ? 0 : Money::cents($credit),
                'description' => isset($line['description']) ? (string) $line['description'] : null,
            ];
        }
        return $out;
    }

    private function insertLines(int $entryId, int $unitId, array $lines): void
    {
        $now = $this->now();
        foreach (array_values($lines) as $i => $line) {
            $this->db->table('journal_lines')->insert([
                'entry_id' => $entryId, 'line_number' => $i + 1, 'unit_id' => $unitId, 'ledger_account_id' => $line['ledger_account_id'],
                'financial_account_id' => $line['financial_account_id'], 'counterparty_unit_id' => $line['counterparty_unit_id'], 'fund_id' => $line['fund_id'],
                'category_id' => $line['category_id'], 'debit' => Money::format($line['debit']), 'credit' => Money::format($line['credit']),
                'description' => $line['description'], 'created_at' => $now, 'lock_version' => 0,
            ]);
        }
    }

    // ---- validation -------------------------------------------------------------------------------------------------

    /** The posting gate. Reads the PERSISTED header and lines (inside the posting transaction) and throws on any breach. */
    private function assertPostable(int $entryId, bool $ownFlowReversal = false): void
    {
        $entry = $this->db->table('journal_entries as e')->join('currencies as c', 'c.id', '=', 'e.currency_id')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')
            ->join('organizational_units as u', 'u.id', '=', 'e.unit_id')->where('e.id', $entryId)
            ->first(['e.*', 'c.code as currency_code', 'p.period_kind', 'p.status as period_status', 'p.starts_on', 'p.ends_on', 'u.status as unit_status']);
        if ($entry->currency_code !== FinanceCatalog::CURRENCY) {
            throw new FinanceError('CURRENCY_NOT_SUPPORTED');
        }
        if ($entry->unit_status !== 'ACTIVE') {
            throw new FinanceError('UNIT_NOT_ACTIVE');
        }
        if ($entry->period_kind !== FinanceCatalog::PERIOD_MONTH) {
            throw new FinanceError('PERIOD_NOT_POSTABLE');
        }
        if ($entry->period_status !== FinanceCatalog::PERIOD_OPEN || $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id)) {
            throw new FinanceError('PERIOD_CLOSED');
        }
        if ($entry->entry_date < $entry->starts_on || $entry->entry_date > $entry->ends_on) {
            throw new FinanceError('ENTRY_DATE_OUTSIDE_PERIOD');
        }
        if ($entry->entry_date > $this->today()) {
            throw new FinanceError('ENTRY_DATE_IN_FUTURE');
        }
        if (in_array($entry->entry_kind, FinanceCatalog::REASON_REQUIRED_KINDS, true) && trim((string) $entry->reason) === '') {
            throw new FinanceError('REASON_REQUIRED');
        }

        $lines = $this->db->table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->join('funds as f', 'f.id', '=', 'l.fund_id')
            ->leftJoin('accounts as fa', 'fa.id', '=', 'l.financial_account_id')->leftJoin('currencies as fc', 'fc.id', '=', 'fa.currency_id')
            ->leftJoin('financial_categories as fcat', 'fcat.id', '=', 'l.category_id')->where('l.entry_id', $entryId)->orderBy('l.line_number')
            ->get(['l.*', 'a.account_kind as class', 'a.system_role as role', 'a.status as account_status', 'a.postable', 'f.status as fund_status',
                'fa.unit_id as fa_unit', 'fa.status as fa_status', 'fa.ledger_account_id as fa_ledger', 'fa.opened_on as fa_opened', 'fc.code as fa_currency',
                'fcat.economic_nature as nature', 'fcat.ledger_account_id as cat_ledger', 'fcat.status as cat_status'])->all();
        $shape = FinanceCatalog::SHAPES[$entry->entry_kind] ?? null;
        if (count($lines) < 2 || ($shape !== null && $shape[2] !== null && count($lines) !== $shape[2])) {
            throw new FinanceError('TOO_FEW_LINES');
        }

        $debit = 0;
        $credit = 0;
        $isTransfer = in_array($entry->entry_kind, FinanceCatalog::TRANSFER_KINDS, true);
        $hasOpeningNet = false;
        foreach ($lines as $line) {
            if ((int) $line->unit_id !== (int) $entry->unit_id) {
                throw new FinanceError('UNIT_MISMATCH');
            }
            $d = Money::fromDecimal($line->debit);
            $c = Money::fromDecimal($line->credit);
            if (($d > 0) === ($c > 0) || $d < 0 || $c < 0) {
                throw new FinanceError('LINE_AMOUNT_INVALID');
            }
            $debit += $d;
            $credit += $c;
            if ($line->account_status !== 'ACTIVE' || (int) $line->postable !== 1) {
                throw new FinanceError('LEDGER_ACCOUNT_NOT_POSTABLE');
            }
            if ($line->fund_status !== 'ACTIVE') {
                throw new FinanceError('FUND_NOT_ACTIVE');
            }
            $treasury = in_array($line->role, FinanceCatalog::TREASURY_ROLES, true);
            if ($treasury) {
                if ($line->financial_account_id === null) {
                    throw new FinanceError('FINANCIAL_ACCOUNT_REQUIRED');
                }
                if ((int) $line->fa_unit !== (int) $entry->unit_id) {
                    throw new FinanceError('UNIT_MISMATCH');
                }
                if ($line->fa_status !== 'OPEN' || $line->fa_opened > $entry->entry_date) {
                    throw new FinanceError('FINANCIAL_ACCOUNT_CLOSED');
                }
                if ($line->fa_currency !== FinanceCatalog::CURRENCY) {
                    throw new FinanceError('CURRENCY_NOT_SUPPORTED');
                }
                if ((int) $line->fa_ledger !== (int) $line->ledger_account_id) {
                    throw new FinanceError('FINANCIAL_ACCOUNT_LEDGER_MISMATCH');
                }
            } elseif ($line->financial_account_id !== null) {
                throw new FinanceError('FINANCIAL_ACCOUNT_LEDGER_MISMATCH');
            }
            $interunit = $line->class === FinanceCatalog::INTERUNIT_CONTROL;
            if ($isTransfer && in_array($line->class, [FinanceCatalog::INCOME, FinanceCatalog::EXPENSE], true)) {
                throw new FinanceError('TRANSFER_TOUCHES_RESULT');
            }
            if ($interunit && !in_array($entry->entry_kind, ['TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND', FinanceCatalog::REVERSAL], true)) {
                throw new FinanceError('INTERUNIT_ONLY_BY_TRANSFER');
            }
            if ($interunit) {
                if ($line->counterparty_unit_id === null || (int) $line->counterparty_unit_id === (int) $entry->unit_id) {
                    throw new FinanceError('COUNTERPARTY_REQUIRED');
                }
                if ($line->nature !== 'INTERNAL_TRANSFER') {
                    throw new FinanceError('TRANSFER_PURPOSE_REQUIRED');
                }
            } elseif ($line->counterparty_unit_id !== null) {
                throw new FinanceError('COUNTERPARTY_FORBIDDEN');
            }
            $resultLine = in_array($line->class, [FinanceCatalog::INCOME, FinanceCatalog::EXPENSE], true) || $line->role === 'FIXED_ASSETS';
            if ($resultLine && $line->category_id === null) {
                throw new FinanceError('CATEGORY_REQUIRED');
            }
            if ($line->category_id !== null) {
                if ($line->cat_status !== 'ACTIVE' || $line->cat_ledger === null) {
                    throw new FinanceError('CATEGORY_NOT_POSTABLE');
                }
                $matches = $line->nature === 'INTERNAL_TRANSFER' ? $interunit : (int) $line->cat_ledger === (int) $line->ledger_account_id;
                if (!$matches || ($line->role === 'FIXED_ASSETS' && $line->nature !== 'INVESTMENT')) {
                    throw new FinanceError('CATEGORY_LEDGER_MISMATCH');
                }
            }
            if ($shape !== null) {
                $allowed = $d > 0 ? $shape[0] : $shape[1];
                if (!in_array($line->role, $allowed, true) && !in_array($line->class, $allowed, true)) {
                    throw new FinanceError('ENTRY_SHAPE_INVALID');
                }
                if ($entry->entry_kind === 'ADJUSTMENT' && $interunit) {
                    throw new FinanceError('INTERUNIT_ONLY_BY_TRANSFER');
                }
            }
            $hasOpeningNet = $hasOpeningNet || $line->role === 'OPENING_NET_ASSETS';
        }
        if ($entry->entry_kind === 'OPENING_BALANCE' && !$hasOpeningNet) {
            throw new FinanceError('ENTRY_SHAPE_INVALID');
        }
        // Double-entry gate: exact integer cents AND the engine's DECIMAL sums must both balance, and the total be > 0.
        $sql = $this->db->selectOne('SELECT SUM(debit) = SUM(credit) AS balanced, SUM(debit) > 0 AS positive FROM journal_lines WHERE entry_id = ?', [$entryId]);
        if ($debit !== $credit || (int) $sql->balanced !== 1) {
            throw new FinanceError('UNBALANCED');
        }
        if ($debit <= 0 || (int) $sql->positive !== 1) {
            throw new FinanceError('ENTRY_TOTAL_NOT_POSITIVE');
        }
        if (in_array($entry->entry_kind, FinanceCatalog::REVERSING_KINDS, true)) {
            $this->assertReversal($entry, $lines, $ownFlowReversal);
        } elseif ($entry->reversal_of_id !== null) {
            throw new FinanceError('REVERSAL_TARGET_INVALID');
        }
    }

    private function assertReversal(object $entry, array $lines, bool $ownFlowReversal = false): void
    {
        $target = $this->db->table('journal_entries')->where('id', $entry->reversal_of_id)->first();
        $expectedKind = ['REVERSAL' => null, 'TRANSFER_REVERSE_SEND' => 'TRANSFER_SEND', 'PAYROLL_REVERSAL' => 'PAYROLL_ACCRUAL'][$entry->entry_kind];
        // A subledger entry is reversed only by its own flow (D11): the generic path never reaches one; the own-flow
        // path (postSubledgerReversal) may reverse exactly the kinds in OWN_FLOW_REVERSIBLE_KINDS.
        $subledgerTarget = $target !== null && in_array($target->entry_kind, FinanceCatalog::SUBLEDGER_KINDS, true);
        $ownFlowOk = $ownFlowReversal && $target !== null && in_array($target->entry_kind, FinanceCatalog::OWN_FLOW_REVERSIBLE_KINDS, true);
        if ($target === null || $target->status !== FinanceCatalog::POSTED || (int) $target->unit_id !== (int) $entry->unit_id || (int) $target->id === (int) $entry->id
            || ($expectedKind !== null && $target->entry_kind !== $expectedKind)
            || ($expectedKind === null && ($target->entry_kind === FinanceCatalog::REVERSAL || ($subledgerTarget && !$ownFlowOk)))) {
            throw new FinanceError('REVERSAL_TARGET_INVALID');
        }
        if ($entry->entry_kind !== FinanceCatalog::REVERSAL) {
            return;
        }
        $signature = static fn (object $l, bool $swap): string => implode('|', [$l->ledger_account_id, $l->financial_account_id, $l->counterparty_unit_id, $l->fund_id, $l->category_id,
            Money::fromDecimal($swap ? $l->credit : $l->debit), Money::fromDecimal($swap ? $l->debit : $l->credit)]);
        $mine = array_map(fn ($l) => $signature($l, false), $lines);
        $theirs = array_map(fn ($l) => $signature($l, true), $this->db->table('journal_lines')->where('entry_id', $target->id)->get()->all());
        sort($mine);
        sort($theirs);
        if ($mine !== $theirs) {
            throw new FinanceError('REVERSAL_NOT_INVERSE');
        }
    }

    // ---- locks, idempotency, helpers -------------------------------------------------------------------------------

    /**
     * First step of the Finance lock order for a subledger operation (F1B transfers, contributions): the MONTH period of
     * $date locked FOR SHARE and the unit close checked FOR SHARE, BEFORE the subledger document is locked.
     */
    public function lockPostingPeriod(int $unitId, string $date): object
    {
        $period = $this->monthFor($date);
        $this->lockPeriodForUnit((int) $period->id, $unitId);
        return $period;
    }

    /** Non-locking: is the MONTH of $date open nationally and for $unitId? (used to pick a deferred posting date). */
    public function isPostingOpen(int $unitId, string $date): bool
    {
        $period = $this->db->table('accounting_periods')->where('code', substr($date, 0, 7))->first();
        return $period !== null && $period->period_kind === FinanceCatalog::PERIOD_MONTH && $period->status === FinanceCatalog::PERIOD_OPEN
            && !$this->unitClosed((int) $period->id, $unitId);
    }

    private function lockPeriodForUnit(int $periodId, int $unitId): void
    {
        $period = $this->db->table('accounting_periods')->where('id', $periodId)->sharedLock()->first();
        if ($period === null) {
            throw new FinanceError('PERIOD_NOT_FOUND');
        }
        if ($period->period_kind !== FinanceCatalog::PERIOD_MONTH) {
            throw new FinanceError('PERIOD_NOT_POSTABLE');
        }
        if ($period->status !== FinanceCatalog::PERIOD_OPEN) {
            throw new FinanceError('PERIOD_CLOSED');
        }
        $close = $this->db->table('accounting_period_unit_closes')->where('period_id', $periodId)->where('unit_id', $unitId)->sharedLock()->first();
        if ($close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {
            throw new FinanceError('PERIOD_CLOSED');
        }
    }

    private function unitClosed(int $periodId, int $unitId): bool
    {
        return $this->db->table('accounting_period_unit_closes')->where('period_id', $periodId)->where('unit_id', $unitId)->where('status', FinanceCatalog::UNIT_CLOSED)->exists();
    }

    private function lockFinancialAccounts(int $entryId): void
    {
        $this->lockOpenAccounts($this->db->table('journal_lines')->where('entry_id', $entryId)->whereNotNull('financial_account_id')->distinct()->pluck('financial_account_id')->all());
    }

    /**
     * Financial accounts FOR SHARE by id, and their status decided on that LOCKING read (F1C): an account close takes
     * the row FOR UPDATE, so a posting that waited for it must see CLOSED, never the OPEN of an older snapshot.
     */
    private function lockOpenAccounts(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === []) {
            return;
        }
        foreach ($this->db->table('accounts')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'status']) as $account) {
            if ($account->status !== 'OPEN') {
                throw new FinanceError('FINANCIAL_ACCOUNT_CLOSED');
            }
        }
    }

    private function lockedEntry(string $publicId, ?int $lockVersion): object
    {
        $entry = $this->db->table('journal_entries')->where('public_id', $publicId)->lockForUpdate()->first();
        if ($entry === null) {
            throw new FinanceError('ENTRY_NOT_FOUND');
        }
        if ($lockVersion !== null && (int) $entry->lock_version !== $lockVersion) {
            throw new FinanceError('STALE_LOCK_VERSION');
        }
        return $entry;
    }

    private function transition(int $actor, string $publicId, int $lockVersion, array $from, string $to, string $action): void
    {
        $this->db->transaction(function () use ($actor, $publicId, $lockVersion, $from, $to, $action): void {
            $entry = $this->lockedEntry($publicId, null);
            if ($entry->status === FinanceCatalog::POSTED) {
                throw new FinanceError('ALREADY_POSTED');
            }
            if (!in_array($entry->status, $from, true)) {
                throw new FinanceError('ENTRY_STATE_INVALID');
            }
            if ((int) $entry->lock_version !== $lockVersion) {
                throw new FinanceError('STALE_LOCK_VERSION');
            }
            $values = ['status' => $to, 'lock_version' => $entry->lock_version + 1];
            if ($to === FinanceCatalog::SUBMITTED) {
                $values += ['submitted_at' => $this->now(), 'submitted_by' => $actor];
            } elseif ($to === FinanceCatalog::DRAFT) {
                $values += ['submitted_at' => null, 'submitted_by' => null];
            }
            $this->db->table('journal_entries')->where('id', $entry->id)->whereIn('status', $from)->update($values);
            FinanceAudit::write($this->db, $actor, $action, 'journal_entries', (int) $entry->id, (int) $entry->unit_id, FinanceAudit::correlation(), ['entry' => $publicId]);
        });
    }

    private function monthFor(string $date): object
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
            throw new FinanceError('ENTRY_DATE_INVALID');
        }
        $period = $this->db->table('accounting_periods')->where('code', substr($date, 0, 7))->first();
        if ($period === null) {
            throw new FinanceError('PERIOD_NOT_FOUND');
        }
        return $period;
    }

    /** Replay of a COMPLETED request with the same hash returns the same entry; a different hash is a conflict. */
    private function replay(int $actor, string $operation, string $clientKey, string $hash): ?array
    {
        $row = $this->db->table('idempotency_requests')->where('actor_id', $actor)->where('operation', $operation)->where('client_key', $clientKey)->first();
        if ($row === null) {
            return null;
        }
        if (!hash_equals($row->request_hash, $hash)) {
            throw new FinanceError('IDEMPOTENCY_CONFLICT');
        }
        if ($row->status !== 'COMPLETED') {
            return null;
        }
        return ['id' => (int) $this->db->table('journal_entries')->where('public_id', $row->result_public_id)->value('id'), 'public_id' => (string) $row->result_public_id, 'replayed' => true];
    }

    /** @return array{id: int, replay: ?array} */
    private function claim(int $actor, string $operation, string $clientKey, string $hash): array
    {
        if ($clientKey === '' || strlen($clientKey) > 64) {
            throw new FinanceError('IDEMPOTENCY_KEY_INVALID');
        }
        $this->db->table('idempotency_requests')->insertOrIgnore(['actor_id' => $actor, 'operation' => $operation, 'client_key' => $clientKey, 'request_hash' => $hash,
            'status' => 'PROCESSING', 'result_public_id' => null, 'expires_at' => null, 'created_at' => $this->now(), 'lock_version' => 0]);
        $row = $this->db->table('idempotency_requests')->where('actor_id', $actor)->where('operation', $operation)->where('client_key', $clientKey)->lockForUpdate()->first();
        if (!hash_equals($row->request_hash, $hash)) {
            throw new FinanceError('IDEMPOTENCY_CONFLICT');
        }
        if ($row->status === 'COMPLETED') {
            return ['id' => (int) $row->id, 'replay' => ['id' => (int) $this->db->table('journal_entries')->where('public_id', $row->result_public_id)->value('id'), 'public_id' => (string) $row->result_public_id, 'replayed' => true]];
        }
        return ['id' => (int) $row->id, 'replay' => null];
    }

    private function hash(string $operation, array $payload): string
    {
        return hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
    }

    private function now(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(FinanceCatalog::TIMEZONE)))->format('Y-m-d');
    }
}
