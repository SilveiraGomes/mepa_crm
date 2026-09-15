<?php
declare(strict_types=1);
// Isolated synthetic worker. Credentials arrive via stdin and are never logged.
require dirname(__DIR__) . '/apps/api/vendor/autoload.php';
require dirname(__DIR__) . '/apps/api/tests/Database/Support/WaveThreeCase.php';

use App\Domain\Events\CheckinService;
use App\Domain\Events\EventError;
use Tests\Database\Support\WaveThreeCase;

$job = [];
$signal = static function (string $state) use (&$job): void {
    if (isset($job['run_id'], $job['worker_id'])) {
        $path = $job['barrier'] . '/' . $job['run_id'] . '_' . $job['worker_id'] . '_' . $state;
    } else {
        $path = $job['barrier'] . '/' . $state . '_' . $job['worker'];
    }
    if (file_put_contents($path, $state) === false) throw new RuntimeException('Worker signal failed');
};
try {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = WaveThreeCase::connect()->getConnection();
    $policy = WaveThreeCase::policy();
    $signal('ready');
    $go = isset($job['run_id']) ? $job['barrier'] . '/' . $job['run_id'] . '_' . $job['worker_id'] . '_go' : $job['barrier'] . '/release';
    $deadline = microtime(true) + 120;
    while (true) {
        clearstatcache(true, $go);
        if (file_exists($go)) break;
        if (microtime(true) >= $deadline) throw new EventError('BARRIER_TIMEOUT');
        usleep(10000);
    }
    if (($job['crash'] ?? false) === true) exit(7);
    $f = $job['fixture'];
    $result = (new CheckinService($db, $policy))->scan($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['person'], $job['token'], $job['key'], new DateTimeImmutable('2026-09-15 12:00:00.123456', new DateTimeZone('UTC')));
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
    $signal('done');
} catch (EventError $error) {
    echo json_encode(['domain_error' => $error->reason]), PHP_EOL;
    if (isset($job['barrier'])) $signal('done');
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(3);
}

