<?php

declare(strict_types=1);

// P0.10-F2A concurrency worker (HC1 / HC2). One process = one MySQL connection. Boots Laravel against the isolated Wave 5
// pool named by WAVE5_DSN (refuses anything but mepa_wave5_test_* with WAVE5_ALLOW_SYNTHETIC=1) and uses the SAME
// PayrollServiceFactory as the HTTP layer; no product code is changed.
// Protocol: prints READY, waits for "go". With "hold_until_commit" it prints HELD from the business transaction's
// before-commit hook (all locks taken, writes done, not committed) and waits for "commit".
//   op=compensation_change  CompensationService::change(employment, body)
//   op=rule_approve         PayrollRuleService::approve(code, version, body)
// Prints one JSON line {"status": "OK" | <FinanceError reason>, ...}.

use App\Domain\Finance\FinanceError;
use App\Domain\Payroll\CompensationService;
use App\Domain\Payroll\PayrollRuleService;
use App\Http\Payroll\PayrollServiceFactory;
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
    $app->instance('payroll.before_commit', static function (): void {
        echo "HELD\n";
        fflush(STDOUT);
        fgets(STDIN);
    });
}
$factory = new PayrollServiceFactory(DB::connection());
DB::connection()->getPdo();
echo 'READY ' . (int) DB::selectOne('SELECT CONNECTION_ID() AS c')->c . "\n";
fflush(STDOUT);
fgets(STDIN);

$user = (int) $job['user'];
$session = (int) $job['session'];
try {
    $result = match ($job['op']) {
        'compensation_change' => $factory->make(CompensationService::class)->change($user, $session, (string) $job['employment'], $job['body'] ?? []),
        'rule_approve' => $factory->make(PayrollRuleService::class)->approve($user, $session, (string) $job['code'], (int) $job['version'], $job['body'] ?? []),
    };
    echo json_encode(['status' => 'OK', 'result' => $result, 'pid' => getmypid()], JSON_THROW_ON_ERROR), "\n";
} catch (FinanceError $e) {
    echo json_encode(['status' => $e->reason, 'items' => $e->items, 'pid' => getmypid()], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'UNEXPECTED', 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 300)], JSON_THROW_ON_ERROR), "\n";
}
