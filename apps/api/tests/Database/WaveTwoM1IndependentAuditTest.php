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

// P0.3.2-M1-R: independent audit of the F-W2F-01 fix. Fresh container/database (mepa-m1r-mysql,
// never the implementer's mepa-wave2m1-mysql), own env-var prefix (M1AUDIT_*), own test
// orchestration written from scratch - not a copy of WaveTwoTransferConcurrencyTest. That suite
// is re-run separately, unmodified, as part of the regression check; this file adds independent
// adversarial coverage, including bypassing TransferService entirely (raw SQL race) to prove the
// physical constraint - not the application layer - is what ultimately preserves the invariant.
final class WaveTwoM1IndependentAuditTest extends TestCase
{
    private static Manager $capsule;
    private static string $root;
    private static array $dbParts;
    private static bool $ready = false;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 4);
        $dsn = getenv('M1AUDIT_DSN') ?: '';
        if (getenv('M1AUDIT_ALLOW_SYNTHETIC') !== '1' || !$dsn) {
            self::markTestSkipped('BLOCKED FOR EXECUTION: explicit authorized MySQL M1AUDIT_DSN and M1AUDIT_ALLOW_SYNTHETIC=1 required.');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) { [$key, $value] = explode('=', $part, 2); $parts[$key] = $value; }
        }
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/^mepa_m1audit_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '')
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            self::fail('Refusing tests: require loopback and an empty mepa_m1audit_test_* database.');
        }
        self::$dbParts = $parts;
        self::$capsule = new Manager;
        self::$capsule->addConnection(['driver' => 'mysql', 'host' => $parts['host'], 'port' => $parts['port'] ?? 3306,
            'database' => $parts['dbname'], 'username' => getenv('M1AUDIT_USER') ?: '', 'password' => getenv('M1AUDIT_PASSWORD') ?: '',
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
        $domainPath = self::$root . '/apps/api/database/migrations/2026_09_14_000000_wave2m1_add_transfers_closed_at.php';
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
        self::assertCount(1, $migrator->run([$domainPath]));
        self::assertCount(1, $migrator->run([$m1Path]), 'M1 migration must apply cleanly');
        self::$ready = true;
    }

    private function lookup(string $table, string $code): int
    {
        $existing = self::$capsule->getConnection()->table($table)->where('code', $code)->value('id');
        if ($existing !== null) return (int) $existing;
        return self::$capsule->getConnection()->table($table)->insertGetId(['code' => $code, 'name' => 'M1 audit fixture',
            'is_active' => 1, 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function unit(string $code): int
    {
        $type = $this->lookup('organizational_unit_types', 'M1AUDIT_TYPE');
        return self::$capsule->getConnection()->table('organizational_units')->insertGetId(['public_id' => (string) Str::ulid(),
            'unit_type_id' => $type, 'code' => $code, 'name' => 'M1 audit ' . $code, 'status' => 'ACTIVE', 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function person(): int
    {
        $status = $this->lookup('person_statuses', 'M1AUDIT_PS_' . strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('people')->insertGetId(['public_id' => (string) Str::ulid(),
            'full_name' => 'M1 audit person', 'birth_precision' => 'UNKNOWN', 'status_id' => $status, 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function membershipRow(): int
    {
        $status = $this->lookup('membership_statuses', 'M1AUDIT_MS_' . strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('memberships')->insertGetId(['public_id' => (string) Str::ulid(),
            'person_id' => $this->person(), 'status_id' => $status, 'date_precision' => 'EXACT',
            'origin' => 'APPROVED_ADMISSION', 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function user(): int
    {
        return self::$capsule->getConnection()->table('users')->insertGetId(['account_kind' => 'SERVICE',
            'public_id' => (string) Str::ulid(), 'login' => 'm1audit_' . strtolower(bin2hex(random_bytes(6))),
            'password_hash' => 'x', 'status' => 'ACTIVE', 'mfa_required' => 0, 'created_at' => '2026-09-14 10:00:00.123456']);
    }

    private function workflowInstance(int $unitId): int
    {
        $workflow = self::$capsule->getConnection()->table('workflows')->insertGetId(['code' => 'M1AUDIT_WF_' . strtoupper(bin2hex(random_bytes(3))),
            'version' => 1, 'name' => 'M1 audit', 'status' => 'ACTIVE', 'created_at' => '2026-09-14 10:00:00.123456']);
        return self::$capsule->getConnection()->table('workflow_instances')->insertGetId(['public_id' => (string) Str::ulid(),
            'workflow_id' => $workflow, 'unit_id' => $unitId, 'requested_by' => $this->user(), 'status' => 'COMPLETED',
            'created_at' => '2026-09-14 10:00:00.123456']);
    }

    /** @return array<int,array{ok:bool}> */
    private function runWorkers(string $scriptPath, array $jobs): array
    {
        $env = getenv();
        $env['W2_HOST'] = self::$dbParts['host']; $env['W2_PORT'] = self::$dbParts['port'] ?? 3306;
        $env['W2_DB'] = self::$dbParts['dbname']; $env['W2_USER'] = getenv('M1AUDIT_USER') ?: ''; $env['W2_PASS'] = getenv('M1AUDIT_PASSWORD') ?: '';
        $tmp = sys_get_temp_dir() . '/m1audit_' . bin2hex(random_bytes(8));
        mkdir($tmp);
        $procs = [];
        foreach ($jobs as $i => $job) {
            $ready = "$tmp/ready_$i"; $go = "$tmp/go"; $result = "$tmp/result_$i";
            $process = proc_open([PHP_BINARY, $scriptPath,
                (string) $job['membershipId'], (string) $job['originUnitId'], (string) $job['destinationUnitId'],
                (string) $job['workflowInstanceId'], $ready, $go, $result],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$root, $env);
            if (!is_resource($process)) throw new RuntimeException('Could not spawn worker');
            $procs[] = ['proc' => $process, 'pipes' => $pipes, 'ready' => $ready, 'result' => $result];
        }
        $deadline = microtime(true) + 25;
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

    private function raceSameMembership(int $n): array
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1AUDIT_ORIGIN_' . $n . '_' . bin2hex(random_bytes(3)));
        $wf = $this->workflowInstance($origin);
        $jobs = array_map(fn($i) => ['membershipId' => $membership, 'originUnitId' => $origin,
            'destinationUnitId' => $this->unit('M1AUDIT_DEST_' . $n . '_' . $i . '_' . bin2hex(random_bytes(3))), 'workflowInstanceId' => $wf], range(1, $n));
        return ['membership' => $membership, 'results' => $this->runWorkers(self::$root . '/scripts/wave2-transfer-concurrency-worker.php', $jobs)];
    }

    // Section 5: reproduce 2/10/20/30 workers independently via the production worker script
    // (TransferService), asserting exactly one success per race regardless of batch size.
    public function test_two_workers_independent(): void { $this->assertExactlyOneSuccess(2); }
    public function test_ten_workers_independent(): void { $this->assertExactlyOneSuccess(10); }
    public function test_twenty_workers_independent(): void { $this->assertExactlyOneSuccess(20); }
    public function test_thirty_workers_independent(): void { $this->assertExactlyOneSuccess(30); }

    private function assertExactlyOneSuccess(int $n): void
    {
        $db = self::$capsule->getConnection();
        $race = $this->raceSameMembership($n);
        $ok = array_filter($race['results'], fn($r) => $r['ok']);
        $failed = array_filter($race['results'], fn($r) => !$r['ok']);
        $this->assertCount(1, $ok, "$n-worker race must yield exactly one success: " . json_encode($race['results']));
        $this->assertCount($n - 1, $failed);
        foreach ($failed as $f) $this->assertStringContainsString('TRANSFER_ALREADY_IN_PROGRESS', $f['error'],
            'Every loser must be the clean domain rejection, never a raw duplicate-key error exposed as a normal domain response: ' . json_encode($f));
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $race['membership'])->count());
    }

    // Section 6-B: bypass TransferService entirely - raw SQL race, no application layer at all.
    // The physical UNIQUE constraint must be the one and only thing preventing corruption here.
    public function test_raw_sql_race_bypassing_transfer_service(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1AUDIT_RAWSQL_ORIGIN');
        $wf = $this->workflowInstance($origin);
        $jobs = array_map(fn($i) => ['membershipId' => $membership, 'originUnitId' => $origin,
            'destinationUnitId' => $this->unit('M1AUDIT_RAWSQL_DEST_' . $i), 'workflowInstanceId' => $wf], range(1, 10));
        $results = $this->runWorkers(self::$root . '/scripts/m1-audit-raw-sql-race-worker.php', $jobs);
        $ok = array_filter($results, fn($r) => $r['ok']);
        $failed = array_filter($results, fn($r) => !$r['ok']);
        $this->assertCount(1, $ok, 'DB constraint alone (no TransferService involved) must still yield exactly one success: ' . json_encode($results));
        $this->assertCount(9, $failed);
        foreach ($failed as $f) $this->assertStringContainsString('1062', $f['error'], 'Raw bypass losers see the raw duplicate-key error directly (expected here - there is no service layer to translate it in this adversarial scenario)');
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $membership)->count(), 'Physical constraint alone preserved the invariant with zero application-layer involvement');
    }

    // Section 8: worker killed after acquiring the membership lock, reproduced independently.
    public function test_worker_killed_after_lock_independent(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1AUDIT_KILL_ORIGIN');
        $dest = $this->unit('M1AUDIT_KILL_DEST');
        $wf = $this->workflowInstance($origin);

        $pdo = new PDO(
            'mysql:host=' . self::$dbParts['host'] . ';port=' . (self::$dbParts['port'] ?? 3306) . ';dbname=' . self::$dbParts['dbname'],
            getenv('M1AUDIT_USER') ?: '', getenv('M1AUDIT_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->beginTransaction();
        $pdo->prepare('SELECT * FROM memberships WHERE id = ? FOR UPDATE')->execute([$membership]);
        $pdo->prepare('INSERT INTO transfers (public_id, membership_id, origin_unit_id, destination_unit_id, requested_at, status, workflow_instance_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(string) Str::ulid(), $membership, $origin, $dest, '2026-09-14 09:00:00.000000', 'PENDING', $wf, '2026-09-14 09:00:00.000000']);
        $pdo = null; // killed, no COMMIT

        $this->assertSame(0, $db->table('transfers')->where('membership_id', $membership)->count(), 'MySQL auto-rollback on dropped connection: no orphaned row, lock released, open_flag not reserved');
        $service = new TransferService($db);
        $result = $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        $this->assertIsInt($result['id'], 'A fresh worker must be able to proceed immediately after the kill');
    }

    // Section 9: exception forced after lock, before commit.
    public function test_rollback_forced_after_lock_independent(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('M1AUDIT_RB_ORIGIN');
        $dest = $this->unit('M1AUDIT_RB_DEST');
        $wf = $this->workflowInstance($origin);
        $service = new TransferService($db);

        try {
            $db->transaction(function () use ($service, $membership, $origin, $dest, $wf) {
                $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
                throw new RuntimeException('SIMULATED_FAILURE_AFTER_LOCK_BEFORE_COMMIT');
            });
            $this->fail('Expected the simulated failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('SIMULATED_FAILURE_AFTER_LOCK_BEFORE_COMMIT', $e->getMessage());
        }

        $this->assertSame(0, $db->table('transfers')->where('membership_id', $membership)->count(), 'Zero partially-persisted transfer');
        $this->assertSame(0, $db->table('memberships')->where('id', $membership)->where('lock_version', '>', 0)->count(), 'No undue state change on the membership row');
        $result = $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        $this->assertIsInt($result['id'], 'Invariant intact: membership available again after rollback');
    }

    // Section 7: lock granularity - N different memberships must not serialize through one global lock.
    public function test_lock_is_membership_granular_not_global(): void
    {
        $db = self::$capsule->getConnection();
        $jobs = []; $memberships = [];
        foreach (range(1, 15) as $i) {
            $membership = $this->membershipRow();
            $memberships[] = $membership;
            $origin = $this->unit('M1AUDIT_GRAN_ORIGIN_' . $i);
            $jobs[] = ['membershipId' => $membership, 'originUnitId' => $origin,
                'destinationUnitId' => $this->unit('M1AUDIT_GRAN_DEST_' . $i), 'workflowInstanceId' => $this->workflowInstance($origin)];
        }
        $start = microtime(true);
        $results = $this->runWorkers(self::$root . '/scripts/wave2-transfer-concurrency-worker.php', $jobs);
        $elapsed = microtime(true) - $start;
        $ok = array_filter($results, fn($r) => $r['ok']);
        $this->assertCount(15, $ok, json_encode($results));
        foreach ($memberships as $m) $this->assertSame(1, $db->table('transfers')->where('membership_id', $m)->count());
        $this->assertLessThan(12.0, $elapsed, 'Fifteen independent memberships evidence non-global locking (observed ' . round($elapsed, 2) . 's); a per-catalog/table lock would serialize far worse under this fan-out');
    }

    // Section 11: origin == destination still rejected, never masked by the new open-transfer guard.
    public function test_origin_equals_destination_still_rejected_and_not_masked(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $unit = $this->unit('M1AUDIT_SAME_UNIT');
        $wf = $this->workflowInstance($unit);
        $service = new TransferService($db);
        try {
            $service->request($membership, $unit, $unit, $wf, new DateTimeImmutable('2026-09-14'));
            $this->fail('Same origin/destination must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('TRANSFER_INVALID_ORIGIN_DESTINATION', $e->getMessage(), 'Must be the CHECK violation, not misclassified as TRANSFER_ALREADY_IN_PROGRESS');
        }
        $this->assertSame(0, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    // Section 12: precisely determine what the retry-safety mechanism depends on. No
    // idempotency_key/client_key column exists anywhere on `transfers` - confirm structurally,
    // then confirm the retry-safety is a pure side effect of (membership lock + UNIQUE), not a
    // dedicated idempotent-replay mechanism (unlike MemberNumberGenerator, a retried request()
    // does NOT hand back the original transfer's id - the caller gets a clean rejection only).
    public function test_idempotency_mechanism_is_precisely_lock_plus_unique_not_a_key(): void
    {
        $db = self::$capsule->getConnection();
        $columns = collect($db->select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transfers'"))->pluck('COLUMN_NAME');
        $this->assertFalse($columns->contains(fn($c) => str_contains(strtolower($c), 'idempot')), 'No idempotency-key column exists on transfers - retry-safety is not key-based');

        $membership = $this->membershipRow();
        $origin = $this->unit('M1AUDIT_IDEMP_ORIGIN');
        $dest = $this->unit('M1AUDIT_IDEMP_DEST');
        $wf = $this->workflowInstance($origin);
        $service = new TransferService($db);
        $first = $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
        try {
            $service->request($membership, $origin, $dest, $wf, new DateTimeImmutable('2026-09-14'));
            $this->fail('Retry must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame('TRANSFER_ALREADY_IN_PROGRESS', $e->getMessage());
            // Confirms this is NOT idempotent-replay-with-result: the exception carries no
            // reference to $first['id'] anywhere in the public contract. The caller who wants the
            // existing transfer's identity must look it up separately (open_flag=1 for the
            // membership) - a real, documented limitation, not an inferred guarantee.
        }
        $this->assertSame(1, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    public static function tearDownAfterClass(): void
    {
        DB::clearResolvedInstances(); Schema::clearResolvedInstances();
    }
}
