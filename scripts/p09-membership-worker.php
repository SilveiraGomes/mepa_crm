<?php

declare(strict_types=1);

// P0.9 concurrency worker (ADR 0020 D12, C1-C5). One process = one MySQL connection. It boots the Laravel application
// against the isolated pool given in DB_* (the SAME MembershipServiceFactory as the HTTP layer), refuses anything but a
// mepa_wave5_test_* database, prints "READY" once booted and connected, waits for "go" on stdin (ready/go barrier: the
// parent releases every racer only after all of them are READY), runs ONE operation and prints one JSON line
// {"status": "OK" | <MembershipReason>, "result": ...}.

use App\Domain\Membership\AdmissionService;
use App\Domain\Membership\LegacyIdentifierService;
use App\Domain\Membership\LifecycleService;
use App\Domain\Membership\MembershipError;
use App\Domain\Membership\MembershipTransferService;
use App\Http\Membership\MembershipServiceFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root . '/apps/api/vendor/autoload.php';
$job = json_decode(base64_decode((string) ($argv[1] ?? ''), true) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', (string) getenv('DB_DATABASE')) || getenv('WAVE5_ALLOW_SYNTHETIC') !== '1') {
    fwrite(STDERR, "refusing: DB_DATABASE must be an isolated mepa_wave5_test_* pool and WAVE5_ALLOW_SYNTHETIC=1\n");
    exit(2);
}
$app = require $root . '/apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['auth_contract.active_user_statuses' => ['SYNTHETIC_READY'], 'auth_contract.active_grant_statuses' => ['SYNTHETIC_READY']]);
if (!preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "refusing: connected database is not an isolated pool\n");
    exit(2);
}
$factory = new MembershipServiceFactory(DB::connection());
DB::connection()->getPdo();
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);

$user = (int) $job['user'];
$session = (int) $job['session'];
$result = null;
try {
    $result = match ($job['op']) {
        'submit' => $factory->make(AdmissionService::class)->submit($user, $session, ['person_public_id' => $job['person'], 'congregation_public_id' => $job['congregation']]),
        'approve' => $factory->make(AdmissionService::class)->approve($user, $session, $job['membership'], ['lock_version' => $job['lock_version']]),
        'collective' => $factory->make(AdmissionService::class)->collectiveApprove($user, $session, ['memberships' => $job['memberships']]),
        'inactivate' => $factory->make(LifecycleService::class)->inactivate($user, $session, $job['membership'], ['lock_version' => $job['lock_version'], 'reason' => 'concurrency probe']),
        'end' => $factory->make(LifecycleService::class)->end($user, $session, $job['membership'], ['lock_version' => $job['lock_version'], 'reason' => 'concurrency probe']),
        'request' => $factory->make(MembershipTransferService::class)->request($user, $session, $job['membership'], ['destination_public_id' => $job['destination']]),
        'complete' => $factory->make(MembershipTransferService::class)->complete($user, $session, $job['transfer'], []),
        'cancel' => $factory->make(MembershipTransferService::class)->cancel($user, $session, $job['transfer'], []),
        'legacy' => $factory->make(LegacyIdentifierService::class)->register($user, $session, $job['membership'], ['raw_number' => $job['raw']]),
    };
    echo json_encode(['status' => 'OK', 'result' => $result], JSON_THROW_ON_ERROR), "\n";
} catch (MembershipError $e) {
    echo json_encode(['status' => $e->reason, 'items' => $e->items === [] ? null : $e->items], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'UNEXPECTED', 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 300)], JSON_THROW_ON_ERROR), "\n";
}
