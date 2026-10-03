<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\FinanceHttpCase;

/**
 * P0.10-F1D-E1 evidence closure (ADR 0021 D15/D16/D17/D20/D21/D30 C8 + D-04A + FIN-D10/FIN-D11). Every R01-R36 scenario
 * that the API can prove is EXECUTED here over HTTP against an isolated Wave 5 pool; R31 / R35 / R36 are also executed
 * in the browser (apps/web/tests/e2e/finance-reporting.spec.ts). Expected figures are written by hand in each test
 * (never computed with production code). The accounting cross-check (§4) and C8 (two real MySQL connections with a
 * deterministic barrier, scripts/p010-f1d-report-worker.php) are separate tests. The product is frozen: this file
 * only reads the API and seeds through it (the ledger domain is used directly only to create a DRAFT that must stay
 * invisible).
 */
final class FinanceReportingEvidenceTest extends FinanceHttpCase
{
    private const OFFICER = ['FINANCE_VIEW', 'FINANCE_MANAGE', 'FINANCE_POST', 'FINANCE_TRANSFER', 'FINANCE_RECONCILE', 'FINANCE_REPORT', 'FINANCE_REVERSE', 'FINANCE_ACCOUNT_MANAGE',
        'FINANCE_PERIOD_CLOSE', 'FINANCE_BUDGET_MANAGE', 'DOCUMENTS_VIEW', 'PEOPLE_VIEW'];

    // ---- R01-R05 own DRE on the accrual basis ------------------------------------------------------------------------

    public function test_r01_own_dre_counts_posted_external_revenue_only(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00', '2026-08-02');
        (new LedgerPostingService(DB::connection()))->createDraft($o['user'], $this->key(), ['unit_id' => $w['a1']['id'], 'entry_kind' => 'REVENUE', 'entry_date' => '2026-08-03', 'description' => 'Rascunho invisível',
            'lines' => [['account' => 'CASH', 'financial_account_id' => $w['cash_a1']['id'], 'debit' => '999.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '999.00']]]);
        $dre = $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08');
        $this->assertSame(['1000.00', '0.00', '1000.00', 'ACCRUAL_POSTED_LEDGER', 'OWN'], [$dre['dre']['revenue'], $dre['dre']['expenses'], $dre['dre']['economic_result'], $dre['dre']['basis'], $dre['view']]);
        $this->assertSame([['REV_TITHES', '1000.00']], array_map(fn ($l) => [$l['category']['code'], $l['amount']], $dre['dre']['revenue_lines']), 'the DRAFT 999 never appears');
        $this->assertNoInternalIds($dre);
    }

    public function test_r02_accrual_revenue_is_in_the_dre_before_any_cash(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->receivable($o, $w['a1'], '500.00', '2026-08-05');
        $this->assertSame(['500.00', '500.00'], [$this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08')['dre']['revenue'], $this->report($o, 'REVENUE_SUMMARY', $w['a1'], 'period_kind=MONTH&period=2026-08')['summary'][0]['amount']]);
        $doaf = $this->report($o, 'OWN_DOAF', $w['a1'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['0.00', '0.00'], [$doaf['external_funds_received'], $doaf['closing_balance']], 'no cash has arrived');
    }

    public function test_r03_accrual_expense_is_in_the_dre_before_payment(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->payable($o, $w['a1'], '300.00', 'ADM_ELECTRICITY', '2026-08-06');
        $dre = $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08')['dre'];
        $this->assertSame(['0.00', '300.00', '-300.00'], [$dre['revenue'], $dre['expenses'], $dre['economic_result']]);
        $this->assertSame('300.00', $this->report($o, 'EXPENSE_SUMMARY', $w['a1'], 'period_kind=MONTH&period=2026-08')['summary'][0]['amount']);
        $this->assertSame('0.00', $this->report($o, 'OWN_DOAF', $w['a1'], 'period_kind=MONTH&period=2026-08')['doaf']['external_applications'], 'nothing paid yet');
    }

    public function test_r04_settlements_never_duplicate_the_dre(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00', '2026-08-01');
        $r = $this->receivable($o, $w['a1'], '500.00', '2026-08-05');
        $p = $this->payable($o, $w['a1'], '300.00', 'ADM_ELECTRICITY', '2026-08-06');
        $this->settle($o, 'receivables', $r['public_id'], $w['cash_a1'], '500.00', '2026-08-07');
        $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '300.00', '2026-08-08');
        $dre = $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08')['dre'];
        // Hand-computed: revenue = tithes 1000 + receivable 500; expense = payable 300 (settlements add nothing).
        $this->assertSame(['1500.00', '300.00', '1200.00'], [$dre['revenue'], $dre['expenses'], $dre['economic_result']]);
        $doaf = $this->report($o, 'OWN_DOAF', $w['a1'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['1500.00', '300.00', '1200.00', true], [$doaf['external_funds_received'], $doaf['external_applications'], $doaf['closing_balance'], $doaf['balanced']]);
    }

    public function test_r05_a_capitalised_investment_has_zero_dre_effect(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->payable($o, $w['a1'], '2000.00', 'INV_AST_IT', '2026-08-06');
        $this->payable($o, $w['a1'], '150.00', 'INV_COM_DIGITAL', '2026-08-06');
        $dre = $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08')['dre'];
        $this->assertSame(['150.00', '150.00', '2000.00', '-150.00'], [$dre['expenses'], $dre['investments_consumed'], $dre['investments_capitalized'], $dre['economic_result']]);
        $this->assertSame(['INV_COM_DIGITAL'], array_map(fn ($l) => $l['category']['code'], $dre['expense_lines']), 'the capitalised acquisition is never an expense line');
    }

    // ---- R06-R11 own DOAF, the normative chain, consolidation -----------------------------------------------------------

    public function test_r06_own_doaf_shows_internal_funds_received(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $doaf = $this->report($actors['a'], 'OWN_DOAF', $w['a'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['0.00', '60.00', '40.00', '20.00', true], [$doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['internal_funds_sent'], $doaf['closing_balance'], $doaf['balanced']]);
        $received = $this->report($actors['a'], 'INTERNAL_FUNDS_RECEIVED', $w['a'], 'period_kind=MONTH&period=2026-08')['transfers'];
        $this->assertSame([[$w['a1']['public_id'], '60.00']], array_map(fn ($t) => [$t['origin']['public_id'], $t['amount']], $received));
    }

    public function test_r07_own_doaf_shows_internal_funds_sent(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $doaf = $this->report($actors['a1'], 'OWN_DOAF', $w['a1'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['100.00', '0.00', '60.00', '40.00'], [$doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['internal_funds_sent'], $doaf['closing_balance']]);
        $sent = $this->report($actors['a1'], 'INTERNAL_FUNDS_SENT', $w['a1'], 'period_kind=MONTH&period=2026-08')['transfers'];
        $this->assertSame([[$w['a']['public_id'], '60.00']], array_map(fn ($t) => [$t['destination']['public_id'], $t['amount']], $sent));
    }

    public function test_r08_own_doaf_balances_with_an_opening_balance(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '700.00', '2026-07-10');
        $this->contribute($o, $w['cash_a1'], '300.00', '2026-08-10');
        $p = $this->payable($o, $w['a1'], '250.00', 'ADM_WATER', '2026-08-11');
        $this->settle($o, 'payables', $p['public_id'], $w['cash_a1'], '250.00', '2026-08-12');
        $doaf = $this->report($o, 'OWN_DOAF', $w['a1'], 'period_kind=MONTH&period=2026-08')['doaf'];
        // opening 700 + external 300 - applications 250 = closing 750; origins 1000 = applications 250 + 750.
        $this->assertSame(['700.00', '300.00', '250.00', '750.00', '1000.00', '1000.00', true],
            [$doaf['opening_balance'], $doaf['external_funds_received'], $doaf['external_applications'], $doaf['closing_balance'], $doaf['total_origins'], $doaf['total_applications'], $doaf['balanced']]);
    }

    public function test_r09_the_normative_100_60_40_chain_recognises_revenue_once(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $q = 'period_kind=MONTH&period=2026-08';
        $own = fn (string $u) => $this->report($actors[$u], 'OWN_DRE', $w[$u], $q)['dre'];
        $this->assertSame(['100.00', '0.00', '0.00'], [$own('a1')['revenue'], $own('a')['revenue'], $own('m')['revenue']], 'only the external origin is revenue');
        $this->assertSame(['0.00', '0.00', '0.00'], [$own('a1')['expenses'], $own('a')['expenses'], $own('m')['expenses']], 'no SEND is an expense');
        $this->assertSame(['0.00', '0.00', '0.00'], [$own('a1')['internal_transfer_effect'], $own('a')['internal_transfer_effect'], $own('m')['internal_transfer_effect']]);
        $this->assertSame(['40.00', '20.00', '40.00'], [$this->doaf($actors['a1'], $w['a1'], $q)['closing_balance'], $this->doaf($actors['a'], $w['a'], $q)['closing_balance'], $this->doaf($actors['m'], $w['m'], $q)['closing_balance']]);
    }

    public function test_r10_consolidated_dre_counts_the_external_100_once(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $dre = $this->report($actors['cons_m'], 'CONSOLIDATED_DRE', $w['m'], 'period_kind=MONTH&period=2026-08');
        $this->assertSame(['CONSOLIDATED', '100.00', '0.00', '100.00'], [$dre['view'], $dre['dre']['revenue'], $dre['dre']['expenses'], $dre['dre']['economic_result']]);
        $this->assertSame(6, $dre['perimeter_units'], 'm, a, b, a1, a2, b1');
    }

    public function test_r11_consolidated_doaf_eliminates_internal_transfers(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $doaf = $this->report($actors['cons_m'], 'CONSOLIDATED_DOAF', $w['m'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['100.00', '0.00', '0.00', '0.00', '100.00', true, true],
            [$doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['internal_funds_sent'], $doaf['funds_in_transit_under_custody'], $doaf['closing_balance'], $doaf['balanced'], $doaf['internal_transfers_eliminated']]);
        $this->assertSame(['INTERNAL_TO_PERIMETER', 'INTERNAL_TO_PERIMETER'], array_column($doaf['memo_internal_transfers'], 'perimeter_class'));
    }

    // ---- R12-R16 perimeter classes, transit, positions ----------------------------------------------------------------

    public function test_r12_into_perimeter_is_kept_as_internal_funds_received(): void
    {
        $w = $this->reportingWorld();
        $consA = $this->officer($w['a'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $b1 = $this->officer($w['b1']);
        $this->contribute($b1, $w['cash_b1'], '50.00', '2026-08-02');
        $this->move($b1, $this->officer($w['a1']), $w['cash_b1'], $w['a1'], $w['cash_a1'], '50.00', '2026-08-03');
        $doaf = $this->report($consA, 'CONSOLIDATED_DOAF', $w['a'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['0.00', '50.00', '0.00', '50.00', true], [$doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['internal_funds_sent'], $doaf['closing_balance'], $doaf['balanced']]);
        $this->assertSame(['INTO_PERIMETER'], array_column($doaf['memo_internal_transfers'], 'perimeter_class'));
        $this->assertSame('0.00', $this->report($consA, 'CONSOLIDATED_DRE', $w['a'], 'period_kind=MONTH&period=2026-08')['dre']['revenue'], 'the revenue stays with b1, outside the perimeter');
    }

    public function test_r13_out_of_perimeter_is_kept_as_internal_funds_sent(): void
    {
        $w = $this->reportingWorld();
        $consA = $this->officer($w['a'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $a2 = $this->officer($w['a2']);
        $this->contribute($a2, $w['cash_a2'], '80.00', '2026-08-02');
        $this->move($a2, $this->officer($w['b']), $w['cash_a2'], $w['b'], $w['cash_b'], '30.00', '2026-08-03');
        $doaf = $this->report($consA, 'CONSOLIDATED_DOAF', $w['a'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['80.00', '0.00', '30.00', '50.00', true], [$doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['internal_funds_sent'], $doaf['closing_balance'], $doaf['balanced']]);
        $this->assertSame(['OUT_OF_PERIMETER'], array_column($doaf['memo_internal_transfers'], 'perimeter_class'));
    }

    public function test_r14_funds_in_transit_are_custody_never_revenue(): void
    {
        $w = $this->reportingWorld();
        $consA = $this->officer($w['a'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $a1 = $this->officer($w['a1']);
        $this->contribute($a1, $w['cash_a1'], '100.00', '2026-08-02');
        $t = $this->requestTransfer($a1, $w['cash_a1'], $w['a'], '70.00');
        $this->sendTransfer($a1, $t['public_id'], '2026-08-03');
        $own = $this->report($a1, 'OWN_DOAF', $w['a1'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['70.00', '30.00'], [$own['internal_funds_sent'], $own['closing_balance']]);
        $cons = $this->report($consA, 'CONSOLIDATED_DOAF', $w['a'], 'period_kind=MONTH&period=2026-08')['doaf'];
        $this->assertSame(['0.00', '70.00', '30.00', '100.00', true], [$cons['internal_funds_sent'], $cons['funds_in_transit_under_custody'], $cons['treasury_closing'], $cons['closing_balance'], $cons['balanced']]);
        $this->assertSame(['100.00', '100.00'], [$this->report($consA, 'CONSOLIDATED_DRE', $w['a'], 'period_kind=MONTH&period=2026-08')['dre']['revenue'], $this->report($a1, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08')['dre']['revenue']]);
        $dashboard = $this->api($consA, 'GET', 'finance/dashboard?unit=' . $w['a']['public_id'] . '&view=CONSOLIDATED&period_kind=MONTH&period=2026-08')->assertOk()->json('data.dashboard');
        $this->assertSame(['100.00', '70.00', '100.00'], [$dashboard['revenue'], $dashboard['in_transit'], $dashboard['cash_bank_position']], 'in-transit is custody, never revenue');
        $position = $this->report($a1, 'INTERUNIT_POSITION', $w['a1'], 'period_kind=MONTH&period=2026-08')['position'];
        $this->assertSame(['70.00', '70.00', [$t['public_id']]], [$position['sent'], $position['in_transit'], $position['transfers']]);
    }

    public function test_r15_interunit_position_uses_the_control_accounts(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $a = $this->report($actors['a'], 'INTERUNIT_POSITION', $w['a'], 'period_kind=MONTH&period=2026-08')['position'];
        $this->assertSame(['40.00', '60.00', '20.00', '0.00', 'INTERUNIT_CONTROL'], [$a['sent'], $a['received'], $a['net_control_position'], $a['in_transit'], $a['account_class']]);
        $rec = $this->report($actors['a'], 'INTERUNIT_RECONCILIATION', $w['a'], 'period_kind=MONTH&period=2026-08')['reconciliation'];
        $this->assertCount(2, $rec);
        $this->assertSame(['RECEIVED', 'RECEIVED'], array_column($rec, 'status'));
    }

    public function test_r16_cash_bank_position_is_derived_and_always_fresh(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00', '2026-07-10');
        $this->contribute($o, $w['cash_a1'], '500.00', '2026-08-10');
        $t = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '200.00');
        $this->sendTransfer($o, $t['public_id'], '2026-08-11');
        $row = fn () => collect($this->report($o, 'CASH_BANK_BALANCES', $w['a1'], 'period_kind=MONTH&period=2026-08')['treasury'])->firstWhere('account.public_id', $w['cash_a1']['public_id']);
        $this->assertSame(['1000.00', '500.00', '200.00', '1300.00'], [$row()['opening'], $row()['inflows'], $row()['outflows'], $row()['closing']]);
        $this->contribute($o, $w['cash_a1'], '25.00', '2026-08-12');
        $this->assertSame(['525.00', '1325.00'], [$row()['inflows'], $row()['closing']], 'a new POSTED line appears at once: no cached or stored balance');
        $this->assertSame(132500, $this->balance($w['cash_a1']));
    }

    // ---- R17-R20 budget, receivables, payables --------------------------------------------------------------------------

    public function test_r17_budget_versus_actual_uses_the_approved_budget_and_posted_actuals(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->approvedBudget($w, [['REV_TITHES', '900.00'], ['ADM_ELECTRICITY', '250.00']]);
        $this->contribute($o, $w['cash_a1'], '1000.00', '2026-08-02');
        $this->payable($o, $w['a1'], '300.00', 'ADM_ELECTRICITY', '2026-08-03');
        $b = $this->report($o, 'BUDGET_VS_ACTUAL', $w['a1'], 'period_kind=YEAR&period=2026')['budget_vs_actual'];
        // approved 900 + 250 = 1150; actual revenue 1000 + electricity 300 = 1300; variance 150.
        $this->assertSame(['2026', '1150.00', '1300.00', '150.00', 'CURRENT_APPROVED_BUDGET_AND_POSTED_LEDGER'], [$b['year'], $b['approved_budget'], $b['actual'], $b['variance'], $b['basis']]);
    }

    public function test_r18_a_superseded_budget_is_ignored(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        [$v1, $m, $a] = $this->approvedBudget($w, [['REV_TITHES', '900.00'], ['ADM_ELECTRICITY', '250.00']]);
        $v2 = $this->fpost($m, 'finance/budgets/' . $v1 . '/revise', [], $this->key())->assertCreated()->json('data.public_id');
        $this->fpost($m, 'finance/budgets/' . $v2 . '/lines', ['lines' => [['category' => 'REV_TITHES', 'requested_amount' => '2000.00'], ['category' => 'ADM_ELECTRICITY', 'requested_amount' => '250.00']]])->assertOk();
        $this->fpost($m, 'finance/budgets/' . $v2 . '/submit')->assertOk();
        $this->fpost($a, 'finance/budgets/' . $v2 . '/review')->assertOk();
        $this->fpost($a, 'finance/budgets/' . $v2 . '/approve')->assertOk();
        $this->assertSame('SUPERSEDED', DB::table('budgets')->where('public_id', $v1)->value('status'));
        $this->assertSame('2250.00', $this->report($o, 'BUDGET_VS_ACTUAL', $w['a1'], 'period_kind=YEAR&period=2026')['budget_vs_actual']['approved_budget'], 'only v2 (2000 + 250), never v1 + v2');
    }

    public function test_r19_receivables_report_lists_what_is_open_at_the_report_end(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '10.00', '2026-08-01');
        $open = $this->receivable($o, $w['a1'], '500.00', '2026-08-05', '2026-12-31');
        $this->settle($o, 'receivables', $open['public_id'], $w['cash_a1'], '200.00', '2026-08-10');
        $done = $this->receivable($o, $w['a1'], '80.00', '2026-08-06', '2026-08-20');
        $this->settle($o, 'receivables', $done['public_id'], $w['cash_a1'], '80.00', '2026-08-21');
        $rows = $this->report($o, 'RECEIVABLES', $w['a1'], 'period_kind=MONTH&period=2026-08')['receivables'];
        // Open at 31/08: the 500 receivable (not yet due, 200 received) -> 300; the settled 80 is not open.
        $this->assertSame([[$open['public_id'], '300.00']], array_map(fn ($r) => [$r['public_id'], $r['outstanding']], $rows));
        $this->assertSame([], $this->report($o, 'RECEIVABLES', $w['a1'], 'period_kind=MONTH&period=2026-07')['receivables'], 'nothing was recognised in July');
        $this->assertSame('300.00', $this->report($o, 'FINANCE_PERIOD_SUMMARY', $w['a1'], 'period_kind=MONTH&period=2026-08')['dashboard']['receivables']);
        // As of the report end: a later settlement never reduces an earlier report; a later recognition never appears.
        $this->settle($o, 'receivables', $open['public_id'], $w['cash_a1'], '100.00', '2026-09-05');
        $late = $this->receivable($o, $w['a1'], '40.00', '2026-09-06');
        $this->assertSame([[$open['public_id'], '300.00']], array_map(fn ($r) => [$r['public_id'], $r['outstanding']], $this->report($o, 'RECEIVABLES', $w['a1'], 'period_kind=MONTH&period=2026-08')['receivables']));
        $this->assertSame([[$open['public_id'], '200.00'], [$late['public_id'], '40.00']], array_map(fn ($r) => [$r['public_id'], $r['outstanding']], $this->report($o, 'RECEIVABLES', $w['a1'], 'period_kind=MONTH&period=2026-09')['receivables']));
    }

    public function test_r20_payables_report_lists_what_is_open_at_the_report_end(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '1000.00', '2026-08-01');
        $open = $this->payable($o, $w['a1'], '400.00', 'ADM_ELECTRICITY', '2026-08-05', '2026-11-30');
        $this->settle($o, 'payables', $open['public_id'], $w['cash_a1'], '150.00', '2026-08-10');
        $done = $this->payable($o, $w['a1'], '60.00', 'ADM_WATER', '2026-08-06', '2026-08-15');
        $this->settle($o, 'payables', $done['public_id'], $w['cash_a1'], '60.00', '2026-08-15');
        $rows = $this->report($o, 'PAYABLES', $w['a1'], 'period_kind=MONTH&period=2026-08')['payables'];
        $this->assertSame([[$open['public_id'], '250.00']], array_map(fn ($r) => [$r['public_id'], $r['outstanding']], $rows));
        $this->assertSame('250.00', $this->report($o, 'FINANCE_PERIOD_SUMMARY', $w['a1'], 'period_kind=MONTH&period=2026-08')['dashboard']['payables']);
        $this->settle($o, 'payables', $open['public_id'], $w['cash_a1'], '250.00', '2026-09-03');
        $this->assertSame(['250.00', '0.00'], [$this->report($o, 'FINANCE_PERIOD_SUMMARY', $w['a1'], 'period_kind=MONTH&period=2026-08')['dashboard']['payables'], $this->report($o, 'FINANCE_PERIOD_SUMMARY', $w['a1'], 'period_kind=MONTH&period=2026-09')['dashboard']['payables']]);
        $this->assertSame([], $this->report($o, 'PAYABLES', $w['a1'], 'period_kind=MONTH&period=2026-09')['payables'], 'fully settled: no longer open');
    }

    // ---- R21-R24 temporal aggregation over a dataset that spans months ---------------------------------------------

    public function test_r21_r24_month_quarter_semester_and_year_aggregate_the_right_months(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        foreach ([['2026-01-20', '10.00'], ['2026-04-15', '20.00'], ['2026-07-10', '40.00'], ['2026-08-12', '80.00'], ['2026-09-05', '160.00']] as [$on, $amount]) {
            $this->contribute($o, $w['cash_a1'], $amount, $on);
        }
        $cases = [
            'R21 month' => ['period_kind=MONTH&period=2026-08', '80.00', '2026-08-01', '2026-08-31', '70.00'],
            'R22 quarter' => ['period_kind=QUARTER&period=2026-Q3', '280.00', '2026-07-01', '2026-09-30', '30.00'],
            'R23 semester' => ['period_kind=SEMESTER&period=2026-H1', '30.00', '2026-01-01', '2026-06-30', '0.00'],
            'R24 year' => ['period_kind=YEAR&period=2026', '310.00', '2026-01-01', null, '0.00'],
        ];
        foreach ($cases as $label => [$query, $revenue, $from, $to, $opening]) {
            $dre = $this->report($o, 'OWN_DRE', $w['a1'], $query);
            $doaf = $this->report($o, 'OWN_DOAF', $w['a1'], $query)['doaf'];
            $this->assertSame([$revenue, $revenue, $from, $opening], [$dre['dre']['revenue'], $doaf['external_funds_received'], $dre['period']['from'], $doaf['opening_balance']], $label);
            $this->assertSame($to ?? now('Africa/Luanda')->format('Y-m-d'), $dre['period']['to'], $label);
        }
        $this->assertSame('280.00', $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=SEMESTER&period=2026-H2')['dre']['revenue'], 'H2 = July 40 + August 80 + September 160');
    }

    // ---- R25-R30 perimeter history, authority, switch, drill-down, export ----------------------------------------------

    public function test_r25_historical_perimeter_comes_from_unit_parent_periods_not_parent_id(): void
    {
        $w = $this->reportingWorld();
        $leaf = $this->officer($w['a1']);
        $consA = $this->officer($w['a'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $consB = $this->officer($w['b'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $this->contribute($leaf, $w['cash_a1'], '250.00', '2026-08-10');
        // X (a1) belongs to A during August; it moves to B on 1 September (history row + current parent).
        DB::table('unit_parent_periods')->where('unit_id', $w['a1']['id'])->update(['status' => 'ENDED', 'ends_at' => '2026-09-01 00:00:00.000000']);
        DB::table('unit_parent_periods')->insert(['unit_id' => $w['a1']['id'], 'parent_unit_id' => $w['b']['id'], 'status' => 'ACTIVE', 'starts_at' => '2026-09-01 00:00:00.000000', 'ends_at' => null,
            'reason' => 'Mudança de pai (R25)', 'source_document_id' => null, 'created_at' => '2026-09-01 00:00:00.000000', 'lock_version' => 0]);
        DB::table('organizational_units')->where('id', $w['a1']['id'])->update(['parent_id' => $w['b']['id']]);
        $this->contribute($leaf, $w['cash_a1'], '70.00', '2026-09-15');
        $aug = 'period_kind=MONTH&period=2026-08';
        $sep = 'period_kind=MONTH&period=2026-09';
        $this->assertSame(['250.00', 3], [$this->report($consA, 'CONSOLIDATED_DRE', $w['a'], $aug)['dre']['revenue'], $this->report($consA, 'CONSOLIDATED_DRE', $w['a'], $aug)['perimeter_units']], 'August: A includes X');
        $this->assertSame(['0.00', 2], [$this->report($consB, 'CONSOLIDATED_DRE', $w['b'], $aug)['dre']['revenue'], $this->report($consB, 'CONSOLIDATED_DRE', $w['b'], $aug)['perimeter_units']], 'August: B does not include X');
        $this->assertSame(['70.00', 3], [$this->report($consB, 'CONSOLIDATED_DRE', $w['b'], $sep)['dre']['revenue'], $this->report($consB, 'CONSOLIDATED_DRE', $w['b'], $sep)['perimeter_units']], 'September: the current structure, B includes X');
        $this->assertSame('0.00', $this->report($consA, 'CONSOLIDATED_DRE', $w['a'], $sep)['dre']['revenue'], 'September: A no longer includes X');
    }

    public function test_r26_consolidated_reports_need_the_dedicated_permission_over_the_whole_subtree(): void
    {
        $w = $this->reportingWorld();
        $ownOnly = $this->officer($w['m'], [], true);
        $this->api($ownOnly, 'GET', 'finance/reports/CONSOLIDATED_DRE?unit=' . $w['m']['public_id'] . '&period_kind=YEAR&period=2026')->assertStatus(403);
        $this->api($ownOnly, 'GET', 'finance/dashboard?unit=' . $w['m']['public_id'] . '&view=CONSOLIDATED&period_kind=YEAR&period=2026')->assertStatus(403);
        $this->report($ownOnly, 'OWN_DRE', $w['m'], 'period_kind=YEAR&period=2026');
        // Consolidated view held on m alone (no descendants): the subtree is not covered -> concealed.
        $partial = $this->officer($w['m'], ['FINANCE_CONSOLIDATED_VIEW'], false);
        $this->assertConcealed($this->api($partial, 'GET', 'finance/reports/CONSOLIDATED_DRE?unit=' . $w['m']['public_id'] . '&period_kind=YEAR&period=2026'));
        $full = $this->officer($w['m'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $this->assertSame('CONSOLIDATED', $this->report($full, 'CONSOLIDATED_DRE', $w['m'], 'period_kind=YEAR&period=2026')['view']);
    }

    public function test_r27_wrong_scope_and_unknown_units_are_the_same_answer_with_no_oracle(): void
    {
        $w = $this->reportingWorld();
        $ghost = (string) Str::ulid();
        // In scope elsewhere: another unit and an unknown unit are the same byte-identical 404.
        $other = $this->officer($w['b1'], ['FINANCE_CONSOLIDATED_VIEW']);
        $bodies = [];
        foreach ([$w['m']['public_id'], $ghost, 'not-a-ulid'] as $unit) {
            foreach (['OWN_DRE', 'CONSOLIDATED_DOAF'] as $type) {
                $bodies[] = $this->api($other, 'GET', 'finance/reports/' . $type . '?unit=' . $unit . '&period_kind=YEAR&period=2026')->assertStatus(404)->getContent();
            }
            $bodies[] = $this->api($other, 'GET', 'finance/reports/OWN_DRE/export?unit=' . $unit . '&period_kind=YEAR&period=2026')->assertStatus(404)->getContent();
        }
        $this->assertCount(1, array_unique($bodies));
        // Without FINANCE_REPORT anywhere: the answer never depends on whether the unit exists (F-06).
        $noReport = $this->staff(['FINANCE_VIEW'], $w['m']['id'], true);
        $existing = $this->api($noReport, 'GET', 'finance/reports/OWN_DRE?unit=' . $w['m']['public_id'] . '&period_kind=YEAR&period=2026');
        $unknown = $this->api($noReport, 'GET', 'finance/reports/OWN_DRE?unit=' . $ghost . '&period_kind=YEAR&period=2026');
        $this->assertSame([403, 403], [$existing->status(), $unknown->status()]);
        $this->assertSame($existing->getContent(), $unknown->getContent());
        $this->assertConcealed($this->api($other, 'GET', 'finance/reports/NOT_A_REPORT?unit=' . $w['b1']['public_id'] . '&period_kind=YEAR&period=2026'));
    }

    public function test_r28_own_and_consolidated_views_are_explicit_and_switchable(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $q = 'unit=' . $w['m']['public_id'] . '&period_kind=MONTH&period=2026-08';
        $own = $this->api($actors['cons_m'], 'GET', 'finance/dashboard?' . $q . '&view=OWN')->assertOk()->json('data');
        $cons = $this->api($actors['cons_m'], 'GET', 'finance/dashboard?' . $q . '&view=CONSOLIDATED')->assertOk()->json('data');
        $this->assertSame(['OWN', 1, '0.00', '40.00'], [$own['view'], $own['perimeter_units'], $own['dashboard']['revenue'], $own['dashboard']['internal_received']]);
        $this->assertSame(['CONSOLIDATED', 6, '100.00', '0.00', '100.00'], [$cons['view'], $cons['perimeter_units'], $cons['dashboard']['revenue'], $cons['dashboard']['internal_received'], $cons['dashboard']['cash_bank_position']]);
        $this->assertNotSame($own['parameters_hash'], $cons['parameters_hash']);
        // The report type names its view (F1D-P04): an OWN_* report is never consolidated, whatever the view parameter says.
        $ownDre = $this->report($actors['cons_m'], 'OWN_DRE', $w['m'], 'view=CONSOLIDATED&period_kind=MONTH&period=2026-08');
        $this->assertSame(['OWN', 1, '0.00'], [$ownDre['view'], $ownDre['perimeter_units'], $ownDre['dre']['revenue']]);
        $ownDoaf = $this->report($actors['cons_m'], 'OWN_DOAF', $w['m'], 'view=CONSOLIDATED&period_kind=MONTH&period=2026-08');
        $this->assertSame(['OWN', '40.00'], [$ownDoaf['view'], $ownDoaf['doaf']['internal_funds_received']]);
        $this->assertSame(['finance.dashboard.viewed'], DB::table('audit_logs')->where('action', 'like', 'finance.dashboard%')->distinct()->pluck('action')->all());
    }

    public function test_r29_drill_down_projects_only_units_the_actor_may_view_and_never_payroll(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $q = 'unit=' . $w['m']['public_id'] . '&view=CONSOLIDATED&period_kind=MONTH&period=2026-08';
        $full = $this->api($actors['cons_m'], 'GET', 'finance/dashboard?' . $q)->assertOk()->json('data');
        $byUnit = collect($full['drill_down'])->keyBy('unit.public_id');
        $this->assertCount(6, $full['drill_down']);
        $this->assertSame(['100.00', '0.00', '0.00'], [$byUnit[$w['a1']['public_id']]['own_result'], $byUnit[$w['a']['public_id']]['own_result'], $byUnit[$w['m']['public_id']]['own_result']]);
        $this->assertSame(['60.00', '40.00', '20.00'], [$byUnit[$w['a1']['public_id']]['funds_sent'], $byUnit[$w['m']['public_id']]['funds_received'], $byUnit[$w['a']['public_id']]['closing_balance']]);
        // Consolidated authority over the subtree, but FINANCE_VIEW only on m itself: the aggregate is whole, the
        // drill-down shows m only (D17), and the other units are not revealed.
        $narrow = $this->staff(['FINANCE_REPORT', 'FINANCE_CONSOLIDATED_VIEW'], $w['m']['id'], true);
        $this->grant($narrow, ['FINANCE_VIEW'], $w['m']['id'], false);
        $limited = $this->api($narrow, 'GET', 'finance/dashboard?' . $q)->assertOk()->json('data');
        $this->assertSame(['100.00', [$w['m']['public_id']]], [$limited['dashboard']['revenue'], array_column(array_column($limited['drill_down'], 'unit'), 'public_id')]);
        $this->assertGreaterThanOrEqual(2, DB::table('audit_logs')->where('action', 'finance.report.drilldown')->count());
        // Payroll is F2: no payroll field or placeholder anywhere in the Finance reporting surface.
        $everything = json_encode([$full, $limited, $this->api($actors['cons_m'], 'GET', 'finance/reports')->json('data')]);
        $this->assertDoesNotMatchRegularExpression('/payroll|salar|vencimento/i', (string) $everything);
    }

    public function test_r30_the_export_carries_exactly_the_api_figures_filters_and_is_audited(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $o = $actors['a1'];
        $this->payable($o, $w['a1'], '15.00', 'ADM_WATER', '2026-08-20', '2026-12-31');
        $before = DB::table('audit_logs')->where('action', 'finance.export')->count();
        foreach ([[$o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-08'], [$o, 'PAYABLES', $w['a1'], 'period_kind=QUARTER&period=2026-Q3'],
            [$actors['cons_m'], 'CONSOLIDATED_DOAF', $w['m'], 'period_kind=YEAR&period=2026'], [$actors['cons_m'], 'FINANCE_PERIOD_SUMMARY', $w['m'], 'view=CONSOLIDATED&period_kind=MONTH&period=2026-08']] as [$actor, $type, $unit, $query]) {
            $api = $this->report($actor, $type, $unit, $query);
            $this->flushHeaders();
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'text/csv'])->get('/api/v1/finance/reports/' . $type . '/export?unit=' . $unit['public_id'] . '&' . $query);
            $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringContainsString('attachment; filename="', (string) $response->headers->get('Content-Disposition'));
            $csv = $this->csv((string) $response->getContent());
            $flat = $this->flatten($api);
            unset($flat['generated_at'], $csv['generated_at']);
            $this->assertSame($flat, $csv, $type . ': every exported field equals the API');
            foreach (['unit.public_id', 'view', 'period.kind', 'period.from', 'period.to', 'parameters_hash', 'report_type', 'source'] as $field) {
                $this->assertArrayHasKey($field, $csv, $type . ' header ' . $field);
            }
        }
        $this->assertSame($before + 4, DB::table('audit_logs')->where('action', 'finance.export')->count(), 'each export is audited');
        $meta = json_decode((string) DB::table('audit_logs')->where('action', 'finance.export')->orderByDesc('id')->value('after_metadata'), true);
        $this->assertSame(['FINANCE_PERIOD_SUMMARY', 'CONSOLIDATED', '2026-08-01', '2026-08-31'], [$meta['report_type'], $meta['view'], $meta['period_from'], $meta['period_to']]);
    }

    // ---- R31-R33 contributions, in kind, closed-period reconciliation --------------------------------------------------

    public function test_r31_contributions_are_listed_as_external_origins_only(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $o = $actors['a1'];
        $this->fpost($o, 'finance/contributions', ['kind' => 'IN_KIND', 'identification' => 'ANONYMOUS', 'unit' => $w['a1']['public_id'], 'category' => 'REV_IN_KIND', 'description' => 'Cadeiras'], $this->key())->assertCreated();
        $list = $this->api($o, 'GET', 'finance/contributions?unit=' . $w['a1']['public_id'])->assertOk()->json('data');
        $this->assertSame([['IN_KIND', null, 'UNVALUED'], ['MONETARY', '100.00', null]], array_map(fn ($c) => [$c['kind'], $c['amount'], $c['valuation_status']], $list));
        $this->assertSame([[], 'EXTERNAL'], [$this->api($actors['a'], 'GET', 'finance/contributions?unit=' . $w['a']['public_id'])->json('data'), $list[1]['origin']], 'a received internal transfer is never a contribution');
    }

    public function test_r32_an_in_kind_contribution_never_creates_cash(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $this->contribute($o, $w['cash_a1'], '100.00', '2026-08-02');
        $gift = $this->fpost($o, 'finance/contributions', ['kind' => 'IN_KIND', 'identification' => 'ANONYMOUS', 'unit' => $w['a1']['public_id'], 'category' => 'REV_IN_KIND', 'description' => 'Mobiliário doado'], $this->key())->assertCreated()->json('data');
        $year = 'period_kind=YEAR&period=2026';
        $this->assertSame(['100.00', '100.00', '100.00'], [$this->report($o, 'OWN_DRE', $w['a1'], $year)['dre']['revenue'], $this->doaf($o, $w['a1'], $year)['external_funds_received'], $this->doaf($o, $w['a1'], $year)['closing_balance']], 'unvalued: no monetary value is invented');
        $this->fpost($o, 'finance/contributions/' . $gift['public_id'] . '/valuation', ['valuation_amount' => '250.00', 'document' => $this->document($w['a1'], 'VALUATION_REPORT')['public_id']])->assertOk();
        $this->assertSame('100.00', $this->report($o, 'OWN_DRE', $w['a1'], $year)['dre']['revenue'], 'valued but not approved: still out of the DRE');
        $this->fpost($o, 'finance/contributions/' . $gift['public_id'] . '/approve-valuation')->assertOk();
        $dre = $this->report($o, 'OWN_DRE', $w['a1'], $year)['dre'];
        $this->assertSame(['350.00', '250.00'], [$dre['revenue'], collect($dre['revenue_lines'])->firstWhere('category.code', 'REV_IN_KIND')['amount']], 'approved valuation: economic revenue');
        $doaf = $this->doaf($o, $w['a1'], $year);
        $this->assertSame(['100.00', '100.00', true], [$doaf['external_funds_received'], $doaf['closing_balance'], $doaf['balanced']], 'never CASH/BANK without a financial movement');
        $this->assertSame([10000, '100.00'], [$this->balance($w['cash_a1']), collect($this->report($o, 'CASH_BANK_BALANCES', $w['a1'], $year)['treasury'])->firstWhere('account.public_id', $w['cash_a1']['public_id'])['closing']]);
    }

    public function test_r33_a_closed_period_adjustment_keeps_its_bank_date_and_takes_an_open_accounting_date(): void
    {
        $w = $this->reportingWorld();
        $o = $this->officer($w['a1']);
        $bank = $this->fpost($o, 'finance/accounts', ['unit' => $w['a1']['public_id'], 'kind' => 'BANK', 'code' => 'BK-R33', 'name' => 'Banco R33', 'opened_on' => '2026-09-01', 'opening_balance' => '100.00',
            'bank_name' => 'Banco Exemplo', 'account_number' => '00401234567890'], $this->key())->assertCreated()->json('data');
        $bankId = (int) DB::table('accounts')->where('public_id', $bank['public_id'])->value('id');
        $statement = $this->fpost($o, 'finance/bank-statements', ['account' => $bank['public_id'], 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'opening_balance' => '0.00', 'closing_balance' => '95.00',
            'document' => $this->document($w['a1'], 'BANK_STATEMENT')['public_id'], 'lines' => [['occurred_on' => '2026-09-01', 'amount' => '100.00', 'description' => 'Depósito'], ['occurred_on' => '2026-09-30', 'amount' => '-5.00', 'description' => 'Comissão bancária']]], $this->key())
            ->assertCreated()->json('data');
        $this->fpost($o, 'finance/periods/2026-09/close', ['unit' => $w['a1']['public_id']])->assertOk();
        $rec = $this->fpost($o, 'finance/reconciliations', ['account' => $bank['public_id'], 'period' => '2026-09', 'statement' => $statement['public_id']], $this->key())->assertCreated()->json('data');
        $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/matches', ['statement_line' => 1, 'entry' => $bank['opening_entry']['entry'], 'entry_line' => 1, 'amount' => '100.00'])->assertOk();
        $adjusted = $this->fpost($o, 'finance/reconciliations/' . $rec['public_id'] . '/adjustments', ['statement_line' => 2, 'category' => 'FIN_BANK_FEES'], $this->key())->assertCreated()->json('meta');
        $bankFactDate = (string) DB::table('bank_statement_lines')->where('statement_id', DB::table('bank_statements')->where('public_id', $statement['public_id'])->value('id'))->where('line_number', 2)->value('occurred_on');
        $accountingDate = (string) DB::table('journal_entries')->where('public_id', $adjusted['adjustment_entry'])->value('entry_date');
        $this->assertSame('2026-09-30', $bankFactDate, 'the bank fact keeps its own date');
        $this->assertGreaterThan('2026-09-30', $accountingDate, 'the accounting date is the first OPEN period');
        $this->assertSame('0.00', $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=2026-09')['dre']['expenses'], 'the closed September DRE is unchanged');
        $month = substr($accountingDate, 0, 7);
        $dre = $this->report($o, 'OWN_DRE', $w['a1'], 'period_kind=MONTH&period=' . $month)['dre'];
        $this->assertSame(['5.00', 'FIN_BANK_FEES'], [$dre['expenses'], $dre['expense_lines'][0]['category']['code']]);
        $movement = collect($this->report($o, 'FINANCIAL_ACCOUNT_MOVEMENTS', $w['a1'], 'period_kind=MONTH&period=' . $month)['movements'])->firstWhere('entry', $adjusted['adjustment_entry']);
        $this->assertSame([$accountingDate, '5.0000'], [$movement['date'], $movement['credit']]);
        $this->assertSame(9500, $this->balance(['id' => $bankId]));
    }

    // ---- §4 accounting cross-check (independent, hand-computed expectations) --------------------------------------

    public function test_accounting_cross_check_of_the_integrated_dataset(): void
    {
        $w = $this->reportingWorld();
        $A = $this->officer($w['a1']);
        $C = $this->officer($w['a']);
        $M = $this->officer($w['m']);
        $cons = $this->officer($w['m'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $this->approvedBudget($w, [['REV_TITHES', '90000.00'], ['ADM_ELECTRICITY', '25000.00']]);
        // Congregação A: external revenue 100000, expense accrued 20000 (15000 paid), SEND 60000 to Centro.
        $this->contribute($A, $w['cash_a1'], '100000.00', '2026-08-02');
        $pa = $this->payable($A, $w['a1'], '20000.00', 'ADM_ELECTRICITY', '2026-08-03');
        $this->settle($A, 'payables', $pa['public_id'], $w['cash_a1'], '15000.00', '2026-08-04');
        // Centro: RECEIVE 60000, expense 10000 (recognised and paid), SEND 40000 to Município.
        $this->move($A, $C, $w['cash_a1'], $w['a'], $w['cash_a'], '60000.00', '2026-08-05');
        $pc = $this->payable($C, $w['a'], '10000.00', 'ADM_TAXI', '2026-08-06');
        $this->settle($C, 'payables', $pc['public_id'], $w['cash_a'], '10000.00', '2026-08-07');
        $this->move($C, $M, $w['cash_a'], $w['m'], $w['cash_m'], '40000.00', '2026-08-08');
        // Município: RECEIVE 40000, external revenue 30000, payable accrued 5000 (unpaid).
        $this->contribute($M, $w['cash_m'], '30000.00', '2026-08-09');
        $this->payable($M, $w['m'], '5000.00', 'ADM_INTERNET', '2026-08-10');

        $q = 'period_kind=MONTH&period=2026-08';
        // EXPECTED, by hand:
        //   A: revenue 100000, expense 20000, result 80000; cash 100000 - 15000 - 60000 = 25000; payables open 5000.
        //   C: revenue 0, expense 10000, result -10000; cash 60000 - 10000 - 40000 = 10000; payables open 0.
        //   M: revenue 30000, expense 5000, result 25000; cash 40000 + 30000 = 70000; payables open 5000.
        //   Consolidated M: revenue 130000, expense 35000, result 95000; external in 130000, external out 25000,
        //   internal 0/0 (eliminated), transit 0, closing 105000; payables open 10000.
        $expected = [
            'a1' => [$A, ['100000.00', '20000.00', '80000.00'], ['0.00', '100000.00', '0.00', '15000.00', '60000.00', '25000.00'], '5000.00'],
            'a' => [$C, ['0.00', '10000.00', '-10000.00'], ['0.00', '0.00', '60000.00', '10000.00', '40000.00', '10000.00'], '0.00'],
            'm' => [$M, ['30000.00', '5000.00', '25000.00'], ['0.00', '30000.00', '40000.00', '0.00', '0.00', '70000.00'], '5000.00'],
        ];
        foreach ($expected as $unit => [$actor, $dreExpected, $doafExpected, $payables]) {
            $dre = $this->report($actor, 'OWN_DRE', $w[$unit], $q)['dre'];
            $doaf = $this->doaf($actor, $w[$unit], $q);
            $this->assertSame($dreExpected, [$dre['revenue'], $dre['expenses'], $dre['economic_result']], $unit . ' DRE');
            $this->assertSame($doafExpected, [$doaf['opening_balance'], $doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['external_applications'], $doaf['internal_funds_sent'], $doaf['closing_balance']], $unit . ' DOAF');
            $this->assertTrue($doaf['balanced'], $unit . ' DOAF balanced');
            $this->assertSame($payables, $this->report($actor, 'FINANCE_PERIOD_SUMMARY', $w[$unit], $q)['dashboard']['payables'], $unit . ' payables');
            $this->assertSame([], $this->report($actor, 'RECEIVABLES', $w[$unit], $q)['receivables'], $unit . ' receivables');
            $cash = collect($this->report($actor, 'CASH_BANK_BALANCES', $w[$unit], $q)['treasury'])->firstWhere('account.public_id', $w['cash_' . $unit]['public_id']);
            $this->assertSame($doafExpected[5], $cash['closing'], $unit . ' cash');
        }
        $this->assertSame(['60000.00'], array_column($this->report($A, 'INTERNAL_FUNDS_SENT', $w['a1'], $q)['transfers'], 'amount'));
        $this->assertSame(['60000.00'], array_column($this->report($C, 'INTERNAL_FUNDS_RECEIVED', $w['a'], $q)['transfers'], 'amount'));
        $this->assertSame(['40000.00'], array_column($this->report($C, 'INTERNAL_FUNDS_SENT', $w['a'], $q)['transfers'], 'amount'));
        $this->assertSame(['40000.00'], array_column($this->report($M, 'INTERNAL_FUNDS_RECEIVED', $w['m'], $q)['transfers'], 'amount'));
        $cdre = $this->report($cons, 'CONSOLIDATED_DRE', $w['m'], $q)['dre'];
        $this->assertSame(['130000.00', '35000.00', '95000.00'], [$cdre['revenue'], $cdre['expenses'], $cdre['economic_result']]);
        $cdoaf = $this->report($cons, 'CONSOLIDATED_DOAF', $w['m'], $q)['doaf'];
        $this->assertSame(['130000.00', '0.00', '25000.00', '0.00', '0.00', '105000.00', true],
            [$cdoaf['external_funds_received'], $cdoaf['internal_funds_received'], $cdoaf['external_applications'], $cdoaf['internal_funds_sent'], $cdoaf['funds_in_transit_under_custody'], $cdoaf['closing_balance'], $cdoaf['balanced']]);
        $position = $this->report($cons, 'INTERUNIT_POSITION', $w['m'], $q . '&view=CONSOLIDATED')['position'];
        $this->assertSame(['0.00', '100000.00', '100000.00'], [$position['in_transit'], $position['sent'], $position['received']], 'in-transit 0; sent 60000 + 40000 = received');
        $this->assertSame('10000.00', $this->report($cons, 'FINANCE_PERIOD_SUMMARY', $w['m'], $q . '&view=CONSOLIDATED')['dashboard']['payables']);
        // Budget (a1, year): approved 90000 + 25000 = 115000; actual = revenue 100000 + electricity 20000 = 120000.
        $budget = $this->report($A, 'BUDGET_VS_ACTUAL', $w['a1'], 'period_kind=YEAR&period=2026')['budget_vs_actual'];
        $this->assertSame(['115000.00', '120000.00', '5000.00'], [$budget['approved_budget'], $budget['actual'], $budget['variance']]);
        $this->evidence('cross-check', ['own' => array_map(fn ($e) => ['dre' => $e[1], 'doaf' => $e[2], 'payables' => $e[3]], $expected), 'consolidated_dre' => $cdre, 'consolidated_doaf' => $cdoaf, 'budget' => $budget]);
    }

    // ---- R34 C8: real concurrent snapshot -------------------------------------------------------------------------

    public function test_r34_c8_a_consolidated_report_never_sees_a_torn_snapshot(): void
    {
        [$w, $actors] = $this->chain('2026-08');
        $cons = $actors['cons_m'];
        $a2 = $this->officer($w['a2']);
        $body = ['unit' => $w['m']['public_id'], 'view' => 'CONSOLIDATED', 'period_kind' => 'MONTH', 'period' => '2026-08'];
        $contribution = fn (string $amount) => ['kind' => 'MONETARY', 'identification' => 'AGGREGATED', 'account' => $w['cash_a2']['public_id'], 'category' => 'REV_TITHES', 'amount' => $amount, 'received_on' => '2026-08-20'];

        // Order 1: A's snapshot is established and it has read part of the dataset; B posts and COMMITS; A resumes.
        $a = $this->spawnWorker(['op' => 'report', 'type' => 'FINANCE_PERIOD_SUMMARY', 'user' => $cons['user'], 'session' => $cons['session'], 'body' => $body, 'pause_after_ledger_read' => true]);
        fwrite($a[1][0], "go\n");
        fflush($a[1][0]);
        $paused = str_replace("\r", '', (string) fgets($a[1][1]));
        $this->assertStringStartsWith('PAUSED', $paused, 'A is part-way through the reads');
        $b = $this->fpost($a2, 'finance/contributions', $contribution('50.00'), $this->key())->assertCreated()->json('data');
        $this->assertSame(1, DB::table('contributions')->where('public_id', $b['public_id'])->count(), 'B is committed while A is paused');
        fwrite($a[1][0], "resume\n");
        fclose($a[1][0]);
        $order1 = $this->finishWorker($a);
        $this->assertSame('OK', $order1['status'], json_encode($order1));
        $this->assertCoherent($order1['result'], '100.00', 'order 1: the whole report is the state BEFORE B');
        $this->assertSame("PAUSED 1\n", $paused, 'A was inside its report transaction when B committed');
        $after = $this->report($cons, 'FINANCE_PERIOD_SUMMARY', $w['m'], 'view=CONSOLIDATED&period_kind=MONTH&period=2026-08');
        $this->assertCoherent($after, '150.00', 'a report started after B commits sees all of B');

        // Order 2: B holds its business transaction open (uncommitted) while A runs end to end; then B commits.
        $hold = $this->spawnWorker(['op' => 'contribute', 'user' => $a2['user'], 'session' => $a2['session'], 'body' => $contribution('25.00'), 'hold_until_commit' => true]);
        fwrite($hold[1][0], "go\n");
        fflush($hold[1][0]);
        $this->assertSame("HELD\n", str_replace("\r", '', (string) fgets($hold[1][1])), 'B has posted but not committed');
        $c = $this->spawnWorker(['op' => 'report', 'type' => 'FINANCE_PERIOD_SUMMARY', 'user' => $cons['user'], 'session' => $cons['session'], 'body' => $body]);
        fwrite($c[1][0], "go\n");
        fclose($c[1][0]);
        $order2 = $this->finishWorker($c);
        fwrite($hold[1][0], "commit\n");
        fclose($hold[1][0]);
        $this->assertSame('OK', $this->finishWorker($hold)['status']);
        $this->assertCoherent($order2['result'], '150.00', 'order 2: an uncommitted posting is invisible to every part of the report');

        // Order 3: B commits BEFORE A's snapshot starts -> A sees all of B everywhere.
        $d = $this->spawnWorker(['op' => 'report', 'type' => 'FINANCE_PERIOD_SUMMARY', 'user' => $cons['user'], 'session' => $cons['session'], 'body' => $body, 'pause_after_ledger_read' => true]);
        fwrite($d[1][0], "go\n");
        fflush($d[1][0]);
        $paused3 = str_replace("\r", '', (string) fgets($d[1][1]));
        fwrite($d[1][0], "resume\n");
        fclose($d[1][0]);
        $order3 = $this->finishWorker($d);
        $this->assertSame("PAUSED 1\n", $paused3);
        $this->assertCoherent($order3['result'], '175.00', 'order 3: the state AFTER both postings');
        $this->evidence('C8', ['order1_before' => $this->figures($order1['result']), 'after_b' => $this->figures($after), 'order2_uncommitted_invisible' => $this->figures($order2['result']), 'order3_after' => $this->figures($order3['result'])]);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** Every figure of one consolidated summary must describe the SAME state (no torn snapshot). */
    private function assertCoherent(array $r, string $external, string $message): void
    {
        $f = $this->figures($r);
        $a2 = $f['drill_a2_result'];
        $expectedA2 = number_format((float) $external - 100, 2, '.', '');
        $this->assertSame([$external, $external, $external, $external, $external, $expectedA2],
            [$f['revenue'], $f['dre_revenue'], $f['doaf_external'], $f['doaf_closing'], $f['drill_closing_sum'], $a2], $message . ' ' . json_encode($f));
    }

    private function figures(array $r): array
    {
        $closing = 0;
        $a2 = null;
        foreach ($r['drill_down'] as $u) {
            $closing += (int) round(((float) $u['closing_balance']) * 100);
            if (str_starts_with($u['unit']['name'], 'Congregação A2')) {
                $a2 = $u['own_result'];
            }
        }
        return ['revenue' => $r['dashboard']['revenue'], 'dre_revenue' => $r['dre']['revenue'], 'doaf_external' => $r['doaf']['external_funds_received'], 'doaf_closing' => $r['doaf']['closing_balance'],
            'drill_closing_sum' => number_format($closing / 100, 2, '.', ''), 'drill_a2_result' => $a2];
    }

    private function officer(array $unit, array $extra = [], bool $descendants = false): array
    {
        return $this->staff(array_values(array_unique(array_merge(self::OFFICER, $extra))), $unit['id'], $descendants);
    }

    /** One more grant (role + scope) for an existing staff user. */
    private function grant(array $actor, array $permissions, int $unit, bool $descendants): void
    {
        $role = $this->row('roles', ['is_active' => 1]);
        $scope = $this->row('scopes', ['unit_id' => $unit, 'include_descendants' => $descendants ? 1 : 0, 'scope_kind' => 'UNIT', 'department_instance_id' => null]);
        foreach ($permissions as $code) {
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => (int) DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $this->row('user_role_scopes', ['user_id' => $actor['user'], 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $actor['user'], 'status' => 'SYNTHETIC_READY',
            'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null]);
    }

    /** The normative chain in $month: A1 receives 100 from outside, sends 60 to A, A sends 40 to M. */
    private function chain(string $month): array
    {
        $w = $this->reportingWorld();
        $actors = ['a1' => $this->officer($w['a1']), 'a' => $this->officer($w['a']), 'm' => $this->officer($w['m']), 'cons_m' => $this->officer($w['m'], ['FINANCE_CONSOLIDATED_VIEW'], true)];
        $this->contribute($actors['a1'], $w['cash_a1'], '100.00', $month . '-02');
        $this->move($actors['a1'], $actors['a'], $w['cash_a1'], $w['a'], $w['cash_a'], '60.00', $month . '-03');
        $this->move($actors['a'], $actors['m'], $w['cash_a'], $w['m'], $w['cash_m'], '40.00', $month . '-04');
        return [$w, $actors];
    }

    /** request + SEND (origin) + RECEIVE (destination) on $on. */
    private function move(array $from, array $to, array $fromAccount, array $destination, array $toAccount, string $amount, string $on): void
    {
        $t = $this->requestTransfer($from, $fromAccount, $destination, $amount);
        $this->sendTransfer($from, $t['public_id'], $on);
        $this->receiveTransfer($to, $t['public_id'], $toAccount, $on);
    }

    private function receivable(array $actor, array $unit, string $amount, string $on, string $due = '2026-12-31'): array
    {
        return $this->fpost($actor, 'finance/receivables', ['unit' => $unit['public_id'], 'party' => ['kind' => 'EXTERNAL', 'name' => 'Cliente'], 'category' => 'REV_OTHER', 'amount' => $amount, 'due_on' => $due, 'recognized_on' => $on], $this->key())
            ->assertCreated()->json('data');
    }

    private function payable(array $actor, array $unit, string $amount, string $category, string $on, string $due = '2026-12-31'): array
    {
        return $this->fpost($actor, 'finance/payables', ['unit' => $unit['public_id'], 'party' => ['kind' => 'EXTERNAL', 'name' => 'Fornecedor'], 'category' => $category, 'amount' => $amount, 'due_on' => $due,
            'recognized_on' => $on, 'document' => $this->document($unit, 'INVOICE')['public_id']], $this->key())->assertCreated()->json('data');
    }

    private function settle(array $actor, string $kind, string $publicId, array $account, string $amount, string $on): void
    {
        $this->fpost($actor, 'finance/' . $kind . '/' . $publicId . '/settlements', ['account' => $account['public_id'], 'amount' => $amount, 'settled_on' => $on], $this->key())->assertCreated();
    }

    /** Approved 2026 budget of a1 (submitted by one person, approved by another). @return array{0: string, 1: array, 2: array} */
    private function approvedBudget(array $w, array $lines): array
    {
        $manager = $this->staff(['FINANCE_VIEW', 'FINANCE_BUDGET_MANAGE'], $w['a1']['id'], false);
        $approver = $this->staff(['FINANCE_VIEW', 'FINANCE_BUDGET_APPROVE'], $w['a']['id'], true);
        $b = $this->fpost($manager, 'finance/budgets', ['unit' => $w['a1']['public_id'], 'year' => '2026', 'lines' => array_map(fn ($l) => ['category' => $l[0], 'requested_amount' => $l[1]], $lines)], $this->key())->assertCreated()->json('data.public_id');
        $this->fpost($manager, 'finance/budgets/' . $b . '/submit')->assertOk();
        $this->fpost($approver, 'finance/budgets/' . $b . '/review')->assertOk();
        $this->fpost($approver, 'finance/budgets/' . $b . '/approve')->assertOk();
        return [$b, $manager, $approver];
    }

    private function report(array $actor, string $type, array $unit, string $query): array
    {
        return $this->api($actor, 'GET', 'finance/reports/' . $type . '?unit=' . $unit['public_id'] . '&' . $query)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function doaf(array $actor, array $unit, string $query): array
    {
        return $this->report($actor, 'OWN_DOAF', $unit, $query)['doaf'];
    }

    /** Same flattening contract as the export: dotted keys; booleans sim/não; null empty; everything a string. */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out += $this->flatten($value, $name);
            } else {
                $out[$name] = is_bool($value) ? ($value ? 'sim' : 'não') : ($value === null ? '' : (string) $value);
            }
        }
        return $out;
    }

    private function csv(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 BOM for spreadsheet tools');
        $rows = array_map(fn ($line) => str_getcsv($line, ';'), preg_split('/\r?\n/', trim(substr($content, 3))));
        $this->assertSame(['Campo', 'Valor'], array_shift($rows));
        $out = [];
        foreach ($rows as $row) {
            $out[$row[0]] = $row[1] ?? '';
        }
        return $out;
    }

    private function reportingWorld(): array
    {
        $w = $this->world();
        $parents = ['r' => 'g', 'p' => 'r', 'm' => 'p', 'a' => 'm', 'b' => 'm', 'a1' => 'a', 'a2' => 'a', 'b1' => 'b'];
        foreach ($parents as $child => $parent) {
            DB::table('unit_parent_periods')->insert(['unit_id' => $w[$child]['id'], 'parent_unit_id' => $w[$parent]['id'], 'status' => 'ACTIVE', 'starts_at' => '2025-01-01 00:00:00.000000', 'ends_at' => null,
                'reason' => 'F1D-E1 fixture', 'source_document_id' => null, 'created_at' => '2025-01-01 00:00:00.000000', 'lock_version' => 0]);
        }
        return $w;
    }

    private function spawnWorker(array $job): array
    {
        $root = dirname(__DIR__, 4);
        $php = getenv('MEPA_PHP_BIN') ?: 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe';
        $env = array_merge(getenv(), ['WAVE5_ALLOW_SYNTHETIC' => '1', 'XDEBUG_MODE' => 'off', 'APP_KEY' => (string) config('app.key')]);
        $proc = proc_open([$php, $root . '/scripts/p010-f1d-report-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        $line = str_replace("\r", '', (string) fgets($pipes[1]));
        if ($line !== "READY\n") {
            $this->fail('worker not ready: ' . $line . stream_get_contents($pipes[2]));
        }
        return [$proc, $pipes];
    }

    private function finishWorker(array $worker): array
    {
        [$proc, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', trim((string) $out)))));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('UNEXPECTED', $result['status'], json_encode($result));
        return $result;
    }

    private function evidence(string $name, array $data): void
    {
        $dir = getenv('P010_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'f1d-' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
