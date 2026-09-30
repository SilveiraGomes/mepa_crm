<?php

declare(strict_types=1);

// P0.9 test-only CLI for the Membership Playwright suite. Refuses to run unless APP_ENV=e2e AND
// MEPA_E2E_MEMBERSHIP_SUPPORT=1 on an isolated Wave 5 pool (same isolation rule as people_e2e_support.php). No HTTP
// surface.
//   reset <user id>...          clear the IP-keyed limiters and the per-user membership / membership-write /
//                               membership-search buckets of the fixture accounts
//   member <membership public>  report the official number, open-period Congregation and period count (evidence)

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!$app->environment('e2e') || getenv('MEPA_E2E_MEMBERSHIP_SUPPORT') !== '1') {
    fwrite(STDERR, "Refusing Membership E2E support outside the explicit E2E test environment.\n");
    exit(2);
}
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "Refusing Membership E2E support outside an isolated Wave 5 test pool.\n");
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
        foreach (['membership', 'membership-write', 'membership-search', 'people', 'files'] as $name) {
            RateLimiter::clear(md5($name . $name . '|' . $user));
        }
    }
    fwrite(STDOUT, "E2E_MEMBERSHIP_RATE_LIMITERS_RESET\n");
    exit(0);
}
if ($command === 'member') {
    $membership = DB::table('memberships')->where('public_id', (string) ($argv[2] ?? ''))->first(['id']);
    if ($membership === null) {
        fwrite(STDOUT, json_encode(['found' => false]) . "\n");
        exit(0);
    }
    $open = DB::table('membership_periods as mp')->join('organizational_units as ou', 'ou.id', '=', 'mp.congregation_id')->where('mp.membership_id', $membership->id)->whereNull('mp.ends_at')->get(['ou.public_id']);
    fwrite(STDOUT, json_encode([
        'found' => true,
        'member_number' => DB::table('member_numbers')->where('membership_id', $membership->id)->value('number'),
        'open_periods' => count($open),
        'open_congregation' => $open[0]->public_id ?? null,
        'periods' => DB::table('membership_periods')->where('membership_id', $membership->id)->count(),
    ]) . "\n");
    exit(0);
}
fwrite(STDERR, "usage: membership_e2e_support.php reset <user id>... | member <membership public_id>\n");
exit(2);
