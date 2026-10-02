<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Finance\LedgerPostingService;
use App\Domain\Territorial\TerritorialActor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * The payroll run pipeline (ADR 0021 D26/D27/D29 + D-04A.14/15), one explicit command per transition — never a generic
 * status write, never a hard delete:
 *
 *   create      PAYROLL_MANAGE                     -> DRAFT        (unit x service month x run_kind x sequence)
 *   calculate   PAYROLL_MANAGE                     DRAFT|CALCULATED -> CALCULATED   (allowed with production disabled)
 *   approve     PAYROLL_APPROVE, approver != calculator, production enabled, input_hash recomputed under lock
 *                                                  CALCULATED -> APPROVED   (PAYROLL_INPUT_STALE when the inputs moved)
 *   post        PAYROLL_POST + FINANCE_POST, production enabled, Finance period OPEN
 *                                                  APPROVED -> POSTED   one aggregated PAYROLL_ACCRUAL entry (D27)
 *   pay         PAYROLL_POST + FINANCE_POST, production enabled, Finance period OPEN, FIN-D10 (LedgerPostingService)
 *                                                  POSTED -> PAID       one PAYROLL_PAYMENT entry = the POSTED net payable
 *   reverse     PAYROLL_POST + FINANCE_POST, production enabled, reason
 *                                                  POSTED -> REVERSED   PAYROLL_REVERSAL = exact inverse (only before PAID)
 *   cancel      PAYROLL_MANAGE, reason             DRAFT|CALCULATED -> CANCELLED
 *
 * Lock order (extends the Finance order): Finance period (+ unit close) FOR SHARE -> run FOR UPDATE -> payroll inputs FOR
 * SHARE (employments, compensation lines, rule components, rules) -> financial account FOR UPDATE -> journal header /
 * idempotency (LedgerPostingService, the only journal writer). payroll_postings UNIQUE (run, stage) + UNIQUE entry +
 * idempotency_requests + the run lock make every Finance stage happen at most once: a retry or a concurrent second call
 * is an idempotent no-op that returns the existing entry. The Finance journal never receives a person, an employment
 * or an individual amount: only aggregates per rubric / ledger account / liability role.
 * Business failures of approve / post / pay / reverse on a visible run are audited as payroll.failed_transition in a
 * separate transaction (code only; never an amount).
 */
final class PayrollRunService extends PayrollService
{
    public const OP_CREATE = 'PAYROLL_RUN_CREATE';
    public const STAGE_ACCRUAL = 'ACCRUAL';
    public const STAGE_PAYMENT = 'PAYMENT';
    public const STAGE_REVERSAL = 'REVERSAL';
    private const NOT_AUDITED = ['NOT_AUTHORIZED', 'OUT_OF_SCOPE', 'TARGET_NOT_FOUND', 'INVALID_INPUT', 'BUSY', 'CONFIG_MISSING'];

    public function create(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $guard->requires(PayrollCatalog::PAYROLL_MANAGE);
            if (strlen($clientKey) > 64) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'idempotency_key']);
            }
            $hash = hash('sha256', json_encode([self::OP_CREATE, $in], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
            if ($clientKey !== '' && ($prior = $this->priorRequest($actor, $clientKey, $hash)) !== null) {
                return $prior;
            }
            $unit = $this->unitByPublicId($in['unit'] ?? null);
            $guard->unit(PayrollCatalog::PAYROLL_MANAGE, (int) $unit->id);
            if ($unit->status !== 'ACTIVE') {
                throw new FinanceError('UNIT_NOT_ACTIVE');
            }
            [$code] = $this->month($in['period'] ?? null);
            $kind = $in['run_kind'] ?? 'REGULAR';
            if (!is_string($kind) || !in_array($kind, PayrollCatalog::RUN_KINDS, true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'run_kind']);
            }
            if ($kind !== 'REGULAR') {
                // HOLIDAY_SUBSIDY / THIRTEENTH / ADJUSTMENT runs need an approved policy (which components, which base,
                // how differences are computed). None exists: refuse instead of improvising (F2B-D2).
                throw new FinanceError('PAYROLL_RUN_KIND_POLICY_MISSING', [$kind], ['field' => 'run_kind']);
            }
            $period = $this->rt->db->table('accounting_periods')->where('code', $code)->where('period_kind', FinanceCatalog::PERIOD_MONTH)->first();
            if ($period === null) {
                throw new FinanceError('PERIOD_NOT_FOUND', [], ['field' => 'period']);
            }
            $existing = $this->rt->db->table('payroll_runs')->where('employing_unit_id', $unit->id)->where('period_id', $period->id)->where('run_kind', $kind)->lockForUpdate()->get(['public_id', 'status', 'sequence']);
            $open = $existing->first(fn ($r) => $r->status !== 'CANCELLED');
            if ($open !== null) {
                throw new FinanceError('PAYROLL_RUN_EXISTS', [(string) $open->public_id]);
            }
            $claim = $clientKey === '' ? null : $this->claimRequest($actor, $clientKey, $hash);
            if (is_array($claim)) {
                return $claim;
            }
            $now = $this->rt->ts();
            $publicId = (string) Str::ulid();
            $sequence = (int) $existing->max('sequence') + 1;
            try {
                $id = (int) $this->rt->db->table('payroll_runs')->insertGetId(['public_id' => $publicId, 'employing_unit_id' => $unit->id, 'period_id' => $period->id, 'run_kind' => $kind, 'sequence' => $sequence,
                    'status' => 'DRAFT', 'input_hash' => null, 'gross_amount' => 0, 'deductions_amount' => 0, 'employer_charges_amount' => 0, 'net_amount' => 0, 'headcount' => 0,
                    'created_by' => $actor->user, 'created_at' => $now, 'lock_version' => 0]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new FinanceError('PAYROLL_RUN_EXISTS');
                }
                throw $e;
            }
            if ($claim !== null) {
                $this->rt->db->table('idempotency_requests')->where('id', $claim)->update(['status' => 'COMPLETED', 'result_public_id' => $publicId]);
            }
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.created', 'payroll_runs', $id, (int) $unit->id,
                ['run' => $publicId, 'unit' => (string) $unit->public_id, 'period' => $code, 'run_kind' => $kind, 'sequence' => $sequence], null, $actor->session);
            return ['public_id' => $publicId, 'status' => 'DRAFT', 'sequence' => $sequence, 'replayed' => false];
        });
    }

    /** DRAFT|CALCULATED -> CALCULATED. Allowed with production disabled (validation of the configuration). */
    public function calculate(int $user, int $session, string $run, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $in): array {
            $guard->requires(PayrollCatalog::PAYROLL_MANAGE);
            $peek = $this->peek($run);
            $this->rt->authority->forUnit($actor, PayrollCatalog::PAYROLL_MANAGE, (int) $peek->employing_unit_id);
            $r = $this->lockRun((int) $peek->id);
            $guard->unit(PayrollCatalog::PAYROLL_MANAGE, (int) $r->employing_unit_id);
            $this->assertLockVersion($r, $in);
            if (!in_array($r->status, ['DRAFT', 'CALCULATED'], true)) {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'DRAFT|CALCULATED']);
            }
            $period = $this->periodOf($r);
            $payload = PayrollInputHash::payload($this->rt->db, (int) $r->employing_unit_id, $period, (string) $r->run_kind, (int) $r->sequence, true);
            $this->assertOnePersonOnce($payload);
            $result = PayrollCalculator::calculate($payload, $this->componentCatalog());
            $hash = PayrollInputHash::hash($payload);
            $previous = $r->input_hash === null ? null : bin2hex((string) $r->input_hash);
            if ($r->status === 'CALCULATED') {
                // A CALCULATED run is a working result: recalculation replaces its lines (D26 "recalcular só em
                // CALCULATED/DRAFT"); the previous input_hash stays in the audit trail. From APPROVED lines are immutable.
                $this->rt->db->table('payroll_run_lines')->where('run_id', $r->id)->delete();
            }
            $this->insertLines((int) $r->id, $result);
            $now = $this->rt->ts();
            $updated = $this->rt->db->table('payroll_runs')->where('id', $r->id)->whereIn('status', ['DRAFT', 'CALCULATED'])->update([
                'status' => 'CALCULATED', 'input_hash' => hex2bin($hash), 'gross_amount' => $result['totals']['gross'], 'deductions_amount' => $result['totals']['deductions'],
                'employer_charges_amount' => $result['totals']['employer_charges'], 'net_amount' => $result['totals']['net'], 'headcount' => $result['headcount'],
                'calculated_by' => $actor->user, 'calculated_at' => $now, 'lock_version' => $r->lock_version + 1]);
            if ($updated !== 1) {
                throw new FinanceError('STALE_WRITE');
            }
            $this->assertCoherent((int) $r->id);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.calculated', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id,
                ['run' => (string) $r->public_id, 'period' => $period, 'run_kind' => (string) $r->run_kind, 'sequence' => (int) $r->sequence, 'input_hash' => $hash, 'input_hash_version' => PayrollCatalog::INPUT_HASH_VERSION,
                    'headcount' => $result['headcount'], 'recalculated' => $previous !== null, 'previous_input_hash' => $previous], null, $actor->session);
            return ['public_id' => (string) $r->public_id, 'status' => 'CALCULATED', 'input_hash' => $hash, 'headcount' => $result['headcount']];
        });
    }

    /** CALCULATED -> APPROVED. Validates the calculation; never recalculates numbers. */
    public function approve(int $user, int $session, string $run, array $in): array
    {
        return $this->transition('approve', $user, $session, $run, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $in): array {
            $guard->requires(PayrollCatalog::PAYROLL_APPROVE);
            $peek = $this->peek($run);
            $this->rt->authority->forUnit($actor, PayrollCatalog::PAYROLL_APPROVE, (int) $peek->employing_unit_id);
            PayrollProduction::fromConfig()->assertEnabled('approve');
            $r = $this->lockRun((int) $peek->id);
            $guard->unit(PayrollCatalog::PAYROLL_APPROVE, (int) $r->employing_unit_id);
            $this->assertLockVersion($r, $in);
            if ($r->status !== 'CALCULATED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'CALCULATED']);
            }
            if ((int) $r->calculated_by === $actor->user) {
                throw new FinanceError('PAYROLL_SEGREGATION_REQUIRED', [], ['reason' => 'approver_is_calculator']);
            }
            $hash = $this->currentHash($r, true);
            if ($hash === null || !hash_equals(bin2hex((string) $r->input_hash), $hash)) {
                throw new FinanceError('PAYROLL_INPUT_STALE', [], ['reason' => $hash === null ? 'inputs_no_longer_resolvable' : 'input_hash_changed']);
            }
            $this->assertCoherent((int) $r->id);
            $now = $this->rt->ts();
            $updated = $this->rt->db->table('payroll_runs')->where('id', $r->id)->where('status', 'CALCULATED')->update(['status' => 'APPROVED', 'approved_by' => $actor->user, 'approved_at' => $now,
                'lock_version' => $r->lock_version + 1]);
            if ($updated !== 1) {
                throw new FinanceError('STALE_WRITE');
            }
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.approved', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id,
                ['run' => (string) $r->public_id, 'period' => $this->periodOf($r), 'input_hash' => $hash], null, $actor->session);
            return ['public_id' => (string) $r->public_id, 'status' => 'APPROVED', 'input_hash' => $hash];
        });
    }

    /** APPROVED -> POSTED: one PAYROLL_ACCRUAL entry aggregated by rubric / ledger account / liability role (D27). */
    public function post(int $user, int $session, string $run, array $in): array
    {
        return $this->transition('post', $user, $session, $run, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $in): array {
            [$peek, $unit] = $this->ledgerPreamble($guard, $actor, $run, 'post');
            $period = $this->periodRow((int) $peek->period_id);
            $entryDate = $this->accrualDate($in['entry_date'] ?? null, $period);
            $deferred = substr($entryDate, 0, 7) !== (string) $period->code;
            $reason = $deferred ? $this->text($in['reason'] ?? null, 'reason') : $this->text($in['reason'] ?? null, 'reason', 2000, false);
            $ledger = new LedgerPostingService($this->rt->db);
            $ledger->lockPostingPeriod($unit, $entryDate);
            $r = $this->lockRun((int) $peek->id);
            $this->lockedDecisions($guard, $actor, $unit);
            if (($posting = $this->posting((int) $r->id, self::STAGE_ACCRUAL)) !== null) {
                return ['public_id' => (string) $r->public_id, 'status' => (string) $r->status, 'entry' => $this->entryPublic((int) $posting->entry_id), 'replayed' => true];
            }
            $this->assertLockVersion($r, $in);
            if ($r->status !== 'APPROVED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'APPROVED']);
            }
            $this->assertCoherent((int) $r->id);
            $entry = $ledger->postSubledgerEntry($actor->user, 'PA-' . $r->public_id, [
                'unit_id' => $unit, 'entry_kind' => 'PAYROLL_ACCRUAL', 'entry_date' => $entryDate, 'reason' => $reason,
                'description' => $this->entryDescription($r, (string) $period->code, 'processamento'), 'lines' => $this->accrualLines($r)]);
            $this->recordPosting((int) $r->id, self::STAGE_ACCRUAL, (int) $entry['id']);
            $now = $this->rt->ts();
            $this->rt->db->table('payroll_runs')->where('id', $r->id)->where('status', 'APPROVED')->update(['status' => 'POSTED', 'posted_by' => $actor->user, 'posted_at' => $now, 'lock_version' => $r->lock_version + 1]);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.posted', 'payroll_runs', (int) $r->id, $unit,
                ['run' => (string) $r->public_id, 'entry' => $entry['public_id'], 'entry_date' => $entryDate, 'service_period' => (string) $period->code, 'deferred_to_open_period' => $deferred], $reason, $actor->session);
            return ['public_id' => (string) $r->public_id, 'status' => 'POSTED', 'entry' => $entry['public_id'], 'entry_date' => $entryDate, 'replayed' => false];
        });
    }

    /** POSTED -> PAID: Dr PAYROLL_NET_PAYABLE / Cr CASH|BANK for exactly the POSTED net (never recalculated). */
    public function pay(int $user, int $session, string $run, array $in): array
    {
        return $this->transition('pay', $user, $session, $run, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $in): array {
            [$peek, $unit] = $this->ledgerPreamble($guard, $actor, $run, 'pay');
            $account = is_string($in['account'] ?? null) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $in['account']) === 1
                ? $this->rt->db->table('accounts')->where('public_id', $in['account'])->first() : null;
            if ($account === null || (int) $account->unit_id !== $unit) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'accounts']);
            }
            [$paidOn, $paidAt] = $this->rt->instantFor($in['paid_on'] ?? null);
            $ledger = new LedgerPostingService($this->rt->db);
            $ledger->lockPostingPeriod($unit, $paidOn);
            $r = $this->lockRun((int) $peek->id);
            $this->lockedDecisions($guard, $actor, $unit);
            if (($posting = $this->posting((int) $r->id, self::STAGE_PAYMENT)) !== null) {
                return ['public_id' => (string) $r->public_id, 'status' => (string) $r->status, 'entry' => $this->entryPublic((int) $posting->entry_id), 'replayed' => true];
            }
            $this->assertLockVersion($r, $in);
            if ($r->status !== 'POSTED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'POSTED']);
            }
            $accrual = $this->posting((int) $r->id, self::STAGE_ACCRUAL);
            $accrualDate = (string) $this->rt->db->table('journal_entries')->where('id', $accrual->entry_id)->sharedLock()->value('entry_date');
            if ($paidOn < $accrualDate) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'paid_on', 'reason' => 'before_accrual']);
            }
            $account = $this->rt->db->table('accounts')->where('id', $account->id)->lockForUpdate()->first();
            if ($account->status !== 'OPEN') {
                throw new FinanceError('ACCOUNT_NOT_OPEN');
            }
            if ($this->rt->db->table('currencies')->where('id', $account->currency_id)->value('code') !== FinanceCatalog::CURRENCY) {
                throw new FinanceError('CURRENCY_NOT_SUPPORTED');
            }
            $net = PayrollMoney::fromStorage((string) $r->net_amount);
            if (bccomp($net, '0', PayrollCatalog::MONEY_SCALE) <= 0) {
                throw new FinanceError('PAYROLL_NET_ZERO');
            }
            // FIN-D10 (no negative CASH/BANK) is enforced by LedgerPostingService on the locked account: INSUFFICIENT_FUNDS.
            $entry = $ledger->postSubledgerEntry($actor->user, 'PP-' . $r->public_id, [
                'unit_id' => $unit, 'entry_kind' => 'PAYROLL_PAYMENT', 'entry_date' => $paidOn,
                'description' => $this->entryDescription($r, $this->periodOf($r), 'pagamento do líquido'), 'lines' => [
                    ['account' => 'PAYROLL_NET_PAYABLE', 'debit' => $net, 'description' => 'Salários líquidos a pagar'],
                    ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$account->account_kind], 'financial_account_id' => (int) $account->id, 'credit' => $net, 'description' => 'Pagamento da folha salarial'],
                ]]);
            $this->recordPosting((int) $r->id, self::STAGE_PAYMENT, (int) $entry['id']);
            $this->rt->db->table('payroll_runs')->where('id', $r->id)->where('status', 'POSTED')->update(['status' => 'PAID', 'paid_by' => $actor->user, 'paid_at' => $paidAt, 'lock_version' => $r->lock_version + 1]);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.paid', 'payroll_runs', (int) $r->id, $unit,
                ['run' => (string) $r->public_id, 'entry' => $entry['public_id'], 'paid_on' => $paidOn, 'financial_account' => (string) $account->public_id, 'statutory_liabilities' => 'UNCHANGED'], null, $actor->session);
            return ['public_id' => (string) $r->public_id, 'status' => 'PAID', 'entry' => $entry['public_id'], 'paid_on' => $paidOn, 'replayed' => false];
        });
    }

    /** POSTED -> REVERSED (D26 correction, only before PAID): PAYROLL_REVERSAL = exact inverse of the accrual. */
    public function reverse(int $user, int $session, string $run, array $in): array
    {
        return $this->transition('reverse', $user, $session, $run, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $in): array {
            [$peek, $unit] = $this->ledgerPreamble($guard, $actor, $run, 'reverse');
            $reason = $this->text($in['reason'] ?? null, 'reason');
            if (mb_strlen((string) $reason) < 3) {
                throw new FinanceError('REASON_REQUIRED', [], ['field' => 'reason']);
            }
            [$entryDate] = $this->rt->instantFor($in['entry_date'] ?? null);
            $ledger = new LedgerPostingService($this->rt->db);
            $ledger->lockPostingPeriod($unit, $entryDate);
            $r = $this->lockRun((int) $peek->id);
            $this->lockedDecisions($guard, $actor, $unit);
            if (($posting = $this->posting((int) $r->id, self::STAGE_REVERSAL)) !== null) {
                return ['public_id' => (string) $r->public_id, 'status' => (string) $r->status, 'entry' => $this->entryPublic((int) $posting->entry_id), 'replayed' => true];
            }
            $this->assertLockVersion($r, $in);
            if ($r->status === 'PAID') {
                throw new FinanceError('PAYROLL_ALREADY_PAID');
            }
            if ($r->status !== 'POSTED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'POSTED']);
            }
            $accrual = $this->rt->db->table('journal_entries')->where('id', $this->posting((int) $r->id, self::STAGE_ACCRUAL)->entry_id)->sharedLock()->first();
            if ($entryDate < (string) $accrual->entry_date) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'entry_date', 'reason' => 'before_accrual']);
            }
            $lines = [];
            foreach ($this->rt->db->table('journal_lines')->where('entry_id', $accrual->id)->orderBy('line_number')->sharedLock()->get() as $l) {
                $lines[] = ['ledger_account_id' => (int) $l->ledger_account_id, 'category_id' => $l->category_id === null ? null : (int) $l->category_id, 'fund_id' => (int) $l->fund_id,
                    'debit' => bccomp((string) $l->credit, '0', 4) > 0 ? PayrollMoney::fromStorage((string) $l->credit) : null,
                    'credit' => bccomp((string) $l->debit, '0', 4) > 0 ? PayrollMoney::fromStorage((string) $l->debit) : null, 'description' => $l->description];
            }
            $entry = $ledger->postSubledgerEntry($actor->user, 'PR-' . $r->public_id, [
                'unit_id' => $unit, 'entry_kind' => 'PAYROLL_REVERSAL', 'entry_date' => $entryDate, 'reason' => $reason, 'reversal_of' => (string) $accrual->public_id,
                'description' => $this->entryDescription($r, $this->periodOf($r), 'anulação do processamento'), 'lines' => $lines]);
            $this->recordPosting((int) $r->id, self::STAGE_REVERSAL, (int) $entry['id']);
            $this->rt->db->table('payroll_runs')->where('id', $r->id)->where('status', 'POSTED')->update(['status' => 'REVERSED', 'reversed_by' => $actor->user, 'reversed_at' => $this->rt->ts(),
                'reversal_reason' => $reason, 'lock_version' => $r->lock_version + 1]);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.reversed', 'payroll_runs', (int) $r->id, $unit,
                ['run' => (string) $r->public_id, 'entry' => $entry['public_id'], 'reversal_of' => (string) $accrual->public_id, 'entry_date' => $entryDate], $reason, $actor->session);
            return ['public_id' => (string) $r->public_id, 'status' => 'REVERSED', 'entry' => $entry['public_id'], 'replayed' => false];
        });
    }

    /** DRAFT|CALCULATED -> CANCELLED (reason). The lines of a cancelled calculation stay as history. */
    public function cancel(int $user, int $session, string $run, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $in): array {
            $guard->requires(PayrollCatalog::PAYROLL_MANAGE);
            $peek = $this->peek($run);
            $this->rt->authority->forUnit($actor, PayrollCatalog::PAYROLL_MANAGE, (int) $peek->employing_unit_id);
            $r = $this->lockRun((int) $peek->id);
            $guard->unit(PayrollCatalog::PAYROLL_MANAGE, (int) $r->employing_unit_id);
            $this->assertLockVersion($r, $in);
            $reason = $this->text($in['reason'] ?? null, 'reason');
            if (!in_array($r->status, ['DRAFT', 'CALCULATED'], true)) {
                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'DRAFT|CALCULATED']);
            }
            $this->rt->db->table('payroll_runs')->where('id', $r->id)->whereIn('status', ['DRAFT', 'CALCULATED'])->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->user,
                'cancelled_at' => $this->rt->ts(), 'cancel_reason' => $reason, 'lock_version' => $r->lock_version + 1]);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.cancelled', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id, ['run' => (string) $r->public_id, 'from' => (string) $r->status], $reason, $actor->session);
            return ['public_id' => (string) $r->public_id, 'status' => 'CANCELLED'];
        });
    }

    // ---- shared steps ---------------------------------------------------------------------------------------------------

    /**
     * Ledger-writing transitions: HR PAYROLL_POST and FINANCE FINANCE_POST held somewhere (403 otherwise, before any target),
     * then the run's unit is decided for both (concealed 404), then the production gate (409 PAYROLL_PRODUCTION_DISABLED).
     *
     * @return array{0: object, 1: int}
     */
    private function ledgerPreamble(FinanceGuard $guard, TerritorialActor $actor, string $run, string $operation): array
    {
        $guard->requires(PayrollCatalog::PAYROLL_POST);
        $this->financeGate()->requires($actor, FinanceCatalog::PERMISSION_POST);
        $peek = $this->peek($run);
        $unit = (int) $peek->employing_unit_id;
        $this->rt->authority->forUnit($actor, PayrollCatalog::PAYROLL_POST, $unit);
        $this->financeGate()->preauthorize($actor, FinanceCatalog::PERMISSION_POST, $unit);
        PayrollProduction::fromConfig()->assertEnabled($operation);
        return [$peek, $unit];
    }

    private function lockedDecisions(FinanceGuard $guard, TerritorialActor $actor, int $unit): void
    {
        $guard->unit(PayrollCatalog::PAYROLL_POST, $unit);
        $this->financeGate()->unit($actor, FinanceCatalog::PERMISSION_POST, $unit);
    }

    /** Wraps approve / post / pay / reverse: a business refusal on a run the actor can see is audited (separately). */
    private function transition(string $operation, int $user, int $session, string $run, callable $work): array
    {
        try {
            return $this->rt->write($user, $session, $work);
        } catch (FinanceError $e) {
            if (!in_array($e->reason, self::NOT_AUDITED, true)) {
                $this->auditFailure($user, $session, $operation, $run, $e->reason);
            }
            throw $e;
        }
    }

    private function auditFailure(int $user, int $session, string $operation, string $run, string $code): void
    {
        try {
            $this->rt->db->transaction(function () use ($user, $session, $operation, $run, $code): void {
                $r = $this->peek($run);
                PayrollAudit::write($this->rt->db, $user, 'payroll.failed_transition', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id,
                    ['run' => (string) $r->public_id, 'operation' => $operation, 'error' => $code, 'status' => (string) $r->status], null, $session);
            });
        } catch (Throwable) {
            // The audit of a refusal never replaces the refusal itself.
        }
    }

    /** Canonical input hash of the run as of now; null when the inputs are no longer resolvable (rule missing / ambiguous / invalid). */
    public function currentHash(object $r, bool $lock): ?string
    {
        try {
            $payload = PayrollInputHash::payload($this->rt->db, (int) $r->employing_unit_id, $this->periodOf($r), (string) $r->run_kind, (int) $r->sequence, $lock);
        } catch (FinanceError $e) {
            if (str_starts_with($e->reason, 'PAYROLL_RULE_')) {
                return null;
            }
            throw $e;
        }
        return PayrollInputHash::hash($payload);
    }

    /** Σ lines by class = run totals, net = gross - deductions, headcount = distinct employments (D26 invariant). */
    private function assertCoherent(int $runId): void
    {
        // LOCKING reads: the invariant is decided on the latest committed rows, never on an older snapshot.
        $r = $this->rt->db->table('payroll_runs')->where('id', $runId)->sharedLock()->first();
        $sums = $this->rt->db->table('payroll_run_lines as l')->join('compensation_component_types as t', 't.id', '=', 'l.component_type_id')->where('l.run_id', $runId)
            ->groupBy('t.nature')->selectRaw('t.nature, SUM(l.amount) AS total')->sharedLock()->pluck('total', 'nature');
        $headcount = (int) $this->rt->db->table('payroll_run_lines')->where('run_id', $runId)->sharedLock()->distinct()->count('employment_id');
        $expect = fn (string $nature) => PayrollMoney::fromStorage((string) ($sums[$nature] ?? '0'));
        $ok = $expect(PayrollCatalog::EARNING) === PayrollMoney::fromStorage((string) $r->gross_amount)
            && $expect(PayrollCatalog::EMPLOYEE_DEDUCTION) === PayrollMoney::fromStorage((string) $r->deductions_amount)
            && $expect(PayrollCatalog::EMPLOYER_CHARGE) === PayrollMoney::fromStorage((string) $r->employer_charges_amount)
            && bcsub((string) $r->gross_amount, (string) $r->deductions_amount, 4) === bcadd((string) $r->net_amount, '0', 4)
            && $headcount === (int) $r->headcount;
        if (!$ok) {
            throw new FinanceError('INVARIANT_VIOLATION', [], ['reason' => 'payroll_totals_incoherent']);
        }
    }

    /**
     * D27 aggregated accrual: Dr OPERATING_EXPENSE x rubric (earnings and employer charges, by the rubric's ledger account),
     * Cr PAYROLL_WITHHOLDINGS / PAYROLL_EMPLOYER_CHARGES (by the component's liability role), Cr PAYROLL_NET_PAYABLE (net).
     * Never per employee: no person, employment or individual amount reaches the journal.
     */
    public function accrualLines(object $r): array
    {
        $rows = $this->rt->db->table('payroll_run_lines as l')->join('compensation_component_types as t', 't.id', '=', 'l.component_type_id')
            ->leftJoin('financial_categories as c', 'c.id', '=', 't.expense_category_id')->where('l.run_id', $r->id)
            ->groupBy('t.nature', 't.liability_role', 'c.code', 'c.ledger_account_id')->orderBy('t.nature')->orderBy('c.code')->orderBy('t.liability_role')
            ->selectRaw('t.nature, t.liability_role, c.code AS rubric, c.ledger_account_id, SUM(l.amount) AS total')->get();
        $debits = [];
        $credits = [];
        foreach ($rows as $row) {
            $total = PayrollMoney::fromStorage((string) $row->total);
            if (in_array($row->nature, [PayrollCatalog::EARNING, PayrollCatalog::EMPLOYER_CHARGE], true)) {
                if ($row->rubric === null || $row->ledger_account_id === null) {
                    throw new FinanceError('PAYROLL_MAPPING_MISSING', [(string) $row->nature], ['reason' => 'expense_rubric']);
                }
                $key = $row->rubric;
                $debits[$key] = ['ledger_account_id' => (int) $row->ledger_account_id, 'category' => (string) $row->rubric, 'debit' => bcadd($debits[$key]['debit'] ?? '0', $total, 2),
                    'description' => 'Gastos com pessoal — ' . $row->rubric];
            }
            if (in_array($row->nature, [PayrollCatalog::EMPLOYEE_DEDUCTION, PayrollCatalog::EMPLOYER_CHARGE], true)) {
                if ($row->liability_role === null) {
                    throw new FinanceError('PAYROLL_MAPPING_MISSING', [(string) $row->nature], ['reason' => 'liability_role']);
                }
                $key = (string) $row->liability_role;
                $credits[$key] = ['account' => $key, 'credit' => bcadd($credits[$key]['credit'] ?? '0', $total, 2), 'description' => FinanceCatalog::CHART[$key][2] ?? $key];
            }
        }
        $credits['PAYROLL_NET_PAYABLE'] = ['account' => 'PAYROLL_NET_PAYABLE', 'credit' => PayrollMoney::fromStorage((string) $r->net_amount), 'description' => 'Salários líquidos a pagar'];
        ksort($debits, SORT_STRING);
        ksort($credits, SORT_STRING);
        return array_values(array_filter([...array_values($debits), ...array_values($credits)], fn ($l) => bccomp($l['debit'] ?? $l['credit'], '0', 2) > 0));
    }

    private function insertLines(int $runId, array $result): void
    {
        $employments = $this->rt->db->table('employments')->whereIn('public_id', array_column($result['employees'], 'employment'))->pluck('id', 'public_id');
        $components = $this->rt->db->table('compensation_component_types')->pluck('id', 'code');
        $now = $this->rt->ts();
        $rows = [];
        foreach ($result['employees'] as $employee) {
            foreach ($employee['lines'] as $line) {
                $rows[] = ['run_id' => $runId, 'employment_id' => (int) $employments[$employee['employment']], 'component_type_id' => (int) $components[$line['component']], 'base_amount' => $line['base_amount'],
                    'rate' => $line['rate'], 'amount' => $line['amount'], 'source' => $line['source'],
                    'rule_id' => $line['rule'] === null ? null : (int) $this->rt->db->table('payroll_rules')->where('code', $line['rule']['code'])->where('version', $line['rule']['version'])->value('id'),
                    'created_at' => $now];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $this->rt->db->table('payroll_run_lines')->insert($chunk);
        }
    }

    /** "Não duplicar Pessoa com dois vínculos incompatíveis": one Person appears at most once in a run's population. */
    private function assertOnePersonOnce(array $payload): void
    {
        $ids = array_column($payload['employments'], 'public_id');
        if ($ids === []) {
            return;
        }
        $people = $this->rt->db->table('employments')->whereIn('public_id', $ids)->select('person_id')->groupBy('person_id')->havingRaw('COUNT(*) > 1')->get();
        if ($people->isNotEmpty()) {
            throw new FinanceError('PAYROLL_POPULATION_CONFLICT', [], ['reason' => 'person_with_two_employments_in_period']);
        }
    }

    /** @return array<string, array{nature: string, method: string}> */
    private function componentCatalog(): array
    {
        $out = [];
        foreach ($this->rt->db->table('compensation_component_types')->get(['code', 'nature', 'calculation_method']) as $c) {
            $out[(string) $c->code] = ['nature' => (string) $c->nature, 'method' => (string) $c->calculation_method];
        }
        return $out;
    }

    /**
     * D29: the accrual references the posting period. Default = the service month's last day (or today while the month
     * runs). A date in another month needs a reason (deferred posting of a closed service month); never before the service
     * month starts, never in the future; the period must be OPEN for the unit (LedgerPostingService => PERIOD_CLOSED).
     */
    private function accrualDate(mixed $requested, object $period): string
    {
        if ($requested === null || $requested === '') {
            $today = $this->rt->today();
            $date = min((string) $period->ends_on, $today);
            if ($date < (string) $period->starts_on) {
                throw new FinanceError('PAYROLL_SERVICE_MONTH_NOT_STARTED');
            }
            return $date;
        }
        [$date] = $this->rt->instantFor($this->date($requested, 'entry_date'));
        if ($date < (string) $period->starts_on) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'entry_date', 'reason' => 'before_service_month']);
        }
        return $date;
    }

    private function entryDescription(object $r, string $period, string $stage): string
    {
        return sprintf('Folha salarial %s %s n.º %d — %s (folha %s)', $period, $r->run_kind, (int) $r->sequence, $stage, $r->public_id);
    }

    private function peek(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->rt->db->table('payroll_runs')->where('public_id', $publicId)->first() : null;
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'payroll_runs']);
        }
        return $row;
    }

    private function lockRun(int $id): object
    {
        return $this->rt->db->table('payroll_runs')->where('id', $id)->lockForUpdate()->first();
    }

    private function assertLockVersion(object $r, array $in): void
    {
        if (array_key_exists('lock_version', $in) && $in['lock_version'] !== null && (int) $in['lock_version'] !== (int) $r->lock_version) {
            throw new FinanceError('STALE_WRITE');
        }
    }

    /** LOCKING read: after waiting on the run lock a snapshot read could miss the competitor's committed posting. */
    private function posting(int $runId, string $stage): ?object
    {
        return $this->rt->db->table('payroll_postings')->where('run_id', $runId)->where('stage', $stage)->sharedLock()->first();
    }

    private function recordPosting(int $runId, string $stage, int $entryId): void
    {
        $this->rt->db->table('payroll_postings')->insert(['run_id' => $runId, 'stage' => $stage, 'entry_id' => $entryId, 'created_at' => $this->rt->ts(), 'lock_version' => 0]);
    }

    private function entryPublic(int $entryId): string
    {
        // LOCKING read: on a replay after a lock wait the competitor's entry is newer than this transaction's snapshot.
        return (string) $this->rt->db->table('journal_entries')->where('id', $entryId)->sharedLock()->value('public_id');
    }

    private function periodRow(int $periodId): object
    {
        return $this->rt->db->table('accounting_periods')->where('id', $periodId)->first();
    }

    private function periodOf(object $r): string
    {
        return (string) $this->periodRow((int) $r->period_id)->code;
    }

    private function priorRequest(TerritorialActor $actor, string $clientKey, string $hash): ?array
    {
        $prior = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', self::OP_CREATE)->where('client_key', $clientKey)->first();
        if ($prior === null) {
            return null;
        }
        if (!hash_equals($prior->request_hash, $hash)) {
            throw new FinanceError('IDEMPOTENCY_CONFLICT');
        }
        return $prior->status === 'COMPLETED' ? $this->replayOf((string) $prior->result_public_id) : null;
    }

    /** @return int|array claim id, or the replay of a completed twin */
    private function claimRequest(TerritorialActor $actor, string $clientKey, string $hash): int|array
    {
        $this->rt->db->table('idempotency_requests')->insertOrIgnore(['actor_id' => $actor->user, 'operation' => self::OP_CREATE, 'client_key' => $clientKey, 'request_hash' => $hash,
            'status' => 'PROCESSING', 'result_public_id' => null, 'expires_at' => null, 'created_at' => $this->rt->ts(), 'lock_version' => 0]);
        $claim = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', self::OP_CREATE)->where('client_key', $clientKey)->lockForUpdate()->first();
        if (!hash_equals($claim->request_hash, $hash)) {
            throw new FinanceError('IDEMPOTENCY_CONFLICT');
        }
        return $claim->status === 'COMPLETED' ? $this->replayOf((string) $claim->result_public_id) : (int) $claim->id;
    }

    private function replayOf(string $publicId): array
    {
        $r = $this->rt->db->table('payroll_runs')->where('public_id', $publicId)->first(['public_id', 'status', 'sequence']);
        return ['public_id' => (string) $r->public_id, 'status' => (string) $r->status, 'sequence' => (int) $r->sequence, 'replayed' => true];
    }
}
