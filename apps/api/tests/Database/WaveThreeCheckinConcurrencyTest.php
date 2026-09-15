<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveThreeCase.php';
require_once __DIR__ . '/Support/WaveFourWorkerHarness.php';
use Tests\Database\Support\WaveThreeCase;
use Tests\Database\Support\WaveFourWorkerHarness;
final class WaveThreeCheckinConcurrencyTest extends WaveThreeCase
{
    private array $evidence = [];
    public static function workers(): array
    {
        return [[2], [10], [30], [50]];
    }
    private function launch(array $jobs): array
    {
        $harness = new WaveFourWorkerHarness(self::$root, 'wave3-checkin-worker.php', 70);
        try {
            foreach ($jobs as $i => $job) $harness->start($i, $job);
            foreach ($jobs as $i => $_) $harness->waitForState($i, 'ready', 90);
            foreach ($jobs as $i => $_) $harness->signal($i, 'go');
            return [$harness, count($jobs)];
        } catch (\Throwable $error) {
            $harness->close();
            throw $error;
        }
    }
    private function collect(WaveFourWorkerHarness $harness, int $workers): array
    {
        $results = [];
        try {
            for ($i = 0; $i < $workers; $i++) {
                $worker = $harness->collect($i, 70);
                $this->assertSame('', trim($worker['stderr']), 'No raw SQL or PHP warnings');
                $this->assertSame(0, $worker['exit'], 'Worker must converge: ' . $worker['stdout']);
                $this->assertTrue($worker['done'], 'Exit code alone is not convergence');
                $results[] = json_decode(trim($worker['stdout']), true, 512, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            $harness->close();
        }
    }
    private function waitForLockWait(string $table, int $seconds = 45): void
    {
        $deadline = microtime(true) + $seconds;
        $schema = $this->db()->getDatabaseName();
        while (microtime(true) < $deadline) {
            $rows = $this->db()->select('SELECT t.trx_query FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.id=t.trx_mysql_thread_id WHERE p.db=? AND t.trx_state=?', [$schema, 'LOCK WAIT']);
            foreach ($rows as $row) {
                if (str_contains(strtolower((string) $row->trx_query), $table)) return;
            }
            usleep(10000);
        }
        $this->fail('Target worker did not reach a real ' . $table . ' LOCK WAIT');
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
        [$harness, $count] = $this->launch($jobs);
        $results = $this->collect($harness, $count);
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
        $harness = null;
        try {
            $lock->table('event_credentials')->where('id', $blockedToken['id'])->lockForUpdate()->first();
            [$harness, $count] = $this->launch([['fixture' => $blocked, 'token' => $blockedToken['token'], 'key' => 'blocked'], ['fixture' => $free, 'token' => $freeToken['token'], 'key' => 'free']]);
            $harness->waitForState(1, 'done', 60);
            $this->assertFileDoesNotExist($harness->stateFile(0, 'done'));
            $this->assertTrue(proc_get_status($harness->process(0))['running'], 'Blocked worker actually waits');
            $lock->rollBack();
            $results = $this->collect($harness, $count);
            $this->assertSame(['CHECKED_IN', 'CHECKED_IN'], array_column($results, 'result'));
            $p = self::$root . '/docs/database/physical/wave3_concurrency_evidence.json';
            $old = json_decode(file_get_contents($p), true);
            $old['granular'] = ['independent_worker_completed_before_release' => true, 'blocked_worker_waited' => true];
            file_put_contents($p, json_encode($old, JSON_PRETTY_PRINT) . PHP_EOL);
        } finally {
            if ($lock->transactionLevel()) $lock->rollBack();
            if ($harness !== null) $harness->close();
        }
    }
    public function test_same_person_different_sessions_do_not_share_a_checkin_lock(): void
    {
        $blocked = $this->fixture();
        $credential = $this->eventCredential($blocked);
        $free = $blocked; // Same actor and token: restore the original isolation property.
        $free['session'] = $this->row('event_sessions', ['event_id' => $blocked['event']]);
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $harness = null;
        try {
            // Block only the target session. Neither idempotency key exists yet;
            // both workers exercise the normal insertOrIgnore path.
            $lock->table('event_sessions')->where('id', $blocked['session'])->lockForUpdate()->first();
            [$harness, $count] = $this->launch([
                ['fixture' => $blocked, 'token' => $credential['token'], 'key' => 'blocked_claim'],
                ['fixture' => $free, 'token' => $credential['token'], 'key' => 'independent_session'],
            ]);
            $this->waitForLockWait('event_sessions');
            $harness->waitForState(1, 'done', 60);
            $this->assertFileDoesNotExist($harness->stateFile(0, 'done'), 'Blocked session must remain behind its own lock');
            $this->assertTrue(proc_get_status($harness->process(0))['running'], 'Blocked worker actually waits');
            $lock->rollBack();
            $results = $this->collect($harness, $count);
            $this->assertSame(['CHECKED_IN', 'CHECKED_IN'], array_column($results, 'result'));
            $this->assertSame(2, $this->db()->table('event_checkins')->where('person_id', $blocked['person'])->count());
            $p = self::$root . '/docs/database/physical/wave3_concurrency_evidence.json';
            $old = file_exists($p) ? json_decode(file_get_contents($p), true) : [];
            $old['same_person_different_sessions'] = [
                'independent_session_completed_before_release' => true,
                'shared_actor_and_token' => true,
                'fresh_claims' => true,
                'lock_table' => 'event_sessions',
                'run_id' => $harness->runId(),
            ];
            file_put_contents($p, json_encode($old, JSON_PRETTY_PRINT) . PHP_EOL);
        } finally {
            if ($lock->transactionLevel()) $lock->rollBack();
            if ($harness !== null) $harness->close();
        }
    }
}
