<?php

declare(strict_types=1);

// P0.10-F1B test-only CLI for the Finance Playwright suite. Refuses to run unless APP_ENV=e2e AND
// MEPA_E2E_FINANCE_SUPPORT=1 on an isolated Wave 5 pool (same isolation rule as membership_e2e_support.php). No HTTP
// surface.
//   reset <user id>...        clear the IP-keyed limiters and the per-user finance / finance-write buckets
//   transfer <public id>      report status, postings and ledger effect of a transfer (evidence)

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
fwrite(STDERR, "usage: finance_e2e_support.php reset <user id>... | transfer <transfer public_id>\n");
exit(2);
