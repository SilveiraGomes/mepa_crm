<?php

declare(strict_types=1);

// Standalone worker process for real concurrency testing of TransferService::request().
// Spawned via proc_open by WaveTwoTransferConcurrencyTest - never invoked directly in normal
// test runs. Synchronizes with siblings via a file barrier: touches READY_FILE, busy-waits for
// GO_FILE, then calls request() and writes the outcome to RESULT_FILE. Exit code is always 0;
// success/failure is reported in the result file.

[, $membershipId, $originUnitId, $destinationUnitId, $workflowInstanceId, $readyFile, $goFile, $resultFile] = $argv;

$root = dirname(__DIR__);
require $root.'/apps/api/vendor/autoload.php';

$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => getenv('W2_HOST') ?: '127.0.0.1',
    'port' => getenv('W2_PORT') ?: 3306,
    'database' => getenv('W2_DB') ?: '',
    'username' => getenv('W2_USER') ?: 'root',
    'password' => getenv('W2_PASS') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'timezone' => '+00:00',
    'options' => [PDO::ATTR_EMULATE_PREPARES => false],
]);
$connection = $capsule->getConnection();

touch($readyFile);
$deadline = microtime(true) + 15.0;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => 'BARRIER_TIMEOUT_WAITING_FOR_GO_FILE']));
        exit(0);
    }
    usleep(2000);
}

$service = new App\Domain\Membership\TransferService($connection);
try {
    $result = $service->request(
        (int) $membershipId,
        (int) $originUnitId,
        (int) $destinationUnitId,
        (int) $workflowInstanceId,
        new DateTimeImmutable('2026-09-14')
    );
    file_put_contents($resultFile, json_encode(['ok' => true, 'result' => $result]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]));
}
