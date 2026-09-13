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

// P0.3.2-M1.1: real MySQL migration lifecycle and durable history proof.
final class WaveTwoTransferMigrationSafetyTest extends TestCase
{
    private static Manager $capsule;
    private static string $root;
    private static array $dbParts;
    private static bool $ready = false;
    private static Migrator $migrator;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 4);
        $dsn = getenv('M1SAFETY_DSN') ?: '';
        if (getenv('M1SAFETY_ALLOW_SYNTHETIC') !== '1' || !$dsn) {
            self::markTestSkipped('BLOCKED FOR EXECUTION: explicit authorized MySQL M1SAFETY_DSN and M1SAFETY_ALLOW_SYNTHETIC=1 required.');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) { [$key, $value] = explode('=', $part, 2); $parts[$key] = $value; }
        }
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/^mepa_m1safety_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '')
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            self::fail('Refusing tests: require loopback and an empty mepa_m1safety_test_* database.');
        }
        self::$dbParts = $parts;
        self::$capsule = new Manager;
        self::$capsule->addConnection(['driver' => 'mysql', 'host' => $parts['host'], 'port' => $parts['port'] ?? 3306,
            'database' => $parts['dbname'], 'username' => getenv('M1SAFETY_USER') ?: '', 'password' => getenv('M1SAFETY_PASSWORD') ?: '',
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
        self::assertCount(1, $migrator->run([$m1Path]));
        self::$migrator = $migrator;
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


    private function guardPath(): string
    {
        return self::$root . '/apps/api/database/migrations/2026_09_14_000001_wave2m1_add_transfers_open_guard.php';
    }

    private function state(bool $guard): array
    {
        $db = self::$capsule->getConnection();
        $columns = $db->select("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transfers' AND COLUMN_NAME IN ('closed_at','open_flag') ORDER BY ORDINAL_POSITION");
        $unique = $db->select("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transfers' AND INDEX_NAME='uq_transfers_membership_open' ORDER BY SEQ_IN_INDEX");
        $this->assertSame($guard ? ['closed_at','open_flag'] : ['closed_at'], array_column($columns, 'COLUMN_NAME'));
        $this->assertCount($guard ? 2 : 0, $unique);
        if ($guard) {
            $this->assertSame(['membership_id','open_flag'], array_column($unique, 'COLUMN_NAME'));
            foreach ($unique as $index) $this->assertSame(0, (int) $index->NON_UNIQUE);
            $this->assertSame('STORED GENERATED', $columns[1]->EXTRA);
        }
        return ['columns' => $columns, 'unique' => $unique];
    }

    private function rows(): array
    {
        return array_map(static function ($row) {
            $data = (array) $row;
            unset($data['open_flag']);
            return $data;
        }, self::$capsule->getConnection()->table('transfers')->orderBy('id')->get()->all());
    }

    private function insertTransfer(int $membership, int $origin, int $dest, int $wf, ?string $closed): int
    {
        return self::$capsule->getConnection()->table('transfers')->insertGetId([
            'public_id' => (string) Str::ulid(), 'membership_id' => $membership,
            'origin_unit_id' => $origin, 'destination_unit_id' => $dest,
            'workflow_instance_id' => $wf, 'requested_at' => '2026-01-01 01:02:03.123456',
            'created_at' => '2026-01-01 01:02:03.654321', 'closed_at' => $closed,
            'effective_at' => null, 'status' => 'SYNTHETIC_UNFIXED_VOCABULARY', 'lock_version' => 7,
        ]);
    }

    public function test_empty_database_guard_rollback_reapply(): void
    {
        $this->assertSame([], $this->rows());
        $this->state(true);
        $this->assertCount(1, self::$migrator->rollback([$this->guardPath()], ['step' => 1]));
        $this->state(false);
        $this->assertSame([], $this->rows());
        $this->assertCount(1, self::$migrator->run([$this->guardPath()]));
        $this->state(true);
    }

    public function test_populated_history_survives_rollback_and_reapply_exactly(): void
    {
        $db = self::$capsule->getConnection();
        $membership = $this->membershipRow();
        $origin = $this->unit('SAFETY_ORIGIN'); $dest = $this->unit('SAFETY_DEST');
        $wf = $this->workflowInstance($origin);
        $a = $this->insertTransfer($membership, $origin, $dest, $wf, '2026-02-03 04:05:06.123456');
        $b = $this->insertTransfer($membership, $origin, $dest, $wf, '2026-03-04 05:06:07.654321');
        $c = $this->insertTransfer($membership, $origin, $dest, $wf, null);
        $before = $this->rows();
        $checksum = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
        $states = ['A' => $this->state(true)];
        $this->assertCount(1, self::$migrator->rollback([$this->guardPath()], ['step' => 1]));
        $states['B'] = $this->state(false);
        $afterRollback = $this->rows();
        $this->assertSame($before, $this->rows(), 'Every persisted field, including status and microseconds, must survive');
        $this->assertSame($checksum, hash('sha256', json_encode($this->rows(), JSON_THROW_ON_ERROR)));
        $domain = require self::$root . '/apps/api/database/migrations/2026_09_14_000000_wave2m1_add_transfers_closed_at.php';
        $domain->down(); $domain->up(); // Durable migration itself also retains/adopts history.
        $this->assertSame($before, $this->rows());
        $this->assertCount(1, self::$migrator->run([$this->guardPath()]));
        $states['C'] = $this->state(true);
        $afterReapply = $this->rows();
        $this->assertSame($before, $this->rows());
        $flags = $db->table('transfers')->orderBy('id')->pluck('open_flag', 'id')->all();
        $this->assertNull($flags[$a]); $this->assertNull($flags[$b]); $this->assertSame(1, (int) $flags[$c]);
        $d = $this->insertTransfer($membership, $origin, $dest, $wf, '2026-04-01 00:00:00.000001');
        $this->assertNull($db->table('transfers')->where('id', $d)->value('open_flag'));
        try {
            $this->insertTransfer($membership, $origin, $dest, $wf, null);
            $this->fail('A second open transfer must fail');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
        file_put_contents(self::$root . '/docs/database/physical/m1_1_migration_safety_evidence.json', json_encode([
            'database' => $db->getDatabaseName(), 'mysql' => $db->selectOne('SELECT VERSION() AS version')->version,
            'states' => $states, 'before' => $before, 'after_rollback' => $afterRollback,
            'after_reapply' => $afterReapply, 'checksum_sha256' => $checksum,
            'flags' => $flags, 'verified' => true,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    public function test_invalid_open_duplicates_abort_before_technical_ddl_without_data_changes(): void
    {
        $db = self::$capsule->getConnection();
        $this->assertCount(1, self::$migrator->rollback([$this->guardPath()], ['step' => 1]));
        $membership = $this->membershipRow();
        $origin = $this->unit('INVALID_ORIGIN'); $dest = $this->unit('INVALID_DEST');
        $wf = $this->workflowInstance($origin);
        $this->insertTransfer($membership, $origin, $dest, $wf, null);
        $this->insertTransfer($membership, $origin, $dest, $wf, null);
        $before = $this->rows();
        $db->enableQueryLog(); $db->flushQueryLog();
        try {
            self::$migrator->run([$this->guardPath()]);
            $this->fail('Invalid data must abort reapply');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('TRANSFER_OPEN_GUARD_INVALID_DATA:', $e->getMessage());
        }
        foreach ($db->getQueryLog() as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(ALTER|UPDATE|DELETE|INSERT)\b/i', $query['query']);
        $db->disableQueryLog();
        $this->state(false);
        $this->assertSame($before, $this->rows());
        $this->assertNotContains(basename($this->guardPath(), '.php'), self::$migrator->getRepository()->getRan());
    }

    public static function tearDownAfterClass(): void
    {
        DB::clearResolvedInstances(); Schema::clearResolvedInstances();
    }
}
