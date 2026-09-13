<?php

declare (strict_types=1);
// Isolated synthetic worker. Secret arrives via stdin and is never returned or logged.
require dirname(__DIR__) . '/apps/api/vendor/autoload.php';
require dirname(__DIR__) . '/apps/api/tests/Database/Support/WaveThreeCase.php';
use Tests\Database\Support\WaveThreeCase;
use App\Domain\Events\CheckinService;
use App\Domain\Events\EventError;
try {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = WaveThreeCase::connect()->getConnection();
    $policy = WaveThreeCase::policy();
    touch($job['barrier'] . '/ready_' . $job['worker']);
    $deadline = microtime(true) + 20;
    while (!file_exists($job['barrier'] . '/release')) {
        if (microtime(true) > $deadline) {
            throw new EventError('BARRIER_TIMEOUT');
        }
        usleep(10000);
    }
    $f = $job['fixture'];
    $result = (new CheckinService($db, $policy))->scan($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['person'], $job['token'], $job['key'], new DateTimeImmutable('2026-09-15 12:00:00.123456', new DateTimeZone('UTC')));
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (EventError $e) {
    echo json_encode(['domain_error' => $e->reason]), PHP_EOL;
    exit(2);
} catch (Throwable) {
    echo '{"worker_error":"UNEXPECTED"}', PHP_EOL;
    exit(3);
}
