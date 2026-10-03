<?php

declare(strict_types=1);

// P0.10-F1C concurrency worker (ADR 0021 D30 C4 / C7 / C10 + close vs posting). One process = one MySQL connection.
// Boots the Laravel application against the isolated Wave 5 pool named by WAVE5_DSN (refuses anything but
// mepa_wave5_test_* with WAVE5_ALLOW_SYNTHETIC=1) and uses the SAME FinanceServiceFactory as the HTTP layer.
// Protocol: prints READY, waits for "go"; "announce" prints RUNNING <MySQL connection id> before the operation;
// "hold_until_commit" prints HELD from the business transaction's before-commit hook (every lock of the operation is
// held) and waits for "commit" on stdin. Prints one JSON line {"status": "OK" | <FinanceError reason>, ...}.

use App\Domain\Finance\AccrualService;
use App\Domain\Finance\BankReconciliationService;
use App\Domain\Finance\BudgetService;
use App\Domain\Finance\FinanceError;
use App\Domain\Finance\PeriodCloseService;
use App\Http\Finance\FinanceServiceFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root . '/apps/api/vendor/autoload.php';
$job = json_decode(base64_decode((string) ($argv[1] ?? ''), true) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
parse_str(str_replace(';', '&', preg_replace('/^mysql:/', '', (string) getenv('WAVE5_DSN'))), $parts);
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', (string) ($parts['dbname'] ?? '')) || getenv('WAVE5_ALLOW_SYNTHETIC') !== '1') {
    fwrite(STDERR, "refusing: WAVE5_DSN must be an isolated mepa_wave5_test_* pool and WAVE5_ALLOW_SYNTHETIC=1\n");
    exit(2);
}
$app = require $root . '/apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'mysql', 'database.connections.mysql.host' => $parts['host'] ?? '127.0.0.1', 'database.connections.mysql.port' => $parts['port'] ?? 3306,
    'database.connections.mysql.database' => $parts['dbname'], 'database.connections.mysql.username' => getenv('WAVE5_USER') ?: 'root',
    'database.connections.mysql.password' => getenv('WAVE5_PASSWORD') ?: '',
    'auth_contract.active_user_statuses' => ['SYNTHETIC_READY'], 'auth_contract.active_grant_statuses' => ['SYNTHETIC_READY'],
]);
DB::purge('mysql');
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "refusing: connected database is not an isolated pool\n");
    exit(2);
}
if (!empty($job['hold_until_commit'])) {
    $app->instance('finance.before_commit', static function (): void {
        echo "HELD\n";
        fflush(STDOUT);
        fgets(STDIN);
    });
}
$factory = new FinanceServiceFactory(DB::connection());
DB::connection()->getPdo();
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);
if (!empty($job['announce'])) {
    echo 'RUNNING ' . (int) DB::selectOne('SELECT CONNECTION_ID() AS c')->c . "\n";
    fflush(STDOUT);
}

$user = (int) $job['user'];
$session = (int) $job['session'];
$key = (string) ($job['key'] ?? ('w-' . bin2hex(random_bytes(8))));
$body = $job['body'] ?? [];
$started = microtime(true);
try {
    $result = match ($job['op']) {
        'settle' => $factory->make(AccrualService::class)->settle((string) $job['kind'], $user, $session, $key, (string) $job['target'], $body),
        'recognize_payable' => $factory->make(AccrualService::class)->recognizePayable($user, $session, $key, $body),
        'recognize_receivable' => $factory->make(AccrualService::class)->recognizeReceivable($user, $session, $key, $body),
        'approve_budget' => $factory->make(BudgetService::class)->approve($user, $session, (string) $job['target'], $body),
        'match' => $factory->make(BankReconciliationService::class)->match($user, $session, (string) $job['target'], $body),
        'close_unit' => $factory->make(PeriodCloseService::class)->closeUnit($user, $session, (string) $job['period'], $body),
        'close_national' => $factory->make(PeriodCloseService::class)->closeNational($user, $session, (string) $job['period']),
    };
    echo json_encode(['status' => 'OK', 'result' => $result, 'pid' => getmypid(), 'ms' => (int) round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), "\n";
} catch (FinanceError $e) {
    echo json_encode(['status' => $e->reason, 'items' => $e->items, 'pid' => getmypid(), 'ms' => (int) round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'UNEXPECTED', 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 300)], JSON_THROW_ON_ERROR), "\n";
}
