<?php

declare(strict_types=1);

// P0.7 concurrency worker (C1 set-primary, C2 end-link vs transfer). One process = one MySQL connection. It builds the
// same PhysicalRuntime as App\Http\Physical\PhysicalServiceFactory (TerritorialAuthority with data_type PHYSICAL, same
// opaque-ref derivation from P07_APP_KEY), waits for "go" on stdin so both racers start together, runs ONE operation
// and prints {"status": "OK" | <PhysicalReason>}. Refuses anything but an isolated mepa_wave5_test_* database.

use App\Domain\Physical\LinkService;
use App\Domain\Physical\PhysicalAudit;
use App\Domain\Physical\PhysicalAuthority;
use App\Domain\Physical\PhysicalCatalog;
use App\Domain\Physical\PhysicalError;
use App\Domain\Physical\PhysicalReason;
use App\Domain\Physical\PhysicalRef;
use App\Domain\Physical\PhysicalRuntime;
use App\Domain\Territorial\TerritorialAuthority;
use Illuminate\Database\Capsule\Manager;

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
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '') || getenv('WAVE5_ALLOW_SYNTHETIC') !== '1' || (string) getenv('P07_APP_KEY') === '') {
    fwrite(STDERR, "refusing: isolated Wave 5 pool, WAVE5_ALLOW_SYNTHETIC=1 and P07_APP_KEY required\n");
    exit(2);
}
$capsule = new Manager();
$capsule->addConnection(['driver' => 'mysql', 'host' => $parts['host'], 'port' => $parts['port'] ?? 3306, 'database' => $parts['dbname'], 'username' => getenv('WAVE5_USER') ?: 'root', 'password' => getenv('WAVE5_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
$db = $capsule->getConnection();
$runtime = new PhysicalRuntime(
    $db,
    new PhysicalAuthority($db, new TerritorialAuthority($db, ['SYNTHETIC_READY'], ['SYNTHETIC_READY'], PhysicalCatalog::DATA_TYPE)),
    new PhysicalAudit($db),
    new PhysicalRef(hash('sha256', 'mepa.physical.ref|' . getenv('P07_APP_KEY'), true)),
    static fn () => throw new PhysicalError(PhysicalReason::CONFIG_MISSING, ['reason' => 'worker_has_no_keyring']),
    null,
    ['pagination' => ['default' => 50, 'max' => 100]]
);
$links = new LinkService($runtime);
fgets(STDIN);
try {
    $in = ['lock_version' => (int) $job['lock_version'], 'reason' => (string) $job['reason']];
    match ($job['op']) {
        'set-primary' => $links->setPrimary((int) $job['user'], (int) $job['session'], $job['location'], $job['ref'], $in),
        'end' => $links->end((int) $job['user'], (int) $job['session'], $job['location'], $job['ref'], $in),
        'transfer' => $links->transfer((int) $job['user'], (int) $job['session'], $job['location'], $job['ref'], $in + ['to_unit_public_id' => $job['to']]),
    };
    echo json_encode(['status' => 'OK'], JSON_THROW_ON_ERROR);
} catch (PhysicalError $e) {
    echo json_encode(['status' => $e->reason], JSON_THROW_ON_ERROR);
}
