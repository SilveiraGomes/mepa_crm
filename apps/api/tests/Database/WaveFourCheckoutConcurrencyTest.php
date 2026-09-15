<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
final class WaveFourCheckoutConcurrencyTest extends WaveFourCase
{
    public static function workers(): array
    {
        return [[2], [10], [30], [50]];
    }
    private function launch(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/mepa_wave4_barrier_' . bin2hex(random_bytes(8));
        mkdir($barrier);
        $processes = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/wave4-checkout-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$root);
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
    private function record(string $key, array $data): void
    {
        $p = self::$root . '/docs/database/physical/wave4_concurrency_evidence.json';
        $out = file_exists($p) ? json_decode(file_get_contents($p), true) : [];
        $out[$key] = $data;
        file_put_contents($p, json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL);
    }
    /** @dataProvider workers */
    public function test_real_workers_two_authorized_collectors_have_one_logical_checkout(int $workers): void
    {
        $f = $this->childFixture();
        unset($f['credential']);
        $jobs = [];
        for ($i = 0; $i < $workers; $i++) {
            $jobs[] = ['fixture' => $f, 'other' => $i % 2 === 1];
        }
        [$processes, $barrier] = $this->launch($jobs);
        $results = $this->collect($processes, $barrier);
        $counts = array_count_values(array_column($results, 'result'));
        self::assertSame(1, $counts['CHECKED_OUT'] ?? 0);
        self::assertSame($workers - 1, $counts['ALREADY_CHECKED_OUT'] ?? 0);
        $row = $this->db()->selectOne('SELECT COUNT(*) AS n, SUM(checked_out_at IS NOT NULL) AS closed FROM child_custody_visits WHERE session_id=? AND child_person_id=?', [$f['session'], $f['child']]);
        self::assertSame(1, (int) $row->n);
        self::assertSame(1, (int) $row->closed);
        self::assertSame(1, (int) $this->db()->selectOne("SELECT COUNT(*) AS n FROM audit_logs WHERE action='CHILD_CHECKOUT' AND entity_type='child_custody_visits' AND entity_id=?", [$f['visit']])->n);
        $this->record('workers_' . $workers, ['workers' => $workers, 'checked_out' => 1, 'already_checked_out' => $workers - 1, 'logical_visits_sql' => 1, 'checkout_audits_sql' => 1, 'raw_sql_errors' => 0]);
    }
    public function test_revocation_and_checkout_linearize_in_both_orders(): void
    {
        foreach (['checkout', 'revoke'] as $first) {
            for ($trial = 0; $trial < 10; $trial++) {
                $f = $this->childFixture();
                unset($f['credential']);
                $second = $first === 'checkout' ? 'revoke' : 'checkout';
                [$processes, $barrier] = $this->launch([['fixture' => $f, 'mode' => $first, 'hold' => true], ['fixture' => $f, 'mode' => $second, 'wait_for_lock' => 0]]);
                $deadline = microtime(true) + 10;
                try {
                    while (!file_exists($barrier . '/locked_0') || !file_exists($barrier . '/attempting_1')) {
                        if (microtime(true) > $deadline) {
                            self::fail('Race synchronization timeout');
                        }
                        usleep(10000);
                    }
                    self::assertTrue(proc_get_status($processes[1][0])['running'], 'Second worker waits for the child anchor');
                } finally {
                    touch($barrier . '/finish');
                }
                $results = $this->collect($processes, $barrier);
                self::assertSame($first === 'checkout' ? ['CHECKED_OUT', 'REVOKED'] : ['REVOKED', 'PICKUP_NOT_AUTHORIZED'], array_column($results, 'result'));
                $v = $this->db()->table('child_custody_visits')->where('id', $f['visit'])->first();
                $a = $this->db()->table('guardian_authorizations')->where('id', $f['pickup'])->first();
                if ($first === 'checkout') {
                    self::assertNotNull($v->checked_out_at);
                    self::assertLessThanOrEqual($a->ends_at, $v->checked_out_at);
                } else {
                    self::assertNull($v->checked_out_at);
                }
            }
        }
        $this->record('revocation_race', ['trials' => 20, 'checkout_first' => 10, 'revocation_first' => 10, 'stale_authorization_after_revocation_commit' => 0, 'raw_sql_errors' => 0]);
    }
    public function test_different_children_in_same_session_progress_without_global_lock(): void
    {
        $blocked = $this->childFixture();
        $free = $blocked;
        $free['person'] = $this->row('people');
        $list = $this->row('event_invitation_lists', ['event_id' => $blocked['event'], 'version' => 2]);
        $r = (new \App\Domain\Events\InvitationService($this->db(), self::policy(), [], []))->freeze($blocked['actor'], $blocked['auth'], $list, [$free['person']], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
        $free['registration'] = $r[$free['person']]['registration_id'];
        $free = $this->childFixture(true, $free);
        unset($free['credential'], $blocked['credential']);
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('child_profiles')->where('person_id', $blocked['child'])->lockForUpdate()->first();
        [$processes, $barrier] = $this->launch([['fixture' => $blocked], ['fixture' => $free]]);
        try {
            $deadline = microtime(true) + 8;
            $completed = false;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($processes[1][0])['running']) {
                    $completed = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($completed, 'Different child in the same event/session finishes before release');
            self::assertTrue(proc_get_status($processes[0][0])['running']);
        } finally {
            $lock->rollBack();
        }
        self::assertSame(['CHECKED_OUT', 'CHECKED_OUT'], array_column($this->collect($processes, $barrier), 'result'));
        $this->record('granularity', ['same_event' => true, 'same_session' => true, 'independent_child_completed_before_release' => true, 'blocked_child_waited' => true]);
    }
}
