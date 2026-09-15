<?php

declare (strict_types=1);
require dirname(__DIR__) . '/apps/api/vendor/autoload.php';
require dirname(__DIR__) . '/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\ChildrenService;
use App\Domain\WaveFour\DomainError;
use App\Domain\Events\EventError;
try {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = WaveFourCase::connect()->getConnection();
    $s = new ChildrenService($db, WaveFourCase::domainPolicy(), WaveFourCase::policy());
    $f = $job['fixture'];
    $barrier = $job['barrier'];
    $worker = $job['worker'];
    touch($barrier . '/ready_' . $worker);
    $wait = function (string $path) {
        $deadline = microtime(true) + 40;
        while (!file_exists($path)) {
            if (microtime(true) > $deadline) {
                throw new DomainError('BARRIER_TIMEOUT');
            }
            usleep(10000);
        }
    };
    $wait($barrier . '/release');
    try {
        $result = $s->checkin($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['guardian'], $f['delivery'], $f['credential']['token'], 'concurrent-child-in-' . $worker, 'SYNTHETIC_IN_PERSON', true);
    } catch (DomainError $e) {
        $result = ['result' => $e->reason];
    } catch (EventError $e) {
        $result = ['result' => $e->reason];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable) {
    echo '{"worker_error":"UNEXPECTED"}', PHP_EOL;
    exit(3);
}
