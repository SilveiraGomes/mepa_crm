<?php

declare (strict_types=1);
namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveThreeCase.php';
require_once __DIR__ . '/../Database/Support/WaveFourWorkerHarness.php';
use Tests\DatabaseV2\Support\PooledWaveThreeCase;
use Tests\Database\Support\WaveFourWorkerHarness;

/**
 * P0-TI.1 Test Infrastructure V2 pilot. Ported from
 * apps/api/tests/Database/WaveThreeCheckinConcurrencyTest.php (unmodified)
 * -- only the "Wave3 check-in concurrency" target named in the P0-TI.1 spec,
 * byte-identical body, running against a pooled/reset database instead of a
 * per-run CREATE/DROP schema. workers() is 2/10/30 (the counts the P0-TI.1
 * pilot actually runs); 50 is left for a future, non-pilot port. The
 * V1-evidence-file side write is dropped -- pilot results are recorded
 * separately by tools/test-infrastructure/run_pilot.py, this file never
 * writes to docs/database/physical/wave3_concurrency_evidence.json.
 */
final class PooledWaveThreeCheckinConcurrencyTest extends PooledWaveThreeCase
{
    public static function workers(): array
    {
        return [[2], [10], [30]];
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
    }
}
