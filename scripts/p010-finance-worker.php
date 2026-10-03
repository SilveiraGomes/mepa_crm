<?php

declare(strict_types=1);

// P0.10-F1A concurrency worker (ADR 0021 D30 C1/C3). One process = one MySQL connection to the isolated Wave 5 pool
// named by WAVE5_DSN (refuses anything but mepa_wave5_test_* with WAVE5_ALLOW_SYNTHETIC=1). Prints "READY" once
// connected, waits for "go" on stdin (ready/go barrier), runs ONE operation and prints one JSON line
// {"status": "OK" | <FinanceError reason>, ...}. `hold_ms` keeps the operation's transaction (and its locks) open.

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinancePeriods;
use App\Domain\Finance\LedgerPostingService;
use Illuminate\Database\Capsule\Manager as Capsule;

$root = dirname(__DIR__);
require $root . '/apps/api/vendor/autoload.php';
$job = json_decode(base64_decode((string) ($argv[1] ?? ''), true) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
$dsn = (string) getenv('WAVE5_DSN');
parse_str(str_replace(';', '&', preg_replace('/^mysql:/', '', $dsn)), $parts);
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', (string) ($parts['dbname'] ?? '')) || getenv('WAVE5_ALLOW_SYNTHETIC') !== '1') {
    fwrite(STDERR, "refusing: WAVE5_DSN must be an isolated mepa_wave5_test_* pool and WAVE5_ALLOW_SYNTHETIC=1\n");
    exit(2);
}
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'mysql', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => (int) ($parts['port'] ?? 3306), 'database' => $parts['dbname'],
    'username' => getenv('WAVE5_USER') ?: 'root', 'password' => getenv('WAVE5_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '', 'strict' => true, 'timezone' => '+00:00', 'options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
$db = $capsule->getConnection();
$db->getPdo();
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);

$hold = (int) ($job['hold_ms'] ?? 0);
$started = microtime(true);
// Deterministic protocol (C3): "announce" prints RUNNING <MySQL connection id> before the operation starts, so the parent
// can observe this connection in LOCK WAIT (information_schema.INNODB_TRX); "hold_until_commit" prints HELD once the
// operation has run INSIDE the still-open transaction (its locks acquired) and waits for "commit" on stdin.
if (!empty($job['announce'])) {
    echo 'RUNNING ' . (int) $db->selectOne('SELECT CONNECTION_ID() AS c')->c . "\n";
    fflush(STDOUT);
}
try {
    $result = $db->transaction(function () use ($db, $job, $hold) {
        $ledger = new LedgerPostingService($db);
        $out = match ($job['op']) {
            'post' => $ledger->post((int) $job['user'], (string) $job['entry'], (int) $job['lock_version']),
            'subledger' => $ledger->postSubledgerEntry((int) $job['user'], (string) $job['key'], $job['input']),
            'close_unit' => (new FinancePeriods($db))->closeUnit((int) $job['user'], (string) $job['period'], (int) $job['unit']),
        };
        if (!empty($job['hold_until_commit'])) {
            echo "HELD\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
        if ($hold > 0) {
            usleep($hold * 1000);
        }
        return $out;
    });
    echo json_encode(['status' => 'OK', 'result' => $result, 'pid' => getmypid(), 'ms' => (int) round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), "\n";
} catch (FinanceError $e) {
    echo json_encode(['status' => $e->reason, 'items' => $e->items, 'pid' => getmypid(), 'ms' => (int) round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'UNEXPECTED', 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 300)], JSON_THROW_ON_ERROR), "\n";
}
