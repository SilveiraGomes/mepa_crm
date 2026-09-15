<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\DomainClock;
// P0.3.4-M1: closes W4R-03 (MEDIUM). The decisive "now" for a temporal authorization
// must be sampled from the server AFTER the child anchor lock is acquired, never
// captured before a wait and reused once the lock is released.
final class WaveFourTemporalAuthorizationTest extends WaveFourCase
{
    private function launch(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/mepa_wave4_temporal_barrier_' . bin2hex(random_bytes(8));
        mkdir($barrier);
        $processes = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/wave4-checkout-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$root);
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
            self::assertSame('', trim($err), 'No raw SQL or PHP warnings');
            self::assertSame(0, $exit, 'Worker must converge: ' . $out);
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (glob($barrier . '/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
        return $results;
    }
    // Worker 0 holds the child anchor (a harmless revoke of an unrelated authorization)
    // for longer than the pickup authorization's remaining validity window. Worker 1 is
    // blocked on the SAME anchor the whole time. If "now" were captured before the wait,
    // worker 1 would validate against a clock still inside the window; the fix samples
    // it only after the anchor is finally acquired, i.e. after the window has closed.
    public function test_authorization_expiring_during_lock_wait_is_denied_with_post_lock_clock(): void
    {
        $f = $this->childFixture();
        unset($f['credential']);
        $dbNow = DomainClock::now($this->db());
        $f['otherPickup'] = $this->children()->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['other'], 'SYNTHETIC_PICKUP', $this->now()->modify('-1 hour'), $dbNow->modify('+2 seconds'));
        [$processes, $barrier] = $this->launch([['fixture' => $f, 'mode' => 'revoke', 'authorization' => $f['delivery'], 'hold' => true], ['fixture' => $f, 'mode' => 'checkout', 'other' => true, 'wait_for_lock' => 0]]);
        $deadline = microtime(true) + 10;
        while (!file_exists($barrier . '/locked_0') || !file_exists($barrier . '/attempting_1')) {
            if (microtime(true) > $deadline) {
                self::fail('Race synchronization timeout');
            }
            usleep(10000);
        }
        self::assertTrue(proc_get_status($processes[1][0])['running'], 'Second worker waits for the child anchor while the authorization is still valid');
        usleep(2600000);
        touch($barrier . '/finish');
        $results = $this->collect($processes, $barrier);
        self::assertSame(['REVOKED', 'PICKUP_NOT_AUTHORIZED'], array_column($results, 'result'));
        $v = $this->db()->table('child_custody_visits')->where('id', $f['visit'])->first();
        self::assertNull($v->checked_out_at);
        $p = self::$root . '/docs/database/physical/wave4_m1_temporal_evidence.json';
        file_put_contents($p, json_encode(['scenario' => 'authorization_expires_during_lock_wait', 'authorization_window_seconds' => 2, 'lock_held_seconds' => 2.6, 'second_worker_result' => 'PICKUP_NOT_AUTHORIZED', 'stale_clock_regression' => 0], JSON_PRETTY_PRINT) . PHP_EOL);
    }
    // The authorization window is DATETIME(6): a boundary a single microsecond in the
    // past must already be denied, and the column must not silently truncate to seconds.
    public function test_authorization_boundary_respects_microsecond_precision(): void
    {
        $f = $this->childFixture();
        $dbNow = DomainClock::now($this->db());
        $justExpired = $this->children()->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['other'], 'SYNTHETIC_PICKUP', $this->now()->modify('-1 hour'), $dbNow->modify('-1 microsecond'));
        $this->denied('PICKUP_NOT_AUTHORIZED', fn() => $this->childOut($f, $f['other'], $justExpired));
        $stillValid = $this->children()->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['other'], 'SYNTHETIC_PICKUP', $this->now()->modify('-1 hour'), $dbNow->modify('+5 seconds'));
        self::assertSame('CHECKED_OUT', $this->childOut($f, $f['other'], $stillValid)['result']);
        $column = $this->db()->select("SHOW COLUMNS FROM guardian_authorizations LIKE 'ends_at'")[0];
        self::assertStringContainsString('datetime(6)', strtolower($column->Type));
    }
}
