<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

/**
 * Bank statements and bank reconciliation (ADR 0021 D06, D14, D30 C7, 04_database_constraints; F1C). Not the interunit
 * reconciliation of F1B (that one pairs SEND <-> RECEIVE of a transfer).
 *
 *   import statement   FINANCE_RECONCILE on the BANK account's unit. Manual / structured V1 ingestion (no bank
 *                      integration): header + every line in ONE transaction, never edited afterwards (immutable
 *                      provenance). The statement file is a Files document of type BANK_STATEMENT owned by the unit
 *                      (public id only, cumulative Files authority); its content SHA-256 is the source_hash (UNIQUE per
 *                      account: the same file is never imported twice). Σ lines = closing - opening.
 *                      A statement line is the bank's fact; it NEVER becomes a journal line.
 *   open reconciliation  one OPEN version per (account, MONTH period); version = previous + 1.
 *   match / unmatch    allocate matched_amount of one statement line to one POSTED journal line of the same BANK account
 *                      (same unit; money in <-> debit, money out <-> credit; journal entry dated up to the period end).
 *                      Partial and many-to-many are allowed (catalog design); under row locks of BOTH lines,
 *                      Σ matched <= |statement line| and Σ matched <= journal line amount across every reconciliation:
 *                      no value is reconciled twice (C7 / BC1).
 *   close              OPEN -> CLOSED (approved_by, closed_at): an immutable version.
 *   adjust             FIN-D11.4: the unmatched remainder of a statement line posted as EXPENSE (money out) or REVENUE
 *                      (money in) in the FIRST OPEN period >= the line date (never into a closed month), referencing the
 *                      bank statement document and the reconciliation; at most once per statement line.
 * FIN-D11 (ADR 0021): a closed period (unit or national) does NOT block reconciliation, which only relates facts that
 * already exist and never writes, changes or back-dates the ledger; only the adjustment posts, in an OPEN period.
 * Lock order: reconciliation FOR UPDATE -> statement line FOR UPDATE -> journal line FOR UPDATE; sums are LOCKING reads
 * (adjust: period of the posting date FOR SHARE first, then the same order, then the ledger's own locks).
 */
final class BankReconciliationService extends FinanceOperation
{
    public const OP_STATEMENT = 'FINANCE_BANK_STATEMENT_IMPORT';
    public const OP_RECONCILIATION = 'FINANCE_RECONCILIATION_OPEN';
    public const OP_ADJUST = 'FINANCE_RECONCILIATION_ADJUST';
    public const MAX_LINES = 500;

    /** @return array{public_id: string, replayed: bool} */
    public function importStatement(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_RECONCILE);
            $hash = $this->payloadHash(self::OP_STATEMENT, $in);
            if (($replay = $this->prior($actor, self::OP_STATEMENT, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $peek = $this->byPublicId('accounts', $in['account'] ?? null);
            $unit = (int) $peek->unit_id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_RECONCILE], $unit);
            $account = $this->rt->db->table('accounts')->where('id', $peek->id)->sharedLock()->first();
            $decision = $guard->unit(FinanceCatalog::PERMISSION_RECONCILE, $unit);
            if ($account->account_kind !== 'BANK') {
                throw new FinanceError('ACCOUNT_NOT_BANK');
            }
            $startsOn = $this->date($in['starts_on'] ?? null, 'starts_on');
            $endsOn = $this->date($in['ends_on'] ?? null, 'ends_on');
            if ($endsOn < $startsOn) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'ends_on']);
            }
            $opening = $this->signedCents($in['opening_balance'] ?? null, 'opening_balance');
            $closing = $this->signedCents($in['closing_balance'] ?? null, 'closing_balance');
            $lines = $in['lines'] ?? null;
            if (!is_array($lines) || $lines === [] || count($lines) > self::MAX_LINES || !array_is_list($lines)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'lines']);
            }
            $normalized = [];
            $sum = 0;
            foreach ($lines as $i => $line) {
                if (!is_array($line)) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'lines']);
                }
                $occurredOn = $this->date($line['occurred_on'] ?? null, 'lines');
                $amount = $this->signedCents($line['amount'] ?? null, 'lines');
                if ($amount === 0 || $occurredOn < $startsOn || $occurredOn > $endsOn) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'lines', 'line' => $i + 1]);
                }
                $sum += $amount;
                $normalized[] = ['line_number' => $i + 1, 'occurred_on' => $occurredOn, 'amount' => $amount,
                    'description' => $this->text($line['description'] ?? null, 'lines', 191, true), 'reference' => $this->text($line['reference'] ?? null, 'lines')];
            }
            if ($opening + $sum !== $closing) {
                throw new FinanceError('STATEMENT_UNBALANCED');
            }
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, $unit);
            if ($document === null || $this->documentTypeCode($document) !== 'BANK_STATEMENT') {
                throw new FinanceError('STATEMENT_DOCUMENT_REQUIRED');
            }
            $file = $this->rt->db->table('document_versions as v')->join('files as f', 'f.id', '=', 'v.file_id')->where('v.document_id', $document->id)
                ->orderByDesc('v.version')->sharedLock()->first(['f.id', 'f.checksum']);
            if ($file === null || strlen((string) $file->checksum) !== 32) {
                throw new FinanceError('STATEMENT_DOCUMENT_REQUIRED');
            }
            if ($this->rt->db->table('bank_statements')->where('account_id', $account->id)->where('source_hash', $file->checksum)->sharedLock()->exists()) {
                throw new FinanceError('STATEMENT_ALREADY_IMPORTED');
            }

            $claim = $this->claim($actor, self::OP_STATEMENT, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $publicId = (string) Str::ulid();
            $now = $this->rt->ts();
            $id = (int) $this->rt->db->table('bank_statements')->insertGetId(['public_id' => $publicId, 'account_id' => (int) $account->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'opening_balance' => Money::format($opening), 'closing_balance' => Money::format($closing), 'file_id' => (int) $file->id, 'source_hash' => $file->checksum,
                'created_at' => $now, 'lock_version' => 0]);
            foreach ($normalized as $line) {
                // The bank fact is stored as such; no journal entry is derived from it.
                $this->rt->db->table('bank_statement_lines')->insert(['statement_id' => $id, 'line_number' => $line['line_number'], 'external_reference' => $line['reference'],
                    'occurred_on' => $line['occurred_on'], 'amount_signed' => Money::format($line['amount']), 'description' => $line['description'], 'created_at' => $now, 'lock_version' => 0]);
            }
            $this->complete($claim['id'], $publicId);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.bank_statement_imported', 'bank_statements', $id, $decision->unit, FinanceAudit::correlation(), [
                'statement' => $publicId, 'account' => (string) $account->public_id, 'lines' => count($normalized), 'document' => (string) $document->public_id,
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** @return array{public_id: string, replayed: bool} */
    public function open(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_RECONCILE);
            $hash = $this->payloadHash(self::OP_RECONCILIATION, $in);
            if (($replay = $this->prior($actor, self::OP_RECONCILIATION, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $peek = $this->byPublicId('accounts', $in['account'] ?? null);
            $unit = (int) $peek->unit_id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_RECONCILE], $unit);
            $period = $this->month($in['period'] ?? null);
            $account = $this->lockRow('accounts', (int) $peek->id);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_RECONCILE, $unit);
            if ($account->account_kind !== 'BANK') {
                throw new FinanceError('ACCOUNT_NOT_BANK');
            }
            $statement = $this->byPublicId('bank_statements', $in['statement'] ?? null, 'share');
            if ((int) $statement->account_id !== (int) $account->id) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'bank_statements']);
            }
            if ($statement->starts_on > $period->ends_on || $statement->ends_on < $period->starts_on) {
                throw new FinanceError('STATEMENT_OUTSIDE_PERIOD');
            }
            $versions = $this->rt->db->table('reconciliations')->where('account_id', $account->id)->where('period_id', $period->id)->sharedLock()->get(['version', 'status']);
            if ($versions->contains(fn ($r) => $r->status === 'OPEN')) {
                throw new FinanceError('RECONCILIATION_ALREADY_OPEN');
            }

            $claim = $this->claim($actor, self::OP_RECONCILIATION, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $publicId = (string) Str::ulid();
            $version = (int) $versions->max('version') + 1;
            $id = (int) $this->rt->db->table('reconciliations')->insertGetId(['public_id' => $publicId, 'account_id' => (int) $account->id, 'statement_id' => (int) $statement->id,
                'counted_balance' => null, 'period_id' => (int) $period->id, 'version' => $version, 'status' => 'OPEN', 'approved_by' => null, 'closed_at' => null,
                'created_at' => $this->rt->ts(), 'lock_version' => 0]);
            $this->complete($claim['id'], $publicId);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.reconciliation_opened', 'reconciliations', $id, $decision->unit, FinanceAudit::correlation(), [
                'reconciliation' => $publicId, 'account' => (string) $account->public_id, 'statement' => (string) $statement->public_id, 'period' => (string) $period->code, 'version' => $version,
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** Allocate part (or all) of a statement line to a POSTED journal line of the same BANK account. */
    public function match(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            [$reconciliation, $decision, $statementLine, $journalLine, $entry] = $this->locked($guard, $actor, $publicId, $in);
            $amount = Money::cents(is_string($in['amount'] ?? null) ? $in['amount'] : '');
            $period = $this->rt->db->table('accounting_periods')->where('id', $reconciliation->period_id)->first();
            if ($entry->entry_date > $period->ends_on) {
                throw new FinanceError('MATCH_DATE_INCOMPATIBLE');
            }
            $bankAmount = Money::fromDecimal((string) $statementLine->amount_signed);
            $debit = Money::fromDecimal((string) $journalLine->debit);
            $credit = Money::fromDecimal((string) $journalLine->credit);
            // Money into the bank (> 0) is a debit of the BANK account; money out (< 0) a credit.
            if (($bankAmount > 0) !== ($debit > 0)) {
                throw new FinanceError('MATCH_DIRECTION_MISMATCH');
            }
            if ($this->rt->db->table('reconciliation_matches')->where('reconciliation_id', $reconciliation->id)->where('statement_line_id', $statementLine->id)
                ->where('journal_line_id', $journalLine->id)->sharedLock()->exists()) {
                throw new FinanceError('ALREADY_MATCHED');
            }
            if ($this->matched('statement_line_id', (int) $statementLine->id) + $amount > abs($bankAmount)) {
                throw new FinanceError('MATCH_EXCEEDS_STATEMENT_LINE');
            }
            if ($this->matched('journal_line_id', (int) $journalLine->id) + $amount > max($debit, $credit)) {
                throw new FinanceError('MATCH_EXCEEDS_LEDGER_LINE');
            }
            $this->rt->db->table('reconciliation_matches')->insert(['reconciliation_id' => (int) $reconciliation->id, 'statement_line_id' => (int) $statementLine->id,
                'journal_line_id' => (int) $journalLine->id, 'matched_amount' => Money::format($amount), 'created_at' => $this->rt->ts(), 'lock_version' => 0]);
            $this->rt->db->table('reconciliations')->where('id', $reconciliation->id)->update(['lock_version' => $reconciliation->lock_version + 1]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.reconciliation_matched', 'reconciliations', (int) $reconciliation->id, $decision->unit, FinanceAudit::correlation(), [
                'reconciliation' => (string) $reconciliation->public_id, 'statement_line' => (int) $statementLine->line_number, 'entry' => (string) $entry->public_id,
                'entry_line' => (int) $journalLine->line_number, 'amount' => Money::format($amount),
            ], null, $actor->session);
            return ['replayed' => false];
        });
    }

    /** Remove one allocation while the reconciliation is OPEN (a CLOSED version is immutable). */
    public function unmatch(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            [$reconciliation, $decision, $statementLine, $journalLine, $entry] = $this->locked($guard, $actor, $publicId, $in);
            $deleted = $this->rt->db->table('reconciliation_matches')->where('reconciliation_id', $reconciliation->id)->where('statement_line_id', $statementLine->id)
                ->where('journal_line_id', $journalLine->id)->delete();
            if ($deleted !== 1) {
                throw new FinanceError('MATCH_NOT_FOUND');
            }
            $this->rt->db->table('reconciliations')->where('id', $reconciliation->id)->update(['lock_version' => $reconciliation->lock_version + 1]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.reconciliation_unmatched', 'reconciliations', (int) $reconciliation->id, $decision->unit, FinanceAudit::correlation(), [
                'reconciliation' => (string) $reconciliation->public_id, 'statement_line' => (int) $statementLine->line_number, 'entry' => (string) $entry->public_id, 'entry_line' => (int) $journalLine->line_number,
            ], null, $actor->session);
            return ['replayed' => false];
        });
    }

    /** OPEN -> CLOSED: the version becomes immutable. Unmatched lines remain visible as reconciling items. */
    public function close(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_RECONCILE);
            [$reconciliation, $unit] = $this->target($actor, $publicId);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_RECONCILE, $unit);
            if ($reconciliation->status === 'CLOSED') {
                return ['replayed' => true];
            }
            $this->assertLockVersion($reconciliation, $in);
            $this->rt->db->table('reconciliations')->where('id', $reconciliation->id)->where('status', 'OPEN')->update(['status' => 'CLOSED', 'approved_by' => $actor->user,
                'closed_at' => $this->rt->ts(), 'lock_version' => $reconciliation->lock_version + 1]);
            $matches = (int) $this->rt->db->table('reconciliation_matches')->where('reconciliation_id', $reconciliation->id)->count();
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.reconciliation_closed', 'reconciliations', (int) $reconciliation->id, $decision->unit, FinanceAudit::correlation(), [
                'reconciliation' => (string) $reconciliation->public_id, 'matches' => $matches,
            ], null, $actor->session);
            return ['replayed' => false];
        });
    }

    /**
     * FIN-D11.4 adjustment of a difference found by the reconciliation. The closed period stays intact: the entry is dated
     * on the line date when that month is OPEN for the unit, otherwise on the first day of the first later OPEN month
     * (never in the future). FIN-D10 applies (a bank fee cannot overdraw the account).
     * @return array{public_id: string, replayed: bool, posted_on: string}
     */
    public function adjust(int $user, int $session, string $clientKey, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $publicId, $in): array {
            $permissions = [FinanceCatalog::PERMISSION_RECONCILE, FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST];
            $guard->requires(...$permissions);
            $hash = $this->payloadHash(self::OP_ADJUST, ['reconciliation' => $publicId] + $in);
            if (($replay = $this->prior($actor, self::OP_ADJUST, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true, 'posted_on' => (string) $this->rt->db->table('journal_entries')->where('public_id', $replay)->value('entry_date')];
            }
            $peek = $this->byPublicId('reconciliations', $publicId);
            $account = $this->rt->db->table('accounts')->where('id', $peek->account_id)->first();
            $unit = (int) $account->unit_id;
            $this->preauthorize($actor, $permissions, $unit);
            $lineNumber = filter_var($in['statement_line'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $peekLine = $lineNumber === false ? null : $this->rt->db->table('bank_statement_lines')->where('statement_id', $peek->statement_id)->where('line_number', $lineNumber)->first();
            if ($peekLine === null) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'statement_line']);
            }
            $postOn = $this->postingDate($unit, (string) $peekLine->occurred_on);
            $this->ledger->lockPostingPeriod($unit, $postOn);
            $reconciliation = $this->lockRow('reconciliations', (int) $peek->id);
            $decision = $guard->all($permissions, $unit);
            if ($reconciliation->status !== 'OPEN') {
                throw new FinanceError('RECONCILIATION_CLOSED');
            }
            $line = $this->lockRow('bank_statement_lines', (int) $peekLine->id);
            $signed = Money::fromDecimal((string) $line->amount_signed);
            $remaining = abs($signed) - $this->matched('statement_line_id', (int) $line->id);
            if ($remaining <= 0) {
                throw new FinanceError('NOTHING_TO_ADJUST');
            }
            $statement = $this->rt->db->table('bank_statements')->where('id', $line->statement_id)->first();
            $marker = 'Ajuste de reconciliação · extracto ' . $statement->public_id . ' linha ' . $line->line_number;
            if ($this->rt->db->table('journal_entries')->where('unit_id', $unit)->where('status', FinanceCatalog::POSTED)->where('description', 'like', $marker . ' %')->sharedLock()->exists()) {
                throw new FinanceError('ALREADY_ADJUSTED');
            }
            $category = $this->category($in['category'] ?? null, $signed < 0 ? FinanceCatalog::PAYABLE_NATURES : FinanceCatalog::RECEIVABLE_NATURES);
            $document = $this->rt->db->table('document_versions')->where('file_id', $statement->file_id)->orderByDesc('version')->value('document_id');

            $claim = $this->claim($actor, self::OP_ADJUST, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true, 'posted_on' => (string) $this->rt->db->table('journal_entries')->where('public_id', $claim['replay'])->value('entry_date')];
            }
            $money = Money::format($remaining);
            $bank = ['account' => 'BANK', 'financial_account_id' => (int) $account->id];
            $economic = ['ledger_account_id' => (int) $category->ledger_account_id, 'category' => (string) $category->code];
            $draft = $this->ledger->createDraft($actor->user, 'RA-' . $reconciliation->public_id . '-' . $line->line_number, [
                'unit_id' => $unit, 'entry_kind' => $signed < 0 ? 'EXPENSE' : 'REVENUE', 'entry_date' => $postOn,
                'description' => $marker . ' (reconciliação ' . $reconciliation->public_id . ')', 'document_id' => $document === null ? null : (int) $document,
                'lines' => $signed < 0 ? [$economic + ['debit' => $money], $bank + ['credit' => $money]] : [$bank + ['debit' => $money], $economic + ['credit' => $money]],
            ]);
            $this->ledger->post($actor->user, $draft['public_id'], 0);
            $this->complete($claim['id'], $draft['public_id']);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.reconciliation_adjustment_posted', 'reconciliations', (int) $reconciliation->id, $decision->unit, FinanceAudit::correlation(), [
                'reconciliation' => (string) $reconciliation->public_id, 'statement' => (string) $statement->public_id, 'statement_line' => (int) $line->line_number,
                'entry' => $draft['public_id'], 'posted_on' => $postOn, 'category' => (string) $category->code,
            ], null, $actor->session);
            return ['public_id' => $draft['public_id'], 'replayed' => false, 'posted_on' => $postOn];
        });
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    /** The line date when its month is OPEN for the unit, else day 1 of the first later OPEN month (never the future). */
    private function postingDate(int $unit, string $date): string
    {
        if ($this->ledger->isPostingOpen($unit, $date)) {
            return $date;
        }
        $today = $this->rt->today();
        $cursor = new \DateTimeImmutable(substr($date, 0, 7) . '-01', new \DateTimeZone('UTC'));
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

    /** Reconciliation resolved, scope pre-authorised on its account unit, row locked FOR UPDATE (FIN-D11: no period check). */
    private function target(TerritorialActor $actor, string $publicId): array
    {
        $peek = $this->byPublicId('reconciliations', $publicId);
        $unit = (int) $this->rt->db->table('accounts')->where('id', $peek->account_id)->value('unit_id');
        $this->preauthorize($actor, [FinanceCatalog::PERMISSION_RECONCILE], $unit);
        return [$this->lockRow('reconciliations', (int) $peek->id), $unit];
    }

    /** Common prefix of match / unmatch: both lines resolved inside the reconciliation's account and locked in order. */
    private function locked(FinanceGuard $guard, TerritorialActor $actor, string $publicId, array $in): array
    {
        $guard->requires(FinanceCatalog::PERMISSION_RECONCILE);
        [$reconciliation, $unit] = $this->target($actor, $publicId);
        $decision = $guard->unit(FinanceCatalog::PERMISSION_RECONCILE, $unit);
        if ($reconciliation->status !== 'OPEN') {
            throw new FinanceError('RECONCILIATION_CLOSED');
        }
        $lineNumber = filter_var($in['statement_line'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $statementLine = $lineNumber === false ? null : $this->rt->db->table('bank_statement_lines')->where('statement_id', $reconciliation->statement_id)->where('line_number', $lineNumber)->lockForUpdate()->first();
        if ($statementLine === null) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'statement_line']);
        }
        $entry = is_string($in['entry'] ?? null) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $in['entry']) === 1
            ? $this->rt->db->table('journal_entries')->where('public_id', $in['entry'])->where('unit_id', $unit)->sharedLock()->first() : null;
        $entryLine = filter_var($in['entry_line'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $journalLine = $entry === null || $entryLine === false ? null : $this->rt->db->table('journal_lines')->where('entry_id', $entry->id)->where('line_number', $entryLine)->lockForUpdate()->first();
        // Another unit's entry, a line of another financial account and an unknown line are the same concealed target.
        if ($journalLine === null || (int) $journalLine->financial_account_id !== (int) $reconciliation->account_id) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'journal_lines']);
        }
        if ($entry->status !== FinanceCatalog::POSTED) {
            throw new FinanceError('ENTRY_NOT_POSTED');
        }
        return [$reconciliation, $decision, $statementLine, $journalLine, $entry];
    }

    /** Σ matched_amount of one statement line or one journal line across EVERY reconciliation (LOCKING read). */
    private function matched(string $column, int $id): int
    {
        $row = $this->rt->db->selectOne("SELECT COALESCE(SUM(matched_amount), 0) AS s FROM reconciliation_matches WHERE {$column} = ? FOR SHARE", [$id]);
        return Money::fromDecimal((string) $row->s);
    }

    private function month(mixed $code): object
    {
        $period = is_string($code) && preg_match('/^\d{4}-\d{2}$/D', $code) === 1 ? $this->rt->db->table('accounting_periods')->where('code', $code)->first() : null;
        if ($period === null || $period->period_kind !== FinanceCatalog::PERIOD_MONTH) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'period']);
        }
        return $period;
    }
}
