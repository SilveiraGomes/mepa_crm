<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';
require_once __DIR__ . '/../Database/Support/WaveFourWorkerHarness.php';

use App\Domain\Academy\AcademyReason;
use App\Domain\WaveFour\DomainClock;
use Tests\Database\Support\WaveFourWorkerHarness;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * P0.3.5-A2 concurrency matrix (mission sections 16, 32, 35, 42, 48, 75): every worker is an
 * independent PHP process with its own MySQL connection, released together by a barrier file.
 * The late-revocation scenarios hold the operation open on a REAL row-lock wait (verified in
 * performance_schema) instead of sleeping, then change the authority underneath it.
 *
 *   E1 duplicate enrollment race            E2 stale grade update (race + sequential)
 *   E3 duplicate certificate issuance race  E4 scope / assignment revoked before commit (A7, A8)
 *   A5 duplicate instructor assignment      A4 minor cover lapses before commit
 */
final class AcademyConcurrencyTest extends PooledWaveFiveCase
{
    private const SCRIPT = 'wave5-academy-worker.php';

    private function launch(array $jobs, int $deadline = 70): WaveFourWorkerHarness
    {
        $harness = new WaveFourWorkerHarness(self::$root, self::SCRIPT, $deadline);
        try {
            foreach ($jobs as $i => $job) {
                $harness->start($i, $job);
            }
            foreach ($jobs as $i => $_) {
                $harness->waitForState($i, 'ready', 90);
            }
        } catch (\Throwable $error) {
            $harness->close();
            throw $error;
        }
        return $harness;
    }

    private function collect(WaveFourWorkerHarness $harness, int $workers, int $seconds = 70): array
    {
        $results = [];
        try {
            for ($i = 0; $i < $workers; $i++) {
                $worker = $harness->collect($i, $seconds);
                self::assertSame('', trim($worker['stderr']), 'No raw SQL or PHP warnings reach the caller: ' . $worker['stderr']);
                self::assertSame(0, $worker['exit'], 'Worker must converge: ' . $worker['stdout']);
                self::assertTrue($worker['done'], 'An exit code alone is not convergence');
                $result = json_decode(trim($worker['stdout']), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(0, $result['transaction_level'], 'No transaction is left open');
                $results[] = $result;
            }
            return $results;
        } finally {
            $harness->close();
        }
    }

    // All workers ready -> released together -> results.
    private function race(array $jobs): array
    {
        $harness = $this->launch($jobs);
        foreach ($jobs as $i => $_) {
            $harness->signal($i, 'go');
        }
        return $this->collect($harness, count($jobs));
    }

    private function job(string $op, array $args): array
    {
        return ['op' => $op, 'args' => $args];
    }

    private function outcomes(array $results): array
    {
        $counts = [];
        foreach ($results as $r) {
            $key = $r['result'] === 'OK' ? ($r['data']['outcome'] ?? 'OK') : $r['result'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    public static function workerCounts(): array
    {
        return [[2], [8]];
    }

    // ---- E1 / C4 ----------------------------------------------------------------------------

    /** @dataProvider workerCounts */
    public function test_c4_e1_duplicate_enrollment_race_yields_one_row_and_deterministic_losers(int $workers): void
    {
        $w = $this->world();
        $actor = $this->actor($w['unit'], ['ACADEMY_ENROLL']);
        $person = $this->row('people');
        $jobs = array_fill(0, $workers, $this->job('enroll', ['actor' => $actor['user'], 'session' => $actor['session'], 'class' => $w['class'], 'person' => $person]));
        $results = $this->race($jobs);
        self::assertSame(['ALREADY_ENROLLED' => $workers - 1, 'CREATED' => 1], $this->outcomes($results));
        self::assertSame(1, (int) $this->db()->table('enrollments')->where('person_id', $person)->where('class_id', $w['class'])->count(), 'One row');
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('action', 'enrollment.created')->where('unit_id', $w['unit'])->count(), 'Exactly one success audit: losers leave none');
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $person)->count());
    }

    public function test_e1_racing_enrollments_of_different_people_all_succeed(): void
    {
        $w = $this->world();
        $actor = $this->actor($w['unit'], ['ACADEMY_ENROLL']);
        $jobs = [];
        for ($i = 0; $i < 4; $i++) {
            $jobs[] = $this->job('enroll', ['actor' => $actor['user'], 'session' => $actor['session'], 'class' => $w['class'], 'person' => $this->row('people')]);
        }
        self::assertSame(['CREATED' => 4], $this->outcomes($this->race($jobs)));
        self::assertSame(4, (int) $this->db()->table('enrollments')->where('class_id', $w['class'])->count());
    }

    // ---- A5 ---------------------------------------------------------------------------------

    public function test_a5_duplicate_instructor_assignment_race_converges_to_one_assignment(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $person = $this->row('people');
        $jobs = array_fill(0, 4, $this->job('assign', ['actor' => $manager['user'], 'session' => $manager['session'], 'class' => $w['class'], 'person' => $person]));
        self::assertSame(['ALREADY_ASSIGNED' => 3, 'ASSIGNED' => 1], $this->outcomes($this->race($jobs)));
        self::assertSame(1, (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->count());
        self::assertSame(1, (int) $this->db()->table('instructors')->where('person_id', $person)->count());
    }

    public function test_the_same_person_assigned_to_two_classes_at_once_creates_one_instructor_profile(): void
    {
        $a = $this->world();
        $b = $this->row('classes', ['academic_unit_id' => $a['academicUnit'], 'course_version_id' => $a['version'], 'status' => 'S_CLS_OPEN']);
        $manager = $this->actor($a['unit'], ['ACADEMY_MANAGE']);
        $person = $this->row('people');
        $results = $this->race([
            $this->job('assign', ['actor' => $manager['user'], 'session' => $manager['session'], 'class' => $a['class'], 'person' => $person]),
            $this->job('assign', ['actor' => $manager['user'], 'session' => $manager['session'], 'class' => $b, 'person' => $person]),
        ]);
        self::assertSame(['ASSIGNED' => 2], $this->outcomes($results));
        self::assertSame(1, (int) $this->db()->table('instructors')->where('person_id', $person)->count(), 'UNIQUE(person_id) never surfaces as an error');
    }

    // ---- E3 / A6 ----------------------------------------------------------------------------

    public function test_e3_a6_duplicate_certificate_issuance_race_yields_one_active_certificate(): void
    {
        $w = $this->world();
        $certifier = $this->actor($w['unit'], ['ACADEMY_CERTIFY']);
        $enrollment = $this->enrollment($w['class'], null, 'S_ENR_COMPLETED');
        $this->db()->table('course_versions')->where('id', $w['version'])->update(['completion_policy_metadata' => json_encode(['criteria' => [['type' => 'ADMINISTRATIVE_APPROVAL']]])]);
        $file = $this->file($w['unit']);
        $jobs = array_fill(0, 6, $this->job('issue_certificate', ['actor' => $certifier['user'], 'session' => $certifier['session'], 'enrollment' => $enrollment['id'], 'file' => $file]));
        $results = $this->race($jobs);
        self::assertSame(['CERTIFICATE_ALREADY_EXISTS' => 5, 'OK' => 1], $this->outcomes($results));
        self::assertSame(1, (int) $this->db()->table('certificates')->where('enrollment_id', $enrollment['id'])->count());
        self::assertSame(1, (int) $this->db()->table('certificates')->where('enrollment_id', $enrollment['id'])->whereNull('revoked_at')->count(), 'Never two active equivalent certificates');
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'certificates')->where('action', 'certificate.issued')->where('unit_id', $w['unit'])->count());
        foreach ($results as $r) {
            self::assertArrayNotHasKey('token', $r['data'] ?? [], 'The harness output never carries a certificate token');
        }
    }

    // ---- E2 / C5 ----------------------------------------------------------------------------

    public function test_e2_concurrent_first_grade_record_stores_one_version(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $jobs = [];
        foreach (['50', '60', '70', '80'] as $score) {
            $jobs[] = $this->job('record_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'score' => $score]);
        }
        self::assertSame(['GRADE_ALREADY_RECORDED' => 3, 'OK' => 1], $this->outcomes($this->race($jobs)));
        self::assertSame(1, (int) $this->db()->table('grades')->where('attempt_id', $w['attempt'])->count());
    }

    /** @dataProvider workerCounts */
    public function test_e2_concurrent_revisions_from_the_same_version_accept_exactly_one(int $workers): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $this->row('grades', ['attempt_id' => $w['attempt'], 'version' => 1, 'score' => '40', 'status' => 'S_GRD_DRAFT', 'graded_by' => $t['user']]);
        $jobs = [];
        for ($i = 0; $i < $workers; $i++) {
            $jobs[] = $this->job('revise_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'expected_version' => 1, 'score' => (string) (50 + $i), 'reason' => 'revisão ' . $i]);
        }
        self::assertSame(['OK' => 1, 'STALE_WRITE' => $workers - 1], $this->outcomes($this->race($jobs)));
        $rows = $this->db()->table('grades')->where('attempt_id', $w['attempt'])->orderBy('version')->get();
        self::assertSame([1, 2], $rows->pluck('version')->map(fn ($v) => (int) $v)->all(), 'One new version; no silent overwrite, no gap');
        self::assertSame('40.0000', (string) $rows[0]->score);
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('action', 'grade.revised')->where('entity_id', $rows[1]->id)->count());
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('action', 'grade.revised')->where('unit_id', $w['unit'])->count(), 'Only the accepted revision is audited');
    }

    public function test_e2_read_then_stale_save_across_two_processes(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $b = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($b, $w['class']);
        $this->row('grades', ['attempt_id' => $w['attempt'], 'version' => 1, 'score' => '40', 'status' => 'S_GRD_DRAFT', 'graded_by' => $t['user']]);
        // Actor A "reads" version 1. Actor B (another process) revises. Actor A (another process) saves against version 1.
        $first = $this->race([$this->job('revise_grade', ['actor' => $b['user'], 'session' => $b['session'], 'attempt' => $w['attempt'], 'expected_version' => 1, 'score' => '75', 'reason' => 'B'])]);
        self::assertSame(['OK' => 1], $this->outcomes($first));
        $second = $this->race([$this->job('revise_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'expected_version' => 1, 'score' => '10', 'reason' => 'A stale'])]);
        self::assertSame(['STALE_WRITE' => 1], $this->outcomes($second));
        self::assertSame(['40.0000', '75.0000'], $this->db()->table('grades')->where('attempt_id', $w['attempt'])->orderBy('version')->pluck('score')->map(fn ($s) => (string) $s)->all());
    }

    // ---- attendance idempotency under a race ------------------------------------------------

    public function test_concurrent_attendance_records_are_naturally_idempotent(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ATTENDANCE']);
        $this->assign($teacher, $w['class']);
        $session = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED']);
        $student = $this->enrollment($w['class']);
        $jobs = array_fill(0, 4, $this->job('record_attendance', ['actor' => $teacher['user'], 'session' => $teacher['session'], 'class_session' => $session, 'enrollment' => $student['id'], 'status' => 'S_ATD_PRESENT']));
        self::assertSame(['CREATED' => 1, 'UNCHANGED' => 3], $this->outcomes($this->race($jobs)));
        self::assertSame(1, (int) $this->db()->table('academic_attendance')->where('class_session_id', $session)->count());
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'academic_attendance')->where('action', 'attendance.recorded')->where('unit_id', $w['unit'])->count());
    }

    // ---- E4 / A7 / A8: authority revoked while the operation is in flight -------------------

    // Holds the worker on a REAL lock wait (its audit insert needs a shared lock on the unit row this
    // test connection holds exclusively), so the authority can be changed after the worker's
    // provisional authorization and before its final one.
    private function waitForSqlWait(string $needle, WaveFourWorkerHarness $harness, int $seconds = 45): void
    {
        $deadline = microtime(true) + $seconds;
        $schema = $this->db()->getDatabaseName();
        while (microtime(true) < $deadline) {
            $rows = $this->db()->select('SELECT p.info AS query FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.thread_id = w.requesting_thread_id JOIN information_schema.processlist p ON p.id = t.processlist_id WHERE p.db = ?', [$schema]);
            foreach ($rows as $row) {
                if (str_contains(strtolower((string) $row->query), $needle)) {
                    return;
                }
            }
            clearstatcache(true, $harness->stateFile(0, 'done'));
            if (file_exists($harness->stateFile(0, 'done'))) {
                self::fail('Worker finished before reaching the target SQL wait: ' . json_encode($harness->collect(0)));
            }
            usleep(10000);
        }
        self::fail('Expected a real SQL lock wait on ' . $needle);
    }

    private function lateChange(array $w, array $job, callable $change, ?callable $beforeRelease = null): array
    {
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('organizational_units')->where('id', $w['unit'])->lockForUpdate()->first();
        $harness = $this->launch([$job]);
        try {
            $harness->signal(0, 'go');
            $harness->waitForState(0, 'started', 25);
            $this->waitForSqlWait('audit_logs', $harness);
            $change();
            if ($beforeRelease) {
                $beforeRelease();
            }
            $lock->rollBack();
        } catch (\Throwable $error) {
            if ($lock->transactionLevel()) {
                $lock->rollBack();
            }
            $harness->close();
            throw $error;
        }
        return $this->collect($harness, 1)[0];
    }

    private function nothingCommitted(array $w): void
    {
        self::assertSame(0, (int) $this->db()->table('grades')->where('attempt_id', $w['attempt'])->count(), 'The business write rolled back');
        self::assertSame(0, (int) $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('unit_id', $w['unit'])->count(), 'No success audit survives');
    }

    public function test_e4_control_without_revocation_the_held_operation_commits(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $result = $this->lateChange($w, $this->job('record_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'score' => '77']), fn () => null);
        self::assertSame('OK', $result['result'], 'The barrier machinery itself never causes a denial');
        self::assertSame(1, (int) $this->db()->table('grades')->where('attempt_id', $w['attempt'])->count());
    }

    // A8
    public function test_a8_instructor_assignment_revoked_before_commit_blocks_the_operation(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $assignment = (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->value('id');
        $result = $this->lateChange(
            $w,
            $this->job('record_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'score' => '77']),
            fn () => $this->db()->statement("UPDATE class_instructors SET status = 'S_CI_ENDED', ends_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?", [$assignment])
        );
        self::assertSame(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, $result['result']);
        $this->nothingCommitted($w);
    }

    // A7
    public function test_a7_institutional_scope_revoked_before_commit_blocks_the_operation(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $result = $this->lateChange(
            $w,
            $this->job('record_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'score' => '77']),
            fn () => $this->db()->statement("UPDATE user_role_scopes SET status = 'S_REVOKED', lock_version = lock_version + 1 WHERE id = ?", [$t['link']])
        );
        self::assertSame(AcademyReason::NOT_AUTHORIZED, $result['result']);
        $this->nothingCommitted($w);
    }

    public function test_e4_revoked_auth_session_before_commit_blocks_the_operation(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $result = $this->lateChange(
            $w,
            $this->job('record_grade', ['actor' => $t['user'], 'session' => $t['session'], 'attempt' => $w['attempt'], 'score' => '77']),
            fn () => $this->db()->statement('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?', [$t['session']])
        );
        self::assertSame(AcademyReason::NOT_AUTHORIZED, $result['result']);
        $this->nothingCommitted($w);
    }

    // A4 (last bullet): the minor's cover lapses between the provisional stage and the commit.
    public function test_a4_minor_guardian_authorization_that_lapses_before_commit_denies_the_attendance(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ATTENDANCE']);
        $this->assign($teacher, $w['class']);
        $session = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED']);
        $c = $this->child($w['unit']);
        $enrollment = $this->enrollment($w['class'], $c['child']);
        $this->db()->statement('UPDATE guardian_authorizations SET ends_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 12 SECOND) WHERE id = ?', [$c['authorization']]);
        $expiry = (string) $this->db()->table('guardian_authorizations')->where('id', $c['authorization'])->value('ends_at');
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('organizational_units')->where('id', $w['unit'])->lockForUpdate()->first();
        $harness = $this->launch([$this->job('record_attendance', ['actor' => $teacher['user'], 'session' => $teacher['session'], 'class_session' => $session, 'enrollment' => $enrollment['id'], 'status' => 'S_ATD_PRESENT'])]);
        try {
            $harness->signal(0, 'go');
            $harness->waitForState(0, 'started', 25);
            $this->waitForSqlWait('audit_logs', $harness);
            $deadline = microtime(true) + 60;
            while (DomainClock::now($this->db())->format('Y-m-d H:i:s.u') <= $expiry) {
                self::assertLessThan($deadline, microtime(true), 'The database clock did not pass the authorization end');
                usleep(20000);
            }
            $lock->rollBack();
        } catch (\Throwable $error) {
            if ($lock->transactionLevel()) {
                $lock->rollBack();
            }
            $harness->close();
            throw $error;
        }
        $result = $this->collect($harness, 1)[0];
        self::assertLessThan($expiry, $result['started'], 'The worker began while the cover was valid');
        self::assertSame(AcademyReason::GUARDIAN_AUTHORIZATION_INVALID, $result['result']);
        self::assertSame(0, (int) $this->db()->table('academic_attendance')->where('class_session_id', $session)->count());
        self::assertSame(0, (int) $this->db()->table('audit_logs')->where('entity_type', 'academic_attendance')->where('unit_id', $w['unit'])->count());
    }
}
