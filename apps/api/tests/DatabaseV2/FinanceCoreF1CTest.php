<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\LedgerPostingService;
use App\Domain\Finance\LedgerQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseV2\Support\FinanceHttpCase;

/**
 * P0.10-F1C accrual subledgers, financial accounts, bank statements / reconciliation, budget and period closes (ADR 0021
 * D01/D05/D06/D07/D11/D12/D13/D14/D17/D20/D30 + D-04A), HTTP level against an isolated Wave 5 pool.
 * A01-A10 accrual (A10 = C10, real processes), B01-B08 bank (B08 = BC1 duplicate match race), U01-U04 accounts,
 * G01-G08 budget (G06 = C4), P01-P06 closes (P06 = PC1 close vs accrual posting, both orders). Concurrency uses REAL
 * processes (scripts/p010-f1c-worker.php) with deterministic ordering: the first connection HOLDS its business
 * transaction, the second is OBSERVED in performance_schema.data_lock_waits blocked by it, then the first commits.
 * The class TRUNCATE-resets its pool once; every test builds its own world (a mutation probe can run one alone).
 * Months 2026-01, 2026-02 and 2026-03 are reserved to the national close tests (P05, P07, N05): no other test posts in them.
 */
final class FinanceCoreF1CTest extends FinanceHttpCase
{
    private const OFFICER = ['FINANCE_VIEW', 'FINANCE_MANAGE', 'FINANCE_POST', 'FINANCE_REVERSE', 'FINANCE_ACCOUNT_MANAGE', 'FINANCE_TRANSFER', 'FINANCE_RECONCILE',
        'FINANCE_BUDGET_MANAGE', 'FINANCE_PERIOD_CLOSE', 'DOCUMENTS_VIEW', 'PEOPLE_VIEW'];

    // ---- A accrual --------------------------------------------------------------------------------------------------

    public function test_a01_receivable_is_recognised_as_revenue_without_cash(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $key = $this->key();
        $body = $this->receivableBody($w['a1'], '250.00');
        $r = $this->fpost($o, 'finance/receivables', $body, $key)->assertCreated()->assertJsonPath('meta.replayed', false)->json('data');
        $this->assertSame(['RECEIVABLE', 'RECOGNIZED', '250.00', '0.00', '250.00', 'REV_OTHER'], [$r['kind'], $r['status'], $r['amount'], $r['settled'], $r['outstanding'], $r['category']['code']]);
        $this->assertSame(['EXTERNAL', 'Cliente Externo'], [$r['party']['kind'], $r['party']['name']]);
        $this->assertNoInternalIds($r);
        $this->assertSame([['RECEIVABLES', 'ASSET', null, '250.0000', '0.0000'], ['OPERATING_INCOME', 'INCOME', 'REV_OTHER', '0.0000', '250.0000']], $this->lines($r['recognition_entry']));
        $this->assertSame(['RECEIVABLE_RECOGNITION', 'POSTED'], $this->entryKind($r['recognition_entry']));
        $this->assertSame(25000, $this->income($w['a1']));
        $this->assertSame(0, $this->balance($w['cash_a1']), 'no cash moves at recognition');
        $this->assertSame(0, (int) DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.unit_id', $w['a1']['id'])->whereIn('a.system_role', ['CASH', 'BANK'])->count());
        // Idempotency and closed input.
        $this->fpost($o, 'finance/receivables', $body, $key)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.public_id', $r['public_id']);
        $this->fpost($o, 'finance/receivables', ['amount' => '1.00'] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, DB::table('receivables')->where('unit_id', $w['a1']['id'])->count());
        $this->fpost($o, 'finance/receivables', ['category' => 'REV_TITHES'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_NOT_RECEIVABLE');
        $this->fpost($o, 'finance/receivables', ['category' => 'REV_DONATIONS'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_NOT_RECEIVABLE');
        $this->fpost($o, 'finance/receivables', ['category' => 'TRF_REMITTANCE'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'INTERNAL_COUNTERPARTY');
        $this->fpost($o, 'finance/receivables', ['party' => ['kind' => 'UNIT']] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'INTERNAL_COUNTERPARTY');
        $this->fpost($o, 'finance/receivables', ['category' => 'ADM_WATER'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.details.fields.category.0', 'invalid');
        $this->fpost($o, 'finance/receivables', ['amount' => 12.5] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->fpost($o, 'finance/receivables', ['amount' => '1.005'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_SCALE');
        $this->fpost($o, 'finance/receivables', ['status' => 'SETTLED'] + $body, $this->key())->assertStatus(422);
        $this->fpost($o, 'finance/receivables', ['unit_id' => $w['a1']['id']] + $body, $this->key())->assertStatus(422);
        $this->fpost($o, 'finance/receivables', $body)->assertStatus(422)->assertJsonPath('error.details.fields.idempotency_key.0', 'invalid');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.receivable_recognized')->where('unit_id', $w['a1']['id'])->count());
        $this->assertLedgerInvariants();
    }

    public function test_a02_settling_a_receivable_moves_cash_against_the_receivable_only(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $r = $this->receivable($o, $w['a1'], '250.00');
        $income = $this->income($w['a1']);
        $s = $this->settle($o, 'receivables', $r['public_id'], $w['cash_a1'], '250.00');
        $this->assertSame(['RECEIPT', 'POSTED', '250.00', $r['public_id']], [$s['direction'], $s['status'], $s['amount'], $s['receivable']]);
        $this->assertSame([['CASH', 'ASSET', null, '250.0000', '0.0000'], ['RECEIVABLES', 'ASSET', null, '0.0000', '250.0000']], $this->lines($s['entry']));
        $detail = $this->api($o, 'GET', 'finance/receivables/' . $r['public_id'])->assertOk()->json('data');
        $this->assertSame(['SETTLED', '250.00', '0.00'], [$detail['status'], $detail['settled'], $detail['outstanding']]);
        $this->assertSame($income, $this->income($w['a1']), 'the settlement never recognises revenue again');
        $this->assertSame(25000, $this->balance($w['cash_a1']));
        $this->fpost($o, 'finance/receivables/' . $r['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '1.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_SETTLED');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.settlement_posted')->where('unit_id', $w['a1']['id'])->count());
        $this->assertLedgerInvariants();
    }

    public function test_a03_partial_receivable_settlements_until_settled(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $r = $this->receivable($o, $w['a1'], '300.00');
        $this->settle($o, 'receivables', $r['public_id'], $w['cash_a1'], '100.00');
        $mid = $this->api($o, 'GET', 'finance/receivables/' . $r['public_id'])->json('data');
        $this->assertSame(['RECOGNIZED', '100.00', '200.00'], [$mid['status'], $mid['settled'], $mid['outstanding']]);
        $this->assertSame(['settle'], $mid['actions'], 'a partly settled receivable can still be settled, never cancelled');
        $this->settle($o, 'receivables', $r['public_id'], $w['cash_a1'], '200.00');
        $end = $this->api($o, 'GET', 'finance/receivables/' . $r['public_id'])->json('data');
        $this->assertSame(['SETTLED', '300.00', '0.00'], [$end['status'], $end['settled'], $end['outstanding']]);
        $this->assertCount(2, $end['settlements']);
        $this->assertSame(30000, $this->balance($w['cash_a1']));
        $this->assertSame(30000, $this->income($w['a1']), 'revenue recognised once, at recognition');
    }

    public function test_a04_over_settlement_is_denied(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $r = $this->receivable($o, $w['a1'], '100.00');
        $this->settle($o, 'receivables', $r['public_id'], $w['cash_a1'], '60.00');
        $this->fpost($o, 'finance/receivables/' . $r['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '60.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'OVER_SETTLEMENT');
        $this->fpost($o, 'finance/receivables/' . $r['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '0.00'], $this->key())->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_NOT_POSITIVE');
        $this->fpost($o, 'finance/receivables/' . $r['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '-1.00'], $this->key())->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_NOT_POSITIVE');
        $this->assertSame('40.00', $this->api($o, 'GET', 'finance/receivables/' . $r['public_id'])->json('data.outstanding'));
        $p = $this->payable($o, $w['a1'], '50.00');
        $this->contribute($o, $w['cash_a1'], '100.00');
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '50.01'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'OVER_SETTLEMENT');
        $this->assertSame(0, DB::table('settlement_allocations')->where('payable_id', DB::table('payables')->where('public_id', $p['public_id'])->value('id'))->count());
        $this->assertLedgerInvariants();
    }

    public function test_a05_payable_is_recognised_as_expense_without_cash_with_its_document(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $invoice = $this->document($w['a1'], 'INVOICE');
        $body = $this->payableBody($w['a1'], '400.00', 'ADM_ELECTRICITY', $invoice['public_id']);
        $p = $this->fpost($o, 'finance/payables', $body, $this->key())->assertCreated()->json('data');
        $this->assertSame(['PAYABLE', 'RECOGNIZED', '400.00', '400.00', false, $invoice['public_id']], [$p['kind'], $p['status'], $p['amount'], $p['outstanding'], $p['capitalized'], $p['document']['public_id']]);
        $this->assertSame([['OPERATING_EXPENSE', 'EXPENSE', 'ADM_ELECTRICITY', '400.0000', '0.0000'], ['PAYABLES', 'LIABILITY', null, '0.0000', '400.0000']], $this->lines($p['recognition_entry']));
        $this->assertSame(-40000, $this->resultOf($w['a1']));
        $this->assertSame(0, $this->balance($w['cash_a1']), 'no cash at recognition');
        $this->assertSame('RECOGNIZED', DB::table('workflow_instances')->where('id', DB::table('payables')->where('public_id', $p['public_id'])->value('workflow_instance_id'))->value('status'));
        // D14: the payable always carries a supporting document (public id only, never a key): a numeric key is refused
        // as a field even next to a valid public id.
        foreach (['document_id', 'source_document_id'] as $field) {
            $this->fpost($o, 'finance/payables', [$field => $invoice['id']] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.details.fields.' . $field . '.0', 'This field is not allowed.');
        }
        unset($body['document']);
        $this->fpost($o, 'finance/payables', $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'PAYABLE_DOCUMENT_REQUIRED');
        $this->fpost($o, 'finance/payables', ['document' => $this->document($w['a1'], 'BANK_STATEMENT')['public_id']] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'PAYABLE_DOCUMENT_REQUIRED');
        $this->assertConcealed($this->fpost($o, 'finance/payables', ['document' => $this->document($w['b1'], 'INVOICE')['public_id']] + $body, $this->key()));
        $this->assertConcealed($this->fpost($o, 'finance/payables', ['document' => (string) Str::ulid()] + $body, $this->key()));
        $this->assertSame(1, DB::table('payables')->where('unit_id', $w['a1']['id'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.payable_recognized')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_a06_a_capitalisable_acquisition_debits_fixed_assets_never_expense(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $p = $this->payable($o, $w['a1'], '1000.00', 'INV_AST_IT');
        $this->assertSame([true, 'FIXED_ASSETS'], [$p['capitalized'], $p['economic_account']]);
        $this->assertSame([['FIXED_ASSETS', 'ASSET', 'INV_AST_IT', '1000.0000', '0.0000'], ['PAYABLES', 'LIABILITY', null, '0.0000', '1000.0000']], $this->lines($p['recognition_entry']));
        $this->assertSame(0, $this->resultOf($w['a1']), 'a capitalised acquisition is not an expense');
        $consumed = $this->payable($o, $w['a1'], '300.00', 'INV_COM_DIGITAL');
        $this->assertSame([false, 'INVESTMENT_EXPENSE'], [$consumed['capitalized'], $consumed['economic_account']]);
        $this->assertSame(-30000, $this->resultOf($w['a1']), 'a consumed investment is in the result');
        $this->assertSame(30000, (new LedgerQueries(DB::connection()))->economicResult($w['a1']['id'], '2026-01-01', '2026-12-31')['investment_consumed']);
    }

    public function test_a07_settling_a_payable_pays_from_cash(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00');
        $p = $this->payable($o, $w['a1'], '400.00');
        $s = $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '400.00');
        $this->assertSame(['PAYMENT', $p['public_id']], [$s['direction'], $s['payable']]);
        $this->assertSame([['PAYABLES', 'LIABILITY', null, '400.0000', '0.0000'], ['CASH', 'ASSET', null, '0.0000', '400.0000']], $this->lines($s['entry']));
        $this->assertSame('SETTLED', $this->api($o, 'GET', 'finance/payables/' . $p['public_id'])->json('data.status'));
        $this->assertSame('SETTLED', DB::table('workflow_instances')->where('id', DB::table('payables')->where('public_id', $p['public_id'])->value('workflow_instance_id'))->value('status'));
        $this->assertSame(60000, $this->balance($w['cash_a1']));
        // The settlement account must be an OPEN account of the payable's unit (another unit's account is concealed).
        $q = $this->payable($o, $w['a1'], '10.00');
        $this->assertConcealed($this->fpost($o, 'finance/payables/' . $q['public_id'] . '/settlements', ['account' => $w['cash_b1']['public_id'], 'amount' => '10.00'], $this->key()));
    }

    public function test_a08_payment_never_duplicates_the_expense(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00');
        $expenseBefore = $this->expense($w['a1']);
        $p = $this->payable($o, $w['a1'], '400.00');
        $this->assertSame($expenseBefore + 40000, $this->expense($w['a1']));
        $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '150.00');
        $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '250.00');
        $this->assertSame($expenseBefore + 40000, $this->expense($w['a1']), 'payment is not a second expense');
        $this->assertSame(1, (int) DB::selectOne("SELECT COUNT(*) AS n FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.ledger_account_id WHERE l.unit_id = ? AND a.account_kind = 'EXPENSE' AND e.status = 'POSTED'", [$w['a1']['id']])->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.ledger_account_id WHERE e.entry_kind = 'SETTLEMENT' AND a.account_kind IN ('INCOME','EXPENSE')")->n);
        $this->assertLedgerInvariants();
    }

    public function test_a09_settlement_cancellation_is_an_own_flow_reversal_and_restores_the_outstanding(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00');
        $p = $this->payable($o, $w['a1'], '400.00');
        $s = $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '400.00');
        $original = $this->entryRows($s['entry']);
        $this->fpost($o, 'finance/settlements/' . $s['public_id'] . '/cancel', ['reason' => ''])->assertStatus(422);
        $this->fpost($this->officer($w['a1'], [], ['FINANCE_REVERSE']), 'finance/settlements/' . $s['public_id'] . '/cancel', ['reason' => 'Pagamento duplicado'])->assertStatus(403);
        $c = $this->fpost($o, 'finance/settlements/' . $s['public_id'] . '/cancel', ['reason' => 'Pagamento lançado na conta errada'])->assertOk()->assertJsonPath('meta.replayed', false)->json('data');
        $this->assertSame(['CANCELLED', 'Pagamento lançado na conta errada'], [$c['status'], $c['cancel_reason']]);
        $reversal = DB::table('journal_entries')->where('public_id', $c['cancellation_entry'])->first();
        $this->assertSame(['REVERSAL', 'POSTED', (int) DB::table('journal_entries')->where('public_id', $s['entry'])->value('id')], [$reversal->entry_kind, $reversal->status, (int) $reversal->reversal_of_id]);
        $this->assertSame([['PAYABLES', 'LIABILITY', null, '0.0000', '400.0000'], ['CASH', 'ASSET', null, '400.0000', '0.0000']], $this->lines($c['cancellation_entry']));
        $this->assertSame($original, $this->entryRows($s['entry']), 'the POSTED settlement entry is never edited');
        $detail = $this->api($o, 'GET', 'finance/payables/' . $p['public_id'])->json('data');
        $this->assertSame(['RECOGNIZED', '0.00', '400.00'], [$detail['status'], $detail['settled'], $detail['outstanding']]);
        $this->assertSame(100000, $this->balance($w['cash_a1']));
        $this->assertSame(100000 - 40000, $this->resultOf($w['a1']), 'contribution 1000 - expense 400: the expense stays recognised once');
        $this->fpost($o, 'finance/settlements/' . $s['public_id'] . '/cancel', ['reason' => 'De novo'])->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame(1, DB::table('journal_entries')->where('reversal_of_id', $reversal->reversal_of_id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.settlement_cancelled')->where('unit_id', $w['a1']['id'])->where('reason', 'Pagamento lançado na conta errada')->count());
        // The generic ledger reversal never reaches a subledger entry (D11: SUBLEDGER_OWNED).
        try {
            (new LedgerPostingService(DB::connection()))->reverse($o['user'], $this->key(), $s['entry'], 'Tentativa genérica', now('Africa/Luanda')->format('Y-m-d'));
            $this->fail('generic reversal of a SETTLEMENT must be refused');
        } catch (FinanceError $e) {
            $this->assertSame('SUBLEDGER_OWNED', $e->reason);
        }
        // Cancelling the recognition: refused while a POSTED settlement exists, then an own-flow reversal.
        $partial = $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '100.00');
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/cancel', ['reason' => 'Factura anulada'])->assertStatus(409)->assertJsonPath('error.code', 'HAS_SETTLEMENTS');
        $this->fpost($o, 'finance/settlements/' . $partial['public_id'] . '/cancel', ['reason' => 'Anulado'])->assertOk();
        $cancelled = $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/cancel', ['reason' => 'Factura anulada pelo fornecedor'])->assertOk()->json('data');
        $this->assertSame(['CANCELLED', '0.00'], [$cancelled['status'], $cancelled['outstanding']]);
        $this->assertSame('REVERSAL', $this->entryKind($cancelled['cancellation_entry'])[0]);
        $this->assertSame(100000, $this->resultOf($w['a1']), 'the cancelled recognition leaves no expense (only the contribution)');
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '1.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertLedgerInvariants();
    }

    public function test_a10_c10_concurrent_settlements_never_exceed_the_outstanding(): void
    {
        $w = $this->world();
        $o1 = $this->officer($w['a1']);
        $o2 = $this->officer($w['a1']);
        $this->contribute($o1, $w['cash_a1'], '500.00');
        $p = $this->payable($o1, $w['a1'], '100.00');
        [$first, $second, $wait] = $this->lockOrdered(
            ['op' => 'settle', 'kind' => 'payables', 'target' => $p['public_id'], 'user' => $o1['user'], 'session' => $o1['session'], 'body' => ['account' => $w['cash_a1']['public_id'], 'amount' => '60.00']],
            ['op' => 'settle', 'kind' => 'payables', 'target' => $p['public_id'], 'user' => $o2['user'], 'session' => $o2['session'], 'body' => ['account' => $w['cash_a1']['public_id'], 'amount' => '60.00']]);
        $this->assertSame(['OK', 'OVER_SETTLEMENT'], [$first['status'], $second['status']], json_encode([$first, $second]));
        $this->assertSame(['payables', true], [$wait['object'], $wait['blocked_by_first']]);
        $payable = DB::table('payables')->where('public_id', $p['public_id'])->first();
        $allocated = (string) DB::table('settlement_allocations as a')->join('settlements as s', 's.id', '=', 'a.settlement_id')->where('a.payable_id', $payable->id)->where('s.status', 'POSTED')->sum('a.amount');
        $this->assertSame(['60.0000', 'RECOGNIZED', 44000], [$allocated, $payable->status, $this->balance($w['cash_a1'])], 'never outstanding < 0');
        // Receivable side, same race.
        $r = $this->receivable($o1, $w['a1'], '100.00');
        [$rf, $rs, $rw] = $this->lockOrdered(
            ['op' => 'settle', 'kind' => 'receivables', 'target' => $r['public_id'], 'user' => $o1['user'], 'session' => $o1['session'], 'body' => ['account' => $w['cash_a1']['public_id'], 'amount' => '60.00']],
            ['op' => 'settle', 'kind' => 'receivables', 'target' => $r['public_id'], 'user' => $o2['user'], 'session' => $o2['session'], 'body' => ['account' => $w['cash_a1']['public_id'], 'amount' => '60.00']]);
        $this->assertSame(['OK', 'OVER_SETTLEMENT', 'receivables', true], [$rf['status'], $rs['status'], $rw['object'], $rw['blocked_by_first']]);
        $this->assertSame('40.00', $this->api($o1, 'GET', 'finance/receivables/' . $r['public_id'])->json('data.outstanding'));
        $this->assertLedgerInvariants();
        $this->evidence('C10', ['payable' => compact('first', 'second', 'wait'), 'receivable' => ['first' => $rf, 'second' => $rs, 'wait' => $rw]]);
    }

    // ---- B bank statements / reconciliation ------------------------------------------------------------------------

    public function test_b01_bank_statement_is_imported_once_with_its_file_and_balances(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->account($w['a1'], 'BANK');
        $doc = $this->document($w['a1'], 'BANK_STATEMENT');
        $key = $this->key();
        $body = $this->statementBody($bank, $doc, '0.00', '1100.00', [['2026-09-01', '1000.00', 'Depósito inicial', 'DEP-1'], ['2026-09-10', '-200.00', 'Pagamento fornecedor', null], ['2026-09-20', '300.00', 'Recebimento cliente', 'TRF-9']]);
        $s = $this->fpost($o, 'finance/bank-statements', $body, $key)->assertCreated()->json('data');
        $this->assertSame([$bank['public_id'], '2026-09-01', '2026-09-30', '0.00', '1100.00', 3, 'EXTERNAL_BANK_FACT', $doc['public_id']],
            [$s['account']['public_id'], $s['starts_on'], $s['ends_on'], $s['opening_balance'], $s['closing_balance'], $s['lines_count'], $s['origin'], $s['document']['public_id']]);
        $this->assertSame(64, strlen($s['source_hash']));
        $this->assertNoInternalIds($s);
        $this->fpost($o, 'finance/bank-statements', $body, $key)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.public_id', $s['public_id']);
        $this->fpost($o, 'finance/bank-statements', $body, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'STATEMENT_ALREADY_IMPORTED');
        $other = $this->document($w['a1'], 'BANK_STATEMENT');
        $this->fpost($o, 'finance/bank-statements', ['document' => $other['public_id'], 'closing_balance' => '1099.99'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'STATEMENT_UNBALANCED');
        $this->fpost($o, 'finance/bank-statements', ['document' => $this->document($w['a1'], 'RECEIPT')['public_id']] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'STATEMENT_DOCUMENT_REQUIRED');
        $this->fpost($o, 'finance/bank-statements', ['document' => $other['public_id'], 'account' => $w['cash_a1']['public_id']] + $body, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_NOT_BANK');
        $outside = $this->statementBody($bank, $other, '0.00', '5.00', [['2026-10-01', '5.00', 'Fora do intervalo', null]], '2026-09-01', '2026-09-30');
        $this->fpost($o, 'finance/bank-statements', $outside, $this->key())->assertStatus(422)->assertJsonPath('error.details.fields.lines.0', 'invalid');
        $this->fpost($o, 'finance/bank-statements', array_diff_key($body, ['document' => 1]), $this->key())->assertStatus(422);
        $this->assertConcealed($this->fpost($o, 'finance/bank-statements', ['account' => $this->account($w['b1'], 'BANK')['public_id'], 'document' => $other['public_id']] + $body, $this->key()));
        $this->assertSame(1, DB::table('bank_statements')->where('account_id', $bank['id'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.bank_statement_imported')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_b02_statement_lines_preserve_the_bank_fact_and_are_never_edited(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->account($w['a1'], 'BANK');
        $s = $this->statement($o, $bank, '50.00', '120.00', [['2026-09-03', '100.00', 'Depósito', 'D-1'], ['2026-09-04', '-30.00', 'Tarifa', null]]);
        $rows = DB::table('bank_statement_lines')->where('statement_id', DB::table('bank_statements')->where('public_id', $s['public_id'])->value('id'))->orderBy('line_number')
            ->get(['line_number', 'occurred_on', 'amount_signed', 'description', 'external_reference'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame([['line_number' => 1, 'occurred_on' => '2026-09-03', 'amount_signed' => '100.0000', 'description' => 'Depósito', 'external_reference' => 'D-1'],
            ['line_number' => 2, 'occurred_on' => '2026-09-04', 'amount_signed' => '-30.0000', 'description' => 'Tarifa', 'external_reference' => null]], $rows);
        $detail = $this->api($o, 'GET', 'finance/bank-statements/' . $s['public_id'])->assertOk()->json('data');
        $this->assertSame([['IN', '100.00', 'UNMATCHED'], ['OUT', '-30.00', 'UNMATCHED']], array_map(fn ($l) => [$l['direction'], $l['amount'], $l['state']], $detail['lines']));
        // No edit path exists for a statement or its lines (immutable provenance).
        foreach (['PATCH', 'PUT', 'DELETE'] as $method) {
            $this->assertContains($this->api($o, $method, 'finance/bank-statements/' . $s['public_id'])->status(), [404, 405]);
        }
        $this->assertContains($this->fpost($o, 'finance/bank-statements/' . $s['public_id'] . '/lines', ['lines' => []])->status(), [404, 405]);
        try {
            DB::table('bank_statement_lines')->insert(['statement_id' => DB::table('bank_statements')->where('public_id', $s['public_id'])->value('id'), 'line_number' => 3, 'occurred_on' => '2026-09-05',
                'amount_signed' => '0.0000', 'description' => 'Zero', 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
            $this->fail('CHECK ck_bank_statement_lines_amount must refuse a zero line');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('ck_bank_statement_lines_amount', $e->getMessage());
        }
    }

    public function test_b03_a_statement_line_is_not_a_journal_line(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->account($w['a1'], 'BANK');
        $entries = DB::table('journal_entries')->count();
        $lines = DB::table('journal_lines')->count();
        $this->statement($o, $bank, '0.00', '700.00', [['2026-09-05', '1000.00', 'Depósito', null], ['2026-09-06', '-300.00', 'Levantamento', null]]);
        $this->assertSame([$entries, $lines], [DB::table('journal_entries')->count(), DB::table('journal_lines')->count()], 'importing a statement writes nothing to the ledger');
        $this->assertSame(0, $this->balance($bank), 'the canonical balance comes only from POSTED journal lines');
        $this->assertSame(0, $this->income($w['a1']));
        $columns = array_map(fn ($c) => $c->COLUMN_NAME, DB::select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_statement_lines'"));
        $this->assertSame([], array_values(array_filter($columns, fn ($c) => str_contains($c, 'journal') || str_contains($c, 'entry'))), 'no ledger reference on a bank fact');
    }

    public function test_b04_a_valid_bank_match_and_its_refusals(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->openedBank($o, $w['a1'], '1000.00', '2026-09-01');
        $p = $this->payable($o, $w['a1'], '200.00', 'ADM_ELECTRICITY', null, ['recognized_on' => '2026-09-08']);
        $paid = $this->settle($o, 'payables', $p['public_id'], $bank, '200.00', '2026-09-10');
        $s = $this->statement($o, $bank, '0.00', '800.00', [['2026-09-01', '1000.00', 'Depósito inicial', null], ['2026-09-11', '-200.00', 'Pagamento', null]]);
        $rec = $this->reconcile($o, $bank, '2026-09', $s);
        $this->assertSame(['OPEN', 1, '2026-09'], [$rec['status'], $rec['version'], $rec['period']]);
        $opening = $bank['opening_entry'];
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 2, 'entry' => $opening, 'entry_line' => 1, 'amount' => '200.00'])->assertStatus(409)->assertJsonPath('error.code', 'MATCH_DIRECTION_MISMATCH');
        $m = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $opening, 'entry_line' => 1, 'amount' => '1000.00'])->assertOk()->json('data');
        $this->assertSame(['MATCHED', '1000.00'], [$m['statement_lines'][0]['state'], $m['statement_lines'][0]['matched_here']]);
        $this->assertSame('MATCHED', collect($m['ledger_lines'])->firstWhere('entry', $opening)['state']);
        $cashLine = DB::table('journal_lines')->where('entry_id', DB::table('journal_entries')->where('public_id', $paid['entry'])->value('id'))->whereNotNull('financial_account_id')->value('line_number');
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 2, 'entry' => $paid['entry'], 'entry_line' => (int) $cashLine, 'amount' => '200.00'])->assertOk();
        // A line of another financial account (cash), of another unit, or unknown is the same concealed target.
        $cashEntry = $this->contribute($o, $w['cash_a1'], '5.00', '2026-09-02')['entry'];
        $this->assertConcealed($this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $cashEntry, 'entry_line' => 1, 'amount' => '1.00']));
        $this->assertConcealed($this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => (string) Str::ulid(), 'entry_line' => 1, 'amount' => '1.00']));
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 9, 'entry' => $opening, 'entry_line' => 1, 'amount' => '1.00'])->assertStatus(422);
        // A DRAFT ledger movement is never reconciled; a movement after the period end is incompatible.
        $draft = (new LedgerPostingService(DB::connection()))->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'EXPENSE', 'entry_date' => '2026-09-12', 'description' => 'Rascunho',
            'lines' => [['account' => 'OPERATING_EXPENSE', 'category' => 'FIN_BANK_FEES', 'debit' => '1.00'], ['account' => 'BANK', 'financial_account_id' => $bank['id'], 'credit' => '1.00']]]);
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 2, 'entry' => $draft['public_id'], 'entry_line' => 2, 'amount' => '1.00'])->assertStatus(409)->assertJsonPath('error.code', 'ENTRY_NOT_POSTED');
        $october = $this->receivable($o, $w['a1'], '9.00');
        $octSettle = $this->settle($o, 'receivables', $october['public_id'], $bank, '9.00');
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $octSettle['entry'], 'entry_line' => 1, 'amount' => '1.00'])->assertStatus(409)->assertJsonPath('error.code', 'MATCH_DATE_INCOMPATIBLE');
        $closed = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/close')->assertOk()->json('data');
        $this->assertSame(['CLOSED', 2, 0, '800.00', '800.00', '0.00'], [$closed['status'], $closed['summary']['matched'], $closed['summary']['unmatched'], $closed['summary']['statement_closing_balance'],
            $closed['summary']['ledger_balance_at_period_end'], $closed['summary']['difference']]);
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $opening, 'entry_line' => 1, 'amount' => '1.00'])->assertStatus(409)->assertJsonPath('error.code', 'RECONCILIATION_CLOSED');
        $this->assertSame([2, 1], [DB::table('audit_logs')->where('action', 'finance.reconciliation_matched')->where('unit_id', $w['a1']['id'])->count(), DB::table('audit_logs')->where('action', 'finance.reconciliation_closed')->where('unit_id', $w['a1']['id'])->count()]);
        $this->assertSame(80900, $this->balance($bank), 'opening 1000 - payment 200 + October receipt 9: reconciliation never writes the ledger');
    }

    public function test_b05_the_same_value_is_never_reconciled_twice(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->openedBank($o, $w['a1'], '1000.00', '2026-09-01');
        $r = $this->receivable($o, $w['a1'], '1000.00', 'REV_OTHER', ['recognized_on' => '2026-09-01']);
        $second = $this->settle($o, 'receivables', $r['public_id'], $bank, '1000.00', '2026-09-02');
        $s = $this->statement($o, $bank, '0.00', '1000.00', [['2026-09-01', '1000.00', 'Depósito', null]]);
        $rec = $this->reconcile($o, $bank, '2026-09', $s);
        $match = ['statement_line' => 1, 'entry' => $bank['opening_entry'], 'entry_line' => 1, 'amount' => '1000.00'];
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', $match)->assertOk();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', $match)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_MATCHED');
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['entry' => $second['entry'], 'amount' => '0.01'] + $match)->assertStatus(409)->assertJsonPath('error.code', 'MATCH_EXCEEDS_STATEMENT_LINE');
        $this->fpost($o, 'finance/reconciliations', ['account' => $bank['public_id'], 'period' => '2026-09', 'statement' => $s['public_id']], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'RECONCILIATION_ALREADY_OPEN');
        // A later version of the same month cannot consume the value again either (Σ over every reconciliation).
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/close')->assertOk();
        $v2 = $this->reconcile($o, $bank, '2026-09', $s);
        $this->assertSame(2, $v2['version']);
        $this->assertSame('MATCHED', $v2['statement_lines'][0]['state']);
        $this->fpost($o, 'finance/reconciliations/' . $v2['public_id'] . '/matches', ['entry' => $second['entry']] + $match)->assertStatus(409)->assertJsonPath('error.code', 'MATCH_EXCEEDS_STATEMENT_LINE');
        // Ledger side: one ledger line cannot be consumed beyond its amount by two statement lines.
        $s2 = $this->statement($o, $bank, '1000.00', '1800.00', [['2026-09-02', '600.00', 'Parte 1', null], ['2026-09-03', '500.00', 'Parte 2', null], ['2026-09-04', '-300.00', 'Outro', null]]);
        $rec3 = $this->reconcileAfterClosing($o, $bank, '2026-09', $s2, $v2);
        $settlementBank = DB::table('journal_lines')->where('entry_id', DB::table('journal_entries')->where('public_id', $second['entry'])->value('id'))->whereNotNull('financial_account_id')->value('line_number');
        $this->fpost($o, 'finance/reconciliations/' . $rec3['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $second['entry'], 'entry_line' => (int) $settlementBank, 'amount' => '600.00'])->assertOk();
        $this->fpost($o, 'finance/reconciliations/' . $rec3['public_id'] . '/matches', ['statement_line' => 2, 'entry' => $second['entry'], 'entry_line' => (int) $settlementBank, 'amount' => '500.00'])->assertStatus(409)->assertJsonPath('error.code', 'MATCH_EXCEEDS_LEDGER_LINE');
        $this->assertSame('1000.0000', (string) DB::table('reconciliation_matches')->where('statement_line_id', DB::table('bank_statement_lines')->where('statement_id', DB::table('bank_statements')->where('public_id', $s['public_id'])->value('id'))->value('id'))->sum('matched_amount'));
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT s.id FROM bank_statement_lines s JOIN reconciliation_matches m ON m.statement_line_id = s.id GROUP BY s.id, s.amount_signed HAVING SUM(m.matched_amount) > ABS(s.amount_signed)) x')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT l.id FROM journal_lines l JOIN reconciliation_matches m ON m.journal_line_id = l.id GROUP BY l.id, l.debit, l.credit HAVING SUM(m.matched_amount) > GREATEST(l.debit, l.credit)) x')->n);
    }

    public function test_b06_partial_and_many_to_one_matches_are_supported(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->openedBank($o, $w['a1'], '10.00', '2026-09-01');
        $r1 = $this->receivable($o, $w['a1'], '100.00', 'REV_OTHER', ['recognized_on' => '2026-09-15']);
        $r2 = $this->receivable($o, $w['a1'], '200.00', 'REV_OTHER', ['recognized_on' => '2026-09-15']);
        $s1 = $this->settle($o, 'receivables', $r1['public_id'], $bank, '100.00', '2026-09-20');
        $s2 = $this->settle($o, 'receivables', $r2['public_id'], $bank, '200.00', '2026-09-20');
        $statement = $this->statement($o, $bank, '10.00', '310.00', [['2026-09-21', '300.00', 'Depósito agregado', 'AGG-1']]);
        $rec = $this->reconcile($o, $bank, '2026-09', $statement);
        $partial = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $s1['entry'], 'entry_line' => 1, 'amount' => '100.00'])->assertOk()->json('data');
        $this->assertSame(['PARTIALLY_MATCHED', '100.00'], [$partial['statement_lines'][0]['state'], $partial['statement_lines'][0]['matched_total']]);
        $full = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $s2['entry'], 'entry_line' => 1, 'amount' => '200.00'])->assertOk()->json('data');
        $this->assertSame('MATCHED', $full['statement_lines'][0]['state']);
        $this->assertCount(2, $full['matches']);
        // Unmatch while OPEN restores the derived state; the audit keeps both facts.
        $back = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/unmatch', ['statement_line' => 1, 'entry' => $s2['entry'], 'entry_line' => 1])->assertOk()->json('data');
        $this->assertSame('PARTIALLY_MATCHED', $back['statement_lines'][0]['state']);
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/unmatch', ['statement_line' => 1, 'entry' => $s2['entry'], 'entry_line' => 1])->assertStatus(409)->assertJsonPath('error.code', 'MATCH_NOT_FOUND');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.reconciliation_unmatched')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_b07_reconciliation_of_another_unit_is_concealed(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $intruder = $this->officer($w['b1']);
        $bank = $this->openedBank($o, $w['a1'], '100.00', '2026-09-01');
        $s = $this->statement($o, $bank, '0.00', '100.00', [['2026-09-01', '100.00', 'Depósito', null]]);
        $rec = $this->reconcile($o, $bank, '2026-09', $s);
        $ghost = (string) Str::ulid();
        foreach (['finance/reconciliations/' . $rec['public_id'], 'finance/reconciliations/' . $ghost, 'finance/reconciliations/not-a-ulid', 'finance/bank-statements/' . $s['public_id'], 'finance/accounts/' . $bank['public_id']] as $uri) {
            $this->assertConcealed($this->api($intruder, 'GET', $uri));
        }
        $this->assertConcealed($this->fpost($intruder, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $bank['opening_entry'], 'entry_line' => 1, 'amount' => '1.00']));
        $this->assertConcealed($this->fpost($intruder, 'finance/reconciliations/' . $rec['public_id'] . '/close'));
        $this->assertConcealed($this->fpost($intruder, 'finance/reconciliations', ['account' => $bank['public_id'], 'period' => '2026-09', 'statement' => $s['public_id']], $this->key()));
        $own = $this->account($w['b1'], 'BANK');
        $this->assertConcealed($this->fpost($intruder, 'finance/reconciliations', ['account' => $own['public_id'], 'period' => '2026-09', 'statement' => $s['public_id']], $this->key()));
        $this->assertSame([], $this->api($intruder, 'GET', 'finance/reconciliations')->assertOk()->json('data'));
        $this->fpost($this->staff(['FINANCE_VIEW'], $w['a1']['id']), 'finance/reconciliations/' . $rec['public_id'] . '/close')->assertStatus(403);
        $this->assertSame(0, DB::table('reconciliation_matches')->where('reconciliation_id', DB::table('reconciliations')->where('public_id', $rec['public_id'])->value('id'))->count());
        $this->assertSame('OPEN', DB::table('reconciliations')->where('public_id', $rec['public_id'])->value('status'));
    }

    public function test_b08_bc1_two_reconciliations_racing_for_the_same_statement_value(): void
    {
        $w = $this->world();
        $o1 = $this->officer($w['a1']);
        $o2 = $this->officer($w['a1']);
        $bank = $this->openedBank($o1, $w['a1'], '5.00', '2026-09-01');
        $r = $this->receivable($o1, $w['a1'], '100.00', 'REV_OTHER', ['recognized_on' => '2026-09-20']);
        $settled = $this->settle($o1, 'receivables', $r['public_id'], $bank, '100.00', '2026-09-25');
        $s = $this->statement($o1, $bank, '5.00', '105.00', [['2026-09-26', '100.00', 'Depósito', null]], '2026-09-20', now('Africa/Luanda')->format('Y-m-d'));
        $september = $this->reconcile($o1, $bank, '2026-09', $s);
        $october = $this->reconcile($o2, $bank, now('Africa/Luanda')->format('Y-m'), $s);
        $body = ['statement_line' => 1, 'entry' => $settled['entry'], 'entry_line' => 1, 'amount' => '100.00'];
        [$first, $second, $wait] = $this->lockOrdered(['op' => 'match', 'target' => $september['public_id'], 'user' => $o1['user'], 'session' => $o1['session'], 'body' => $body],
            ['op' => 'match', 'target' => $october['public_id'], 'user' => $o2['user'], 'session' => $o2['session'], 'body' => $body]);
        $this->assertSame(['OK', 'MATCH_EXCEEDS_STATEMENT_LINE'], [$first['status'], $second['status']], json_encode([$first, $second]));
        $this->assertSame(['bank_statement_lines', true], [$wait['object'], $wait['blocked_by_first']]);
        $this->assertSame(1, DB::table('reconciliation_matches')->where('statement_line_id', DB::table('bank_statement_lines')->where('statement_id', DB::table('bank_statements')->where('public_id', $s['public_id'])->value('id'))->value('id'))->count());
        $this->evidence('BC1', compact('first', 'second', 'wait'));
    }

    // ---- U financial accounts -----------------------------------------------------------------------------------------

    public function test_u01_open_financial_accounts_cash_with_custodian_and_bank_with_encrypted_number(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $custodian = $this->person($w['a1'], 'Tesoureira da Congregação');
        $key = $this->key();
        $cashBody = ['unit' => $w['a1']['public_id'], 'kind' => 'CASH', 'code' => 'cx-sede', 'name' => 'Caixa da sede', 'opened_on' => '2026-09-01', 'custodian' => $custodian['public_id']];
        $cash = $this->fpost($o, 'finance/accounts', $cashBody, $key)->assertCreated()->json('data');
        $this->assertSame(['CASH', 'OPEN', 'CX-SEDE', '0.00', $custodian['public_id'], 'POSTED_JOURNAL_LINES', null], [$cash['kind'], $cash['status'], $cash['code'], $cash['balance'], $cash['cash_register']['custodian'],
            $cash['balance_source'], $cash['opening_entry']]);
        $this->assertNoInternalIds($cash);
        $this->fpost($o, 'finance/accounts', $cashBody, $key)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->fpost($o, 'finance/accounts', $cashBody, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_CODE_TAKEN');
        $bank = $this->fpost($o, 'finance/accounts', ['unit' => $w['a1']['public_id'], 'kind' => 'BANK', 'code' => 'BNK-01', 'name' => 'Banco da sede', 'opened_on' => '2026-09-01',
            'bank_name' => 'Banco Exemplo', 'account_number' => '0040 0000 1234 5678 9010 7'], $this->key())->assertCreated()->json('data');
        $this->assertSame(['Banco Exemplo', '•••• 0107'], [$bank['bank']['bank_name'], $bank['bank']['account_number']]);
        $stored = (string) DB::table('bank_account_details')->where('account_id', DB::table('accounts')->where('public_id', $bank['public_id'])->value('id'))->value('account_number_ciphertext');
        $this->assertStringNotContainsString('004000001234567890107', $stored, 'the number is never stored in clear');
        $this->assertStringNotContainsString('0107', json_encode(DB::table('audit_logs')->where('action', 'finance.account_opened')->pluck('after_metadata')->all()));
        // Custodian not visible in People, missing permission, wrong scope, raw keys.
        $this->assertConcealed($this->fpost($o, 'finance/accounts', ['code' => 'CX-2', 'custodian' => $this->person($w['b1'])['public_id']] + $cashBody, $this->key()));
        $this->fpost($this->officer($w['a1'], [], ['FINANCE_ACCOUNT_MANAGE']), 'finance/accounts', ['code' => 'CX-3'] + $cashBody, $this->key())->assertStatus(403);
        $this->assertConcealed($this->fpost($o, 'finance/accounts', ['unit' => $w['b1']['public_id'], 'code' => 'CX-4'] + $cashBody, $this->key()));
        $this->fpost($o, 'finance/accounts', ['unit_id' => $w['a1']['id'], 'code' => 'CX-5'] + $cashBody, $this->key())->assertStatus(422);
        $this->fpost($o, 'finance/accounts', ['kind' => 'LOAN', 'code' => 'CX-6'] + $cashBody, $this->key())->assertStatus(422);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.account_opened')->where('unit_id', $w['a1']['id'])->where('after_metadata', 'like', '%BNK-01%')->count());
        $list = $this->api($o, 'GET', 'finance/accounts?unit=' . $w['a1']['public_id'])->assertOk()->json('data');
        $this->assertContains($bank['public_id'], array_column($list, 'public_id'));
    }

    public function test_u02_opening_balance_is_a_journal_entry_and_the_balance_is_derived(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $cash = $this->openedCash($o, $w['a1'], '500.00', '2026-09-01');
        $entry = DB::table('journal_entries')->where('public_id', $cash['opening_entry'])->first();
        $this->assertSame(['OPENING_BALANCE', 'POSTED', '2026-09-01'], [$entry->entry_kind, $entry->status, (string) $entry->entry_date]);
        $this->assertSame([['CASH', 'ASSET', null, '500.0000', '0.0000'], ['OPENING_NET_ASSETS', 'EQUITY', null, '0.0000', '500.0000']], $this->lines($cash['opening_entry']));
        $this->assertSame('500.00', $this->api($o, 'GET', 'finance/accounts/' . $cash['public_id'])->json('data.balance'));
        $this->assertSame(0, $this->resultOf($w['a1']), 'an opening balance is not revenue');
        $this->assertSame([], DB::select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME LIKE '%balance%'"), 'no mutable balance column');
        // Only POSTED lines count: a DRAFT movement on the account changes nothing.
        (new LedgerPostingService(DB::connection()))->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'EXPENSE', 'entry_date' => '2026-09-02', 'description' => 'Rascunho',
            'lines' => [['account' => 'OPERATING_EXPENSE', 'category' => 'ADM_TAXI', 'debit' => '120.00'], ['account' => 'CASH', 'financial_account_id' => $cash['id'], 'credit' => '120.00']]]);
        $detail = $this->api($o, 'GET', 'finance/accounts/' . $cash['public_id'])->json('data');
        $sql = DB::selectOne("SELECT SUM(l.debit) - SUM(l.credit) AS b FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.status = 'POSTED' AND l.financial_account_id = ?", [$cash['id']])->b;
        $this->assertSame(['500.00', '500.0000'], [$detail['balance'], (string) $sql]);
        $history = $this->api($o, 'GET', 'finance/accounts/' . $cash['public_id'] . '/history')->assertOk()->json();
        $this->assertSame([[$cash['opening_entry'], 'OPENING_BALANCE', '500.00']], array_map(fn ($h) => [$h['entry'], $h['kind'], $h['debit']], $history['data']));
        $this->assertSame('500.00', $history['meta']['balance']);
        // An opening balance is a posting: FINANCE_POST is required.
        $this->fpost($this->officer($w['a1'], [], ['FINANCE_POST']), 'finance/accounts', ['unit' => $w['a1']['public_id'], 'kind' => 'CASH', 'code' => 'CX-NP', 'name' => 'Sem post',
            'custodian' => $this->person($w['a1'])['public_id'], 'opening_balance' => '10.00'], $this->key())->assertStatus(403);
    }

    public function test_u03_closing_an_account_requires_a_zero_balance_and_nothing_pending(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $cash = $this->openedCash($o, $w['a1'], '500.00', '2026-09-01');
        $this->fpost($o, 'finance/accounts/' . $cash['public_id'] . '/close')->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_BALANCE_NOT_ZERO');
        $ledger = new LedgerPostingService(DB::connection());
        $move = $ledger->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'ACCOUNT_TRANSFER', 'entry_date' => '2026-09-05', 'description' => 'Depósito no outro caixa',
            'lines' => [['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'debit' => '500.00'], ['account' => 'CASH', 'financial_account_id' => $cash['id'], 'credit' => '500.00']]]);
        $this->fpost($o, 'finance/accounts/' . $cash['public_id'] . '/close')->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_HAS_PENDING_ENTRIES');
        $ledger->post($o['user'], $move['public_id'], 0);
        $closed = $this->fpost($o, 'finance/accounts/' . $cash['public_id'] . '/close', ['closed_on' => '2026-09-06'])->assertOk()->json('data');
        $this->assertSame(['CLOSED', '2026-09-06', '0.00', []], [$closed['status'], $closed['closed_on'], $closed['balance'], $closed['actions']]);
        $history = $this->api($o, 'GET', 'finance/accounts/' . $cash['public_id'] . '/history')->json('data');
        $this->assertCount(2, $history, 'history is kept');
        $this->fpost($o, 'finance/accounts/' . $cash['public_id'] . '/close')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame('CLOSED', DB::table('cash_registers')->where('account_id', $cash['id'])->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.account_closed')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_u04_a_closed_account_rejects_every_new_posting(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $cash = $this->openedCash($o, $w['a1'], null, '2026-09-01');
        $this->fpost($o, 'finance/accounts/' . $cash['public_id'] . '/close', ['closed_on' => '2026-09-02'])->assertOk();
        $p = $this->payable($o, $w['a1'], '10.00');
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/settlements', ['account' => $cash['public_id'], 'amount' => '10.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_NOT_OPEN');
        $this->fpost($o, 'finance/contributions', ['kind' => 'MONETARY', 'identification' => 'AGGREGATED', 'account' => $cash['public_id'], 'category' => 'REV_OFFERINGS', 'amount' => '5.00'], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_NOT_OPEN');
        $ledger = new LedgerPostingService(DB::connection());
        foreach (['2026-09-03', now('Africa/Luanda')->format('Y-m-d')] as $date) {
            try {
                $d = $ledger->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'REVENUE', 'entry_date' => $date, 'description' => 'Depois do fecho',
                    'lines' => [['account' => 'CASH', 'financial_account_id' => $cash['id'], 'debit' => '1.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '1.00']]]);
                $ledger->post($o['user'], $d['public_id'], 0);
                $this->fail('a CLOSED financial account must refuse postings');
            } catch (FinanceError $e) {
                $this->assertSame('FINANCIAL_ACCOUNT_CLOSED', $e->reason);
            }
        }
        $this->assertSame(0, DB::table('journal_lines')->where('financial_account_id', $cash['id'])->count());
        $this->assertSame('0.00', $this->api($o, 'GET', 'finance/accounts/' . $cash['public_id'])->json('data.balance'), 'the balance stays computable');
    }

    // ---- G budget -------------------------------------------------------------------------------------------------------

    public function test_g01_budget_draft_with_rubric_lines_never_touches_the_ledger(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $entries = DB::table('journal_entries')->count();
        $key = $this->key();
        $body = ['unit' => $w['a1']['public_id'], 'year' => '2026', 'lines' => [['category' => 'REV_OTHER', 'requested_amount' => '1000.00'], ['category' => 'ADM_ELECTRICITY', 'requested_amount' => '400.00'], ['category' => 'INV_AST_IT', 'requested_amount' => '2000.00']]];
        $b = $this->fpost($m, 'finance/budgets', $body, $key)->assertCreated()->json('data');
        $this->assertSame(['DRAFT', 1, '2026', 'GENERAL', '3400.00', ['edit_lines', 'submit', 'cancel']], [$b['status'], $b['version'], $b['year'], $b['fund'], $b['requested_total'], $b['actions']]);
        $this->assertSame([['REV_OTHER', '1000.00'], ['ADM_ELECTRICITY', '400.00'], ['INV_AST_IT', '2000.00']], array_map(fn ($l) => [$l['category']['code'], $l['requested_amount']], $b['lines']));
        $this->assertNoInternalIds($b);
        $this->fpost($m, 'finance/budgets', $body, $key)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->fpost($m, 'finance/budgets', ['lines' => [['category' => 'TRF_REMITTANCE', 'requested_amount' => '1.00']]] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'INTERNAL_COUNTERPARTY');
        $this->fpost($m, 'finance/budgets', ['lines' => [['category' => 'BS_PAST_DEBTS', 'requested_amount' => '1.00']]] + $body, $this->key())->assertStatus(422);
        $this->fpost($m, 'finance/budgets', ['lines' => [['category' => 'ADM_WATER', 'requested_amount' => '1.00'], ['category' => 'ADM_WATER', 'requested_amount' => '2.00']]] + $body, $this->key())->assertStatus(422);
        $this->fpost($m, 'finance/budgets', ['lines' => [['category' => 'ADM_WATER', 'requested_amount' => '1.005']]] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_SCALE');
        $this->fpost($m, 'finance/budgets', ['year' => '2027'] + $body, $this->key())->assertStatus(422);
        $edited = $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/lines', ['lines' => [['category' => 'REV_OTHER', 'requested_amount' => '1200.00']], 'lock_version' => $b['lock_version']])->assertOk()->json('data');
        $this->assertSame([['REV_OTHER', '1200.00']], array_map(fn ($l) => [$l['category']['code'], $l['requested_amount']], $edited['lines']));
        $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/lines', ['lines' => [], 'lock_version' => 0])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $this->assertSame($entries, DB::table('journal_entries')->count(), 'a budget never writes the ledger');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.budget_created')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_g02_submit_freezes_the_lines(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $empty = $this->budget($m, $w['a1'], []);
        $this->fpost($m, 'finance/budgets/' . $empty['public_id'] . '/submit')->assertStatus(409)->assertJsonPath('error.code', 'BUDGET_EMPTY');
        $b = $this->budget($m, $w['a1'], [['REV_OTHER', '100.00']]);
        $s = $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/submit')->assertOk()->json('data');
        $this->assertSame(['SUBMITTED', true], [$s['status'], $s['submitted_by_me']]);
        $this->assertSame($m['user'], (int) DB::table('budgets')->where('public_id', $b['public_id'])->value('submitted_by'));
        $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/lines', ['lines' => [['category' => 'REV_OTHER', 'requested_amount' => '1.00']]])->assertStatus(409)->assertJsonPath('error.code', 'BUDGET_NOT_EDITABLE');
        $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/submit')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/approve')->assertStatus(403);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.budget_submitted')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_g03_review_and_return_are_explicit_transitions(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $a = $this->approver($w['a']);
        $b = $this->submittedBudget($m, $w['a1'], [['REV_OTHER', '1000.00'], ['ADM_ELECTRICITY', '400.00']]);
        $returned = $this->fpost($a, 'finance/budgets/' . $b['public_id'] . '/return', ['reason' => 'Rever a electricidade'])->assertOk()->json('data');
        $this->assertSame('DRAFT', $returned['status']);
        $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/submit')->assertOk();
        $reviewed = $this->fpost($a, 'finance/budgets/' . $b['public_id'] . '/review', ['approved_lines' => [['category' => 'ADM_ELECTRICITY', 'approved_amount' => '350.00']]])->assertOk()->json('data');
        $this->assertSame(['REVIEWED', '1350.00'], [$reviewed['status'], $reviewed['approved_total']]);
        $this->assertSame([['REV_OTHER', '1000.00', '1000.00'], ['ADM_ELECTRICITY', '400.00', '350.00']], array_map(fn ($l) => [$l['category']['code'], $l['requested_amount'], $l['approved_amount']], $reviewed['lines']));
        $this->assertSame($a['user'], (int) DB::table('budgets')->where('public_id', $b['public_id'])->value('reviewed_by'));
        $this->fpost($a, 'finance/budgets/' . $b['public_id'] . '/review', ['approved_lines' => [['category' => 'ADM_WATER', 'approved_amount' => '1.00']]])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->fpost($m, 'finance/budgets/' . $b['public_id'] . '/review')->assertStatus(403);
        $this->assertSame([1, 1], [DB::table('audit_logs')->where('action', 'finance.budget_returned')->where('reason', 'Rever a electricidade')->count(), DB::table('audit_logs')->where('action', 'finance.budget_reviewed')->where('unit_id', $w['a1']['id'])->count()]);
    }

    public function test_g04_approval_by_a_different_actor(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $a = $this->approver($w['a']);
        $b = $this->reviewedBudget($m, $a, $w['a1'], [['REV_OTHER', '1000.00']]);
        $ok = $this->fpost($a, 'finance/budgets/' . $b['public_id'] . '/approve')->assertOk()->json('data');
        $this->assertSame(['APPROVED', true], [$ok['status'], $ok['in_execution']]);
        $this->assertSame(['revise'], $this->api($m, 'GET', 'finance/budgets/' . $b['public_id'])->json('data.actions'));
        $row = DB::table('budgets')->where('public_id', $b['public_id'])->first();
        $this->assertSame([$a['user'], $m['user'], 1], [(int) $row->approved_by, (int) $row->submitted_by, (int) $row->approved_guard]);
        $this->assertNotNull($row->approved_at);
        $this->fpost($a, 'finance/budgets/' . $b['public_id'] . '/approve')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.budget_approved')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_g05_the_submitter_can_never_approve(): void
    {
        $w = $this->world();
        $both = $this->officer($w['a1'], ['FINANCE_BUDGET_APPROVE']);
        $b = $this->reviewedBudget($both, $both, $w['a1'], [['REV_OTHER', '1000.00']]);
        $this->assertNotContains('approve', $this->api($both, 'GET', 'finance/budgets/' . $b['public_id'])->json('data.actions'));
        $this->fpost($both, 'finance/budgets/' . $b['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'SEGREGATION_REQUIRED');
        $this->assertSame('REVIEWED', DB::table('budgets')->where('public_id', $b['public_id'])->value('status'));
        try {
            DB::table('budgets')->where('public_id', $b['public_id'])->update(['status' => 'APPROVED', 'approved_by' => $both['user'], 'approved_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->fail('CHECK ck_budgets_segregation must refuse');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('ck_budgets_segregation', $e->getMessage());
        }
    }

    public function test_g06_c4_concurrent_approvals_leave_one_approved_version(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $a1 = $this->approver($w['a']);
        $a2 = $this->approver($w['a']);
        // Newer version first: the older approval waits, then is refused (never two APPROVED).
        $v1 = $this->reviewedBudget($m, $a1, $w['a1'], [['REV_OTHER', '100.00']]);
        $v2 = $this->reviewedBudget($m, $a1, $w['a1'], [['REV_OTHER', '200.00']]);
        [$first, $second, $wait] = $this->lockOrdered(['op' => 'approve_budget', 'target' => $v2['public_id'], 'user' => $a1['user'], 'session' => $a1['session']],
            ['op' => 'approve_budget', 'target' => $v1['public_id'], 'user' => $a2['user'], 'session' => $a2['session']]);
        $this->assertSame(['OK', 'BUDGET_VERSION_OUTDATED', 'budgets', true], [$first['status'], $second['status'], $wait['object'], $wait['blocked_by_first']], json_encode([$first, $second]));
        $this->assertSame(['REVIEWED', 'APPROVED'], [DB::table('budgets')->where('public_id', $v1['public_id'])->value('status'), DB::table('budgets')->where('public_id', $v2['public_id'])->value('status')]);
        // Older version first: the newer approval waits, then supersedes it in its own transaction.
        $u1 = $this->reviewedBudget($m, $a1, $w['a2'], [['REV_OTHER', '100.00']], $this->officer($w['a2']));
        $u2 = $this->reviewedBudget($m, $a1, $w['a2'], [['REV_OTHER', '150.00']], $this->officer($w['a2']));
        [$f2, $s2, $w2] = $this->lockOrdered(['op' => 'approve_budget', 'target' => $u1['public_id'], 'user' => $a1['user'], 'session' => $a1['session']],
            ['op' => 'approve_budget', 'target' => $u2['public_id'], 'user' => $a2['user'], 'session' => $a2['session']]);
        $this->assertSame(['OK', 'OK', 'budgets', true], [$f2['status'], $s2['status'], $w2['object'], $w2['blocked_by_first']]);
        $this->assertSame(['SUPERSEDED', 'APPROVED'], [DB::table('budgets')->where('public_id', $u1['public_id'])->value('status'), DB::table('budgets')->where('public_id', $u2['public_id'])->value('status')]);
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM (SELECT unit_id, period_id, fund_id FROM budgets WHERE status = 'APPROVED' GROUP BY unit_id, period_id, fund_id HAVING COUNT(*) > 1) x")->n);
        try {
            DB::table('budgets')->where('public_id', $v1['public_id'])->update(['status' => 'APPROVED', 'approved_by' => $a2['user'], 'approved_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->fail('UNIQUE approved_guard must refuse a second APPROVED version');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('uq_budgets_unit_id_period_id_fund_id_approved', $e->getMessage());
        }
        $this->evidence('C4', ['newer_first' => compact('first', 'second', 'wait'), 'older_first' => ['first' => $f2, 'second' => $s2, 'wait' => $w2]]);
    }

    public function test_g07_a_revision_is_a_new_version_and_supersedes_the_approved_one(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $a = $this->approver($w['a']);
        $v1 = $this->reviewedBudget($m, $a, $w['a1'], [['REV_OTHER', '1000.00'], ['ADM_ELECTRICITY', '400.00']]);
        $this->fpost($a, 'finance/budgets/' . $v1['public_id'] . '/approve')->assertOk();
        $before = DB::table('budget_lines')->where('budget_id', DB::table('budgets')->where('public_id', $v1['public_id'])->value('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->fpost($m, 'finance/budgets/' . $v1['public_id'] . '/lines', ['lines' => [['category' => 'REV_OTHER', 'requested_amount' => '1.00']]])->assertStatus(409)->assertJsonPath('error.code', 'BUDGET_NOT_EDITABLE');
        $this->fpost($m, 'finance/budgets/' . $v1['public_id'] . '/cancel', ['reason' => 'Não'])->assertStatus(409);
        $key = $this->key();
        $v2 = $this->fpost($m, 'finance/budgets/' . $v1['public_id'] . '/revise', [], $key)->assertCreated()->json('data');
        $this->fpost($m, 'finance/budgets/' . $v1['public_id'] . '/revise', [], $key)->assertOk()->assertJsonPath('data.public_id', $v2['public_id']);
        $this->assertSame(['DRAFT', 2], [$v2['status'], $v2['version']]);
        $this->assertSame([['REV_OTHER', '1000.00'], ['ADM_ELECTRICITY', '400.00']], array_map(fn ($l) => [$l['category']['code'], $l['requested_amount']], $v2['lines']));
        $this->fpost($m, 'finance/budgets/' . $v2['public_id'] . '/lines', ['lines' => [['category' => 'REV_OTHER', 'requested_amount' => '1500.00'], ['category' => 'ADM_ELECTRICITY', 'requested_amount' => '400.00']]])->assertOk();
        $this->fpost($m, 'finance/budgets/' . $v2['public_id'] . '/submit')->assertOk();
        $this->fpost($a, 'finance/budgets/' . $v2['public_id'] . '/review')->assertOk();
        $this->fpost($a, 'finance/budgets/' . $v2['public_id'] . '/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame('SUPERSEDED', DB::table('budgets')->where('public_id', $v1['public_id'])->value('status'));
        $this->assertSame($before, DB::table('budget_lines')->where('budget_id', DB::table('budgets')->where('public_id', $v1['public_id'])->value('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'history is preserved');
        $versions = $this->api($m, 'GET', 'finance/budgets/' . $v2['public_id'])->json('data.versions');
        $this->assertSame([[1, 'SUPERSEDED'], [2, 'APPROVED']], array_map(fn ($v) => [$v['version'], $v['status']], $versions));
        $audit = json_decode((string) DB::table('audit_logs')->where('action', 'finance.budget_approved')->where('entity_id', DB::table('budgets')->where('public_id', $v2['public_id'])->value('id'))->value('after_metadata'), true);
        $this->assertSame($v1['public_id'], $audit['superseded']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.budget_revised')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_g08_actual_versus_budget_comes_only_from_posted_ledger_lines(): void
    {
        $w = $this->world();
        $m = $this->officer($w['a1']);
        $a = $this->approver($w['a']);
        $b = $this->reviewedBudget($m, $a, $w['a1'], [['REV_OTHER', '1000.00'], ['ADM_ELECTRICITY', '400.00'], ['INV_AST_IT', '2000.00'], ['ADM_MOBILE', '0.00']]);
        $this->fpost($a, 'finance/budgets/' . $b['public_id'] . '/approve')->assertOk();
        $this->contribute($m, $w['cash_a1'], '3000.00', '2026-09-01');
        $r = $this->receivable($m, $w['a1'], '250.00', 'REV_OTHER', ['recognized_on' => '2026-09-05']);
        $this->settle($m, 'receivables', $r['public_id'], $w['cash_a1'], '250.00', '2026-09-06');
        $electricity = $this->payable($m, $w['a1'], '500.00', 'ADM_ELECTRICITY', null, ['recognized_on' => '2026-09-07']);
        $this->settle($m, 'payables', $electricity['public_id'], $w['cash_a1'], '500.00', '2026-09-08');
        $this->payable($m, $w['a1'], '1500.00', 'INV_AST_IT', null, ['recognized_on' => '2026-09-09']);
        $this->payable($m, $w['a1'], '50.00', 'ADM_WATER', null, ['recognized_on' => '2026-09-10']);
        (new LedgerPostingService(DB::connection()))->createDraft($m['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'EXPENSE', 'entry_date' => '2026-09-11', 'description' => 'Rascunho não conta',
            'lines' => [['account' => 'OPERATING_EXPENSE', 'category' => 'ADM_ELECTRICITY', 'debit' => '999.00'], ['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'credit' => '999.00']]]);
        $report = $this->api($m, 'GET', 'finance/budgets/' . $b['public_id'] . '/actual-vs-budget?to=2026-09-30')->assertOk()->json('data');
        $this->assertSame(['POSTED_JOURNAL_LINES', 'APPROVED_AMOUNT', '2026-01-01', '2026-09-30'], [$report['source'], $report['basis'], $report['interval']['from'], $report['interval']['to']]);
        $rows = collect($report['rows'])->keyBy('category.code');
        $this->assertSame(['1000.00', '250.00', '-750.00', '-75.00'], [$rows['REV_OTHER']['budget_amount'], $rows['REV_OTHER']['actual_amount'], $rows['REV_OTHER']['variance'], $rows['REV_OTHER']['variance_percent']]);
        $this->assertSame(['400.00', '500.00', '100.00', '25.00'], [$rows['ADM_ELECTRICITY']['budget_amount'], $rows['ADM_ELECTRICITY']['actual_amount'], $rows['ADM_ELECTRICITY']['variance'], $rows['ADM_ELECTRICITY']['variance_percent']]);
        $this->assertSame(['2000.00', '1500.00', '-500.00', '-25.00'], [$rows['INV_AST_IT']['budget_amount'], $rows['INV_AST_IT']['actual_amount'], $rows['INV_AST_IT']['variance'], $rows['INV_AST_IT']['variance_percent']]);
        $this->assertSame([false, '0.00', '50.00', null], [$rows['ADM_WATER']['budgeted'], $rows['ADM_WATER']['budget_amount'], $rows['ADM_WATER']['actual_amount'], $rows['ADM_WATER']['variance_percent']]);
        $this->assertSame(['0.00', null], [$rows['ADM_MOBILE']['actual_amount'], $rows['ADM_MOBILE']['variance_percent']]);
        $this->assertSame('3000.00', $rows['REV_TITHES']['actual_amount'], 'an unbudgeted rubric with actuals is reported');
        $this->assertSame(['1000.00', '3250.00', '2400.00', '2050.00'], [$report['totals']['REVENUE']['budget_amount'], $report['totals']['REVENUE']['actual_amount'], $report['totals']['COST']['budget_amount'], $report['totals']['COST']['actual_amount']]);
        $early = $this->api($m, 'GET', 'finance/budgets/' . $b['public_id'] . '/actual-vs-budget?to=2026-09-06')->json('data.rows');
        $this->assertSame('0.00', collect($early)->keyBy('category.code')['ADM_ELECTRICITY']['actual_amount'], 'YTD up to the date');
        $this->api($m, 'GET', 'finance/budgets/' . $b['public_id'] . '/actual-vs-budget?to=2027-01-01')->assertStatus(422);
        $this->assertConcealed($this->api($this->officer($w['b1']), 'GET', 'finance/budgets/' . $b['public_id'] . '/actual-vs-budget'));
    }

    // ---- P periods / closes ---------------------------------------------------------------------------------------------

    public function test_p01_unit_period_close_blocks_postings_of_that_unit(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $closed = $this->fpost($o, 'finance/periods/2026-08/close', ['unit' => $w['a1']['public_id']])->assertOk()->json('data');
        $this->assertSame(['CLOSED', true], [collect($closed['items'])->firstWhere('code', '2026-08')['unit_status'], collect($closed['items'])->firstWhere('code', '2026-08')['closed_by_me']]);
        $row = DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->first();
        $this->assertSame(['CLOSED', $o['user']], [$row->status, (int) $row->closed_by]);
        $this->fpost($o, 'finance/receivables', $this->receivableBody($w['a1'], '10.00', ['recognized_on' => '2026-08-10']), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->fpost($o, 'finance/payables', $this->payableBody($w['a1'], '10.00', 'ADM_TAXI', $this->document($w['a1'], 'INVOICE')['public_id'], ['recognized_on' => '2026-08-10']), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->fpost($o, 'finance/periods/2026-08/close', ['unit' => $w['a1']['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_ALREADY_CLOSED');
        $this->assertConcealed($this->fpost($o, 'finance/periods/2026-08/close', ['unit' => $w['b1']['public_id']]));
        $this->fpost($this->officer($w['a1'], [], ['FINANCE_PERIOD_CLOSE']), 'finance/periods/2026-07/close', ['unit' => $w['a1']['public_id']])->assertStatus(403);
        // D12: a unit with a DRAFT entry in the month cannot close it.
        (new LedgerPostingService(DB::connection()))->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'REVENUE', 'entry_date' => '2026-07-10', 'description' => 'Pendente',
            'lines' => [['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'debit' => '1.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '1.00']]]);
        $this->fpost($o, 'finance/periods/2026-07/close', ['unit' => $w['a1']['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_HAS_PENDING_ENTRIES');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.period_unit_closed')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_p02_closing_one_unit_never_closes_another(): void
    {
        $w = $this->world();
        $o = $this->staff(self::OFFICER, $w['a']['id'], true);
        $this->fpost($o, 'finance/periods/2026-08/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $this->assertSame(1, DB::table('accounting_period_unit_closes')->where('period_id', DB::table('accounting_periods')->where('code', '2026-08')->value('id'))->whereIn('unit_id', [$w['a1']['id'], $w['a2']['id'], $w['a']['id']])->count());
        $r = $this->fpost($o, 'finance/receivables', $this->receivableBody($w['a2'], '10.00', ['recognized_on' => '2026-08-10']), $this->key())->assertCreated()->json('data');
        $this->assertSame('2026-08-10', $r['recognized_on']);
        $this->assertSame('OPEN', collect($this->api($o, 'GET', 'finance/periods?unit=' . $w['a2']['public_id'] . '&year=2026')->json('data.items'))->firstWhere('code', '2026-08')['unit_status']);
        $this->assertSame('OPEN', DB::table('accounting_periods')->where('code', '2026-08')->value('status'), 'a unit close is not national');
    }

    public function test_p03_reopen_needs_a_reason_and_a_different_actor_and_keeps_the_history(): void
    {
        $w = $this->world();
        $closer = $this->officer($w['a1']);
        $reopener = $this->staff(['FINANCE_VIEW', 'FINANCE_PERIOD_REOPEN'], $w['a']['id'], true);
        $this->fpost($closer, 'finance/periods/2026-08/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $closedAt = (string) DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->value('closed_at');
        $this->fpost($reopener, 'finance/periods/2026-08/reopen', ['unit' => $w['a1']['public_id']])->assertStatus(422);
        $page = $this->fpost($reopener, 'finance/periods/2026-08/reopen', ['unit' => $w['a1']['public_id'], 'reason' => 'Factura de Agosto recebida tarde'])->assertOk()->json('data');
        $month = collect($page['items'])->firstWhere('code', '2026-08');
        $this->assertSame(['REOPENED', 'Factura de Agosto recebida tarde'], [$month['unit_status'], $month['reopen_reason']]);
        $row = DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->first();
        $this->assertSame([$closer['user'], $closedAt, $reopener['user']], [(int) $row->closed_by, (string) $row->closed_at, (int) $row->reopened_by], 'the close is not erased');
        $this->fpost($closer, 'finance/receivables', $this->receivableBody($w['a1'], '10.00', ['recognized_on' => '2026-08-10']), $this->key())->assertCreated();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.period_unit_reopened')->where('unit_id', $w['a1']['id'])->where('reason', 'Factura de Agosto recebida tarde')->count());
        $this->fpost($closer, 'finance/periods/2026-08/close', ['unit' => $w['a1']['public_id']])->assertOk();
    }

    public function test_p04_the_closer_can_never_reopen(): void
    {
        $w = $this->world();
        $both = $this->officer($w['a1'], ['FINANCE_PERIOD_REOPEN']);
        $this->fpost($both, 'finance/periods/2026-08/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $this->assertNotContains('reopen', collect($this->api($both, 'GET', 'finance/periods?unit=' . $w['a1']['public_id'])->json('data.items'))->firstWhere('code', '2026-08')['actions']);
        $this->fpost($both, 'finance/periods/2026-08/reopen', ['unit' => $w['a1']['public_id'], 'reason' => 'Eu próprio'])->assertStatus(409)->assertJsonPath('error.code', 'SEGREGATION_REQUIRED');
        $this->assertSame('CLOSED', DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->value('status'));
        try {
            DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->update(['status' => 'REOPENED', 'reopened_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'reopened_by' => $both['user'], 'reason' => 'x']);
            $this->fail('CHECK ck_accounting_period_unit_closes_segregation must refuse');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('ck_accounting_period_unit_closes_segregation', $e->getMessage());
        }
    }

    public function test_p05_national_close_needs_the_national_root_authority_and_is_irreversible(): void
    {
        $w = $this->world();
        // D12/D17: exactly one national root (earlier worlds of this class are archived for this test).
        DB::table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')->whereNull('u.parent_id')->where('t.code', 'GENERAL_DIRECTION')
            ->where('u.id', '<>', $w['g']['id'])->update(['u.status' => 'CLOSED']);
        $o = $this->officer($w['a1']);
        $national = $this->staff(['FINANCE_VIEW', 'FINANCE_PERIOD_CLOSE'], $w['g']['id'], true);
        $regional = $this->staff(['FINANCE_VIEW', 'FINANCE_PERIOD_CLOSE'], $w['r']['id'], true);
        $reopener = $this->staff(['FINANCE_VIEW', 'FINANCE_PERIOD_REOPEN'], $w['g']['id'], true);
        $this->contribute($o, $w['cash_a1'], '10.00', '2026-01-15');
        $this->contribute($this->officer($w['b1']), $w['cash_b1'], '10.00', '2026-01-16');
        // A high but non-national grant is not enough.
        $this->assertConcealed($this->fpost($regional, 'finance/periods/2026-01/national-close'));
        $this->fpost($o, 'finance/periods/2026-01/national-close')->assertStatus(404);
        $this->fpost($this->staff(['FINANCE_VIEW'], $w['g']['id'], true), 'finance/periods/2026-01/national-close')->assertStatus(403);
        $this->fpost($national, 'finance/periods/2026-01/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $refused = $this->fpost($national, 'finance/periods/2026-01/national-close')->assertStatus(409)->assertJsonPath('error.code', 'UNITS_NOT_CLOSED');
        $this->assertSame('OPEN', DB::table('accounting_periods')->where('code', '2026-01')->value('status'));
        unset($refused);
        $this->fpost($national, 'finance/periods/2026-01/close', ['unit' => $w['b1']['public_id']])->assertOk();
        $this->fpost($national, 'finance/periods/2026-01/national-close')->assertOk()->assertJsonPath('data.national_status', 'CLOSED');
        $period = DB::table('accounting_periods')->where('code', '2026-01')->first();
        $this->assertSame(['CLOSED', $national['user']], [$period->status, (int) $period->closed_by]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.period_national_closed')->where('unit_id', $w['g']['id'])->count());
        // Irreversible: no unit reopen, no posting, no second close.
        $this->fpost($reopener, 'finance/periods/2026-01/reopen', ['unit' => $w['a1']['public_id'], 'reason' => 'Correcção tardia'])->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->fpost($o, 'finance/receivables', $this->receivableBody($w['a1'], '10.00', ['recognized_on' => '2026-01-20']), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->fpost($national, 'finance/periods/2026-01/national-close')->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->assertSame('NATIONALLY_CLOSED', collect($this->api($o, 'GET', 'finance/periods?unit=' . $w['a1']['public_id'] . '&year=2026')->json('data.items'))->firstWhere('code', '2026-01')['unit_status']);
        $this->assertSame([], array_values(array_filter(get_class_methods(\App\Domain\Finance\FinancePeriods::class), fn ($m) => stripos($m, 'national') !== false && stripos($m, 'reopen') !== false)));
    }

    public function test_p06_pc1_close_versus_accrual_posting_in_both_orders(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $c = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '100.00', '2026-08-01');
        // Posting first (payable recognition holds the August period FOR SHARE): the close waits, the posting counts.
        [$post, $close, $wait] = $this->lockOrdered(['op' => 'recognize_payable', 'user' => $o['user'], 'session' => $o['session'], 'body' => $this->payableBody($w['a1'], '20.00', 'ADM_TAXI', $this->document($w['a1'], 'INVOICE')['public_id'], ['recognized_on' => '2026-08-12'])],
            ['op' => 'close_unit', 'period' => '2026-08', 'user' => $c['user'], 'session' => $c['session'], 'body' => ['unit' => $w['a1']['public_id']]]);
        $this->assertSame(['OK', 'OK', 'accounting_periods', true], [$post['status'], $close['status'], $wait['object'], $wait['blocked_by_first']], json_encode([$post, $close]));
        $closedAt = (string) DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->value('closed_at');
        $this->assertLessThan($closedAt, (string) DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->where('entry_kind', 'PAYABLE_RECOGNITION')->value('posted_at'));
        // Close first (holds the period FOR UPDATE): a waiting settlement / recognition in August is refused, nothing posted.
        $p = $this->payable($o, $w['a1'], '30.00', 'ADM_TAXI', null, ['recognized_on' => '2026-09-01']);
        $c2 = $this->officer($w['a2']);
        $o2 = $this->officer($w['a2']);
        $this->contribute($o2, $w['cash_a2'], '100.00', '2026-08-01');
        $p2 = $this->payable($o2, $w['a2'], '30.00', 'ADM_TAXI', null, ['recognized_on' => '2026-08-02']);
        [$close2, $settle, $wait2] = $this->lockOrdered(['op' => 'close_unit', 'period' => '2026-08', 'user' => $c2['user'], 'session' => $c2['session'], 'body' => ['unit' => $w['a2']['public_id']]],
            ['op' => 'settle', 'kind' => 'payables', 'target' => $p2['public_id'], 'user' => $o2['user'], 'session' => $o2['session'], 'body' => ['account' => $w['cash_a2']['public_id'], 'amount' => '30.00', 'settled_on' => '2026-08-20']]);
        $this->assertSame(['OK', 'PERIOD_CLOSED', 'accounting_periods', true], [$close2['status'], $settle['status'], $wait2['object'], $wait2['blocked_by_first']]);
        $this->assertSame(0, DB::table('settlements as s')->join('accounts as a', 'a.id', '=', 's.account_id')->where('a.unit_id', $w['a2']['id'])->count());
        $c3 = $this->officer($w['b1']);
        [$close3, $recognize, $wait3] = $this->lockOrdered(['op' => 'close_unit', 'period' => '2026-08', 'user' => $c3['user'], 'session' => $c3['session'], 'body' => ['unit' => $w['b1']['public_id']]],
            ['op' => 'recognize_receivable', 'user' => $c3['user'], 'session' => $c3['session'], 'body' => $this->receivableBody($w['b1'], '15.00', ['recognized_on' => '2026-08-25'])]);
        $this->assertSame(['OK', 'PERIOD_CLOSED', true], [$close3['status'], $recognize['status'], $wait3['blocked_by_first']]);
        $this->assertSame(0, DB::table('journal_entries')->where('unit_id', $w['b1']['id'])->whereBetween('entry_date', ['2026-08-01', '2026-08-31'])->count(), 'nothing posted into the closed (period, unit)');
        $this->assertSame('RECOGNIZED', DB::table('payables')->where('public_id', $p['public_id'])->value('status'));
        $this->evidence('PC1', ['posting_first' => compact('post', 'close', 'wait'), 'close_first_settlement' => ['close' => $close2, 'settle' => $settle, 'wait' => $wait2],
            'close_first_recognition' => ['close' => $close3, 'recognize' => $recognize, 'wait' => $wait3]]);
    }

    public function test_p07_pc2_a_national_close_waiting_for_a_posting_sees_it_and_refuses(): void
    {
        $w = $this->world();
        DB::table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')->whereNull('u.parent_id')->where('t.code', 'GENERAL_DIRECTION')
            ->where('u.id', '<>', $w['g']['id'])->update(['u.status' => 'CLOSED']);
        $o = $this->officer($w['a2']);
        $national = $this->staff(['FINANCE_VIEW', 'FINANCE_PERIOD_CLOSE'], $w['g']['id'], true);
        // A2 has no line in 2026-02 yet. Its first posting holds February FOR SHARE; the national close waits for it and,
        // deciding on LOCKING reads (F1C-P02), must see the committed line of an unclosed unit: never a national close
        // over an open unit.
        [$post, $close, $wait] = $this->lockOrdered(['op' => 'recognize_receivable', 'user' => $o['user'], 'session' => $o['session'], 'body' => $this->receivableBody($w['a2'], '12.00', ['recognized_on' => '2026-02-10'])],
            ['op' => 'close_national', 'period' => '2026-02', 'user' => $national['user'], 'session' => $national['session']]);
        $this->assertSame(['OK', 'UNITS_NOT_CLOSED', 'accounting_periods', true], [$post['status'], $close['status'], $wait['object'], $wait['blocked_by_first']], json_encode([$post, $close]));
        $this->assertSame([$w['a2']['public_id']], $close['items']);
        $this->assertSame('OPEN', DB::table('accounting_periods')->where('code', '2026-02')->value('status'));
        $this->evidence('PC2', compact('post', 'close', 'wait'));
    }

    // ---- N FIN-D10 (no negative CASH/BANK) and FIN-D11 (reconciliation of closed periods) -----------------------------

    public function test_n01_no_posting_may_leave_cash_negative(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '100.00', '2026-09-01');
        $p = $this->payable($o, $w['a1'], '150.00', 'ADM_ELECTRICITY', null, ['recognized_on' => '2026-09-02']);
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '150.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');
        $this->assertSame([0, 'RECOGNIZED', 10000], [DB::table('settlements')->where('account_id', $w['cash_a1']['id'])->count(), DB::table('payables')->where('public_id', $p['public_id'])->value('status'), $this->balance($w['cash_a1'])]);
        $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '100.00');
        $this->assertSame(0, $this->balance($w['cash_a1']), 'exactly zero is allowed');
        // Every other posting that reduces cash: a manual expense, an account transfer, a reversal that takes funds back.
        $ledger = new LedgerPostingService(DB::connection());
        $refusals = [];
        $expense = $ledger->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'EXPENSE', 'entry_date' => '2026-09-05', 'description' => 'Táxi',
            'lines' => [['account' => 'OPERATING_EXPENSE', 'category' => 'ADM_TAXI', 'debit' => '0.01'], ['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'credit' => '0.01']]]);
        $move = $ledger->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'ACCOUNT_TRANSFER', 'entry_date' => '2026-09-05', 'description' => 'Para o banco',
            'lines' => [['account' => 'BANK', 'financial_account_id' => $this->account($w['a1'], 'BANK')['id'], 'debit' => '1.00'], ['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'credit' => '1.00']]]);
        foreach ([$expense, $move] as $draft) {
            try {
                $ledger->post($o['user'], $draft['public_id'], 0);
                $refusals[] = 'POSTED';
            } catch (FinanceError $e) {
                $refusals[] = $e->reason;
            }
        }
        $revenue = $ledger->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'REVENUE', 'entry_date' => '2026-09-06', 'description' => 'Receita a estornar',
            'lines' => [['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'debit' => '40.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '40.00']]]);
        $ledger->post($o['user'], $revenue['public_id'], 0);
        $q = $this->payable($o, $w['a1'], '40.00', 'ADM_TAXI', null, ['recognized_on' => '2026-09-06']);
        $this->settle($o, 'payables', $q['public_id'], $w['cash_a1'], '40.00');
        try {
            $ledger->reverse($o['user'], $this->key(), $revenue['public_id'], 'Receita lançada em duplicado', now('Africa/Luanda')->format('Y-m-d'));
            $refusals[] = 'POSTED';
        } catch (FinanceError $e) {
            $refusals[] = $e->reason;
        }
        $this->assertSame(['INSUFFICIENT_FUNDS', 'INSUFFICIENT_FUNDS', 'INSUFFICIENT_FUNDS'], $refusals);
        $this->assertSame(0, $this->balance($w['cash_a1']));
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM (SELECT l.financial_account_id FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.financial_account_id WHERE e.status = 'POSTED' GROUP BY l.financial_account_id HAVING SUM(l.debit) - SUM(l.credit) < 0) x")->n, 'no CASH/BANK account negative anywhere');
    }

    public function test_n02_settlements_and_their_cancellation_cannot_leave_bank_negative(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->openedBank($o, $w['a1'], '50.00', '2026-09-01');
        $p = $this->payable($o, $w['a1'], '80.00', 'ADM_INTERNET', null, ['recognized_on' => '2026-09-02']);
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/settlements', ['account' => $bank['public_id'], 'amount' => '80.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');
        $r = $this->receivable($o, $w['a1'], '100.00', 'REV_OTHER', ['recognized_on' => '2026-09-02']);
        $in = $this->settle($o, 'receivables', $r['public_id'], $bank, '100.00', '2026-09-03');
        $this->settle($o, 'payables', $p['public_id'], $bank, '80.00', '2026-09-04');
        $this->assertSame(7000, $this->balance($bank));
        // Cancelling the receipt would take 100 out of a bank holding 70: refused, nothing changes.
        $this->fpost($o, 'finance/settlements/' . $in['public_id'] . '/cancel', ['reason' => 'Recebimento anulado'])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');
        $this->assertSame(['POSTED', 'SETTLED', 7000], [DB::table('settlements')->where('public_id', $in['public_id'])->value('status'), DB::table('receivables')->where('public_id', $r['public_id'])->value('status'), $this->balance($bank)]);
        $this->assertLedgerInvariants();
    }

    public function test_n03_two_concurrent_payments_cannot_overdraw_the_account(): void
    {
        $w = $this->world();
        $o1 = $this->officer($w['a1']);
        $o2 = $this->officer($w['a1']);
        $this->contribute($o1, $w['cash_a1'], '100.00', '2026-09-01');
        $p1 = $this->payable($o1, $w['a1'], '70.00', 'ADM_FUEL', null, ['recognized_on' => '2026-09-02']);
        $p2 = $this->payable($o1, $w['a1'], '70.00', 'ADM_FUEL', null, ['recognized_on' => '2026-09-02']);
        [$first, $second, $wait] = $this->lockOrdered(
            ['op' => 'settle', 'kind' => 'payables', 'target' => $p1['public_id'], 'user' => $o1['user'], 'session' => $o1['session'], 'body' => ['account' => $w['cash_a1']['public_id'], 'amount' => '70.00']],
            ['op' => 'settle', 'kind' => 'payables', 'target' => $p2['public_id'], 'user' => $o2['user'], 'session' => $o2['session'], 'body' => ['account' => $w['cash_a1']['public_id'], 'amount' => '70.00']]);
        $this->assertSame(['OK', 'INSUFFICIENT_FUNDS'], [$first['status'], $second['status']], json_encode([$first, $second]));
        $this->assertSame(['accounts', true], [$wait['object'], $wait['blocked_by_first']]);
        $this->assertSame([3000, 1], [$this->balance($w['cash_a1']), DB::table('settlements')->where('account_id', $w['cash_a1']['id'])->where('status', 'POSTED')->count()], 'only one payment posted; never negative');
        $this->assertSame(['SETTLED', 'RECOGNIZED'], [DB::table('payables')->where('public_id', $p1['public_id'])->value('status'), DB::table('payables')->where('public_id', $p2['public_id'])->value('status')]);
        $this->evidence('N03', compact('first', 'second', 'wait'));
    }

    public function test_n04_accrual_recognition_needs_no_available_cash(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $this->assertSame(0, $this->balance($w['cash_a1']));
        $p = $this->payable($o, $w['a1'], '1000.00', 'COS_SUPPLIERS');
        $r = $this->receivable($o, $w['a1'], '500.00');
        $this->assertSame(['RECOGNIZED', 'RECOGNIZED'], [$p['status'], $r['status']]);
        $this->assertSame([0, -50000], [$this->balance($w['cash_a1']), $this->resultOf($w['a1'])], 'the obligation is recognised; only its payment needs funds');
        $this->fpost($o, 'finance/payables/' . $p['public_id'] . '/settlements', ['account' => $w['cash_a1']['public_id'], 'amount' => '1.00'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');
    }

    public function test_n05_a_closed_month_can_still_be_reconciled(): void
    {
        $w = $this->world();
        DB::table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')->whereNull('u.parent_id')->where('t.code', 'GENERAL_DIRECTION')
            ->where('u.id', '<>', $w['g']['id'])->update(['u.status' => 'CLOSED']);
        $o = $this->officer($w['a1']);
        $national = $this->staff(['FINANCE_VIEW', 'FINANCE_PERIOD_CLOSE'], $w['g']['id'], true);
        // Unit close (September) and the irreversible national close (March, reserved to this test).
        foreach ([['2026-09', '2026-09-01', '2026-09-30', false], ['2026-03', '2026-03-02', '2026-03-31', true]] as [$month, $on, $end, $nationally]) {
            $bank = $this->openedBank($o, $w['a1'], '100.00', $on);
            $s = $this->statement($o, $bank, '0.00', '100.00', [[$on, '100.00', 'Depósito ' . $month, null]], substr($on, 0, 8) . '01', $end);
            $this->fpost($o, 'finance/periods/' . $month . '/close', ['unit' => $w['a1']['public_id']])->assertOk();
            if ($nationally) {
                $this->fpost($national, 'finance/periods/' . $month . '/national-close')->assertOk();
            }
            $rec = $this->reconcile($o, $bank, $month, $s);
            $matched = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $bank['opening_entry'], 'entry_line' => 1, 'amount' => '100.00'])->assertOk()->json('data');
            $this->assertSame(['MATCHED', '0.00'], [$matched['statement_lines'][0]['state'], $matched['summary']['difference']]);
            $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/close')->assertOk()->assertJsonPath('data.status', 'CLOSED');
            $this->assertSame($nationally ? 'CLOSED' : 'OPEN', DB::table('accounting_periods')->where('code', $month)->value('status'));
            $this->assertSame('CLOSED', DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->where('period_id', DB::table('accounting_periods')->where('code', $month)->value('id'))->value('status'));
        }
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'finance.reconciliation_closed')->where('unit_id', $w['a1']['id'])->count(), 'who reconciled and when stays audited');
    }

    public function test_n06_reconciling_a_closed_month_never_touches_the_ledger(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $bank = $this->openedBank($o, $w['a1'], '300.00', '2026-09-01');
        $r = $this->receivable($o, $w['a1'], '50.00', 'REV_OTHER', ['recognized_on' => '2026-09-10']);
        $in = $this->settle($o, 'receivables', $r['public_id'], $bank, '50.00', '2026-09-11');
        $s = $this->statement($o, $bank, '0.00', '350.00', [['2026-09-01', '300.00', 'Depósito', null], ['2026-09-12', '50.00', 'Recebimento', null]]);
        $this->fpost($o, 'finance/periods/2026-09/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $ledger = fn () => [DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(),
            DB::table('journal_lines')->where('unit_id', $w['a1']['id'])->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(), $this->balance($bank)];
        $before = $ledger();
        $rec = $this->reconcile($o, $bank, '2026-09', $s);
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $bank['opening_entry'], 'entry_line' => 1, 'amount' => '300.00'])->assertOk();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 2, 'entry' => $in['entry'], 'entry_line' => 1, 'amount' => '50.00'])->assertOk();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/unmatch', ['statement_line' => 2, 'entry' => $in['entry'], 'entry_line' => 1])->assertOk();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 2, 'entry' => $in['entry'], 'entry_line' => 1, 'amount' => '50.00'])->assertOk();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/close')->assertOk();
        $this->assertSame($before, $ledger(), 'journals, lines and the closed balance are untouched');
        $this->assertSame(2, DB::table('reconciliation_matches')->where('reconciliation_id', DB::table('reconciliations')->where('public_id', $rec['public_id'])->value('id'))->count());
    }

    public function test_n07_a_difference_found_in_a_closed_month_is_never_posted_into_it(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        [$bank, $s, $rec] = $this->closedMonthWithBankFee($o, $w);
        $septemberEntries = DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->whereBetween('entry_date', ['2026-09-01', '2026-09-30'])->count();
        $result = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $this->key())->assertCreated()->json();
        $entry = DB::table('journal_entries')->where('public_id', $result['meta']['adjustment_entry'])->first();
        $open = DB::table('accounting_periods')->where('id', $entry->period_id)->first();
        $this->assertGreaterThan('2026-09-30', (string) $entry->entry_date, 'the closed month stays intact');
        $this->assertSame(['OPEN', null], [$open->status, DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->where('period_id', $open->id)->value('status')]);
        $this->assertSame($result['meta']['posted_on'], (string) $entry->entry_date);
        $this->assertSame($septemberEntries, DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->whereBetween('entry_date', ['2026-09-01', '2026-09-30'])->count());
        // The ledger refuses any retroactive posting into the closed month.
        try {
            $d = (new LedgerPostingService(DB::connection()))->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'EXPENSE', 'entry_date' => '2026-09-30', 'description' => 'Retroactivo',
                'lines' => [['account' => 'OPERATING_EXPENSE', 'category' => 'FIN_BANK_FEES', 'debit' => '5.00'], ['account' => 'BANK', 'financial_account_id' => $bank['id'], 'credit' => '5.00']]]);
            $this->fail('a closed month must refuse postings: ' . json_encode($d));
        } catch (FinanceError $e) {
            $this->assertSame('PERIOD_CLOSED', $e->reason);
        }
    }

    public function test_n08_a_reconciliation_adjustment_posts_once_in_an_open_period_and_references_its_origin(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        [$bank, $s, $rec] = $this->closedMonthWithBankFee($o, $w);
        $key = $this->key();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 1, 'category' => 'FIN_BANK_FEES'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'NOTHING_TO_ADJUST');
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'REV_OTHER'], $this->key())->assertStatus(422)->assertJsonPath('error.details.fields.category.0', 'invalid');
        $this->fpost($this->officer($w['a1'], [], ['FINANCE_POST']), 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $this->key())->assertStatus(403);
        $this->assertConcealed($this->fpost($this->officer($w['b1']), 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $this->key()));
        $made = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $key)->assertCreated()->json();
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $key)->assertOk()->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('meta.adjustment_entry', $made['meta']['adjustment_entry']);
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ADJUSTED');
        $entry = DB::table('journal_entries')->where('public_id', $made['meta']['adjustment_entry'])->first();
        $statementDocument = (int) DB::table('document_versions')->where('file_id', DB::table('bank_statements')->where('public_id', $s['public_id'])->value('file_id'))->value('document_id');
        $this->assertSame(['EXPENSE', 'POSTED', $statementDocument], [$entry->entry_kind, $entry->status, (int) $entry->document_id], 'the posting points at the bank statement document');
        $this->assertStringContainsString($rec['public_id'], (string) $entry->description);
        $this->assertSame([['OPERATING_EXPENSE', 'EXPENSE', 'FIN_BANK_FEES', '5.0000', '0.0000'], ['BANK', 'ASSET', null, '0.0000', '5.0000']], $this->lines($entry->public_id));
        $audit = json_decode((string) DB::table('audit_logs')->where('action', 'finance.reconciliation_adjustment_posted')->where('unit_id', $w['a1']['id'])->value('after_metadata'), true);
        $this->assertSame([$rec['public_id'], $s['public_id'], 2, $entry->public_id], [$audit['reconciliation'], $audit['statement'], $audit['statement_line'], $audit['entry']]);
        $this->assertSame(9500, $this->balance($bank), 'the fee is now in the open period; September is unchanged');
        // A line of an OPEN month is adjusted on its own date.
        $today = now('Africa/Luanda')->format('Y-m-d');
        $s2 = $this->statement($o, $bank, '95.00', '102.00', [[$today, '7.00', 'Juros creditados', null]], substr($today, 0, 8) . '01', $today);
        $rec2 = $this->reconcile($o, $bank, substr($today, 0, 7), $s2);
        $interest = $this->fpost($o, 'finance/reconciliations/' . $rec2['public_id'] . '/adjustments', ['statement_line' => 1, 'category' => 'NOP_IN_OTHER'], $this->key())->assertCreated()->json('meta');
        $this->assertSame([$today, 'REVENUE'], [$interest['posted_on'], DB::table('journal_entries')->where('public_id', $interest['adjustment_entry'])->value('entry_kind')]);
        $this->assertSame(10200, $this->balance($bank));
    }

    // ---- extra: F-06 sweep, bounded collections ---------------------------------------------------------------------------

    public function test_f06_every_f1c_resource_is_concealed_out_of_scope_and_lists_are_bounded(): void
    {
        $w = $this->world();
        $o = $this->officer($w['a1']);
        $x = $this->officer($w['b1']);
        $r = $this->receivable($o, $w['a1'], '10.00');
        $p = $this->payable($o, $w['a1'], '10.00');
        $this->contribute($o, $w['cash_a1'], '50.00');
        $s = $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '10.00');
        $b = $this->budget($o, $w['a1'], [['REV_OTHER', '1.00']]);
        $resources = ['finance/receivables/' . $r['public_id'], 'finance/payables/' . $p['public_id'], 'finance/settlements/' . $s['public_id'], 'finance/budgets/' . $b['public_id'],
            'finance/accounts/' . $w['cash_a1']['public_id'], 'finance/accounts/' . $w['cash_a1']['public_id'] . '/history'];
        $bodies = [];
        foreach ($resources as $uri) {
            $bodies[] = $this->api($x, 'GET', $uri)->assertStatus(404)->getContent();
            $bodies[] = $this->api($x, 'GET', preg_replace('/[0-9A-HJKMNP-TV-Z]{26}/', (string) Str::ulid(), $uri))->assertStatus(404)->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'one byte-identical concealed body');
        foreach ([['finance/receivables/' . $r['public_id'] . '/settlements', ['account' => $w['cash_b1']['public_id'], 'amount' => '1.00'], true], ['finance/payables/' . $p['public_id'] . '/cancel', ['reason' => 'Intruso'], false],
            ['finance/settlements/' . $s['public_id'] . '/cancel', ['reason' => 'Intruso'], false], ['finance/budgets/' . $b['public_id'] . '/submit', [], false], ['finance/accounts/' . $w['cash_a1']['public_id'] . '/close', [], false]] as [$uri, $body, $keyed]) {
            $this->assertConcealed($this->fpost($x, $uri, $body, $keyed ? $this->key() : null));
        }
        $this->assertSame([[], [], []], [$this->api($x, 'GET', 'finance/receivables')->json('data'), $this->api($x, 'GET', 'finance/payables')->json('data'), $this->api($x, 'GET', 'finance/budgets')->json('data')]);
        $this->assertConcealed($this->api($x, 'GET', 'finance/receivables?unit=' . $w['a1']['public_id']));
        $this->api($this->staff(['PEOPLE_VIEW'], $w['a1']['id']), 'GET', 'finance/receivables')->assertStatus(403);
        // Bounded collections.
        for ($i = 0; $i < 3; $i++) {
            $this->receivable($o, $w['a1'], '1.00');
        }
        $page = $this->api($o, 'GET', 'finance/receivables?per_page=2')->assertOk()->json();
        $this->assertSame([2, 2, 4, 2], [count($page['data']), $page['meta']['per_page'], $page['meta']['total'], $page['meta']['last_page']]);
        foreach (['receivables', 'payables', 'accounts', 'bank-statements', 'reconciliations', 'budgets'] as $collection) {
            $this->api($o, 'GET', 'finance/' . $collection . '?per_page=101')->assertStatus(422);
            $this->assertSame(50, $this->api($o, 'GET', 'finance/' . $collection)->assertOk()->json('meta.per_page'));
        }
        $this->api($o, 'GET', 'finance/receivables?status=PAID')->assertStatus(422);
        $ctx = $this->api($o, 'GET', 'finance/context')->assertOk()->json('data');
        $this->assertNotContains('REV_TITHES', array_column($ctx['receivable_categories'], 'code'));
        $this->assertTrue(collect($ctx['payable_categories'])->firstWhere('code', 'INV_AST_IT')['capitalized']);
        $this->assertContains('2026', $ctx['years']);
        $this->assertNoInternalIds($ctx);
    }

    // ---- helpers --------------------------------------------------------------------------------------------------------

    /** BANK 100 opened 2026-09-01, September statement (+100 deposit, -5 bank fee), September CLOSED for the unit, reconciliation with the deposit matched. */
    private function closedMonthWithBankFee(array $o, array $w): array
    {
        $bank = $this->openedBank($o, $w['a1'], '100.00', '2026-09-01');
        $s = $this->statement($o, $bank, '0.00', '95.00', [['2026-09-01', '100.00', 'Depósito', null], ['2026-09-30', '-5.00', 'Comissão bancária', 'COM-09']]);
        $this->fpost($o, 'finance/periods/2026-09/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $rec = $this->reconcile($o, $bank, '2026-09', $s);
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $bank['opening_entry'], 'entry_line' => 1, 'amount' => '100.00'])->assertOk();
        return [$bank, $s, $rec];
    }

    private function officer(array $unit, array $extra = [], array $without = []): array
    {
        return $this->staff(array_values(array_diff(array_merge(self::OFFICER, $extra), $without)), $unit['id'], false);
    }

    /** Budget approver at the parent level (grants decide the level; no role is created). */
    private function approver(array $unit): array
    {
        return $this->staff(['FINANCE_VIEW', 'FINANCE_BUDGET_APPROVE'], $unit['id'], true);
    }

    private function receivableBody(array $unit, string $amount, array $extra = []): array
    {
        return $extra + ['unit' => $unit['public_id'], 'party' => ['kind' => 'EXTERNAL', 'name' => 'Cliente Externo'], 'category' => 'REV_OTHER', 'amount' => $amount, 'due_on' => '2026-12-31'];
    }

    private function payableBody(array $unit, string $amount, string $category, string $document, array $extra = []): array
    {
        return $extra + ['unit' => $unit['public_id'], 'party' => ['kind' => 'EXTERNAL', 'name' => 'Fornecedor Externo'], 'category' => $category, 'amount' => $amount, 'due_on' => '2026-12-31', 'document' => $document];
    }

    private function receivable(array $actor, array $unit, string $amount, string $category = 'REV_OTHER', array $extra = []): array
    {
        return $this->fpost($actor, 'finance/receivables', $this->receivableBody($unit, $amount, ['category' => $category] + $extra), $this->key())->assertCreated()->json('data');
    }

    private function payable(array $actor, array $unit, string $amount, string $category = 'ADM_ELECTRICITY', ?string $document = null, array $extra = []): array
    {
        return $this->fpost($actor, 'finance/payables', $this->payableBody($unit, $amount, $category, $document ?? $this->document($unit, 'INVOICE')['public_id'], $extra), $this->key())->assertCreated()->json('data');
    }

    private function settle(array $actor, string $kind, string $publicId, array $account, string $amount, ?string $on = null): array
    {
        return $this->fpost($actor, 'finance/' . $kind . '/' . $publicId . '/settlements', ['account' => $account['public_id'], 'amount' => $amount] + ($on === null ? [] : ['settled_on' => $on]), $this->key())
            ->assertCreated()->json('data');
    }

    /** BANK account opened through the API with an opening balance (OPENING_BALANCE entry). */
    private function openedBank(array $actor, array $unit, string $opening, string $on): array
    {
        $a = $this->fpost($actor, 'finance/accounts', ['unit' => $unit['public_id'], 'kind' => 'BANK', 'code' => 'BK-' . Str::upper(Str::random(6)), 'name' => 'Banco ' . Str::random(4), 'opened_on' => $on,
            'opening_balance' => $opening, 'bank_name' => 'Banco Exemplo', 'account_number' => '9999' . random_int(100000, 999999)], $this->key())->assertCreated()->json('data');
        return ['id' => (int) DB::table('accounts')->where('public_id', $a['public_id'])->value('id'), 'public_id' => $a['public_id'], 'unit' => $unit['id'], 'opening_entry' => $a['opening_entry']['entry'] ?? null];
    }

    private function openedCash(array $actor, array $unit, ?string $opening, string $on): array
    {
        $a = $this->fpost($actor, 'finance/accounts', ['unit' => $unit['public_id'], 'kind' => 'CASH', 'code' => 'CX-' . Str::upper(Str::random(6)), 'name' => 'Caixa ' . Str::random(4), 'opened_on' => $on,
            'custodian' => $this->person($unit)['public_id']] + ($opening === null ? [] : ['opening_balance' => $opening]), $this->key())->assertCreated()->json('data');
        return ['id' => (int) DB::table('accounts')->where('public_id', $a['public_id'])->value('id'), 'public_id' => $a['public_id'], 'unit' => $unit['id'], 'opening_entry' => $a['opening_entry']['entry'] ?? null];
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: ?string}> $lines */
    private function statementBody(array $account, array $document, string $opening, string $closing, array $lines, string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return ['account' => $account['public_id'], 'starts_on' => $from, 'ends_on' => $to, 'opening_balance' => $opening, 'closing_balance' => $closing, 'document' => $document['public_id'],
            'lines' => array_map(fn ($l) => ['occurred_on' => $l[0], 'amount' => $l[1], 'description' => $l[2]] + ($l[3] === null ? [] : ['reference' => $l[3]]), $lines)];
    }

    private function statement(array $actor, array $account, string $opening, string $closing, array $lines, string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        $unit = ['id' => (int) DB::table('accounts')->where('id', $account['id'])->value('unit_id')];
        return $this->fpost($actor, 'finance/bank-statements', $this->statementBody($account, $this->document($unit, 'BANK_STATEMENT'), $opening, $closing, $lines, $from, $to), $this->key())->assertCreated()->json('data');
    }

    private function reconcile(array $actor, array $account, string $period, array $statement): array
    {
        return $this->fpost($actor, 'finance/reconciliations', ['account' => $account['public_id'], 'period' => $period, 'statement' => $statement['public_id']], $this->key())->assertCreated()->json('data');
    }

    private function reconcileAfterClosing(array $actor, array $account, string $period, array $statement, array $open): array
    {
        $this->fpost($actor, 'finance/reconciliations/' . $open['public_id'] . '/close')->assertOk();
        return $this->reconcile($actor, $account, $period, $statement);
    }

    /** @param list<array{0: string, 1: string}> $lines */
    private function budget(array $actor, array $unit, array $lines): array
    {
        return $this->fpost($actor, 'finance/budgets', ['unit' => $unit['public_id'], 'year' => '2026', 'lines' => array_map(fn ($l) => ['category' => $l[0], 'requested_amount' => $l[1]], $lines)], $this->key())
            ->assertCreated()->json('data');
    }

    private function submittedBudget(array $manager, array $unit, array $lines): array
    {
        $b = $this->budget($manager, $unit, $lines);
        $this->fpost($manager, 'finance/budgets/' . $b['public_id'] . '/submit')->assertOk();
        return $b;
    }

    private function reviewedBudget(array $manager, array $reviewer, array $unit, array $lines, ?array $unitManager = null): array
    {
        $b = $this->submittedBudget($unitManager ?? $manager, $unit, $lines);
        $this->fpost($reviewer, 'finance/budgets/' . $b['public_id'] . '/review')->assertOk();
        return $b;
    }

    /** [role, class, rubric, debit, credit] of the lines of an entry, in line order. */
    private function lines(string $entry): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('financial_categories as c', 'c.id', '=', 'l.category_id')->where('e.public_id', $entry)->orderBy('l.line_number')
            ->get(['a.system_role', 'a.account_kind', 'c.code', 'l.debit', 'l.credit'])->map(fn ($l) => [$l->system_role, $l->account_kind, $l->code, (string) $l->debit, (string) $l->credit])->all();
    }

    private function entryKind(string $entry): array
    {
        $e = DB::table('journal_entries')->where('public_id', $entry)->first();
        return [(string) $e->entry_kind, (string) $e->status];
    }

    private function entryRows(string $entry): array
    {
        $id = DB::table('journal_entries')->where('public_id', $entry)->value('id');
        return [(array) DB::table('journal_entries')->where('id', $id)->first(), DB::table('journal_lines')->where('entry_id', $id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
    }

    private function expense(array $unit): int
    {
        return (new LedgerQueries(DB::connection()))->economicResult($unit['id'], '2026-01-01', '2026-12-31')['expense'];
    }

    /**
     * Deterministic two-connection ordering (F1A C3 / F1B protocol): FIRST runs its operation inside its business
     * transaction and HOLDS it (HELD from the before-commit hook: every lock acquired); SECOND is released and must be
     * OBSERVED in performance_schema.data_lock_waits blocked by FIRST's connection; only then FIRST commits.
     */
    private function lockOrdered(array $first, array $second): array
    {
        $a = $this->spawn($first + ['hold_until_commit' => true, 'announce' => true]);
        $b = $this->spawn($second + ['announce' => true]);
        fwrite($a[1][0], "go\n");
        fflush($a[1][0]);
        $firstConnection = (int) substr(trim(str_replace("\r", '', (string) fgets($a[1][1]))), 8);
        $held = str_replace("\r", '', (string) fgets($a[1][1]));
        if ($held !== "HELD\n") {
            $this->fail('FIRST must hold its locks: ' . $held . stream_get_contents($a[1][1]) . stream_get_contents($a[1][2]));
        }
        // Never read FIRST's remaining output while it holds (P0.9 lesson): it only ends after "commit".
        fwrite($b[1][0], "go\n");
        fclose($b[1][0]);
        $running = str_replace("\r", '', (string) fgets($b[1][1]));
        $this->assertMatchesRegularExpression('/^RUNNING \d+\n$/', $running);
        $connection = (int) substr(trim($running), 8);
        $wait = null;
        for ($i = 0; $i < 750 && $wait === null; $i++) {
            $lock = DB::selectOne('SELECT l.OBJECT_NAME AS o, l.LOCK_MODE AS m, bt.PROCESSLIST_ID AS blocker FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID WHERE t.PROCESSLIST_ID = ?', [$connection]);
            if ($lock !== null) {
                $wait = ['object' => $lock->o, 'mode' => $lock->m, 'state' => 'LOCK WAIT', 'blocked_by_first' => (int) $lock->blocker === $firstConnection];
            } else {
                usleep(20000); // observation poll only; the outcome never depends on it
            }
        }
        // Release FIRST and collect both processes BEFORE asserting (a failed observation never leaves locks held).
        fwrite($a[1][0], "commit\n");
        fclose($a[1][0]);
        $results = [$this->finish($a), $this->finish($b)];
        $this->assertNotNull($wait, "SECOND was never observed waiting on FIRST's lock: " . json_encode($results));
        return [$results[0], $results[1], $wait];
    }

    private function spawn(array $job): array
    {
        $root = dirname(__DIR__, 4);
        $php = getenv('MEPA_PHP_BIN') ?: 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe';
        $env = array_merge(getenv(), ['WAVE5_ALLOW_SYNTHETIC' => '1', 'XDEBUG_MODE' => 'off', 'APP_KEY' => (string) config('app.key')]);
        $proc = proc_open([$php, $root . '/scripts/p010-f1c-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        $line = str_replace("\r", '', (string) fgets($pipes[1]));
        if ($line !== "READY\n") {
            $this->fail('worker not ready: ' . $line . stream_get_contents($pipes[2]));
        }
        return [$proc, $pipes];
    }

    private function finish(array $worker): array
    {
        [$proc, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
        $result = json_decode(trim((string) $out), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('UNEXPECTED', $result['status'], json_encode($result));
        return $result;
    }

    private function evidence(string $name, array $data): void
    {
        $dir = getenv('P010_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'concurrency-' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
