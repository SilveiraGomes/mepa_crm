<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveThreeCase.php';
use Tests\Database\Support\WaveThreeCase;
final class WaveThreeCheckinConcurrencyTest extends WaveThreeCase
{
    private array $evidence = [];
    public static function workers(): array
    {
        return [[2], [10], [30], [50]];
    }
    private function launch(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/mepa_wave3_barrier_' . bin2hex(random_bytes(8));
        mkdir($barrier);
        $processes = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/wave3-checkin-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$root);
            $this->assertIsResource($process);
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
                $this->fail('Worker readiness timeout');
            }
            usleep(20000);
        }
        touch($barrier . '/release');
        return [$processes, $barrier];
    }
    private function collect(array $processes, string $barrier): array
    {
        $results = [];
        foreach ($processes as [$process, $stdout, $stderr]) {
            $out = stream_get_contents($stdout);
            $err = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $exit = proc_close($process);
            $this->assertSame('', trim($err), 'No raw SQL or PHP warnings');
            $this->assertSame(0, $exit, 'Worker must converge: ' . $out);
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (glob($barrier . '/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
        return $results;
    }
    /** @dataProvider workers */
    public function test_same_person_session_token_converges(int $workers): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $jobs = [];
        for ($i = 0; $i < $workers; $i++) {
            $jobs[] = ['fixture' => $f, 'token' => $c['token'], 'key' => 'parallel_' . $i];
        }
        [$processes, $barrier] = $this->launch($jobs);
        $results = $this->collect($processes, $barrier);
        $counts = array_count_values(array_column($results, 'result'));
        $this->assertSame(1, $counts['CHECKED_IN'] ?? 0);
        $this->assertSame($workers - 1, $counts['ALREADY_CHECKED_IN'] ?? 0);
        $this->assertCount(1, array_unique(array_column($results, 'checkin_id')));
        $this->assertCount(1, array_unique(array_column($results, 'attendance_id')));
        $this->assertSame(1, $this->db()->table('event_checkins')->where('session_id', $f['session'])->count());
        $this->assertSame(1, $this->db()->table('event_attendance')->where('session_id', $f['session'])->count());
        $this->evidence[] = ['workers' => $workers, 'checked_in' => 1, 'already_checked_in' => $workers - 1, 'raw_sql_errors' => 0, 'logical_checkins' => 1, 'attendance' => 1];
        $path = self::$root . '/docs/database/physical/wave3_concurrency_evidence.json';
        $old = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        $old['same_person_' . $workers] = $this->evidence[array_key_last($this->evidence)];
        file_put_contents($path, json_encode($old, JSON_PRETTY_PRINT) . PHP_EOL);
    }
    public function test_different_people_and_sessions_progress_while_another_credential_is_locked(): void
    {
        $blocked = $this->fixture();
        $blockedToken = $this->eventCredential($blocked);
        $free = $blocked;
        $free['person'] = $this->row('people');
        $free['session'] = $this->row('event_sessions', ['event_id' => $blocked['event']]);
        $list = $this->row('event_invitation_lists', ['event_id' => $blocked['event'], 'version' => 2]);
        $selection = (new \App\Domain\Events\InvitationService($this->db(), self::policy(), [], []))->freeze($blocked['actor'], $blocked['auth'], $list, [$free['person']], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
        $free['registration'] = $selection[$free['person']]['registration_id'];
        $freeToken = $this->eventCredential($free);
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('event_credentials')->where('id', $blockedToken['id'])->lockForUpdate()->first();
        [$processes, $barrier] = $this->launch([['fixture' => $blocked, 'token' => $blockedToken['token'], 'key' => 'blocked'], ['fixture' => $free, 'token' => $freeToken['token'], 'key' => 'free']]);
        try {
            $deadline = microtime(true) + 5;
            $completed = false;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($processes[1][0]);
                if (!$status['running']) {
                    $completed = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($completed, 'Different person/session must not wait for locked credential');
            $this->assertTrue(proc_get_status($processes[0][0])['running'], 'Blocked worker actually waits');
        } finally {
            $lock->rollBack();
        }
        $results = $this->collect($processes, $barrier);
        $this->assertSame(['CHECKED_IN', 'CHECKED_IN'], array_column($results, 'result'));
        $p = self::$root . '/docs/database/physical/wave3_concurrency_evidence.json';
        $old = json_decode(file_get_contents($p), true);
        $old['granular'] = ['independent_worker_completed_before_release' => true, 'blocked_worker_waited' => true];
        file_put_contents($p, json_encode($old, JSON_PRETTY_PRINT) . PHP_EOL);
    }
    public function test_same_person_different_sessions_do_not_share_a_checkin_lock(): void
    {
        $blocked = $this->fixture();
        $c = $this->eventCredential($blocked);
        $free = $blocked;
        $free['session'] = $this->row('event_sessions', ['event_id' => $blocked['event']]);
        $hash = hash('sha256', json_encode([$blocked['actor'], $blocked['auth'], $blocked['device'], $blocked['event'], $blocked['session'], $blocked['person'], hash('sha256', $c['token']), false], JSON_THROW_ON_ERROR), true);
        $claim = $this->row('idempotency_requests', ['actor_id' => $blocked['actor'], 'operation' => 'EVENT_CHECKIN', 'client_key' => 'blocked_claim', 'request_hash' => $hash, 'status' => 'PROCESSING']);
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('idempotency_requests')->where('id', $claim)->lockForUpdate()->first();
        [$processes, $barrier] = $this->launch([['fixture' => $blocked, 'token' => $c['token'], 'key' => 'blocked_claim'], ['fixture' => $free, 'token' => $c['token'], 'key' => 'independent_session']]);
        try {
            $deadline = microtime(true) + 5;
            $completed = false;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($processes[1][0])['running']) {
                    $completed = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($completed);
            $this->assertTrue(proc_get_status($processes[0][0])['running']);
        } finally {
            $lock->rollBack();
        }
        $results = $this->collect($processes, $barrier);
        $this->assertSame(['CHECKED_IN', 'CHECKED_IN'], array_column($results, 'result'));
        $p = self::$root . '/docs/database/physical/wave3_concurrency_evidence.json';
        $old = json_decode(file_get_contents($p), true);
        $old['same_person_different_sessions'] = ['independent_session_completed_before_release' => true, 'shared_actor_and_token' => true];
        file_put_contents($p, json_encode($old, JSON_PRETTY_PRINT) . PHP_EOL);
    }
}
