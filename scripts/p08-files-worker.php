<?php

declare(strict_types=1);

// P0.8 concurrency worker (F20 quota race). One process = one MySQL connection. Boots the Laravel application, points it
// at the isolated Wave 5 pool and at the test's private storage root / key ring, waits for "go" on stdin so every racer
// starts together, runs ONE FileService::upload and prints {"status": "OK" | <FilesReason>}. Refuses anything but an
// isolated mepa_wave5_test_* database.

use App\Domain\Files\FileService;
use App\Domain\Files\FilesError;
use App\Http\Files\FilesServiceFactory;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root . '/apps/api/vendor/autoload.php';
$job = json_decode(base64_decode((string) ($argv[1] ?? ''), true) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
$dsn = (string) getenv('WAVE5_DSN');
$parts = [];
foreach (explode(';', substr($dsn, 6)) as $part) {
    if (str_contains($part, '=')) {
        [$k, $v] = explode('=', $part, 2);
        $parts[$k] = $v;
    }
}
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '') || getenv('WAVE5_ALLOW_SYNTHETIC') !== '1') {
    fwrite(STDERR, "refusing: isolated Wave 5 pool and WAVE5_ALLOW_SYNTHETIC=1 required\n");
    exit(2);
}
$app = require $root . '/apps/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.connections.mysql.host' => $parts['host'] ?? '127.0.0.1', 'database.connections.mysql.port' => $parts['port'] ?? 3306,
    'database.connections.mysql.database' => $parts['dbname'], 'database.connections.mysql.username' => getenv('WAVE5_USER') ?: 'root',
    'database.connections.mysql.password' => getenv('WAVE5_PASSWORD') ?: '', 'database.default' => 'mysql',
    'auth_contract.active_user_statuses' => ['SYNTHETIC_READY'], 'auth_contract.active_grant_statuses' => ['SYNTHETIC_READY'],
    'filesystems.disks.files_private.root' => $job['root'], 'files.storage_root' => $job['root'], 'files.keyring_path' => $job['keyring'],
    'files.unit_quota_bytes' => (int) $job['quota'], 'files.total_quota_bytes' => null, 'files.total_quota_required' => false, 'files.clamd' => null,
]);
DB::purge('mysql');
DB::reconnect('mysql');
$service = $app->make(FilesServiceFactory::class)->make(FileService::class);
fgets(STDIN);
try {
    $result = $service->upload((int) $job['user'], (int) $job['session'], ['owner_unit_public_id' => $job['unit'], 'classification' => 'RESTRICTED'], (string) $job['path'], (string) $job['name']);
    echo json_encode(['status' => 'OK', 'public_id' => $result['public_id']], JSON_THROW_ON_ERROR);
} catch (FilesError $e) {
    echo json_encode(['status' => $e->reason], JSON_THROW_ON_ERROR);
}
