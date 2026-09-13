<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Domain\Membership\TransferService;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// P0.3.2-M1: proves the fix for F-W2F-01 under real concurrency (proc_open worker processes,
// never simulated interleaving). Own database, own env-var prefix (WAVE2M1_*).
final class WaveTwoTransferConcurrencyTest extends TestCase
{
    private static Manager $capsule;
    private static string $root;
    private static array $dbParts;
    private static bool $ready = false;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 4);
        $dsn = getenv('WAVE2M1_DSN') ?: '';
        if (getenv('WAVE2M1_ALLOW_SYNTHETIC') !== '1' || !$dsn) {
            self::markTestSkipped('BLOCKED FOR EXECUTION: explicit authorized MySQL WAVE2M1_DSN and WAVE2M1_ALLOW_SYNTHETIC=1 required.');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) { [$key, $value] = explode('=', $part, 2); $parts[$key] = $value; }
        }
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/^mepa_wave2m1_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '')
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            self::fail('Refusing tests: require loopback and an empty mepa_wave2m1_test_* database.');
        }
        self::$dbParts = $parts;
        self::$capsule = new Manager;
        self::$capsule->addConnection(['driver' => 'mysql', 'host' => $parts['host'], 'port' => $parts['port'] ?? 3306,
            'database' => $parts['dbname'], 'username' => getenv('WAVE2M1_USER') ?: '', 'password' => getenv('WAVE2M1_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00',
            'options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
        $db = self::$capsule->getConnection();
        self::assertSame([], $db->select('SHOW TABLES'), 'Tests require an empty database.');
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        $wave1Manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave1_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $wave2Manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave2_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $wave1Paths = array_map(static fn($m) => self::$root . '/apps/api/database/migrations/' . $m['file'], $wave1Manifest['migrations']);
        $wave2Paths = array_map(static fn($f) => self::$root . '/apps/api/database/migrations/' . $f, $wave2Manifest['migrations']);
        $m1Path = self::$root . '/apps/api/database/migrations/2026_09_14_000001_wave2m1_add_transfers_open_guard.php';
        $repository = new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(), 'migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, self::$capsule->getDatabaseManager(), new Filesystem);
        $scaffolding = array_map(static fn($file) => self::$root . '/apps/api/database/migrations/' . $file, [
            '2014_10_12_000000_create_users_table.php', '2014_10_12_100000_create_password_resets_table.php',
            '2019_08_19_000000_create_failed_jobs_table.php', '2019_12_14_000001_create_personal_access_tokens_table.php']);
        self::assertCount(4, $migrator->run($scaffolding));
        self::assertCount(31, $migrator->run($wave1Paths));
        self::assertCount(44, $migrator->run($wave2Paths));
        self::assertCount(1, $migrator->run([$m1Path]));
        self::$ready = true;
    }

    private array $unitTypeCache = [];

    private function lookup(string $table, string $code): int
    {
        $existing = self::$capsule->getConnection()->table($table)->where('code', $code)->value('id');
        if ($existing !== null) return (int) $existing;
        return self::$capsule->getConnection()->table($table)->insertGetId(['code' => $code, 'name' => 'M1 fixture',
            'is_active' => 1, 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function unitTypeId(string $typeCode): int
    {
        return $this->unitTypeCache[$typeCode] ??= $this->lookup('organizational_unit_types', $typeCode);
    }

    private function unit(string $code): int
    {
        return self::$capsule->getConnection()->table('organizational_units')->insertGetId(['public_id' => (string) Str::ulid(),
            'unit_type_id' => $this->unitTypeId('CONGREGATION'), 'code' => $code, 'name' => 'M1 ' . $code,
            'status' => 'ACTIVE', 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function person(string $name): int
    {
        $status = $this->lookup('person_statuses', 'M1_' . strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('people')->insertGetId(['public_id' => (string) Str::ulid(),
            'full_name' => $name, 'birth_precision' => 'UNKNOWN', 'status_id' => $status, 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function membershipRow(): int
    {
        $status = $this->lookup('membership_statuses', 'M1_' . strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('memberships')->insertGetId(['public_id' => (string) Str::ulid(),
            'person_id' => $this->person('M1 member'), 'status_id' => $status, 'date_precision' => 'EXACT',
            'origin' => 'APPROVED_ADMISSION', 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function user(): int
    {
        return self::$capsule->getConnection()->table('users')->insertGetId(['account_kind' => 'SERVICE',
            'public_id' => (string) Str::ulid(), 'login' => 'm1_' . strtolower(bin2hex(random_bytes(6))),
            'password_hash' => 'x', 'status' => 'ACTIVE', 'mfa_required' => 0, 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function workflowInstance(int $unitId): int
    {
        $workflow = self::$capsule->getConnection()->table('workflows')->insertGetId(['code' => 'M1_WF_' . strtoupper(bin2hex(random_bytes(3))),
            'version' => 1, 'name' => 'M1', 'status' => 'ACTIVE', 'created_at' => '2026-09-14 10:00:00.123456']);
        return self::$capsule->getConnection()->table('workflow_instances')->insertGetId(['public_id' => (string) Str::ulid(),
            'workflow_id' => $workflow, 'unit_id' => $unitId, 'requested_by' => $this->user(), 'status' => 'COMPLETED',
            'created_at' => '2026-09-14 10:00:00.123456']);
    }

    /** @return array<int,array{ok:bool,result?:array,error?:string}> */
    private function runWorkers(array $jobs): array
    {
        $env = getenv();
        $env['W2_HOST'] = self::$dbParts['host']; $env['W2_PORT'] = self::$dbParts['port'] ?? 3306;
        $env['W2_DB'] = self::$dbParts['dbname']; $env['W2_USER'] = getenv('WAVE2M1_USER') ?: ''; $env['W2_PASS'] = getenv('WAVE2M1_PASSWORD') ?: '';
        $tmp = sys_get_temp_dir() . '/wave2m1_transfer_' . bin2hex(random_bytes(8));
        mkdir($tmp);
        $procs = [];
        foreach ($jobs as $i => $job) {
            $ready = "$tmp/ready_$i"; $go = "$tmp/go"; $result = "$tmp/result_$i";
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/wave2-transfer-concurrency-worker.php',
                (string) $job['membershipId'], (string) $job['originUnitId'], (string) $job['destinationUnitId'],
                (string) $job['workflowInstanceId'], $ready, $go, $result],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$root, $env);
            if (!is_resource($process)) throw new RuntimeException('Could not spawn worker');
            $procs[] = ['proc' => $process, 'pipes' => $pipes, 'ready' => $ready, 'result' => $result];
        }
        $deadline = microtime(true) + 20;
        foreach ($procs as $p) {
            while (!file_exists($p['ready'])) {
                if (microtime(true) > $deadline) throw new RuntimeException('Timed out waiting for READY barrier');
                usleep(2000);
            }
        }
        touch("$tmp/go");
        $results = [];
        foreach ($procs as $p) {
            fclose($p['pipes'][0]); $out = stream_get_contents($p['pipes'][1]); $err = stream_get_contents($p['pipes'][2]);
            fclose($p['pipes'][1]); fclose($p['pipes'][2]); proc_close($p['proc']);
            if (!file_exists($p['result'])) throw new RuntimeException('Worker produced no result: ' . $out . $err);
            $results[] = json_decode(file_get_contents($p['result']), true);
        }
        foreach (glob("$tmp/*") as $f) unlink($f);
        rmdir($tmp);
        return $results;
    }

    // 1. Two workers, same membership, different destinations.
    public function test_two_workers_same_membership_different_destinations(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_2W');
        $destB = $this->unit('M1_DEST_2W_B');
        $destC = $this->unit('M1_DEST_2W_C');
        $wf = $this->workflowInstance($origin);

        $results = $this->runWorkers([
            ['membershipId' => $membership, 'originUnitId' => $origin, 'destinationUnitId' => $destB, 'workflowInstanceId' => $wf],
            ['membershipId' => $membership, 'originUnitId' => $origin, 'destinationUnitId' => $destC, 'workflowInstanceId' => $wf],
        ]);
        $ok = array_filter($results, fn($r) => $r['ok']);
        $failed = array_filter($results, fn($r) => !$r['ok']);
        $this->assertCount(1, $ok, 'Exactly one of the two racing requests must succeed: ' . json_encode($results));
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('TRANSFER_ALREADY_IN_PROGRESS', $failed[array_key_first($failed)]['error']);
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    // 2. Ten workers concurrently for the same membership.
    public function test_ten_workers_same_membership_concurrent(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_10W');
        $wf = $this->workflowInstance($origin);
        $jobs = array_map(fn($i) => ['membershipId' => $membership, 'originUnitId' => $origin,
            'destinationUnitId' => $this->unit('M1_DEST_10W_' . $i), 'workflowInstanceId' => $wf], range(1, 10));
        $results = $this->runWorkers($jobs);
        $ok = array_filter($results, fn($r) => $r['ok']);
        $failed = array_filter($results, fn($r) => !$r['ok']);
        $this->assertCount(1, $ok, 'Exactly one of ten racing requests must succeed: ' . json_encode($results));
        $this->assertCount(9, $failed);
        foreach ($failed as $f) $this->assertStringContainsString('TRANSFER_ALREADY_IN_PROGRESS', $f['error'], 'Every loser must be the clean domain rejection, not a technical error: ' . json_encode($f));
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    // 3. Repeated identical request is safe to retry (never silently duplicates the open transfer).
    public function test_repeated_request_is_safe_to_retry(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_RETRY');
        $dest = $this->unit('M1_DEST_RETRY');
        $wf = $this->workflowInstance($origin);
        $service = new TransferService($db);

        $first = $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        $this->assertIsInt($first['id']);
        try {
            $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
            $this->fail('Retry of an in-flight request must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('TRANSFER_ALREADY_IN_PROGRESS', $e->getMessage());
        }
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    // 4. Previous transfer COMPLETED (effectuated) frees the membership for a new request.
    public function test_new_request_allowed_after_previous_transfer_completed(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_COMPLETED');
        $destB = $this->unit('M1_DEST_COMPLETED_B');
        $destC = $this->unit('M1_DEST_COMPLETED_C');
        $wf = $this->workflowInstance($origin);
        $service = new TransferService($db);

        $first = $service->request($membership, $origin, $destB, $wf, new DateTimeImmutable('2026-01-01'));
        $service->effectuate($first['id'], new DateTimeImmutable('2026-01-15'));
        $second = $service->request($membership, $destB, $destC, $wf, new DateTimeImmutable('2026-06-01'));

        $this->assertSame(2, $db->table('transfers')->where('membership_id', $membership)->count(), 'Completed transfer preserved as history');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertNotNull($db->table('transfers')->where('id', $first['id'])->value('effective_at'));
        $this->assertNotNull($db->table('transfers')->where('id', $first['id'])->value('closed_at'));
        $this->assertNull($db->table('transfers')->where('id', $second['id'])->value('closed_at'), 'The new transfer is open');
    }

    // 5. Previous transfer CANCELLED (never effectuated) also frees the membership.
    public function test_new_request_allowed_after_previous_transfer_cancelled(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_CANCELLED');
        $destB = $this->unit('M1_DEST_CANCELLED_B');
        $destC = $this->unit('M1_DEST_CANCELLED_C');
        $wf = $this->workflowInstance($origin);
        $service = new TransferService($db);

        $first = $service->request($membership, $origin, $destB, $wf, new DateTimeImmutable('2026-01-01'));
        $service->cancel($first['id'], new DateTimeImmutable('2026-01-05'));
        $second = $service->request($membership, $origin, $destC, $wf, new DateTimeImmutable('2026-02-01'));

        $this->assertSame(2, $db->table('transfers')->where('membership_id', $membership)->count(), 'Cancelled transfer preserved as history, not deleted');
        $this->assertNull($db->table('transfers')->where('id', $first['id'])->value('effective_at'), 'Cancelled transfer never took effect');
        $this->assertNotNull($db->table('transfers')->where('id', $first['id'])->value('closed_at'));
        $this->assertNull($db->table('transfers')->where('id', $second['id'])->value('closed_at'));
    }

    // 6. Rollback during creation leaves the membership open (no phantom "in progress" state).
    public function test_rollback_during_creation_leaves_membership_open(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_ROLLBACK');
        $dest = $this->unit('M1_DEST_ROLLBACK');
        $wf = $this->workflowInstance($origin);
        $service = new TransferService($db);

        $db->beginTransaction();
        $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        $db->rollBack();

        $this->assertSame(0, $db->table('transfers')->where('membership_id', $membership)->count(), 'Rolled-back request leaves no row');
        $second = $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        $this->assertIsInt($second['id'], 'Membership must be available again after the rollback');
    }

    // 7. Worker killed after acquiring the lock leaves no orphaned open transfer.
    public function test_worker_killed_after_lock_leaves_no_orphaned_transfer(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_KILLED');
        $dest = $this->unit('M1_DEST_KILLED');
        $wf = $this->workflowInstance($origin);

        $pdo = new PDO(
            'mysql:host=' . self::$dbParts['host'] . ';port=' . (self::$dbParts['port'] ?? 3306) . ';dbname=' . self::$dbParts['dbname'],
            getenv('WAVE2M1_USER') ?: '', getenv('WAVE2M1_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->beginTransaction();
        $pdo->prepare('SELECT * FROM memberships WHERE id = ? FOR UPDATE')->execute([$membership]);
        $pdo->prepare('INSERT INTO transfers (public_id, membership_id, origin_unit_id, destination_unit_id, requested_at, status, workflow_instance_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(string) Str::ulid(), $membership, $origin, $dest, '2026-09-14 09:00:00.000000', 'PENDING', $wf, '2026-09-14 09:00:00.000000']);
        // No COMMIT - simulate the worker process being killed right after the lock+insert.
        $pdo = null;

        $this->assertSame(0, $db->table('transfers')->where('membership_id', $membership)->count(), 'A killed worker must not leave an orphaned open transfer');
        $service = new TransferService($db);
        $result = $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        $this->assertIsInt($result['id'], 'Membership must be available again after the kill');
    }

    // 8. No deadlock/technical-error leakage under repeated heavy contention on one membership.
    public function test_no_deadlock_under_heavy_contention(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1_ORIGIN_CONTENTION');
        $wf = $this->workflowInstance($origin);
        $jobs = array_map(fn($i) => ['membershipId' => $membership, 'originUnitId' => $origin,
            'destinationUnitId' => $this->unit('M1_DEST_CONTENTION_' . $i), 'workflowInstanceId' => $wf], range(1, 20));
        $results = $this->runWorkers($jobs);
        $ok = array_filter($results, fn($r) => $r['ok']);
        $failed = array_filter($results, fn($r) => !$r['ok']);
        $this->assertCount(1, $ok, json_encode($results));
        $this->assertCount(19, $failed);
        foreach ($failed as $f) {
            $this->assertStringContainsString('TRANSFER_ALREADY_IN_PROGRESS', $f['error'],
                'No raw deadlock (1213) or lock-wait-timeout (1205) may leak past the service as an unclassified error: ' . json_encode($f));
        }
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    // 9. Invalid origin/destination (same unit) is rejected with a clean domain message.
    public function test_invalid_origin_destination_rejected(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $unit = $this->unit('M1_SAME_UNIT');
        $wf = $this->workflowInstance($unit);
        $service = new TransferService($db);
        try {
            $service->request($membership, $unit, $unit, $wf, new DateTimeImmutable('2026-09-14'));
            $this->fail('Same origin/destination must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('TRANSFER_INVALID_ORIGIN_DESTINATION', $e->getMessage());
        }
        $this->assertSame(0, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    // 10. Different memberships transfer concurrently without blocking each other.
    public function test_different_memberships_do_not_block_each_other(): void
    {
        $db = self::$capsule->getConnection();
        $jobs = [];
        $memberships = [];
        foreach (range(1, 10) as $i) {
            $membership = $this->membershipRow();
            $memberships[] = $membership;
            $origin = $this->unit('M1_ORIGIN_PARALLEL_' . $i);
            $jobs[] = ['membershipId' => $membership, 'originUnitId' => $origin,
                'destinationUnitId' => $this->unit('M1_DEST_PARALLEL_' . $i), 'workflowInstanceId' => $this->workflowInstance($origin)];
        }
        $start = microtime(true);
        $results = $this->runWorkers($jobs);
        $elapsed = microtime(true) - $start;

        $ok = array_filter($results, fn($r) => $r['ok']);
        $this->assertCount(10, $ok, 'Ten distinct memberships must all succeed independently: ' . json_encode($results));
        foreach ($memberships as $m) $this->assertSame(1, $db->table('transfers')->where('membership_id', $m)->count());
        $this->assertLessThan(10.0, $elapsed, 'Ten distinct memberships must not serialize through one global lock (observed ' . round($elapsed, 2) . 's)');
    }

    public static function tearDownAfterClass(): void
    {
        DB::clearResolvedInstances(); Schema::clearResolvedInstances();
    }
}
