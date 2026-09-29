<?php

declare(strict_types=1);

// P0.5-I test-only CLI for the People Playwright suite. Refuses to run unless APP_ENV=e2e AND
// MEPA_E2E_PEOPLE_SUPPORT=1 (same isolation rule as reset_e2e_rate_limiters.php). No HTTP surface.
//   reset <user id>...        clear the IP-keyed and per-user limiter keys of the fixture accounts
//   membership <public_id>    report memberships / member numbers of one Person (P10 evidence)

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!$app->environment('e2e') || getenv('MEPA_E2E_PEOPLE_SUPPORT') !== '1') {
    fwrite(STDERR, "Refusing People E2E support outside the explicit E2E test environment.\n");
    exit(2);
}
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "Refusing People E2E support outside an isolated Wave 5 test pool.\n");
    exit(2);
}

$command = $argv[1] ?? '';
if ($command === 'reset') {
    $ip = '127.0.0.1';
    RateLimiter::clear(md5('api' . $ip));
    RateLimiter::clear(md5('login' . hash('sha256', $ip)));
    RateLimiter::clear(sha1('|' . $ip));
    foreach (array_slice($argv, 2) as $user) {
        RateLimiter::clear(sha1((string) (int) $user));
    }
    fwrite(STDOUT, "E2E_PEOPLE_RATE_LIMITERS_RESET\n");
    exit(0);
}
if ($command === 'membership') {
    $person = DB::table('people')->where('public_id', (string) ($argv[2] ?? ''))->value('id');
    if ($person === null) {
        fwrite(STDOUT, json_encode(['found' => false]) . "\n");
        exit(0);
    }
    $memberships = DB::table('memberships')->where('person_id', $person)->pluck('id')->all();
    fwrite(STDOUT, json_encode([
        'found' => true,
        'memberships' => count($memberships),
        'member_numbers' => $memberships === [] ? 0 : DB::table('member_numbers')->whereIn('membership_id', $memberships)->count(),
        'onboarding_contexts' => DB::table('person_unit_contexts')->where('person_id', $person)->where('context_kind', 'ONBOARDING')->count(),
    ]) . "\n");
    exit(0);
}
fwrite(STDERR, "usage: people_e2e_support.php reset <user id>... | membership <public_id>\n");
exit(2);
