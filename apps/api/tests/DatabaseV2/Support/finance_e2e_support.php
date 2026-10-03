<?php

declare(strict_types=1);

// P0.10-F1B test-only CLI for the Finance Playwright suite. Refuses to run unless APP_ENV=e2e AND
// MEPA_E2E_FINANCE_SUPPORT=1 on an isolated Wave 5 pool (same isolation rule as membership_e2e_support.php). No HTTP
// surface.
//   reset <user id>...        clear the IP-keyed limiters and the per-user finance / finance-write buckets
//   transfer <public id>      report status, postings and ledger effect of a transfer (evidence)
//   subledger / budget / reconciliation / account <...>   P0.10-F1C evidence of the browser journeys

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!$app->environment('e2e') || getenv('MEPA_E2E_FINANCE_SUPPORT') !== '1') {
    fwrite(STDERR, "Refusing Finance E2E support outside the explicit E2E test environment.\n");
    exit(2);
}
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "Refusing Finance E2E support outside an isolated Wave 5 test pool.\n");
    exit(2);
}

$command = $argv[1] ?? '';
if ($command === 'reset') {
    $ip = '127.0.0.1';
    RateLimiter::clear(md5('api' . $ip));
    RateLimiter::clear(md5('login' . hash('sha256', $ip)));
    RateLimiter::clear(sha1('|' . $ip));
    foreach (array_slice($argv, 2) as $user) {
        $user = (string) (int) $user;
        RateLimiter::clear(sha1($user));
        foreach (['finance', 'finance-write'] as $name) {
            RateLimiter::clear(md5($name . $name . '|' . $user));
        }
    }
    fwrite(STDOUT, "E2E_FINANCE_RATE_LIMITERS_RESET\n");
    exit(0);
}
if ($command === 'transfer') {
    $t = DB::table('internal_transfers')->where('public_id', (string) ($argv[2] ?? ''))->first(['id', 'status', 'reconciled_at']);
    if ($t === null) {
        fwrite(STDOUT, json_encode(['found' => false]) . "\n");
        exit(0);
    }
    $postings = DB::table('transfer_postings as p')->join('journal_entries as e', 'e.id', '=', 'p.entry_id')->where('p.transfer_id', $t->id)->orderBy('p.id')->pluck('e.entry_kind')->all();
    $result = DB::selectOne("SELECT COUNT(*) AS n FROM transfer_postings p JOIN journal_lines l ON l.entry_id = p.entry_id JOIN chart_of_accounts a ON a.id = l.ledger_account_id WHERE p.transfer_id = ? AND a.account_kind IN ('INCOME','EXPENSE')", [$t->id]);
    fwrite(STDOUT, json_encode(['found' => true, 'status' => $t->status, 'reconciled' => $t->reconciled_at !== null, 'postings' => $postings, 'result_lines' => (int) $result->n]) . "\n");
    exit(0);
}
// P0.10-F1C evidence probes: the ledger effect of the browser journeys, read directly from the pool.
if ($command === 'subledger') {
    $kind = ($argv[2] ?? '') === 'payables' ? 'payables' : 'receivables';
    $d = DB::table($kind)->where('public_id', (string) ($argv[3] ?? ''))->first();
    if ($d === null) {
        fwrite(STDOUT, json_encode(['found' => false]) . "\n");
        exit(0);
    }
    $column = $kind === 'receivables' ? 'receivable_id' : 'payable_id';
    $settlements = DB::table('settlement_allocations as a')->join('settlements as s', 's.id', '=', 'a.settlement_id')->where('a.' . $column, $d->id)->where('s.status', 'POSTED')->pluck('s.entry_id')->all();
    $resultLines = $settlements === [] ? 0 : (int) DB::table('journal_lines as l')->join('chart_of_accounts as c', 'c.id', '=', 'l.ledger_account_id')->whereIn('l.entry_id', $settlements)->whereIn('c.account_kind', ['INCOME', 'EXPENSE'])->count();
    $economic = DB::table('journal_lines as l')->join('chart_of_accounts as c', 'c.id', '=', 'l.ledger_account_id')->where('l.entry_id', $d->recognition_entry_id)->whereNotNull('l.category_id')->value('c.system_role');
    fwrite(STDOUT, json_encode(['found' => true, 'status' => $d->status, 'settlements' => count($settlements), 'settlement_result_lines' => $resultLines, 'economic_role' => $economic]) . "\n");
    exit(0);
}
if ($command === 'budget') {
    $b = DB::table('budgets')->where('public_id', (string) ($argv[2] ?? ''))->first();
    fwrite(STDOUT, json_encode($b === null ? ['found' => false] : ['found' => true, 'status' => $b->status, 'segregated' => $b->approved_by !== null && (int) $b->approved_by !== (int) $b->submitted_by]) . "\n");
    exit(0);
}
if ($command === 'reconciliation') {
    $r = DB::table('reconciliations')->where('public_id', (string) ($argv[2] ?? ''))->first();
    fwrite(STDOUT, json_encode($r === null ? ['found' => false] : ['found' => true, 'status' => $r->status, 'matches' => DB::table('reconciliation_matches')->where('reconciliation_id', $r->id)->count()]) . "\n");
    exit(0);
}
if ($command === 'account') {
    $a = DB::table('accounts')->where('public_id', (string) ($argv[2] ?? ''))->first();
    $details = $a === null ? null : DB::table('bank_account_details')->where('account_id', $a->id)->first();
    fwrite(STDOUT, json_encode($a === null ? ['found' => false] : ['found' => true, 'status' => $a->status, 'kind' => $a->account_kind,
        'number_in_clear' => $details !== null && str_contains((string) $details->account_number_ciphertext, (string) ($argv[3] ?? "\0"))]) . "\n");
    exit(0);
}
// P0.10-F1D-E1 evidence probe: audit rows of one action for one user (count + the newest metadata).
if ($command === 'audit') {
    $q = DB::table('audit_logs')->where('action', (string) ($argv[3] ?? ''))->where('actor_id', (int) ($argv[2] ?? 0));
    $last = (clone $q)->orderByDesc('id')->value('after_metadata');
    fwrite(STDOUT, json_encode(['count' => $q->count(), 'last' => $last === null ? null : json_decode((string) $last, true)]) . "\n");
    exit(0);
}
fwrite(STDERR, "usage: finance_e2e_support.php reset <user id>... | audit <user id> <action> | transfer <public_id> | subledger <receivables|payables> <public_id> | budget <public_id> | reconciliation <public_id> | account <public_id> <number>\n");
exit(2);
