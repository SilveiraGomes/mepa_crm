<?php
declare(strict_types=1);
namespace Tests\Unit;

require_once __DIR__ . '/../Database/Support/WaveFourWorkerHarness.php';

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Database\Support\WaveFourWorkerHarness;

final class WaveFourWorkerHarnessTest extends TestCase
{
    private function harness(): WaveFourWorkerHarness
    {
        return new WaveFourWorkerHarness(dirname(__DIR__, 4), 'wave4-m12-liveness-worker.php');
    }
    public function test_done_signal_from_live_worker_times_out_and_cleans_up(): void
    {
        $harness = $this->harness();
        $directory = $harness->directory();
        try {
            $harness->start(0, ['mode' => 'done_alive']);
            $harness->waitForState(0, 'ready');
            $harness->signal(0, 'go');
            $harness->waitForState(0, 'done');
            try {
                $harness->collect(0, 2);
                self::fail('done file cannot produce PASS while process is alive');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('completion timeout', $error->getMessage());
            }
        } finally {
            $harness->close();
        }
        self::assertDirectoryDoesNotExist($directory);
    }
    public function test_crashed_worker_has_nonzero_exit_and_no_done(): void
    {
        $harness = $this->harness();
        $directory = $harness->directory();
        try {
            $harness->start(0, ['mode' => 'crash']);
            $harness->waitForState(0, 'ready');
            $harness->signal(0, 'go');
            $result = $harness->collect(0);
            self::assertSame(7, $result['exit']);
            self::assertFalse($result['done']);
        } finally {
            $harness->close();
        }
        self::assertDirectoryDoesNotExist($directory);
    }
    public function test_two_live_runs_have_distinct_signals_and_results(): void
    {
        $first = $this->harness();
        $second = $this->harness();
        $one = $first->directory();
        $two = $second->directory();
        try {
            self::assertNotSame($first->runId(), $second->runId());
            $first->start(0, ['mode' => 'pass']);
            $second->start(0, ['mode' => 'pass']);
            $first->waitForState(0, 'ready');
            $second->waitForState(0, 'ready');
            $first->signal(0, 'go');
            $resultOne = $first->collect(0);
            self::assertSame(0, $resultOne['exit']);
            self::assertTrue($resultOne['done']);
            self::assertSame('PASS', json_decode(trim($resultOne['stdout']), true, 512, JSON_THROW_ON_ERROR)['result']);
            self::assertFileDoesNotExist($second->stateFile(0, 'go'));
            self::assertFileDoesNotExist($second->stateFile(0, 'done'));
            $second->signal(0, 'go');
            $resultTwo = $second->collect(0);
            self::assertSame(0, $resultTwo['exit']);
            self::assertTrue($resultTwo['done']);
            self::assertSame('PASS', json_decode(trim($resultTwo['stdout']), true, 512, JSON_THROW_ON_ERROR)['result']);
        } finally {
            $first->close();
            $second->close();
        }
        self::assertDirectoryDoesNotExist($one);
        self::assertDirectoryDoesNotExist($two);
    }
}

