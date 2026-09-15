<?php
declare(strict_types=1);
namespace Tests\Database\Support;

use RuntimeException;

final class WaveFourWorkerHarness
{
    private string $directory;
    private string $runId;
    private array $workers = [];

    public function __construct(private string $root, private string $script, private int $deadlineSeconds = 35)
    {
        $this->runId = bin2hex(random_bytes(16));
        $this->directory = sys_get_temp_dir() . '/mepa_wave4_m12_' . $this->runId;
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create isolated worker barrier');
        }
    }

    public function runId(): string { return $this->runId; }
    public function process(int $workerId) { return $this->workers[$workerId]["process"]; }
    public function directory(): string { return $this->directory; }
    public function stateFile(int $workerId, string $state): string
    {
        return $this->directory . '/' . $this->runId . '_' . $workerId . '_' . $state;
    }
    public function start(int $workerId, array $job): void
    {
        if (isset($this->workers[$workerId])) {
            throw new RuntimeException('Duplicate worker_id');
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, $this->root . '/scripts/' . $this->script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Worker launch failed');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->workers[$workerId] = ['process' => $process, 'pipes' => $pipes,
            'stdout' => '', 'stderr' => '', 'exit' => null, 'closed' => false];
        $job += ['barrier' => $this->directory, 'run_id' => $this->runId, 'worker_id' => $workerId];
        $payload = json_encode($job, JSON_THROW_ON_ERROR);
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($pipes[0], substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Worker job pipe write failed');
            }
            $offset += $written;
        }
        fclose($pipes[0]);
    }
    public function signal(int $workerId, string $state): void
    {
        if (file_put_contents($this->stateFile($workerId, $state), $state) === false) {
            throw new RuntimeException('Barrier signal failed');
        }
    }
    public function waitForState(int $workerId, string $state, int $seconds = 10): void
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            clearstatcache(true, $this->stateFile($workerId, $state));
            if (file_exists($this->stateFile($workerId, $state))) return;
            $this->poll();
            if (($this->workers[$workerId]['exit'] ?? null) !== null) {
                throw new RuntimeException("Worker $workerId exited before $state: " . $this->workers[$workerId]["stderr"] . $this->workers[$workerId]["stdout"]);
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Worker $workerId timed out before $state");
            }
            usleep(10000);
        }
    }
    private function poll(): void
    {
        foreach ($this->workers as &$worker) {
            if ($worker['closed']) continue;
            if ($worker['exit'] === null) {
                $status = proc_get_status($worker['process']);
                if (!$status['running']) {
                    $worker['exit'] = $status['exitcode'];
                }
            }
            // PHP Windows pipes may ignore stream_set_blocking(false). Do not read
            // them while the child is live; it only emits a short final JSON result.
            if ($worker['exit'] !== null || PHP_OS_FAMILY !== 'Windows') {
                $worker['stdout'] .= stream_get_contents($worker['pipes'][1]);
                $worker['stderr'] .= stream_get_contents($worker['pipes'][2]);
            }
        }
        unset($worker);
    }
    public function collect(int $workerId, ?int $seconds = null): array
    {
        $seconds = $seconds ?? $this->deadlineSeconds;
        $deadline = microtime(true) + $seconds;
        while ($this->workers[$workerId]['exit'] === null) {
            $this->poll();
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Worker $workerId completion timeout");
            }
            usleep(10000);
        }
        $this->poll();
        $worker = &$this->workers[$workerId];
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $closedExit = proc_close($worker['process']);
        $worker['closed'] = true;
        $exit = $worker['exit'] >= 0 ? $worker['exit'] : $closedExit;
        return ['exit' => $exit, 'stdout' => $worker['stdout'], 'stderr' => $worker['stderr'],
            'done' => file_exists($this->stateFile($workerId, 'done'))];
    }
    public function close(): void
    {
        try {
            foreach ($this->workers as &$worker) {
                if ($worker['closed']) continue;
                if (is_resource($worker['pipes'][0])) fclose($worker['pipes'][0]);
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    proc_terminate($worker['process']);
                    $deadline = microtime(true) + 2;
                    while (proc_get_status($worker['process'])['running'] && microtime(true) < $deadline) usleep(10000);
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        proc_terminate($worker['process'], 9);
                        $deadline = microtime(true) + 2;
                        while (proc_get_status($worker['process'])['running'] && microtime(true) < $deadline) usleep(10000);
                    }
                    $status = proc_get_status($worker['process']);
                    if ($status['running'] && PHP_OS_FAMILY === 'Windows') {
                        // PID comes solely from proc_get_status() for our own child.
                        $pid = (int) $status['pid'];
                        if ($pid > 0) exec('taskkill /PID ' . $pid . ' /T /F >NUL 2>&1');
                    }
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        throw new RuntimeException('Worker termination failed; refusing blocking proc_close');
                    }
                }
                $worker['stdout'] .= stream_get_contents($worker['pipes'][1]);
                $worker['stderr'] .= stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                proc_close($worker['process']);
                $worker['closed'] = true;
            }
            unset($worker);
        } finally {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                if (is_file($file)) unlink($file);
            }
            if (is_dir($this->directory)) rmdir($this->directory);
        }
    }
}

