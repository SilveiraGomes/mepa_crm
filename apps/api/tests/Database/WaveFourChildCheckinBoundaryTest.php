<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\Events\CheckinService;
use App\Domain\Events\EventError;
use App\Domain\WaveFour\DomainError;
// P0.3.4-M1: closes W4R-01 (HIGH). The generic CheckinService entry must never
// admit a protected participant; only ChildrenService::checkin (via
// ChildParticipationSafetyGate::admit) may create custody for a child_profiles person.
final class WaveFourChildCheckinBoundaryTest extends WaveFourCase
{
    public static function workers(): array
    {
        return [[2], [10], [30], [50]];
    }
    private function otherActor(array $f, bool $withChildren): array
    {
        $person = $this->row('people');
        $actor = $this->row('users', ['person_id' => $person]);
        $auth = $this->row('auth_sessions', ['user_id' => $actor]);
        $scope = $this->row('scopes', ['unit_id' => $f['unit'], 'include_descendants' => 0]);
        $role = $this->row('roles');
        $codes = ['CHECKIN' => 'EVENTS', 'CHECKIN_MANUAL' => 'EVENTS'];
        if ($withChildren) {
            $codes['CHILD_CHECKIN'] = 'CHILDREN';
        }
        foreach ($codes as $code => $type) {
            $permission = (int) $this->db()->table('permissions')->where('code', $code)->where('data_type', $type)->value('id');
            if (!$permission) {
                $permission = $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => $type]);
            }
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        $this->row('user_role_scopes', ['user_id' => $actor, 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $f['actor']]);
        return ['actor' => $actor, 'auth' => $auth];
    }
    private function attempt(array $f, int $actor, int $auth, string $key): array
    {
        return $this->children()->checkin($actor, $auth, $f['device'], $f['event'], $f['session'], $f['child'], $f['guardian'], $f['delivery'], $f['credential']['token'], $key, 'SYNTHETIC_IN_PERSON', true);
    }
    private function assertNoWrites(array $f): void
    {
        self::assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
        self::assertSame(0, $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
        self::assertSame(0, $this->db()->table('child_custody_visits')->where('session_id', $f['session'])->where('child_person_id', $f['child'])->count());
    }
    // Matrix A: CHILDREN permission + valid consent => PASS.
    public function test_children_permission_and_valid_consent_admits_child(): void
    {
        $f = $this->childFixture(false);
        $result = $this->attempt($f, $f['actor'], $f['auth'], 'matrix-a');
        self::assertSame('CHECKED_IN', $result['result']);
        self::assertSame(1, $this->db()->table('child_custody_visits')->where('id', $result['visit_id'])->count());
        self::assertSame(1, $this->db()->table('event_attendance')->where('id', $result['attendance_id'])->where('person_id', $f['child'])->count());
    }
    // Matrix B: no CHILDREN permission + valid consent => DENIED.
    public function test_missing_children_permission_denies_even_with_valid_consent(): void
    {
        $f = $this->childFixture(false);
        $stranger = $this->otherActor($f, false);
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $this->attempt($f, $stranger['actor'], $stranger['auth'], 'matrix-b'));
        $this->assertNoWrites($f);
    }
    // Matrix C: CHILDREN permission + revoked consent => DENIED.
    public function test_revoked_consent_denies_even_with_children_permission(): void
    {
        $f = $this->childFixture(false);
        $this->children()->revokeConsent($f['actor'], $f['auth'], $f['consent']);
        $this->denied('CONSENT_REQUIRED', fn() => $this->attempt($f, $f['actor'], $f['auth'], 'matrix-c'));
        $this->assertNoWrites($f);
    }
    // Matrix D: no CHILDREN permission + revoked consent => DENIED (permission checked first).
    public function test_missing_children_permission_and_revoked_consent_denies(): void
    {
        $f = $this->childFixture(false);
        $this->children()->revokeConsent($f['actor'], $f['auth'], $f['consent']);
        $stranger = $this->otherActor($f, false);
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $this->attempt($f, $stranger['actor'], $stranger['auth'], 'matrix-d'));
        $this->assertNoWrites($f);
    }
    // Matrix F: the generic flow keeps working for a non-child, unrelated to this fix.
    public function test_generic_flow_continues_for_non_child_participant(): void
    {
        $f = $this->fixture();
        $credential = $this->eventCredential($f);
        $result = $this->scan($f, $credential['token'], 'matrix-f');
        self::assertSame('CHECKED_IN', $result['result']);
    }
    // The generic door is closed to a protected participant even when the actor
    // legitimately holds both EVENTS and CHILDREN permissions and consent is valid:
    // only ChildrenService::checkin may admit a child.
    public function test_generic_service_direct_call_refuses_protected_participant_regardless_of_permission(): void
    {
        $f = $this->childFixture(false);
        try {
            (new CheckinService($this->db(), self::policy()))->scan($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['credential']['token'], 'bypass-full-permission', $this->now());
            self::fail('Expected the generic service to refuse a protected participant');
        } catch (EventError $e) {
            self::assertSame('CHILD_SAFETY_FLOW_REQUIRED', $e->reason);
        }
        $this->assertNoWrites($f);
    }
    // Preserves the exact W4R-01 reproduction: revoked consent + actor without CHILDREN,
    // calling CheckinService directly. Must now be DENIED with zero durable writes.
    public function test_generic_service_direct_call_reproduces_original_bypass_scenario_and_is_now_denied(): void
    {
        $f = $this->childFixture(false);
        $this->children()->revokeConsent($f['actor'], $f['auth'], $f['consent']);
        $stranger = $this->otherActor($f, false);
        try {
            (new CheckinService($this->db(), self::policy()))->scan($stranger['actor'], $stranger['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['credential']['token'], 'bypass-w4r-01', $this->now());
            self::fail('Expected denial reproducing W4R-01');
        } catch (EventError $e) {
            self::assertSame('CHILD_SAFETY_FLOW_REQUIRED', $e->reason);
        }
        $this->assertNoWrites($f);
    }
    /** @dataProvider workers */
    public function test_concurrent_child_checkins_for_same_session_yield_one_logical_admission(int $workers): void
    {
        $f = $this->childFixture(false);
        $jobs = array_fill(0, $workers, ['fixture' => $f]);
        $barrier = sys_get_temp_dir() . '/mepa_wave4_checkin_barrier_' . bin2hex(random_bytes(8));
        mkdir($barrier);
        $processes = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/wave4-checkin-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$root);
            self::assertIsResource($process);
            $job['barrier'] = $barrier;
            $job['worker'] = $i;
            fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes[1], $pipes[2]];
        }
        $deadline = microtime(true) + 25;
        while (count(glob($barrier . '/ready_*')) < count($jobs)) {
            if (microtime(true) > $deadline) {
                touch($barrier . '/release');
                self::fail('Worker readiness timeout');
            }
            usleep(20000);
        }
        touch($barrier . '/release');
        $results = [];
        foreach ($processes as [$process, $stdout, $stderr]) {
            $out = stream_get_contents($stdout);
            $err = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $exit = proc_close($process);
            self::assertSame('', trim($err), 'No raw SQL or PHP warnings');
            self::assertSame(0, $exit, 'Worker must converge: ' . $out);
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (glob($barrier . '/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
        $counts = array_count_values(array_column($results, 'result'));
        self::assertSame(1, $counts['CHECKED_IN'] ?? 0);
        self::assertSame($workers - 1, $counts['OPEN_VISIT_EXISTS'] ?? 0);
        $row = $this->db()->selectOne('SELECT COUNT(*) AS n FROM child_custody_visits WHERE session_id=? AND child_person_id=?', [$f['session'], $f['child']]);
        self::assertSame(1, (int) $row->n);
        $attendanceRow = $this->db()->selectOne('SELECT COUNT(*) AS n FROM event_attendance WHERE session_id=? AND person_id=?', [$f['session'], $f['child']]);
        self::assertSame(1, (int) $attendanceRow->n);
        $checkinRow = $this->db()->selectOne('SELECT COUNT(*) AS n FROM event_checkins WHERE session_id=? AND person_id=?', [$f['session'], $f['child']]);
        self::assertSame(1, (int) $checkinRow->n);
        $p = self::$root . '/docs/database/physical/wave4_m1_checkin_concurrency_evidence.json';
        $out = file_exists($p) ? json_decode(file_get_contents($p), true) : [];
        $out['workers_' . $workers] = ['workers' => $workers, 'checked_in' => 1, 'open_visit_exists' => $workers - 1, 'logical_visits_sql' => 1, 'attendance_rows_sql' => 1, 'checkin_rows_sql' => 1];
        file_put_contents($p, json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL);
    }
}
