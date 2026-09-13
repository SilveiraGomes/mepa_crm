<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Domain\Membership\MemberNumberGenerator;
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

// P0.3.2-F: adversarial scenarios NOT already covered by WaveTwoPhysicalTest /
// WaveTwoConcurrencyTest (the implementer's own suites, re-run separately as part of this
// reconciliation but not duplicated here). Own database, own env-var prefix (WAVE2F_*), own
// disposable container - never the implementer's mepa-wave2-mysql/mepa_wave2_test_* databases.
final class WaveTwoIndependentReconciliationTest extends TestCase
{
    private static Manager $capsule;
    private static string $root;
    private static array $dbParts;
    private static bool $ready = false;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 4);
        $dsn = getenv('WAVE2F_DSN') ?: '';
        if (getenv('WAVE2F_ALLOW_SYNTHETIC') !== '1' || !$dsn) {
            self::markTestSkipped('BLOCKED FOR EXECUTION: explicit authorized MySQL WAVE2F_DSN and WAVE2F_ALLOW_SYNTHETIC=1 required.');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) { [$key, $value] = explode('=', $part, 2); $parts[$key] = $value; }
        }
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/^mepa_wave2f_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '')
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            self::fail('Refusing reconciliation tests: require loopback and an empty mepa_wave2f_test_* database.');
        }
        self::$dbParts = $parts;
        self::$capsule = new Manager;
        self::$capsule->addConnection(['driver' => 'mysql', 'host' => $parts['host'], 'port' => $parts['port'] ?? 3306,
            'database' => $parts['dbname'], 'username' => getenv('WAVE2F_USER') ?: '', 'password' => getenv('WAVE2F_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00',
            'options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
        $db = self::$capsule->getConnection();
        self::assertSame([], $db->select('SHOW TABLES'), 'Reconciliation tests require an empty database.');
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        $wave1Manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave1_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $wave2Manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave2_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $wave1Paths = array_map(static fn($m) => self::$root . '/apps/api/database/migrations/' . $m['file'], $wave1Manifest['migrations']);
        $wave2Paths = array_map(static fn($f) => self::$root . '/apps/api/database/migrations/' . $f, $wave2Manifest['migrations']);
        $repository = new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(), 'migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, self::$capsule->getDatabaseManager(), new Filesystem);
        $scaffolding = array_map(static fn($file) => self::$root . '/apps/api/database/migrations/' . $file, [
            '2014_10_12_000000_create_users_table.php', '2014_10_12_100000_create_password_resets_table.php',
            '2019_08_19_000000_create_failed_jobs_table.php', '2019_12_14_000001_create_personal_access_tokens_table.php']);
        self::assertCount(4, $migrator->run($scaffolding));
        self::assertCount(31, $migrator->run($wave1Paths));
        self::assertCount(44, $migrator->run($wave2Paths));
        $db->table('member_number_sequences')->insert(['code' => 'MEPA_NATIONAL', 'last_value' => 0, 'created_at' => '2026-09-13 10:00:00.123456']);
        self::$ready = true;
    }

    // Deliberately find-or-create: unlike WaveTwoPhysicalTest, this class has no per-test
    // rollback wrapper (the concurrency-style tests need real commits visible to separate worker
    // processes), so fixture rows persist across test methods within this class - reference codes
    // like 'CONGREGATION' are legitimately reused test-to-test, not just call-to-call.
    private function lookup(string $table, string $code): int
    {
        $existing = self::$capsule->getConnection()->table($table)->where('code', $code)->value('id');
        if ($existing !== null) return (int) $existing;
        return self::$capsule->getConnection()->table($table)->insertGetId(['code' => $code, 'name' => 'Reconciliation fixture',
            'is_active' => 1, 'created_at' => '2026-09-13 10:00:00.123456']);
    }

    private array $unitTypeCache = [];

    private function unitTypeId(string $typeCode): int
    {
        return $this->unitTypeCache[$typeCode] ??= $this->lookup('organizational_unit_types', $typeCode);
    }

    private function unit(string $typeCode, ?int $parentId, string $code): int
    {
        return self::$capsule->getConnection()->table('organizational_units')->insertGetId(['public_id' => (string) Str::ulid(),
            'unit_type_id' => $this->unitTypeId($typeCode), 'parent_id' => $parentId, 'code' => $code,
            'name' => 'Reconciliation ' . $code, 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
    }

    private function person(string $name): int
    {
        $status = $this->lookup('person_statuses', 'RECON_' . strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('people')->insertGetId(['public_id' => (string) Str::ulid(),
            'full_name' => $name, 'birth_precision' => 'UNKNOWN', 'status_id' => $status, 'created_at' => '2026-09-13 10:00:00.123456']);
    }

    private function membership(int $personId, ?string $approvedAt = null): int
    {
        $status = $this->lookup('membership_statuses', 'RECON_' . strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('memberships')->insertGetId(['public_id' => (string) Str::ulid(),
            'person_id' => $personId, 'status_id' => $status, 'date_precision' => 'EXACT', 'approved_at' => $approvedAt,
            'origin' => 'APPROVED_ADMISSION', 'created_at' => '2026-09-13 10:00:00.123456']);
    }

    private function user(?int $personId = null): int
    {
        return self::$capsule->getConnection()->table('users')->insertGetId(['person_id' => $personId, 'account_kind' => $personId ? 'HUMAN' : 'SERVICE',
            'public_id' => (string) Str::ulid(), 'login' => 'recon_' . strtolower(bin2hex(random_bytes(6))),
            'password_hash' => 'x', 'status' => 'ACTIVE', 'mfa_required' => 0, 'created_at' => '2026-09-13 10:00:00.123456']);
    }

    private function workflowInstance(int $unitId): int
    {
        $workflow = self::$capsule->getConnection()->table('workflows')->insertGetId(['code' => 'RECON_WF_' . strtoupper(bin2hex(random_bytes(3))),
            'version' => 1, 'name' => 'Reconciliation', 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
        return self::$capsule->getConnection()->table('workflow_instances')->insertGetId(['public_id' => (string) Str::ulid(),
            'workflow_id' => $workflow, 'unit_id' => $unitId, 'requested_by' => $this->user(), 'status' => 'COMPLETED',
            'created_at' => '2026-09-13 10:00:00.123456']);
    }

    private function rejects(callable $write, int $expected): void
    {
        try { $write(); $this->fail('Database accepted invalid fixture'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertSame($expected, (int) ($e->errorInfo[1] ?? 0)); }
    }

    /** @return array<int,array{ok:bool,result?:array,error?:string}> */
    private function runWorkers(array $jobs): array
    {
        $env = getenv();
        $env['W2_HOST'] = self::$dbParts['host']; $env['W2_PORT'] = self::$dbParts['port'] ?? 3306;
        $env['W2_DB'] = self::$dbParts['dbname']; $env['W2_USER'] = getenv('WAVE2F_USER') ?: ''; $env['W2_PASS'] = getenv('WAVE2F_PASSWORD') ?: '';
        $tmp = sys_get_temp_dir() . '/wave2f_recon_' . bin2hex(random_bytes(8));
        mkdir($tmp);
        $procs = [];
        foreach ($jobs as $i => $job) {
            $ready = "$tmp/ready_$i"; $go = "$tmp/go"; $result = "$tmp/result_$i";
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/wave2-concurrency-worker.php',
                (string) $job['membershipId'], $job['issuedAt'], $ready, $go, $result, $job['origin'] ?? 'APPROVED_ADMISSION'],
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

    // ---- 5/6. Número Único adversarial: 50-worker stress ----

    public function test_fifty_worker_stress_batch(): void
    {
        $db = self::$capsule->getConnection();
        $before = (int) $db->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->value('last_value');
        $jobs = array_map(fn() => ['membershipId' => $this->membership($this->person('Recon stress'), '2026-09-13 09:00:00.000000'), 'issuedAt' => '2026-09-13'], range(1, 50));
        $results = $this->runWorkers($jobs);
        $ok = array_filter($results, fn($r) => $r['ok']);
        $this->assertCount(50, $ok, 'All 50 workers must succeed: ' . json_encode($results));
        $seq = array_map(fn($r) => $r['result']['sequence_value'], $ok);
        $numbers = array_map(fn($r) => $r['result']['number'], $ok);
        $this->assertCount(50, array_unique($seq), 'Zero duplicate sequence_value at 50-worker stress');
        $this->assertCount(50, array_unique($numbers), 'Zero duplicate number at 50-worker stress');
        sort($seq);
        $this->assertSame(range($before + 1, $before + 50), $seq, 'Contiguous, no gaps at 50-worker stress');
    }

    // ---- 6. Gap investigation: hard-kill mid-transaction must never consume a number ----

    public function test_connection_killed_mid_transaction_leaves_no_gap(): void
    {
        $db = self::$capsule->getConnection();
        $membershipId = $this->membership($this->person('Recon kill-mid-tx'), '2026-09-13 09:00:00.000000');
        $before = (int) $db->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->value('last_value');

        // Independent, raw PDO connection - simulates a worker process that acquires the locks,
        // performs the insert and the sequence UPDATE, and is then killed (connection dropped)
        // before COMMIT. This is the worst case the generator's transaction wrapper is meant to
        // survive: it never gets a chance to run its own rollback code at all.
        $pdo = new PDO(
            'mysql:host=' . self::$dbParts['host'] . ';port=' . (self::$dbParts['port'] ?? 3306) . ';dbname=' . self::$dbParts['dbname'],
            getenv('WAVE2F_USER') ?: '', getenv('WAVE2F_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->beginTransaction();
        $pdo->prepare('SELECT * FROM memberships WHERE id = ? FOR UPDATE')->execute([$membershipId]);
        $pdo->prepare('SELECT * FROM member_number_sequences WHERE code = ? FOR UPDATE')->execute(['MEPA_NATIONAL']);
        $next = $before + 1;
        $pdo->prepare('INSERT INTO member_numbers (membership_id, number, sequence_value, issued_year, issued_month, issued_at, origin, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$membershipId, sprintf('MEPA%02d%02d%06d', 26, 9, $next), $next, 2026, 9, '2026-09-13 09:00:00.000000', 'APPROVED_ADMISSION', '2026-09-13 09:00:00.000000']);
        // `last_value` is a reserved MySQL 8 window-function keyword and MUST be backtick-quoted
        // in hand-written raw SQL (Laravel's query builder and the Node-generated DDL both already
        // quote every identifier, so the production code path was never at risk from this).
        $pdo->prepare('UPDATE member_number_sequences SET `last_value` = ? WHERE code = ?')->execute([$next, 'MEPA_NATIONAL']);
        // No COMMIT. Drop the connection entirely (simulates the process being killed).
        $pdo = null;

        $afterSequence = (int) $db->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->value('last_value');
        $rowCount = (int) $db->table('member_numbers')->where('membership_id', $membershipId)->count();
        $this->assertSame($before, $afterSequence, 'A killed connection must not leave the sequence advanced');
        $this->assertSame(0, $rowCount, 'A killed connection must not leave a member_numbers row');

        // Confirm the number is still available for a normal, successful allocation afterwards.
        $generator = new MemberNumberGenerator($db);
        $result = $generator->generateFor($membershipId, new DateTimeImmutable('2026-09-13'));
        $this->assertSame($before + 1, $result['sequence_value'], 'The "lost" sequence value is available again, not skipped');
    }

    // ---- 6. Gap investigation: repeated racing for the SAME membership never partially consumes ----

    public function test_ten_workers_racing_same_membership_never_partially_consumes(): void
    {
        $db = self::$capsule->getConnection();
        $before = (int) $db->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->value('last_value');
        $membershipId = $this->membership($this->person('Recon same-membership race'), '2026-09-13 09:00:00.000000');
        $jobs = array_fill(0, 10, ['membershipId' => $membershipId, 'issuedAt' => '2026-09-13']);
        $results = $this->runWorkers($jobs);
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], 'Every racer must either succeed or the test would need to classify a real error: ' . json_encode($r));
        }
        $numbers = array_unique(array_map(fn($r) => $r['result']['number'], $results));
        $this->assertCount(1, $numbers, 'Ten racers for the same membership must all converge on one number');
        $this->assertSame(1, $db->table('member_numbers')->where('membership_id', $membershipId)->count());
        $after = (int) $db->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->value('last_value');
        $this->assertSame($before + 1, $after, 'Ten racers for the same membership must advance the sequence by exactly one, never ten');
    }

    // ---- 5. Exact boundary values from the brief ----

    public function test_december_to_january_boundary_exact_values(): void
    {
        $db = self::$capsule->getConnection();
        $db->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->update(['last_value' => 998]);
        $generator = new MemberNumberGenerator($db);
        $dec = $this->membership($this->person('Recon Dec'), '2026-12-30 09:00:00.000000');
        $this->assertSame('MEPA2612000999', $generator->generateFor($dec, new DateTimeImmutable('2026-12-30'))['number']);
        $jan = $this->membership($this->person('Recon Jan'), '2027-01-02 09:00:00.000000');
        $this->assertSame('MEPA2701001000', $generator->generateFor($jan, new DateTimeImmutable('2027-01-02'))['number'], 'Counter continues past 999 into 1000 across the year boundary, never resets');
    }

    // ---- 7. Membership: duplicate active membership is physically impossible ----

    public function test_second_membership_for_same_person_is_physically_rejected(): void
    {
        $db = self::$capsule->getConnection();
        $person = $this->person('Recon single membership');
        $this->membership($person);
        $this->rejects(fn() => $db->table('memberships')->insert(['public_id' => (string) Str::ulid(), 'person_id' => $person,
            'status_id' => $this->lookup('membership_statuses', 'RECON_DUP'), 'date_precision' => 'EXACT',
            'origin' => 'APPROVED_ADMISSION', 'created_at' => '2026-09-13 10:00:00.123456']), 1062);
    }

    // ---- 9/10. Transfers: adversarial scenarios ----

    public function test_transfer_rejects_same_origin_and_destination(): void
    {
        $db = self::$capsule->getConnection();
        $unit = $this->unit('CONGREGATION', null, 'RECON_SAME_UNIT');
        $membership = $this->membership($this->person('Recon same-unit transfer'), '2026-09-13 09:00:00.000000');
        $workflow = $this->workflowInstance($unit);
        $this->rejects(fn() => $db->table('transfers')->insert(['public_id' => (string) Str::ulid(), 'membership_id' => $membership,
            'origin_unit_id' => $unit, 'destination_unit_id' => $unit, 'requested_at' => '2026-09-13 09:00:00.000000',
            'status' => 'PENDING', 'workflow_instance_id' => $workflow, 'created_at' => '2026-09-13 10:00:00.123456']), 3819);
    }

    public function test_repeated_transfer_chain_preserves_identity_and_full_history(): void
    {
        $db = self::$capsule->getConnection();
        $peopleBefore = $db->table('people')->count();
        $a = $this->unit('CONGREGATION', null, 'RECON_CHAIN_A');
        $b = $this->unit('CONGREGATION', null, 'RECON_CHAIN_B');
        $c = $this->unit('CONGREGATION', null, 'RECON_CHAIN_C');
        $person = $this->person('Recon chain-transferred member');
        $membership = $this->membership($person, '2026-01-01 09:00:00.000000');

        $periodA = $db->table('membership_periods')->insertGetId(['membership_id' => $membership, 'congregation_id' => $a,
            'status_id' => $this->lookup('membership_statuses', 'RECON_CHAIN_1'), 'starts_at' => '2026-01-01 09:00:00.000000', 'created_at' => '2026-09-13 10:00:00.123456']);
        $db->table('transfers')->insert(['public_id' => (string) Str::ulid(), 'membership_id' => $membership, 'origin_unit_id' => $a,
            'destination_unit_id' => $b, 'requested_at' => '2026-03-01 00:00:00.000000', 'effective_at' => '2026-03-15 00:00:00.000000',
            'status' => 'COMPLETED', 'workflow_instance_id' => $this->workflowInstance($a), 'created_at' => '2026-09-13 10:00:00.123456']);
        $db->table('membership_periods')->where('id', $periodA)->update(['ends_at' => '2026-03-15 00:00:00.000000']);
        $periodB = $db->table('membership_periods')->insertGetId(['membership_id' => $membership, 'congregation_id' => $b,
            'status_id' => $this->lookup('membership_statuses', 'RECON_CHAIN_2'), 'starts_at' => '2026-03-15 00:00:00.000000', 'created_at' => '2026-09-13 10:00:00.123456']);

        $db->table('transfers')->insert(['public_id' => (string) Str::ulid(), 'membership_id' => $membership, 'origin_unit_id' => $b,
            'destination_unit_id' => $c, 'requested_at' => '2026-06-01 00:00:00.000000', 'effective_at' => '2026-06-15 00:00:00.000000',
            'status' => 'COMPLETED', 'workflow_instance_id' => $this->workflowInstance($b), 'created_at' => '2026-09-13 10:00:00.123456']);
        $db->table('membership_periods')->where('id', $periodB)->update(['ends_at' => '2026-06-15 00:00:00.000000']);
        $db->table('membership_periods')->insert(['membership_id' => $membership, 'congregation_id' => $c,
            'status_id' => $this->lookup('membership_statuses', 'RECON_CHAIN_3'), 'starts_at' => '2026-06-15 00:00:00.000000', 'created_at' => '2026-09-13 10:00:00.123456']);

        $this->assertSame($peopleBefore + 1, $db->table('people')->count(), 'Two consecutive transfers, still exactly one new Person (the original) created');
        $this->assertSame($person, $db->table('memberships')->where('id', $membership)->value('person_id'), 'Same person_id across the whole chain');
        $this->assertSame(3, $db->table('membership_periods')->where('membership_id', $membership)->count(), 'All three periods preserved (A, B, C)');
        $this->assertSame(2, $db->table('transfers')->where('membership_id', $membership)->count());
    }

    public function test_two_concurrent_transfers_for_the_same_membership_are_not_physically_guarded(): void
    {
        // FINDING candidate: unlike Número Único (protected by row-locking + UNIQUE) and unlike
        // memberships.person_id (UNIQUE), `transfers` has no constraint tying membership_id to
        // "at most one in-flight transfer". Two incompatible transfer requests for the same
        // membership, to two DIFFERENT destinations, both physically succeed at the DB layer.
        $db = self::$capsule->getConnection();
        $origin = $this->unit('CONGREGATION', null, 'RECON_RACE_ORIGIN');
        $destB = $this->unit('CONGREGATION', null, 'RECON_RACE_DEST_B');
        $destC = $this->unit('CONGREGATION', null, 'RECON_RACE_DEST_C');
        $membership = $this->membership($this->person('Recon racing transfer'), '2026-09-13 09:00:00.000000');
        $workflow = $this->workflowInstance($origin);

        $db->table('transfers')->insert(['public_id' => (string) Str::ulid(), 'membership_id' => $membership, 'origin_unit_id' => $origin,
            'destination_unit_id' => $destB, 'requested_at' => '2026-09-13 09:00:00.000000', 'status' => 'PENDING',
            'workflow_instance_id' => $workflow, 'created_at' => '2026-09-13 10:00:00.123456']);
        $db->table('transfers')->insert(['public_id' => (string) Str::ulid(), 'membership_id' => $membership, 'origin_unit_id' => $origin,
            'destination_unit_id' => $destC, 'requested_at' => '2026-09-13 09:00:01.000000', 'status' => 'PENDING',
            'workflow_instance_id' => $this->workflowInstance($origin), 'created_at' => '2026-09-13 10:00:00.123456']);

        $this->assertSame(2, $db->table('transfers')->where('membership_id', $membership)->count(),
            'Both incompatible PENDING transfers were accepted by the schema - no physical single-in-flight-transfer guard exists (tracked as a finding, not fixed during this audit)');
    }

    // ---- 11. Departments: four territorial levels at once ----

    public function test_department_definition_reused_across_all_four_territorial_levels(): void
    {
        $db = self::$capsule->getConnection();
        $province = $this->unit('PROVINCIAL_DIRECTION', null, 'RECON_PROV4L');
        $municipality = $this->unit('MUNICIPAL_DIRECTION', $province, 'RECON_MUN4L');
        $center = $this->unit('CENTER', $municipality, 'RECON_CENTER4L');
        $congregation = $this->unit('CONGREGATION', $center, 'RECON_CONG4L');

        $category = $this->lookup('department_categories', 'RECON_CAT4L');
        $department = $db->table('departments')->insertGetId(['category_id' => $category, 'code' => 'RECON_DEPT_4L',
            'name' => 'Reconciliation Department', 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);

        $instances = [];
        foreach ([$province, $municipality, $center, $congregation] as $unit) {
            $instances[] = $db->table('department_instances')->insertGetId(['public_id' => (string) Str::ulid(),
                'department_id' => $department, 'unit_id' => $unit, 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
        }

        $this->assertSame(1, $db->table('departments')->where('code', 'RECON_DEPT_4L')->count(), 'One definition for all four levels');
        $this->assertSame(4, $db->table('department_instances')->where('department_id', $department)->count());
        $this->assertSame([$department, $department, $department, $department],
            $db->table('department_instances')->whereIn('id', $instances)->pluck('department_id')->all());
    }

    // ---- 15. users adaptation: Person != User != Membership ----

    public function test_person_user_and_membership_are_structurally_independent(): void
    {
        $db = self::$capsule->getConnection();

        // Person with no User and no Membership at all - still a valid row.
        $bystander = $this->person('Recon bystander person');
        $this->assertSame(0, $db->table('users')->where('person_id', $bystander)->count());
        $this->assertSame(0, $db->table('memberships')->where('person_id', $bystander)->count());

        // A SERVICE account User with no Person at all.
        $serviceUser = $this->user(null);
        $this->assertNull($db->table('users')->where('id', $serviceUser)->value('person_id'));
        $this->assertSame('SERVICE', $db->table('users')->where('id', $serviceUser)->value('account_kind'));

        // A HUMAN User linked to a Person who has NO Membership - proves login capability does
        // not require church membership (e.g. staff/volunteer account).
        $staffPerson = $this->person('Recon staff person');
        $staffUser = $this->user($staffPerson);
        $this->assertSame(0, $db->table('memberships')->where('person_id', $staffPerson)->count());
        $this->assertSame($staffPerson, $db->table('users')->where('id', $staffUser)->value('person_id'));

        // No FK anywhere ties `users` to `memberships` directly - confirmed physically.
        $fk = $db->select("SELECT COUNT(*) c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND REFERENCED_TABLE_NAME='memberships'");
        $this->assertSame(0, (int) $fk[0]->c, 'users has no direct FK to memberships - membership status must never be inferred from login existence');

        // login is not, and cannot structurally become, the member_number.
        $login = $db->table('users')->where('id', $staffUser)->value('login');
        $this->assertDoesNotMatchRegularExpression('/^MEPA\d{10}$/', $login);
    }

    // ---- 13. Institutional queries with the brief's exact names ----

    private function findDirector(string $departmentCode, int $unitId): ?string
    {
        $row = self::$capsule->getConnection()->select(
            'SELECT p.full_name AS name FROM department_instances di
             JOIN departments d ON d.id = di.department_id AND d.code = ?
             JOIN department_posts dp ON dp.instance_id = di.id
             JOIN positions pos ON pos.id = dp.position_id AND pos.code = ?
             JOIN department_appointments da ON da.post_id = dp.id AND da.status = ? AND da.ends_at IS NULL
             JOIN people p ON p.id = da.person_id
             WHERE di.unit_id = ?',
            [$departmentCode, 'DIRECTOR_ESTATISTICAS', 'ACTIVE', $unitId]
        );
        return $row[0]->name ?? null;
    }

    public function test_institutional_queries_director_de_estatisticas(): void
    {
        $db = self::$capsule->getConnection();
        $province = $this->unit('PROVINCIAL_DIRECTION', null, 'RECON_CAALA_PROV');
        $caala = $this->unit('MUNICIPAL_DIRECTION', $province, 'RECON_DM_CAALA');
        $center = $this->unit('CENTER', $caala, 'RECON_CAALA_CENTER');
        $congA = $this->unit('CONGREGATION', $center, 'RECON_CONG_A');
        $congB = $this->unit('CONGREGATION', $center, 'RECON_CONG_B');

        $category = $this->lookup('department_categories', 'RECON_STATS_CAT');
        $department = $db->table('departments')->insertGetId(['category_id' => $category, 'code' => 'ESTATISTICAS',
            'name' => 'Estatística', 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
        $position = $this->lookup('positions', 'DIRECTOR_ESTATISTICAS');

        $instanceA = $db->table('department_instances')->insertGetId(['public_id' => (string) Str::ulid(), 'department_id' => $department,
            'unit_id' => $congA, 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
        $postA = $db->table('department_posts')->insertGetId(['public_id' => (string) Str::ulid(), 'instance_id' => $instanceA,
            'position_id' => $position, 'slot' => 1, 'occupancy_status' => 'FILLED', 'created_at' => '2026-09-13 10:00:00.123456']);
        $directorA = $this->person('Director de Estatísticas da Congregação A');
        $db->table('department_appointments')->insert(['public_id' => (string) Str::ulid(), 'post_id' => $postA, 'person_id' => $directorA,
            'appointment_kind' => 'SUBSTANTIVE', 'status' => 'ACTIVE', 'starts_at' => '2026-01-01 00:00:00.000000', 'created_at' => '2026-09-13 10:00:00.123456']);

        $instanceB = $db->table('department_instances')->insertGetId(['public_id' => (string) Str::ulid(), 'department_id' => $department,
            'unit_id' => $congB, 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
        $db->table('department_posts')->insert(['public_id' => (string) Str::ulid(), 'instance_id' => $instanceB,
            'position_id' => $position, 'slot' => 1, 'occupancy_status' => 'VACANT', 'created_at' => '2026-09-13 10:00:00.123456']);

        $instanceCaala = $db->table('department_instances')->insertGetId(['public_id' => (string) Str::ulid(), 'department_id' => $department,
            'unit_id' => $caala, 'status' => 'ACTIVE', 'created_at' => '2026-09-13 10:00:00.123456']);
        $postCaala = $db->table('department_posts')->insertGetId(['public_id' => (string) Str::ulid(), 'instance_id' => $instanceCaala,
            'position_id' => $position, 'slot' => 1, 'occupancy_status' => 'FILLED', 'created_at' => '2026-09-13 10:00:00.123456']);
        $directorCaala = $this->person('Director de Estatísticas da Direcção Municipal da Caála');
        $db->table('department_appointments')->insert(['public_id' => (string) Str::ulid(), 'post_id' => $postCaala, 'person_id' => $directorCaala,
            'appointment_kind' => 'SUBSTANTIVE', 'status' => 'ACTIVE', 'starts_at' => '2026-01-01 00:00:00.000000', 'created_at' => '2026-09-13 10:00:00.123456']);

        // 1. Quem e o Director de Estatisticas da Congregacao A?
        $this->assertSame('Director de Estatísticas da Congregação A', $this->findDirector('ESTATISTICAS', $congA));
        // 2. Quem e o Director de Estatisticas da Direccao Municipal da Caala?
        $this->assertSame('Director de Estatísticas da Direcção Municipal da Caála', $this->findDirector('ESTATISTICAS', $caala));

        // 3. Quais congregacoes da Provincia estao sem Director de Estatisticas?
        $lacking = $db->select(
            'WITH RECURSIVE descendants AS (
               SELECT id FROM organizational_units WHERE id = ?
               UNION ALL
               SELECT ou.id FROM organizational_units ou JOIN descendants d ON ou.parent_id = d.id
             )
             SELECT ou.id, ou.code FROM organizational_units ou
             JOIN organizational_unit_types ut ON ut.id = ou.unit_type_id AND ut.code = ?
             WHERE ou.id IN (SELECT id FROM descendants)
             AND NOT EXISTS (
               SELECT 1 FROM department_instances di
               JOIN departments d ON d.id = di.department_id AND d.code = ?
               JOIN department_posts dp ON dp.instance_id = di.id
               JOIN positions pos ON pos.id = dp.position_id AND pos.code = ?
               JOIN department_appointments da ON da.post_id = dp.id AND da.status = ? AND da.ends_at IS NULL
               WHERE di.unit_id = ou.id
             )',
            [$province, 'CONGREGATION', 'ESTATISTICAS', 'DIRECTOR_ESTATISTICAS', 'ACTIVE']
        );
        $this->assertCount(1, $lacking);
        $this->assertSame('RECON_CONG_B', $lacking[0]->code);
    }

    public static function tearDownAfterClass(): void
    {
        DB::clearResolvedInstances(); Schema::clearResolvedInstances();
    }
}
