<?php
declare(strict_types=1);
require dirname(__DIR__) . '/apps/api/vendor/autoload.php';
require dirname(__DIR__) . '/apps/api/tests/Database/Support/WaveFourCase.php';

use App\Domain\WaveFour\DomainClock;
use App\Domain\WaveFour\DomainError;
use App\Domain\WaveFour\EvangelismService;
use Tests\Database\Support\WaveFourCase;

$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$path = static function (string $state) use ($job): string {
    return $job['barrier'] . '/' . $job['run_id'] . '_' . $job['worker_id'] . '_' . $state;
};
$signal = static function (string $state) use ($path): void {
    if (file_put_contents($path($state), $state) === false) {
        throw new RuntimeException('Worker state write failed');
    }
};
$wait = static function (string $state) use ($path): void {
    $deadline = microtime(true) + 30;
    while (true) {
        clearstatcache(true, $path($state));
        if (file_exists($path($state))) return;
        if (microtime(true) >= $deadline) throw new RuntimeException('Worker barrier timeout: ' . $path($state));
        usleep(10000);
    }
};
try {
    $db = WaveFourCase::connect()->getConnection();
    $db->statement('SET SESSION innodb_lock_wait_timeout=120');
    $f = $job['fixture'];
    $signal('ready');
    $wait('go');
    if ($job['method'] === 'crash') {
        exit(7);
    }
    $started = DomainClock::now($db)->format('Y-m-d H:i:s.u');
    $signal('started');
    $service = new EvangelismService($db, WaveFourCase::domainPolicy(), WaveFourCase::policy());
    try {
        $id = match ($job['method']) {
            'campaign' => $service->campaign($f['actor'], $f['auth'], $f['unit'], $f['name'], DomainClock::now($db)),
            'track' => $service->track($f['actor'], $f['auth'], $f['unit'], $f['code'], 1, 'SYNTHETIC_M12'),
            'step' => $service->step($f['actor'], $f['auth'], $f['unit'], $f['track'], 1, 'SYNTHETIC_M12'),
            'progress' => $service->progress($f['actor'], $f['auth'], $f['unit'], $f['enrollment'], $f['step']),
        };
        $result = ['result' => 'PASS', 'id' => $id];
    } catch (DomainError $error) {
        $result = ['result' => $error->reason];
    }
    echo json_encode($result + ['started' => $started, 'transaction_level' => $db->transactionLevel()], JSON_THROW_ON_ERROR), PHP_EOL;
    $signal('done');
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(3);
}

