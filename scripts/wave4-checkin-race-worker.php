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
    if (isset($job['wait_for_lock'])) {
        $wait($barrier . '/locked_' . $job['wait_for_lock']);
    }
    touch($barrier . '/attempting_' . $worker);
    if (!empty($job['hold'])) {
        $db->beginTransaction();
        $db->table('child_profiles')->where('person_id', $f['child'])->lockForUpdate()->first();
        touch($barrier . '/locked_' . $worker);
        $wait($barrier . '/finish');
    }
    try {
        if (($job['mode'] ?? 'checkin') === 'revoke_consent') {
            $s->revokeConsent($f['actor'], $f['auth'], $f['consent']);
            $result = ['result' => 'REVOKED'];
        } else {
            $result = $s->checkin($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['guardian'], $f['delivery'], $f['credential']['token'], 'race-checkin-' . $worker, 'SYNTHETIC_IN_PERSON', true);
        }
        if (!empty($job['hold'])) {
            $db->commit();
        }
    } catch (DomainError $e) {
        if (!empty($job['hold'])) {
            $db->rollBack();
        }
        $result = ['result' => $e->reason];
    } catch (EventError $e) {
        if (!empty($job['hold'])) {
            $db->rollBack();
        }
        $result = ['result' => $e->reason];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable) {
    echo '{"worker_error":"UNEXPECTED"}', PHP_EOL;
    exit(3);
}
