<?php

declare (strict_types=1);
namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFourCase.php';
use Tests\DatabaseV2\Support\PooledWaveFourCase;
use App\Domain\WaveFour\DomainClock;

/**
 * P0-TI.1 Test Infrastructure V2 pilot. Ported from
 * apps/api/tests/Database/WaveFourTemporalAuthorizationTest.php (unmodified)
 * -- only the "checkout temporal expiry" target named in the P0-TI.1 spec,
 * byte-identical body, running against a pooled/reset database instead of a
 * per-run CREATE/DROP schema. The worker subprocess it spawns
 * (scripts/wave4-checkout-worker.php) reads WAVE4_DSN/etc. from the
 * inherited process environment unchanged, so it transparently targets
 * whichever database run_pilot.py points the PHPUnit process at.
 */
final class PooledTemporalAuthorizationTest extends PooledWaveFourCase
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
    }
}
