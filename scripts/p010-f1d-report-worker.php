<?php

declare(strict_types=1);

// P0.10-F1D-E1 C8 worker (ADR 0021 D30 C8: consolidated report during concurrent postings). One process = one MySQL
// connection. Boots Laravel against the isolated Wave 5 pool named by WAVE5_DSN (refuses anything but mepa_wave5_test_*
// with WAVE5_ALLOW_SYNTHETIC=1) and uses the SAME FinanceServiceFactory as the HTTP layer; no product code is changed.
// Protocol: prints READY, waits for "go".
//   op=report    runs FinanceReportingService::report. With "pause_after_ledger_read" a TEST-ONLY DB::listen barrier
//                prints PAUSED right after the report's FIRST journal_lines query has executed (the snapshot is already
//                established and part of the dataset read) and waits for "resume" on stdin before the report reads
//                the remaining units / lines.
//   op=contribute records a MONETARY contribution (revenue + cash in one posting). With "hold_until_commit" it prints
//                HELD from the business transaction's before-commit hook and waits for "commit".
// Prints one JSON line {"status": "OK" | <FinanceError reason>, "result": ...}.

use App\Domain\Finance\ContributionService;
use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceReportingService;
use App\Http\Finance\FinanceServiceFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
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
$paused = false;
if (!empty($job['pause_after_ledger_read'])) {
    DB::listen(static function (QueryExecuted $query) use (&$paused): void {
        if (!$paused && str_contains($query->sql, 'journal_lines')) {
            $paused = true;
            echo 'PAUSED ' . (int) DB::connection()->transactionLevel() . "\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
    });
}
$factory = new FinanceServiceFactory(DB::connection());
DB::connection()->getPdo();
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);

$user = (int) $job['user'];
$session = (int) $job['session'];
$started = microtime(true);
try {
    $result = match ($job['op']) {
        'report' => $factory->make(FinanceReportingService::class)->report($user, $session, (string) $job['type'], $job['body'] ?? []),
        'contribute' => $factory->make(ContributionService::class)->record($user, $session, (string) ($job['key'] ?? ('c8-' . bin2hex(random_bytes(8)))), $job['body'] ?? []),
    };
    echo json_encode(['status' => 'OK', 'result' => $result, 'paused' => $paused, 'pid' => getmypid(), 'ms' => (int) round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), "\n";
} catch (FinanceError $e) {
    echo json_encode(['status' => $e->reason, 'items' => $e->items, 'pid' => getmypid()], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'UNEXPECTED', 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 300)], JSON_THROW_ON_ERROR), "\n";
}
