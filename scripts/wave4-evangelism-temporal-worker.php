<?php

declare (strict_types=1);
require dirname(__DIR__) . '/apps/api/vendor/autoload.php';
require dirname(__DIR__) . '/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\EvangelismService;
use App\Domain\WaveFour\DomainError;
try {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = WaveFourCase::connect()->getConnection();
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
    if (isset($job['wait_for_lock'])) {
        $wait($barrier . '/locked_' . $job['wait_for_lock']);
    }
    touch($barrier . '/attempting_' . $worker);
    if (!empty($job['hold'])) {
        $db->beginTransaction();
        $db->table($job['lock_table'])->where('id', $job['lock_id'])->lockForUpdate()->first();
        touch($barrier . '/locked_' . $worker);
        $wait($barrier . '/finish');
        $db->rollBack();
        echo json_encode(['result' => 'LOCK_RELEASED'], JSON_THROW_ON_ERROR), PHP_EOL;
    } else {
        $s = new EvangelismService($db, WaveFourCase::domainPolicy(), WaveFourCase::policy());
        try {
            $id = match ($job['method']) {
                'contact' => $s->contact($f['actor'], $f['auth'], $f['unit'], $f['campaign'], $f['target']),
                'step' => $s->step($f['actor'], $f['auth'], $f['unit'], $f['track'], $f['sequence'], 'SYNTHETIC_STEP_RACE'),
            };
            $result = ['result' => 'OK', 'id' => $id];
        } catch (DomainError $e) {
            $result = ['result' => $e->reason];
        }
        echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
    }
} catch (Throwable) {
    echo '{"worker_error":"UNEXPECTED"}', PHP_EOL;
    exit(3);
}
