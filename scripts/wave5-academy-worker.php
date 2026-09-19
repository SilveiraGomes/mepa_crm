<?php

declare(strict_types=1);

// P0.3.5-A2 concurrency worker. One PHP process = one independent database connection.
// Protocol (same as scripts/wave4-m12-worker.php, driven by Tests\Database\Support\WaveFourWorkerHarness):
//   read the JSON job from STDIN -> signal 'ready' -> wait for 'go' -> signal 'started' -> run the
//   operation -> print one JSON line -> signal 'done'. Barrier files are the only synchronization.
require dirname(__DIR__) . '/apps/api/vendor/autoload.php';
require dirname(__DIR__) . '/apps/api/tests/DatabaseV2/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademicAttendanceService;
use App\Domain\Academy\AcademyError;
use App\Domain\Academy\CertificateService;
use App\Domain\Academy\EnrollmentService;
use App\Domain\Academy\GradeService;
use App\Domain\Academy\InstructorAssignmentService;
use App\Domain\WaveFour\DomainClock;
use Tests\Database\Support\WaveFiveCase;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$path = static fn (string $state): string => $job['barrier'] . '/' . $job['run_id'] . '_' . $job['worker_id'] . '_' . $state;
$signal = static function (string $state) use ($path): void {
    if (file_put_contents($path($state), $state) === false) {
        throw new RuntimeException('Worker state write failed');
    }
};
$wait = static function (string $state) use ($path): void {
    $deadline = microtime(true) + 60;
    while (true) {
        clearstatcache(true, $path($state));
        if (file_exists($path($state))) {
            return;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker barrier timeout: ' . $state);
        }
        usleep(10000);
    }
};

try {
    $db = WaveFiveCase::connect()->getConnection();
    $db->statement('SET SESSION innodb_lock_wait_timeout=120');
    $rt = PooledWaveFiveCase::runtime($db);
    $a = $job['args'];
    $signal('ready');
    $wait('go');
    $started = DomainClock::now($db)->format('Y-m-d H:i:s.u');
    $signal('started');
    try {
        $data = match ($job['op']) {
            'enroll' => (new EnrollmentService($rt))->enroll($a['actor'], $a['session'], $a['class'], $a['person']),
            'assign' => (new InstructorAssignmentService($rt))->assign($a['actor'], $a['session'], $a['class'], $a['person']),
            'issue_certificate' => (new CertificateService($rt))->issue($a['actor'], $a['session'], $a['enrollment'], $a['file']),
            'record_grade' => (new GradeService($rt))->record($a['actor'], $a['session'], $a['attempt'], $a['score']),
            'revise_grade' => (new GradeService($rt))->revise($a['actor'], $a['session'], $a['attempt'], $a['expected_version'], $a['score'], $a['reason']),
            'record_attendance' => (new AcademicAttendanceService($rt))->record($a['actor'], $a['session'], $a['class_session'], $a['enrollment'], $a['status']),
        };
        // Never echo the certificate token back through the harness output.
        unset($data['token']);
        $result = ['result' => 'OK', 'data' => $data];
    } catch (AcademyError $error) {
        $result = ['result' => $error->reason];
    }
    echo json_encode($result + ['started' => $started, 'transaction_level' => $db->transactionLevel()], JSON_THROW_ON_ERROR), PHP_EOL;
    $signal('done');
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(3);
}
