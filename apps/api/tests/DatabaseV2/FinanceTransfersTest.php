<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Finance\FinancePeriods;
use App\Domain\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\FinanceHttpCase;

/**
 * P0.10-F1B interunit transfers, fund custody and contributions (ADR 0021 D04/D09/D10/D15/D17/D20 + D-04A), HTTP level
 * against an isolated Wave 5 pool. T01-T28 (T27 = Playwright mobile flow), C2/C7/C9 and the period-close interaction
 * with REAL processes (scripts/p010-transfer-worker.php): deterministic ordering = the first connection holds its
 * business transaction open (HELD), the second is OBSERVED in performance_schema.data_lock_waits blocked by it, then the
 * first commits. Each test builds its own world, so any test can run alone (mutation probes use --filter).
 */
final class FinanceTransfersTest extends FinanceHttpCase
{
    // ---- T01 contributions ------------------------------------------------------------------------------------------

    public function test_t01_external_contribution_is_recorded_once_and_is_the_only_revenue_path(): void
    {
        $w = $this->world();
        $t = $this->treasurer($w['a1'], ['DOCUMENTS_VIEW']);
        $key = $this->key();
        $body = ['kind' => 'MONETARY', 'identification' => 'AGGREGATED', 'account' => $w['cash_a1']['public_id'], 'category' => 'REV_OFFERINGS', 'amount' => '1500.50'];
        $c = $this->fpost($t, 'finance/contributions', $body, $key)->assertCreated()->assertJsonPath('meta.replayed', false)->json('data');
        $this->assertSame(['EXTERNAL', 'MONETARY', '1500.50', 'RECORDED'], [$c['origin'], $c['kind'], $c['amount'], $c['status']]);
        $this->assertNoInternalIds($c);
        $this->fpost($t, 'finance/contributions', $body, $key)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.public_id', $c['public_id']);
        $this->fpost($t, 'finance/contributions', ['amount' => '1.00'] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, DB::table('contributions')->where('receiving_unit_id', $w['a1']['id'])->count());
        $this->assertSame(1, DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->where('entry_kind', 'CONTRIBUTION')->where('status', 'POSTED')->count());
        $this->assertSame(150050, $this->income($w['a1']));
        $this->assertSame(150050, $this->balance($w['cash_a1']));
        // An internal MEPA origin is never a contribution; money is a string, never a float.
        $this->fpost($t, 'finance/contributions', ['identification' => 'IDENTIFIED', 'party' => ['kind' => 'UNIT']] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'INTERNAL_COUNTERPARTY');
        $this->fpost($t, 'finance/contributions', ['category' => 'TRF_REMITTANCE'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'INTERNAL_COUNTERPARTY');
        $this->fpost($t, 'finance/contributions', ['amount' => 12.5] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->fpost($t, 'finance/contributions', ['amount' => '1.005'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_SCALE');
        $this->fpost($t, 'finance/contributions', $body)->assertStatus(422)->assertJsonPath('error.details.fields.idempotency_key.0', 'invalid');
        // Identified contributor: identity only with FINANCE_CONTRIBUTOR_VIEW (audited); in kind never in the result until approved.
        $named = $this->fpost($t, 'finance/contributions', ['identification' => 'IDENTIFIED', 'party' => ['kind' => 'EXTERNAL', 'name' => 'Doador Externo']] + $body, $this->key())->assertCreated()->json('data');
        $this->assertNull($named['party']);
        $viewer = $this->treasurer($w['a1'], ['FINANCE_CONTRIBUTOR_VIEW']);
        $this->api($viewer, 'GET', 'finance/contributions/' . $named['public_id'])->assertOk()->assertJsonPath('data.party.name', 'Doador Externo');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.contributor_detail_viewed')->where('unit_id', $w['a1']['id'])->count());
        $gift = $this->fpost($t, 'finance/contributions', ['kind' => 'IN_KIND', 'identification' => 'ANONYMOUS', 'unit' => $w['a1']['public_id'], 'category' => 'REV_IN_KIND', 'description' => 'Cadeiras de plástico'], $this->key())->assertCreated()->json('data');
        $this->assertSame(['UNVALUED', null], [$gift['valuation_status'], $gift['entry']]);
        $income = $this->income($w['a1']);
        $this->fpost($t, 'finance/contributions/' . $gift['public_id'] . '/valuation', ['valuation_amount' => '250.00', 'document' => $this->document($w['a1'], 'RECEIPT')['public_id']])->assertStatus(422)->assertJsonPath('error.code', 'VALUATION_DOCUMENT_REQUIRED');
        $this->fpost($t, 'finance/contributions/' . $gift['public_id'] . '/valuation', ['valuation_amount' => '250.00', 'document' => $this->document($w['a1'], 'VALUATION_REPORT')['public_id']])->assertOk()->assertJsonPath('data.valuation_status', 'VALUED');
        $this->assertSame($income, $this->income($w['a1']), 'a valued-but-unapproved gift is not revenue');
        $this->fpost($t, 'finance/contributions/' . $gift['public_id'] . '/approve-valuation')->assertOk()->assertJsonPath('data.valuation_status', 'APPROVED');
        $this->assertSame($income + 25000, $this->income($w['a1']));
        $this->assertSame(150050 + 150050, $this->balance($w['cash_a1']), 'an in-kind gift never moves cash');
        $this->assertLedgerInvariants();
    }

    // ---- T02-T09 request / SEND / RECEIVE -----------------------------------------------------------------------------

    public function test_t02_transfer_request_creates_a_draft_with_purpose_and_no_accounting_effect(): void
    {
        $w = $this->world();
        $t = $this->treasurer($w['a1']);
        $key = $this->key();
        $body = ['origin_account' => $w['cash_a1']['public_id'], 'destination_unit' => $w['a']['public_id'], 'amount' => '600.00', 'purpose' => 'TRF_REMITTANCE'];
        $d = $this->fpost($t, 'finance/transfers', $body, $key)->assertCreated()->json('data');
        $this->assertSame(['DRAFT', 'NOT_APPLICABLE', 'TRF_REMITTANCE', true, '600.00', 'ORIGIN'], [$d['status'], $d['reconciliation_state'], $d['purpose']['code'], $d['purpose']['regular'], $d['amount'], $d['side']]);
        $this->assertSame([$w['a1']['public_id'], $w['a']['public_id']], [$d['origin']['public_id'], $d['destination']['public_id']]);
        $this->assertSame($w['cash_a1']['public_id'], $d['origin_account']['public_id']);
        $this->assertNoInternalIds($d);
        $this->fpost($t, 'finance/transfers', $body, $key)->assertOk()->assertJsonPath('data.public_id', $d['public_id'])->assertJsonPath('meta.replayed', true);
        $this->assertSame(0, DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->count(), 'a request posts nothing');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.transfer_requested')->where('entity_id', $this->transferId($d['public_id']))->where('unit_id', $w['a1']['id'])->count());
        $this->fpost($t, 'finance/transfers', ['destination_unit' => $w['a1']['public_id']] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.details.fields.destination_unit.0', 'invalid');
        $this->fpost($t, 'finance/transfers', ['purpose' => 'REV_TITHES'] + $body, $this->key())->assertStatus(422)->assertJsonPath('error.details.fields.purpose.0', 'invalid');
        $this->fpost($t, 'finance/transfers', ['status' => 'SENT'] + $body, $this->key())->assertStatus(422);
        $this->fpost($t, 'finance/transfers', ['origin_unit_id' => $w['a1']['id']] + $body, $this->key())->assertStatus(422);
        foreach (FinanceCatalogPurposes::ALL as $purpose) {
            $this->fpost($t, 'finance/transfers', ['purpose' => $purpose] + $body, $this->key())->assertCreated()->assertJsonPath('data.purpose.code', $purpose);
        }
        // Cancel a draft (reason required), still no accounting effect.
        $this->fpost($t, 'finance/transfers/' . $d['public_id'] . '/cancel', ['reason' => ''])->assertStatus(422);
        $this->fpost($t, 'finance/transfers/' . $d['public_id'] . '/cancel', ['reason' => 'Pedido duplicado'])->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.reconciliation_state', 'NOT_APPLICABLE');
        $this->fpost($t, 'finance/transfers/' . $d['public_id'] . '/send')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertSame(0, DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->count());
    }

    public function test_t03_t04_send_posts_one_origin_entry_on_interunit_clearing_and_never_the_result(): void
    {
        $w = $this->world();
        $t = $this->treasurer($w['a1']);
        $this->contribute($t, $w['cash_a1'], '1000.00');
        $result = $this->resultOf($w['a1']);
        $r = $this->requestTransfer($t, $w['cash_a1'], $w['a'], '600.00');
        $sent = $this->fpost($t, 'finance/transfers/' . $r['public_id'] . '/send')->assertOk()->assertJsonPath('meta.replayed', false)->json('data');
        $this->assertSame(['SENT', 'IN_TRANSIT'], [$sent['status'], $sent['reconciliation_state']]);
        $this->assertCount(1, $sent['stages']);
        $entry = DB::table('journal_entries')->where('public_id', $sent['stages'][0]['entry'])->first();
        $this->assertSame(['TRANSFER_SEND', 'POSTED', $w['a1']['id']], [$entry->entry_kind, $entry->status, (int) $entry->unit_id]);
        $lines = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.entry_id', $entry->id)->orderBy('l.line_number')
            ->get(['a.system_role', 'a.account_kind', 'l.unit_id', 'l.counterparty_unit_id', 'l.debit', 'l.credit']);
        $this->assertSame([['INTERUNIT_CLEARING_OUT', 'INTERUNIT_CONTROL', $w['a1']['id'], $w['a']['id'], '600.0000', '0.0000'], ['CASH', 'ASSET', $w['a1']['id'], null, '0.0000', '600.0000']],
            $lines->map(fn ($l) => [$l->system_role, $l->account_kind, (int) $l->unit_id, $l->counterparty_unit_id === null ? null : (int) $l->counterparty_unit_id, $l->debit, $l->credit])->all());
        $this->assertSame(0, DB::table('journal_lines')->where('unit_id', $w['a']['id'])->count(), 'SEND never touches the destination');
        $this->assertSame($result, $this->resultOf($w['a1']), 'T04: SEND is not an expense');
        $this->assertSame(40000, $this->balance($w['cash_a1']));
        $this->assertSame(1, DB::table('transfer_postings')->where('transfer_id', $this->transferId($r['public_id']))->where('posting_stage', 'SEND')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.transfer_sent')->where('unit_id', $w['a1']['id'])->count());
        // Insufficient funds: the origin cannot send money it does not hold.
        $big = $this->requestTransfer($t, $w['cash_a1'], $w['a'], '400.01');
        $this->fpost($t, 'finance/transfers/' . $big['public_id'] . '/send')->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');
        $this->assertLedgerInvariants();
    }

    public function test_t05_in_transit_is_visible_to_both_sides_with_age_and_never_as_result(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '900.00', '2026-08-01');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '300.00');
        $this->sendTransfer($o, $r['public_id'], '2026-08-10');
        $today = now('Africa/Luanda')->format('Y-m-d');
        $age = (int) (new \DateTimeImmutable('2026-08-10'))->diff(new \DateTimeImmutable($today))->days;
        foreach ([$o, $d] as $actor) {
            $page = $this->api($actor, 'GET', 'finance/transfers?direction=in_transit')->assertOk()->json();
            $this->assertSame([$r['public_id']], array_column($page['data'], 'public_id'));
            $this->assertSame([$age, 'IN_TRANSIT', '0.00'], [$page['data'][0]['age_days'], $page['data'][0]['reconciliation_state'], $page['data'][0]['economic_effect']]);
        }
        $out = $this->custody($o, $w['a1']);
        $this->assertSame([[$r['public_id'], $w['a']['public_id'], '300.00', $age]], array_map(fn ($i) => [$i['transfer'], $i['counterpart']['public_id'], $i['amount'], $i['age_days']], $out['in_transit_outgoing']));
        $this->assertSame('300.00', $out['in_transit_outgoing_total']);
        $in = $this->custody($d, $w['a']);
        $this->assertSame([$r['public_id']], array_column($in['in_transit_incoming'], 'transfer'));
        $this->assertSame('0.00', $in['internal_funds_received'], 'not received yet');
        $this->assertSame(['900.00', '0.00'], [$out['economic_result']['income'], $in['economic_result']['income']]);
        // Aging uses as_of - send date without touching the journal.
        $before = DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->get()->map(fn ($r) => (array) $r)->all();
        $past = $this->custody($o, $w['a1'], '2026-08-01', '2026-08-20');
        $this->assertSame(10, $past['in_transit_outgoing'][0]['age_days']);
        $this->assertSame($before, DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_t06_t07_t08_receive_posts_one_destination_entry_equal_to_the_send_and_never_the_result(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '1000.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '600.00', 'TRF_BUDGET_QUOTA');
        $this->sendTransfer($o, $r['public_id']);
        $result = $this->resultOf($w['a']);
        $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $w['cash_a']['public_id'], 'amount' => '599.99'])->assertStatus(409)->assertJsonPath('error.code', 'AMOUNT_MISMATCH');
        $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $w['cash_a']['public_id'], 'amount' => '600.01'])->assertStatus(409)->assertJsonPath('error.code', 'AMOUNT_MISMATCH');
        $this->assertConcealed($this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $w['cash_a1']['public_id']]));
        $got = $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $w['cash_a']['public_id'], 'amount' => '600.00'])->assertOk()->json('data');
        $this->assertSame(['RECEIVED', 'AWAITING_RECONCILIATION', 'DESTINATION'], [$got['status'], $got['reconciliation_state'], $got['side']]);
        $this->assertNull($got['origin_account'], 'the destination never sees the origin account');
        $this->assertSame($w['cash_a']['public_id'], $got['destination_account']['public_id']);
        $entry = DB::table('journal_entries')->where('public_id', collect($got['stages'])->firstWhere('stage', 'RECEIVE')['entry'])->first();
        $this->assertSame(['TRANSFER_RECEIVE', $w['a']['id']], [$entry->entry_kind, (int) $entry->unit_id]);
        $this->assertSame([$w['a']['id']], DB::table('journal_lines')->where('entry_id', $entry->id)->distinct()->pluck('unit_id')->map(fn ($v) => (int) $v)->all());
        $send = DB::table('journal_lines')->where('entry_id', DB::table('transfer_postings')->where('transfer_id', $this->transferId($r['public_id']))->where('posting_stage', 'SEND')->value('entry_id'))->sum('debit');
        $this->assertSame((string) $send, (string) DB::table('journal_lines')->where('entry_id', $entry->id)->sum('debit'), 'T08: received = sent');
        $this->assertSame($result, $this->resultOf($w['a']), 'T07: RECEIVE is not revenue');
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM journal_lines l JOIN chart_of_accounts a ON a.id = l.ledger_account_id WHERE l.entry_id = ? AND a.account_kind IN ('INCOME','EXPENSE')", [$entry->id])->n);
        $this->assertSame([40000, 60000], [$this->balance($w['cash_a1']), $this->balance($w['cash_a'])]);
        $this->assertLedgerInvariants();
    }

    public function test_t09_a_bank_fee_is_a_separate_expense_and_never_reduces_the_receive(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $bank = $this->account($w['a'], 'BANK');
        $this->contribute($o, $w['cash_a1'], '500.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '500.00');
        $this->sendTransfer($o, $r['public_id']);
        $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $bank['public_id'], 'amount' => '495.00'])->assertStatus(409)->assertJsonPath('error.code', 'AMOUNT_MISMATCH');
        $this->receiveTransfer($d, $r['public_id'], $bank);
        $ledger = new LedgerPostingService(DB::connection());
        $fee = $ledger->createDraft($d['user'], $this->key(), ['unit_id' => $w['a']['id'], 'entry_kind' => 'EXPENSE', 'entry_date' => now('Africa/Luanda')->format('Y-m-d'), 'description' => 'Tarifa bancária da recepção', 'lines' => [
            ['account' => 'OPERATING_EXPENSE', 'category' => 'FIN_BANK_FEES', 'debit' => '5.00'], ['account' => 'BANK', 'financial_account_id' => $bank['id'], 'credit' => '5.00']]]);
        $ledger->post($d['user'], $fee['public_id'], 0);
        $this->assertSame(49500, $this->balance($bank));
        $this->assertSame('500.0000', (string) DB::table('internal_transfers')->where('public_id', $r['public_id'])->value('amount'), 'the transfer amount is never reduced by a fee');
        $c = $this->custody($d, $w['a']);
        $this->assertSame(['500.00', '5.00', '495.00'], [$c['internal_funds_received'], $c['external_applications'], $c['closing_balance']]);
        $this->assertSame('-5.00', $c['economic_result']['result'], 'only the fee is an expense');
        $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/reconcile')->assertOk()->assertJsonPath('data.reconciliation_state', 'RECONCILED');
    }

    // ---- T10-T11 independent close ---------------------------------------------------------------------------------

    public function test_t10_t11_origin_closes_after_send_and_the_destination_receives_in_a_later_open_period(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $periods = new FinancePeriods(DB::connection());
        $this->contribute($o, $w['cash_a1'], '800.00', '2026-08-02');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '800.00');
        $this->sendTransfer($o, $r['public_id'], '2026-08-12');
        $periods->closeUnit($o['user'], '2026-08', $w['a1']['id']);
        $originAugust = DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
        // T11: the destination closed August too; a reception dated in August posts on the first open day of its next open month.
        $periods->closeUnit($d['user'], '2026-08', $w['a']['id']);
        $got = $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $w['cash_a']['public_id'], 'received_on' => '2026-08-20'])->assertOk()->json('data');
        $receive = collect($got['stages'])->firstWhere('stage', 'RECEIVE');
        $send = collect($got['stages'])->firstWhere('stage', 'SEND');
        $this->assertSame(['2026-08-12', '2026-09-01'], [$send['entry_date'], $receive['entry_date']]);
        $this->assertSame('2026-08-20', \App\Domain\Finance\FinanceRuntime::luandaDate((string) DB::table('internal_transfers')->where('public_id', $r['public_id'])->value('received_at')), 'received_at keeps the real date');
        $this->assertSame($originAugust, DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(), 'T10: the origin period is never altered retroactively');
        $this->assertNull($send['entry'], 'the destination never sees the origin SEND entry');
        $sendEntry = (int) DB::table('transfer_postings')->where('transfer_id', $this->transferId($r['public_id']))->where('posting_stage', 'SEND')->value('entry_id');
        $this->assertSame('2026-08', DB::table('accounting_periods')->where('id', DB::table('journal_entries')->where('id', $sendEntry)->value('period_id'))->value('code'));
        $this->assertSame('CLOSED', DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->value('status'));
        // The origin can no longer post in August (a later reverse is impossible anyway: received).
        $late = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '0.01');
        $this->fpost($o, 'finance/transfers/' . $late['public_id'] . '/send', ['sent_on' => '2026-08-30'])->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->assertLedgerInvariants();
    }

    // ---- T12-T13 duplicates ----------------------------------------------------------------------------------------

    public function test_t12_t13_a_second_send_or_receive_is_an_idempotent_no_op_never_a_second_effect(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $o2 = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '100.00');
        $this->sendTransfer($o, $r['public_id']);
        $this->fpost($o, 'finance/transfers/' . $r['public_id'] . '/send')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->fpost($o2, 'finance/transfers/' . $r['public_id'] . '/send')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame([1, 0], [DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->where('entry_kind', 'TRANSFER_SEND')->count(), $this->balance($w['cash_a1'])]);
        $this->receiveTransfer($d, $r['public_id'], $w['cash_a']);
        $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/receive', ['destination_account' => $w['cash_a']['public_id']])->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame([1, 10000], [DB::table('journal_entries')->where('unit_id', $w['a']['id'])->where('entry_kind', 'TRANSFER_RECEIVE')->count(), $this->balance($w['cash_a'])]);
        $this->fpost($o, 'finance/transfers/' . $r['public_id'] . '/send')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame(1, DB::table('transfer_postings')->where('transfer_id', $this->transferId($r['public_id']))->where('posting_stage', 'SEND')->count());
        $this->assertLedgerInvariants();
    }

    // ---- T14-T16 F-06 / IDOR / documents ---------------------------------------------------------------------------

    public function test_t14_t15_wrong_scope_sides_malformed_and_nonexistent_are_the_same_concealed_404(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $stranger = $this->treasurer($w['b1']);
        $none = $this->staff(['PEOPLE_VIEW'], $w['a1']['id']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $draft = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '10.00');
        $sent = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '20.00');
        $this->sendTransfer($o, $sent['public_id']);
        $missing = (string) Str::ulid();
        foreach (['send' => [], 'receive' => ['destination_account' => $w['cash_a']['public_id']], 'reverse-send' => ['reason' => 'teste'], 'reconcile' => [], 'cancel' => ['reason' => 'teste']] as $stage => $body) {
            $this->fpost($none, "finance/transfers/{$sent['public_id']}/{$stage}", $body)->assertStatus(403)->assertExactJson(['error' => ['code' => 'FORBIDDEN', 'message' => 'You are not authorized to perform this operation.']]);
            foreach (['not-a-ulid', $missing] as $target) {
                $this->assertConcealed($this->fpost($stranger, "finance/transfers/{$target}/{$stage}", $body));
            }
            $this->assertConcealed($this->fpost($stranger, "finance/transfers/{$sent['public_id']}/{$stage}", $body));
        }
        // T14: the destination side cannot act as the origin (SEND / REVERSE_SEND / cancel), whatever the state.
        $this->assertConcealed($this->fpost($d, "finance/transfers/{$draft['public_id']}/send"));
        $this->assertConcealed($this->fpost($d, "finance/transfers/{$sent['public_id']}/send"));
        $this->assertConcealed($this->fpost($d, "finance/transfers/{$sent['public_id']}/reverse-send", ['reason' => 'devolver']));
        $this->assertConcealed($this->fpost($d, "finance/transfers/{$draft['public_id']}/cancel", ['reason' => 'cancelar']));
        // T15: the origin side cannot act as the destination (RECEIVE), whatever the state.
        $this->assertConcealed($this->fpost($o, "finance/transfers/{$sent['public_id']}/receive", ['destination_account' => $w['cash_a']['public_id']]));
        $this->assertConcealed($this->fpost($o, "finance/transfers/{$draft['public_id']}/receive", ['destination_account' => $w['cash_a']['public_id']]));
        // Reads: a third unit sees nothing (detail, custody, contributions), identical to a nonexistent target.
        $this->assertConcealed($this->api($stranger, 'GET', 'finance/transfers/' . $sent['public_id']));
        $this->assertConcealed($this->api($stranger, 'GET', 'finance/transfers/' . $missing));
        $this->assertConcealed($this->api($stranger, 'GET', 'finance/transfers/nope'));
        $this->assertConcealed($this->api($stranger, 'GET', 'finance/units/' . $w['a1']['public_id'] . '/custody'));
        $this->assertConcealed($this->api($stranger, 'GET', 'finance/units/' . $missing . '/custody'));
        $this->assertSame([], $this->api($stranger, 'GET', 'finance/transfers?direction=sent')->assertOk()->json('data'));
        $this->assertConcealed($this->api($stranger, 'GET', 'finance/transfers?direction=sent&unit=' . $w['a1']['public_id']));
        // A request whose origin account belongs to another unit is concealed like a nonexistent account.
        $this->assertConcealed($this->fpost($stranger, 'finance/transfers', ['origin_account' => $w['cash_a1']['public_id'], 'destination_unit' => $w['a']['public_id'], 'amount' => '1.00', 'purpose' => 'TRF_OTHER'], $this->key()));
        $this->assertConcealed($this->fpost($stranger, 'finance/transfers', ['origin_account' => $missing, 'destination_unit' => $w['a']['public_id'], 'amount' => '1.00', 'purpose' => 'TRF_OTHER'], $this->key()));
        // Both sides see the transfer; each sees only its own account and stage entry.
        $origin = $this->detail($o, $sent['public_id']);
        $this->assertSame(['ORIGIN', null], [$origin['side'], $origin['destination_account']]);
        $this->assertNotNull($origin['stages'][0]['entry']);
        $this->assertNoInternalIds($origin);
        $this->assertSame(['DESTINATION', null], [($dest = $this->detail($d, $sent['public_id']))['side'], $dest['origin_account']]);
        $this->assertNull($dest['stages'][0]['entry'], 'the destination never sees the origin journal');
    }

    public function test_t16_supporting_documents_are_public_ids_with_cumulative_files_authority(): void
    {
        $w = $this->world();
        $o = $this->staff(['FINANCE_VIEW', 'FINANCE_TRANSFER', 'FINANCE_POST', 'DOCUMENTS_VIEW'], $w['a1']['id'], false);
        $noFiles = $this->treasurer($w['a1']);
        $doc = $this->document($w['a1']);
        $foreign = $this->document($w['b1']);
        $this->contribute($this->treasurer($w['a1']), $w['cash_a1'], '50.00');
        $body = ['origin_account' => $w['cash_a1']['public_id'], 'destination_unit' => $w['a']['public_id'], 'amount' => '5.00', 'purpose' => 'TRF_PROJECT'];
        $r = $this->fpost($o, 'finance/transfers', $body + ['document' => $doc['public_id']], $this->key())->assertCreated()->json('data');
        $this->assertSame($doc['public_id'], $r['document']['public_id']);
        $this->assertSame((int) $doc['id'], (int) DB::table('internal_transfers')->where('public_id', $r['public_id'])->value('document_id'));
        $this->assertNull($this->detail($noFiles, $r['public_id'])['document']['public_id'], 'no Files authority: the document stays hidden');
        $this->assertConcealed($this->fpost($noFiles, 'finance/transfers', $body + ['document' => $doc['public_id']], $this->key()));
        $this->assertConcealed($this->fpost($o, 'finance/transfers', $body + ['document' => $foreign['public_id']], $this->key()));
        $this->assertConcealed($this->fpost($o, 'finance/transfers', $body + ['document' => (string) Str::ulid()], $this->key()));
        $this->fpost($o, 'finance/transfers', $body + ['document_id' => $doc['id']], $this->key())->assertStatus(422);
        $this->fpost($o, 'finance/transfers', $body + ['source_document_id' => $doc['id']], $this->key())->assertStatus(422);
        $this->fpost($o, 'finance/transfers', $body + ['document' => (string) $doc['id']], $this->key())->assertStatus(422);
    }

    // ---- T17-T21 custody, normative example, perimeter --------------------------------------------------------------

    public function test_t17_t18_custody_shows_where_received_funds_came_from_and_where_sent_funds_went(): void
    {
        $w = $this->world();
        $a1 = $this->treasurer($w['a1']);
        $a = $this->treasurer($w['a']);
        $m = $this->treasurer($w['m']);
        $this->contribute($a1, $w['cash_a1'], '1000.00');
        $this->transfer($a1, $w['cash_a1'], $w['a'], '300.00', $a, $w['cash_a'], 'TRF_REMITTANCE');
        $this->transfer($a1, $w['cash_a1'], $w['a'], '200.00', $a, $w['cash_a'], 'TRF_SUPPORT');
        $this->transfer($a, $w['cash_a'], $w['m'], '100.00', $m, $w['cash_m'], 'TRF_BUDGET_QUOTA');
        $received = $this->custody($a, $w['a']);
        $this->assertSame([[$w['a1']['public_id'], 'TRF_REMITTANCE', '300.00'], [$w['a1']['public_id'], 'TRF_SUPPORT', '200.00']],
            array_map(fn ($g) => [$g['origin']['public_id'], $g['purpose']['code'], $g['amount']], $received['received_by_origin']), 'T17: from where');
        $this->assertSame([[$w['m']['public_id'], 'TRF_BUDGET_QUOTA', '100.00']], array_map(fn ($g) => [$g['destination']['public_id'], $g['purpose']['code'], $g['amount']], $received['sent_by_destination']));
        $sent = $this->custody($a1, $w['a1']);
        $this->assertSame([[$w['a']['public_id'], 'TRF_REMITTANCE', '300.00'], [$w['a']['public_id'], 'TRF_SUPPORT', '200.00']],
            array_map(fn ($g) => [$g['destination']['public_id'], $g['purpose']['code'], $g['amount']], $sent['sent_by_destination']), 'T18: to where');
        $this->assertTrue($received['balanced']);
        $this->assertTrue($sent['balanced']);
        $list = $this->api($a, 'GET', 'finance/transfers?direction=received')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$w['a1']['public_id']], array_unique(array_map(fn ($i) => $i['origin']['public_id'], $list)));
        foreach ($list as $item) {
            $this->assertSame(['RECEIVED', '0.00'], [$item['status'], $item['economic_effect']]);
            $this->assertNotNull($item['sent_at']);
            $this->assertNotNull($item['received_at']);
        }
        $this->assertSame(['0.00', '0.00'], [$received['economic_result']['income'], $received['economic_result']['expense']], 'custody moved, the result did not');
    }

    public function test_t19_the_normative_example_100_60_40_never_duplicates_revenue(): void
    {
        $w = $this->world();
        $c = $this->treasurer($w['a1']);
        $x = $this->treasurer($w['a']);
        $m = $this->treasurer($w['m']);
        $origin = $this->contribute($c, $w['cash_a1'], '100000.00');
        $snapshot = fn () => [(array) DB::table('contributions')->where('public_id', $origin['public_id'])->first(), DB::table('journal_lines')->where('entry_id', DB::table('journal_entries')->where('public_id', $origin['entry'])->value('id'))->orderBy('id')->get()->map(fn ($l) => (array) $l)->all()];
        $before = $snapshot();
        $this->transfer($c, $w['cash_a1'], $w['a'], '60000.00', $x, $w['cash_a'], 'TRF_REMITTANCE');
        $this->transfer($x, $w['cash_a'], $w['m'], '40000.00', $m, $w['cash_m'], 'TRF_REMITTANCE');
        $cong = $this->custody($c, $w['a1']);
        $centro = $this->custody($x, $w['a']);
        $mun = $this->custody($m, $w['m']);
        $this->assertSame(['100000.00', '0.00', '60000.00', '40000.00'], [$cong['external_funds_received'], $cong['internal_funds_received'], $cong['internal_funds_sent'], $cong['closing_balance']]);
        $this->assertSame(['0.00', '60000.00', '40000.00', '20000.00'], [$centro['external_funds_received'], $centro['internal_funds_received'], $centro['internal_funds_sent'], $centro['closing_balance']]);
        $this->assertSame(['0.00', '40000.00', '0.00', '40000.00'], [$mun['external_funds_received'], $mun['internal_funds_received'], $mun['internal_funds_sent'], $mun['closing_balance']]);
        $this->assertSame(10000000, $this->income($w['a1']) + $this->income($w['a']) + $this->income($w['m']), 'economic revenue across the subtree = 100 000 (never 160 000 / 200 000)');
        $this->assertSame([10000000, 0, 0], [$this->income($w['a1']), $this->income($w['a']), $this->income($w['m'])]);
        $this->assertSame($before, $snapshot(), 'the original external revenue (contribution + its entry) is never mutated by the transfers');
        $this->assertSame(10000000, $this->balance($w['cash_a1']) + $this->balance($w['cash_a']) + $this->balance($w['cash_m']));
        foreach ([$cong, $centro, $mun] as $report) {
            $this->assertTrue($report['balanced']);
        }
        $this->assertLedgerInvariants();
    }

    public function test_t20_t21_subtree_view_classifies_the_perimeter_and_the_economic_effect_is_always_zero(): void
    {
        $w = $this->world();
        $a1 = $this->treasurer($w['a1']);
        $b1 = $this->treasurer($w['b1']);
        $a = $this->treasurer($w['a']);
        $b = $this->treasurer($w['b']);
        $this->contribute($a1, $w['cash_a1'], '1000.00');
        $this->contribute($b1, $w['cash_b1'], '1000.00');
        $internal = $this->transfer($a1, $w['cash_a1'], $w['a'], '100.00', $a, $w['cash_a']);
        $out = $this->transfer($a1, $w['cash_a1'], $w['b'], '70.00', $b, $w['cash_b']);
        $into = $this->transfer($b1, $w['cash_b1'], $w['a2'], '30.00');
        $upper = $this->staff(['FINANCE_VIEW', 'FINANCE_CONSOLIDATED_VIEW'], $w['a']['id'], true);
        $view = $this->api($upper, 'GET', 'finance/units/' . $w['a']['public_id'] . '/subtree-transfers?from=2026-01-01&to=' . now('Africa/Luanda')->format('Y-m-d'))->assertOk()->json();
        $classes = array_column($view['data'], 'perimeter_class', 'public_id');
        $this->assertSame(['INTERNAL_TO_PERIMETER', 'OUT_OF_PERIMETER', 'INTO_PERIMETER'], [$classes[$internal['public_id']], $classes[$out['public_id']], $classes[$into['public_id']]]);
        $this->assertSame(['0.00'], array_values(array_unique(array_column($view['data'], 'economic_effect'))));
        $this->assertSame(['transfers' => 1, 'amount' => '100.00', 'economic_effect' => '0.00'], $view['meta']['totals']['INTERNAL_TO_PERIMETER']);
        $this->assertSame(['transfers' => 1, 'amount' => '70.00', 'economic_effect' => '0.00'], $view['meta']['totals']['OUT_OF_PERIMETER']);
        $this->assertSame(['transfers' => 1, 'amount' => '30.00', 'economic_effect' => '0.00'], $view['meta']['totals']['INTO_PERIMETER']);
        $this->assertSame(3, $view['meta']['perimeter_units'], 'perimeter = A + A1 + A2');
        $this->assertNoInternalIds($view);
        // Subtree revenue is the sum of own results; no transfer changed it.
        $this->assertSame(100000, $this->income($w['a1']) + $this->income($w['a']) + $this->income($w['a2']));
        // D17: the consolidated grant must cover the unit AND all its current descendants; a grant on A without descendants is not enough.
        $shallow = $this->staff(['FINANCE_VIEW', 'FINANCE_CONSOLIDATED_VIEW'], $w['a']['id'], false);
        $this->assertConcealed($this->api($shallow, 'GET', 'finance/units/' . $w['a']['public_id'] . '/subtree-transfers'));
        $this->api($this->treasurer($w['a']), 'GET', 'finance/units/' . $w['a']['public_id'] . '/subtree-transfers')->assertStatus(403);
    }

    // ---- T22 interunit reconciliation --------------------------------------------------------------------------------

    public function test_t22_interunit_reconciliation_verifies_send_transfer_receive_and_stamps_once(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '40.00');
        $this->fpost($o, 'finance/transfers/' . $r['public_id'] . '/reconcile')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->sendTransfer($o, $r['public_id']);
        $this->fpost($o, 'finance/transfers/' . $r['public_id'] . '/reconcile')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertSame('IN_TRANSIT', $this->detail($o, $r['public_id'])['reconciliation_state']);
        $got = $this->receiveTransfer($d, $r['public_id'], $w['cash_a']);
        $this->assertSame(['AWAITING_RECONCILIATION', []], [$got['reconciliation_state'], $got['pairing']]);
        $this->assertNull(DB::table('internal_transfers')->where('public_id', $r['public_id'])->value('reconciled_at'));
        $done = $this->fpost($d, 'finance/transfers/' . $r['public_id'] . '/reconcile')->assertOk()->assertJsonPath('meta.replayed', false)->json('data');
        $this->assertSame('RECONCILED', $done['reconciliation_state']);
        $this->assertNotNull($done['reconciled_at']);
        $this->fpost($o, 'finance/transfers/' . $r['public_id'] . '/reconcile')->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.transfer_reconciled')->where('entity_id', $this->transferId($r['public_id']))->count());
        // A tampered pairing (test-only raw corruption of a RECEIVE line account) is never reconciled.
        $s = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '10.00');
        $this->sendTransfer($o, $s['public_id']);
        $this->receiveTransfer($d, $s['public_id'], $w['cash_a']);
        $other = $this->account($w['a']);
        $receive = (int) DB::table('transfer_postings')->where('transfer_id', $this->transferId($s['public_id']))->where('posting_stage', 'RECEIVE')->value('entry_id');
        DB::table('journal_lines')->where('entry_id', $receive)->whereNotNull('financial_account_id')->update(['financial_account_id' => $other['id']]);
        $this->fpost($d, 'finance/transfers/' . $s['public_id'] . '/reconcile')->assertStatus(409)->assertJsonPath('error.code', 'RECONCILIATION_MISMATCH')->assertJsonPath('error.details.mismatches', ['RECEIVE_LINES_MISMATCH']);
        $this->assertNull(DB::table('internal_transfers')->where('public_id', $s['public_id'])->value('reconciled_at'));
        // Physical: reconciled_at only on a RECEIVED transfer.
        try {
            DB::table('internal_transfers')->where('public_id', $this->requestTransfer($o, $w['cash_a1'], $w['a'], '1.00')['public_id'])->update(['reconciled_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->fail('CHECK ck_internal_transfers_reconciled must refuse');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('ck_internal_transfers_reconciled', $e->getMessage());
        }
    }

    // ---- T23-T26 concurrency (real processes, deterministic ordering) ----------------------------------------------

    public function test_t23_c2_two_processes_receiving_the_same_transfer_produce_one_receive(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d1 = $this->treasurer($w['a']);
        $d2 = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '100.00');
        $this->sendTransfer($o, $r['public_id']);
        [$first, $second, $wait] = $this->lockOrdered(['op' => 'receive', 'user' => $d1['user'], 'session' => $d1['session'], 'transfer' => $r['public_id'], 'body' => ['destination_account' => $w['cash_a']['public_id']]],
            ['op' => 'receive', 'user' => $d2['user'], 'session' => $d2['session'], 'transfer' => $r['public_id'], 'body' => ['destination_account' => $w['cash_a']['public_id']]]);
        $this->assertSame(['OK', 'OK'], [$first['status'], $second['status']], json_encode([$first, $second]));
        $this->assertSame([false, true], [$first['result']['replayed'], $second['result']['replayed']]);
        $this->assertTrue($wait['blocked_by_first']);
        $this->assertSame([1, 10000], [DB::table('journal_entries')->where('unit_id', $w['a']['id'])->where('entry_kind', 'TRANSFER_RECEIVE')->count(), $this->balance($w['cash_a'])], 'one economic RECEIVE, never double cash');
        $this->assertLedgerInvariants();
        $this->evidence('C2', compact('first', 'second', 'wait'));
    }

    public function test_t24_c7_two_concurrent_reconciliations_reconcile_once(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $r = $this->transfer($o, $w['cash_a1'], $w['a'], '100.00', $d, $w['cash_a']);
        [$first, $second, $wait] = $this->lockOrdered(['op' => 'reconcile', 'user' => $o['user'], 'session' => $o['session'], 'transfer' => $r['public_id']],
            ['op' => 'reconcile', 'user' => $d['user'], 'session' => $d['session'], 'transfer' => $r['public_id']]);
        $this->assertSame(['OK', 'OK'], [$first['status'], $second['status']], json_encode([$first, $second]));
        $this->assertSame([false, true], [$first['result']['replayed'], $second['result']['replayed']]);
        $this->assertSame(['internal_transfers', true], [$wait['object'], $wait['blocked_by_first']]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.transfer_reconciled')->where('entity_id', $this->transferId($r['public_id']))->count(), 'one reconciliation, coherent audit');
        $this->assertNotNull(DB::table('internal_transfers')->where('public_id', $r['public_id'])->value('reconciled_at'));
        $this->evidence('C7', compact('first', 'second', 'wait'));
    }

    public function test_t25_c9_receive_first_then_reverse_send_is_refused(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '100.00');
        $this->sendTransfer($o, $r['public_id']);
        [$receive, $reverse, $wait] = $this->lockOrdered(['op' => 'receive', 'user' => $d['user'], 'session' => $d['session'], 'transfer' => $r['public_id'], 'body' => ['destination_account' => $w['cash_a']['public_id']]],
            ['op' => 'reverse_send', 'user' => $o['user'], 'session' => $o['session'], 'transfer' => $r['public_id'], 'body' => ['reason' => 'Devolução']]);
        $this->assertSame(['OK', 'ALREADY_RECEIVED'], [$receive['status'], $reverse['status']]);
        $this->assertSame(['internal_transfers', true], [$wait['object'], $wait['blocked_by_first']]);
        $t = DB::table('internal_transfers')->where('public_id', $r['public_id'])->first();
        $this->assertSame('RECEIVED', $t->status);
        $this->assertSame(['RECEIVE', 'SEND'], DB::table('transfer_postings')->where('transfer_id', $t->id)->orderBy('posting_stage')->pluck('posting_stage')->all());
        $this->assertSame([0, 10000], [$this->balance($w['cash_a1']), $this->balance($w['cash_a'])], 'the funds are in the destination only');
        $this->assertLedgerInvariants();
        $this->evidence('C9A', compact('receive', 'reverse', 'wait'));
    }

    public function test_t26_c9_reverse_first_then_receive_is_refused_and_the_origin_gets_its_funds_back(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00');
        $r = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '100.00');
        $this->sendTransfer($o, $r['public_id']);
        [$reverse, $receive, $wait] = $this->lockOrdered(['op' => 'reverse_send', 'user' => $o['user'], 'session' => $o['session'], 'transfer' => $r['public_id'], 'body' => ['reason' => 'Devolução']],
            ['op' => 'receive', 'user' => $d['user'], 'session' => $d['session'], 'transfer' => $r['public_id'], 'body' => ['destination_account' => $w['cash_a']['public_id']]]);
        $this->assertSame(['OK', 'TRANSITION_NOT_ALLOWED'], [$reverse['status'], $receive['status']]);
        $this->assertSame(['internal_transfers', true], [$wait['object'], $wait['blocked_by_first']]);
        $t = DB::table('internal_transfers')->where('public_id', $r['public_id'])->first();
        $this->assertSame(['CANCELLED', 'Devolução'], [$t->status, $t->cancel_reason]);
        $this->assertSame(['REVERSE_SEND', 'SEND'], DB::table('transfer_postings')->where('transfer_id', $t->id)->orderBy('posting_stage')->pluck('posting_stage')->all());
        $this->assertSame([10000, 0], [$this->balance($w['cash_a1']), $this->balance($w['cash_a'])], 'the origin has its funds back, the destination nothing');
        $this->assertSame('RETURNED', $this->detail($o, $r['public_id'])['reconciliation_state']);
        $position = (new \App\Domain\Finance\LedgerQueries(DB::connection()))->interunitPosition($w['a1']['id'], '2026-12-31');
        $this->assertSame(0, $position['sent'], 'clearing OUT nets to zero after the return');
        $this->assertSame(10000, $this->resultOf($w['a1']), 'the return is not revenue (only the original contribution is)');
        $this->assertLedgerInvariants();
        $this->evidence('C9B', compact('reverse', 'receive', 'wait'));
    }

    // ---- §27 period close interaction (deterministic, both orders) ------------------------------------------------

    public function test_period_close_serialises_with_send_in_the_origin_and_with_receive_in_the_destination(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $this->contribute($o, $w['cash_a1'], '100.00', '2026-08-01');
        $month = '2026-08';
        // SEND holds the origin August period FOR SHARE; the close waits, then closes: the SEND committed before it.
        $r1 = $this->requestTransfer($o, $w['cash_a1'], $w['a'], '10.00');
        [$send, $close, $wait] = $this->lockOrdered(['op' => 'send', 'user' => $o['user'], 'session' => $o['session'], 'transfer' => $r1['public_id'], 'body' => ['sent_on' => '2026-08-15']],
            ['op' => 'close_unit', 'user' => $d['user'], 'session' => $d['session'], 'period' => $month, 'unit' => $w['a1']['id']]);
        $this->assertSame(['OK', 'OK', 'accounting_periods', true], [$send['status'], $close['status'], $wait['object'], $wait['blocked_by_first']]);
        $closedAt = (string) DB::table('accounting_period_unit_closes')->where('unit_id', $w['a1']['id'])->value('closed_at');
        $this->assertLessThan($closedAt, (string) DB::table('journal_entries')->where('unit_id', $w['a1']['id'])->where('entry_kind', 'TRANSFER_SEND')->value('posted_at'));
        // Close first on the destination: a waiting RECEIVE dated in August never posts into the closed month.
        [$close2, $receive, $wait2] = $this->lockOrdered(['op' => 'close_unit', 'user' => $o['user'], 'session' => $o['session'], 'period' => $month, 'unit' => $w['a']['id']],
            ['op' => 'receive', 'user' => $d['user'], 'session' => $d['session'], 'transfer' => $r1['public_id'], 'body' => ['destination_account' => $w['cash_a']['public_id'], 'received_on' => '2026-08-20']]);
        $this->assertSame(['OK', 'PERIOD_CLOSED', 'accounting_periods', true], [$close2['status'], $receive['status'], $wait2['object'], $wait2['blocked_by_first']]);
        $this->assertSame(0, DB::table('journal_entries')->where('unit_id', $w['a']['id'])->count(), 'nothing posted into the closed (period, unit)');
        $this->assertSame('SENT', DB::table('internal_transfers')->where('public_id', $r1['public_id'])->value('status'));
        // Retried after the close, the same reception posts in the next open month.
        $got = $this->receiveTransfer($d, $r1['public_id'], $w['cash_a'], '2026-08-20');
        $this->assertSame('2026-09-01', collect($got['stages'])->firstWhere('stage', 'RECEIVE')['entry_date']);
        $this->evidence('CLOSE', compact('send', 'close', 'wait', 'close2', 'receive', 'wait2'));
    }

    // ---- T28 retry / idempotency + bounded lists ------------------------------------------------------------------

    public function test_t28_retries_are_idempotent_and_collections_are_bounded(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $d = $this->treasurer($w['a']);
        $key = $this->key();
        $body = ['origin_account' => $w['cash_a1']['public_id'], 'destination_unit' => $w['a']['public_id'], 'amount' => '1.00', 'purpose' => 'TRF_OTHER'];
        $first = $this->fpost($o, 'finance/transfers', $body, $key)->assertCreated()->json('data.public_id');
        for ($i = 0; $i < 3; $i++) {
            $this->fpost($o, 'finance/transfers', $body, $key)->assertOk()->assertJsonPath('data.public_id', $first)->assertJsonPath('meta.replayed', true);
        }
        $this->fpost($o, 'finance/transfers', ['amount' => '2.00'] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, DB::table('internal_transfers')->where('origin_unit_id', $w['a1']['id'])->count());
        $this->contribute($o, $w['cash_a1'], '10.00');
        $this->sendTransfer($o, $first);
        $this->sendTransfer($o, $first);
        $this->receiveTransfer($d, $first, $w['cash_a']);
        $this->receiveTransfer($d, $first, $w['cash_a']);
        $this->assertSame([1, 1], [DB::table('journal_entries')->where('entry_kind', 'TRANSFER_SEND')->where('unit_id', $w['a1']['id'])->count(), DB::table('journal_entries')->where('entry_kind', 'TRANSFER_RECEIVE')->where('unit_id', $w['a']['id'])->count()]);
        for ($i = 0; $i < 3; $i++) {
            $this->requestTransfer($o, $w['cash_a1'], $w['a'], '0.01');
        }
        $page = $this->api($o, 'GET', 'finance/transfers?direction=sent&per_page=2')->assertOk()->json();
        $this->assertSame([2, 2, 4, 2], [count($page['data']), $page['meta']['per_page'], $page['meta']['total'], $page['meta']['last_page']]);
        $this->assertNotNull($page['links']['next']);
        $this->api($o, 'GET', 'finance/transfers?direction=sent&per_page=101')->assertStatus(422);
        $this->assertSame(50, $this->api($o, 'GET', 'finance/transfers?direction=sent')->json('meta.per_page'));
        $this->api($o, 'GET', 'finance/transfers?direction=everything')->assertStatus(422);
        $this->assertSame(100, $this->api($o, 'GET', 'finance/contributions?per_page=100')->assertOk()->json('meta.per_page'));
        $this->api($o, 'GET', 'finance/contributions?per_page=1000')->assertStatus(422);
    }

    public function test_context_exposes_permissions_units_accounts_and_purposes_without_internal_ids(): void
    {
        $w = $this->world();
        $o = $this->treasurer($w['a1']);
        $ctx = $this->api($o, 'GET', 'finance/context')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing($this->financePermissions(), $ctx['permissions']);
        $this->assertSame([$w['a1']['public_id']], array_column($ctx['units'], 'public_id'));
        $this->assertSame([$w['cash_a1']['public_id']], array_column($ctx['accounts'], 'public_id'));
        $this->assertSame(['TRF_REMITTANCE', 'TRF_BUDGET_QUOTA', 'TRF_SPECIAL_CONTRIBUTION', 'TRF_SUPPORT', 'TRF_PROJECT', 'TRF_OTHER'], array_column($ctx['purposes'], 'code'));
        $this->assertNoInternalIds($ctx);
        $found = $this->api($o, 'GET', 'finance/units?search=' . urlencode((string) DB::table('organizational_units')->where('id', $w['a']['id'])->value('name')))->assertOk()->json('data.items');
        $this->assertContains($w['a']['public_id'], array_column($found, 'public_id'));
        $this->assertNoInternalIds($found);
        $this->api($this->staff(['PEOPLE_VIEW'], $w['a1']['id']), 'GET', 'finance/context')->assertOk()->assertJsonPath('data.permissions', []);
    }

    // ---- helpers --------------------------------------------------------------------------------------------------------

    /**
     * Deterministic two-connection ordering (F1A C3 protocol): FIRST runs its operation inside its business transaction
     * and HOLDS it (HELD from the before-commit hook: every lock acquired); SECOND is then released and must be OBSERVED
     * in performance_schema.data_lock_waits blocked by FIRST's connection; only then FIRST commits.
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
            // FIRST ended early (it failed): its output is complete, so reading it cannot block.
            $this->fail('FIRST must hold its locks: ' . $held . stream_get_contents($a[1][1]) . stream_get_contents($a[1][2]));
        }
        // Never read FIRST's remaining output while it holds (not even in an assertion message): it only ends after
        // "commit" (P0.9 lesson: an eager message argument deadlocks the run).
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
        // Always release FIRST and collect both processes BEFORE asserting: a failed observation must never leave a
        // worker holding locks (it would stall every later test).
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
        $proc = proc_open([$php, $root . '/scripts/p010-transfer-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
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

/** The six approved transfer purposes (D-04A.6), as the API accepts them. */
final class FinanceCatalogPurposes
{
    public const ALL = ['TRF_REMITTANCE', 'TRF_BUDGET_QUOTA', 'TRF_SPECIAL_CONTRIBUTION', 'TRF_SUPPORT', 'TRF_PROJECT', 'TRF_OTHER'];
}
