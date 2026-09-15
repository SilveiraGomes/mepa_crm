<?php
declare(strict_types=1);
$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$path = static fn(string $state): string => $job['barrier'] . '/' . $job['run_id'] . '_' . $job['worker_id'] . '_' . $state;
$signal = static function (string $state) use ($path): void {
    if (file_put_contents($path($state), $state) === false) exit(3);
};
$signal('ready');
$deadline = microtime(true) + 10;
while (true) {
    clearstatcache(true, $path('go'));
    if (file_exists($path('go'))) break;
    if (microtime(true) >= $deadline) exit(4);
    usleep(10000);
}
if ($job['mode'] === 'crash') exit(7);
if ($job['mode'] === 'done_alive') {
    $signal('done');
    sleep(120);
    exit(0);
}
echo json_encode(['result' => 'PASS', 'worker_id' => $job['worker_id']], JSON_THROW_ON_ERROR), PHP_EOL;
$signal('done');

