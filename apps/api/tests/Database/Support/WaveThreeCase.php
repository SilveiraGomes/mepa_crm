<?php

declare (strict_types=1);
namespace Tests\Database\Support;

use App\Domain\Events\EventPolicy;
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
abstract class WaveThreeCase extends TestCase
{
    protected static Manager $capsule;
    protected static Migrator $migrator;
    protected static string $root;
    protected static array $wavePaths;
    private static array $catalog;
    protected static bool $physical = false;
    public static function connect(): Manager
    {
        $dsn = getenv('WAVE3_DSN') ?: '';
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $parts[$k] = $v;
            }
        }
        if (getenv('WAVE3_ALLOW_SYNTHETIC') !== '1' || !str_starts_with($dsn, 'mysql:') || !preg_match('/^mepa_wave3_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '') || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Explicit isolated Wave 3 synthetic DB required');
        }
        $m = new Manager();
        $m->addConnection(['driver' => 'mysql', 'host' => $parts['host'], 'port' => $parts['port'] ?? 3306, 'database' => $parts['dbname'], 'username' => getenv('WAVE3_USER') ?: '', 'password' => getenv('WAVE3_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00', 'options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
        return $m;
    }
    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 5);
        self::$capsule = self::connect();
        $db = self::$capsule->getConnection();
        self::assertSame([], $db->select('SHOW TABLES'), 'Virgin dedicated DB required');
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        if (static::$physical) {
            $env = getenv();
            $env['DB_CAPABILITIES_DSN'] = getenv('WAVE3_DSN');
            $env['DB_CAPABILITIES_USER'] = getenv('WAVE3_USER');
            $env['DB_CAPABILITIES_PASSWORD'] = getenv('WAVE3_PASSWORD') ?: '';
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/check-database-capabilities.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$root, $env);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err);
            $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('READ_ONLY_PREFLIGHT_PASS', $result['status']);
            file_put_contents(self::$root . '/docs/database/physical/wave3_preflight.json', json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL);
        }
        $repo = new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(), 'migrations');
        $repo->createRepository();
        self::$migrator = new Migrator($repo, self::$capsule->getDatabaseManager(), new Filesystem());
        $base = self::$root . '/apps/api/database/migrations/';
        $scaffold = ['2014_10_12_000000_create_users_table.php', '2014_10_12_100000_create_password_resets_table.php', '2019_08_19_000000_create_failed_jobs_table.php', '2019_12_14_000001_create_personal_access_tokens_table.php'];
        self::assertCount(4, self::$migrator->run(array_map(fn($f) => $base . $f, $scaffold)));
        foreach ([1, 2] as $wave) {
            $manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave' . $wave . '_manifest.json'), true);
            $files = array_map(fn($e) => is_array($e) ? $e['file'] : $e, $manifest['migrations']);
            self::assertCount(count($files), self::$migrator->run(array_map(fn($f) => $base . $f, $files)));
        }
        self::assertCount(2, self::$migrator->run([$base . '2026_09_14_000000_wave2m1_add_transfers_closed_at.php', $base . '2026_09_14_000001_wave2m1_add_transfers_open_guard.php']));
        $manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave3_manifest.json'), true);
        self::$wavePaths = array_map(fn($f) => $base . $f, $manifest['migrations']);
        if (!static::$physical) {
            self::assertCount(32, self::$migrator->run(self::$wavePaths));
        }
        self::$catalog = json_decode(file_get_contents(self::$root . '/docs/database/model_catalog.json'), true);
    }
    protected function db(): \Illuminate\Database\Connection
    {
        return self::$capsule->getConnection();
    }
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15 12:00:00.123456', new \DateTimeZone('UTC'));
    }
    public static function policy(): EventPolicy
    {
        $s = [];
        foreach (['users', 'auth_grants', 'devices', 'events', 'sessions', 'registrations', 'lists', 'templates', 'composition', 'ministerial', 'departments', 'memberships'] as $kind) {
            $s[$kind] = ['SYNTHETIC_READY'];
        }
        $s['credentials'] = ['ISSUED'];
        // A lookup of configured synthetic types is server-side, never supplied by QR/request.
        $types = self::connect()->getConnection()->table('credential_types')->where('code', 'like', 'SYNTHETIC_%')->pluck('id')->map(fn($v) => (int) $v)->all();
        return new EventPolicy('SYNTHETIC_V1', $s, true, $types ?: [PHP_INT_MAX]);
    }
    protected function row(string $table, array $values = []): int
    {
        $t = null;
        foreach (self::$catalog['tables'] as $candidate) {
            if ($candidate['name'] === $table) {
                $t = $candidate;
                break;
            }
        }
        if (!$t) {
            throw new RuntimeException('Unknown fixture table');
        }
        $row = [];
        foreach ($t['columns'] as $c) {
            $name = $c['name'];
            if ($name === 'id') {
                continue;
            }
            if (array_key_exists($name, $values)) {
                $row[$name] = $values[$name];
                continue;
            }
            if ($c['nullable'] || $c['default'] === '0') {
                continue;
            }
            if (!empty($c['fk'])) {
                $row[$name] = $this->row($c['fk']);
                continue;
            }
            $type = $c['type'];
            if ($name === 'country_code') {
                $value = 'ZZ';
            } elseif ($name === 'public_id') {
                $value = (string) Str::ulid();
            } elseif ($name === 'code' || $name === 'login' || $name === 'client_key' || $name === 'storage_key') {
                $value = 'SYNTHETIC_' . Str::ulid();
            } elseif ($name === 'birth_precision' || $name === 'date_precision') {
                $value = 'UNKNOWN';
            } elseif ($name === 'account_kind') {
                $value = 'HUMAN';
            } elseif ($name === 'scope_kind') {
                $value = 'UNIT';
            } elseif ($name === 'occupancy_status') {
                $value = 'VACANT';
            } elseif ($name === 'appointment_kind') {
                $value = 'SUBSTANTIVE';
            } elseif ($name === 'status' && $table === 'organizational_units') {
                $value = 'DRAFT';
            } elseif ($name === 'status' && $table === 'department_instances') {
                $value = 'ACTIVE';
            } elseif ($name === 'status' && $table === 'files') {
                $value = 'AVAILABLE';
            } elseif ($name === 'origin' && $table === 'member_numbers') {
                $value = 'APPROVED_ADMISSION';
            } elseif ($name === 'eligibility_policy_version' || $name === 'checkin_policy') {
                $value = 'SYNTHETIC_V1';
            } elseif (str_contains($type, 'DATETIME')) {
                $value = $name === 'ends_at' || $name === 'expires_at' ? '2026-09-15 18:00:00.123456' : '2026-09-15 10:00:00.123456';
            } elseif ($type === 'DATE') {
                $value = '2026-09-15';
            } elseif ($type === 'JSON') {
                $value = '{}';
            } elseif (str_starts_with($type, 'BINARY')) {
                $value = random_bytes(32);
            } elseif (str_contains($type, 'INT')) {
                $value = 1;
            } elseif ($name === 'number') {
                $value = 'MEPA2609000001';
            } elseif ($name === 'color' || $name === 'text_color') {
                $value = '#000000';
            } else {
                $value = 'SYNTHETIC_READY';
            }
            $row[$name] = $value;
        }
        return (int) $this->db()->table($table)->insertGetId($row);
    }
    protected function fixture(bool $invite = true): array
    {
        $unit = $this->row('organizational_units');
        $person = $this->row('people');
        $actor = $this->row('users', ['person_id' => $this->row('people')]);
        $auth = $this->row('auth_sessions', ['user_id' => $actor]);
        $device = $this->row('devices', ['unit_id' => $unit, 'registered_by' => $actor]);
        $scope = $this->row('scopes', ['unit_id' => $unit, 'include_descendants' => 0]);
        $role = $this->row('roles');
        foreach (['CHECKIN', 'CHECKIN_MANUAL', 'CREDENTIAL_ISSUE', 'CREDENTIAL_REVOKE', 'INVITATION_WRITE'] as $code) {
            $permission = (int) $this->db()->table('permissions')->where('code', $code)->value('id');
            if (!$permission) {
                $permission = $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => 'EVENTS']);
            }
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        $this->row('user_role_scopes', ['user_id' => $actor, 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $actor]);
        $event = $this->row('events', ['owner_unit_id' => $unit]);
        $session = $this->row('event_sessions', ['event_id' => $event]);
        $list = $this->row('event_invitation_lists', ['event_id' => $event, 'selection_at' => '2026-09-15 11:00:00.123456']);
        $type = $this->row('credential_types', ['requires_member_number' => 1, 'requires_formal_approval' => 1, 'default_validity_days' => 365]);
        $template = $this->row('credential_templates', ['credential_type_id' => $type, 'file_id' => $this->row('files', ['created_by' => $actor, 'owner_unit_id' => $unit])]);
        $f = compact('unit', 'person', 'actor', 'auth', 'device', 'event', 'session', 'list', 'type', 'template');
        if ($invite) {
            $s = new \App\Domain\Events\InvitationService($this->db(), self::policy(), ['SYNTHETIC_INCLUDE' => 'UNION', 'SYNTHETIC_EXCLUDE' => 'SUBTRACT'], ['SYNTHETIC_YES', 'SYNTHETIC_NO']);
            $result = $s->freeze($actor, $auth, $list, [$person], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
            $f['registration'] = $result[$person]['registration_id'];
        } else {
            $f['registration'] = $this->row('event_registrations', ['event_id' => $event, 'person_id' => $person]);
        }
        return $f;
    }
    protected function scan(array $f, string $token, string $key = 'scan', bool $manual = false): array
    {
        return (new \App\Domain\Events\CheckinService($this->db(), self::policy()))->scan($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['person'], $token, $key, $this->now(), $manual);
    }
    protected function eventCredential(array $f): array
    {
        return (new \App\Domain\Events\CredentialService($this->db(), self::policy()))->issueEvent($f['actor'], $f['auth'], $f['registration'], $this->now(), $this->now()->modify('+3 hours'));
    }
    protected function permanent(array &$f): array
    {
        if (!isset($f['membership'])) {
            $status = (int) $this->db()->table('membership_statuses')->where('code', 'SYNTHETIC_READY')->value('id');
            if (!$status) {
                $status = $this->row('membership_statuses', ['code' => 'SYNTHETIC_READY']);
            }
            $f['membership'] = $this->row('memberships', ['person_id' => $f['person'], 'status_id' => $status, 'approved_by' => $f['actor'], 'approved_at' => '2026-09-15 10:00:00.123456']);
            $this->row('membership_periods', ['membership_id' => $f['membership'], 'congregation_id' => $f['unit'], 'status_id' => $status, 'ends_at' => null]);
            $sequence = (int) $this->db()->table('member_numbers')->max('sequence_value') + 1;
            $f['number'] = $this->row('member_numbers', ['membership_id' => $f['membership'], 'number' => sprintf('MEPA2609%06d', $sequence), 'sequence_value' => $sequence, 'issued_year' => 2026, 'issued_month' => 9]);
        }
        return (new \App\Domain\Events\CredentialService($this->db(), self::policy()))->issuePermanent($f['actor'], $f['auth'], $f['unit'], $f['person'], $f['type'], $f['template'], $f['number'], $this->now(), $this->now()->modify('+100 days'));
    }
}
