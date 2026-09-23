<?php

declare(strict_types=1);

namespace Tests\Database\Support;

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

/**
 * P0.3.5-A1 physical test harness for Wave 5 (Academia), mirroring WaveFourCase's
 * shape exactly (same guard style, same migrate-through-history approach) without
 * modifying WaveFourCase.php/WaveThreeCase.php themselves (ADR-0014: V2 is additive).
 *
 * No Academia domain services exist yet (A2) -- this class only proves the physical
 * schema (FK/UNIQUE/CHECK/public_id), not application behavior.
 */
abstract class WaveFiveCase extends TestCase
{
    protected static Manager $capsule;
    protected static Migrator $migrator;
    protected static string $root;
    protected static array $catalog;
    protected static array $waveFivePaths;
    protected static bool $physical = false;

    public static function connect(): Manager
    {
        $dsn = getenv('WAVE5_DSN') ?: '';
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $parts[$k] = $v;
            }
        }
        if (
            getenv('WAVE5_ALLOW_SYNTHETIC') !== '1'
            || !str_starts_with($dsn, 'mysql:')
            || !preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '')
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
        ) {
            throw new RuntimeException('Explicit isolated Wave 5 synthetic DB required');
        }
        $m = new Manager();
        $m->addConnection([
            'driver' => 'mysql',
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 3306,
            'database' => $parts['dbname'],
            'username' => getenv('WAVE5_USER') ?: '',
            'password' => getenv('WAVE5_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'timezone' => '+00:00',
            'options' => [PDO::ATTR_EMULATE_PREPARES => false],
        ]);
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

        $repo = new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(), 'migrations');
        $repo->createRepository();
        self::$migrator = new Migrator($repo, self::$capsule->getDatabaseManager(), new Filesystem());
        $base = self::$root . '/apps/api/database/migrations/';

        $scaffold = [
            '2014_10_12_000000_create_users_table.php',
            '2014_10_12_100000_create_password_resets_table.php',
            '2019_08_19_000000_create_failed_jobs_table.php',
            '2019_12_14_000001_create_personal_access_tokens_table.php',
        ];
        self::assertCount(4, self::$migrator->run(array_map(fn ($f) => $base . $f, $scaffold)));

        foreach ([1, 2] as $wave) {
            $manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave' . $wave . '_manifest.json'), true);
            $files = array_map(fn ($e) => is_array($e) ? $e['file'] : $e, $manifest['migrations']);
            self::assertCount(count($files), self::$migrator->run(array_map(fn ($f) => $base . $f, $files)));
        }
        self::assertCount(2, self::$migrator->run([
            $base . '2026_09_14_000000_wave2m1_add_transfers_closed_at.php',
            $base . '2026_09_14_000001_wave2m1_add_transfers_open_guard.php',
        ]));

        $wave3 = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave3_manifest.json'), true);
        self::assertCount(count($wave3['migrations']), self::$migrator->run(array_map(fn ($f) => $base . $f, $wave3['migrations'])));

        $wave4 = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave4_manifest.json'), true);
        self::assertCount(count($wave4['migrations']), self::$migrator->run(array_map(fn ($f) => $base . $f, $wave4['migrations'])));

        $wave5 = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave5_migrations_manifest.json'), true);
        self::$waveFivePaths = array_map(fn ($f) => $base . $f, $wave5['migrations']);
        if (!static::$physical) {
            self::assertCount(count(self::$waveFivePaths), self::$migrator->run(self::$waveFivePaths));
        }

        self::$catalog = json_decode(file_get_contents(self::$root . '/docs/database/model_catalog.json'), true);
    }

    protected function db(): \Illuminate\Database\Connection
    {
        return self::$capsule->getConnection();
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Generic synthetic-row inserter driven by model_catalog.json column metadata
     * (same approach as WaveFourCase::row(), independent copy so WaveFourCase is
     * never touched). No Academia `status` column has a closed CHECK enum (D-11
     * pending), so an opaque literal satisfies every status/kind column here.
     */
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
            throw new RuntimeException('Unknown fixture table: ' . $table);
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
            if ($c['nullable']) {
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
            } elseif ($name === 'account_kind') {
                $value = 'HUMAN';
            } elseif ($name === 'date_precision' || $name === 'birth_precision') {
                $value = 'UNKNOWN';
            } elseif ($name === 'status' && $table === 'files') {
                $value = 'AVAILABLE';
            } elseif ($name === 'status' && $table === 'organizational_units') {
                $value = 'DRAFT';
            } elseif ($name === 'status' && $table === 'department_instances') {
                $value = 'ACTIVE';
            } elseif ($name === 'scope_kind') {
                $value = 'UNIT';
            } elseif ($name === 'occupancy_status') {
                $value = 'VACANT';
            } elseif ($name === 'appointment_kind') {
                $value = 'SUBSTANTIVE';
            } elseif ($name === 'eligibility_policy_version' || $name === 'checkin_policy' || $name === 'source_version') {
                $value = 'SYNTHETIC_V1';
            } elseif ($name === 'token_hash') {
                $value = random_bytes(32);
            } elseif (str_contains($type, 'DATETIME')) {
                $value = $name === 'ends_at' ? $this->now()->modify('+6 hours')->format('Y-m-d H:i:s.u') : $this->now()->modify('-2 hours')->format('Y-m-d H:i:s.u');
            } elseif ($type === 'DATE') {
                $value = '2026-09-15';
            } elseif ($type === 'JSON') {
                $value = '{}';
            } elseif (str_starts_with($type, 'BINARY')) {
                $value = random_bytes(32);
            } elseif (str_starts_with($type, 'DECIMAL')) {
                $value = 1;
            } elseif (str_contains($type, 'INT')) {
                $value = 1;
            } elseif ($allowed = $this->enumValuesFor($table, $name)) {
                $value = $allowed[0];
            } else {
                $value = 'SYNTHETIC_READY';
            }
            $row[$name] = $value;
        }
        // P0.5-I: explicit values for PHYSICAL delta columns absent from the catalog (e.g. people.birth_month)
        // are kept; values for columns that do not exist are still ignored, exactly as before.
        $physical = array_map(fn (object $c): string => $c->c, $this->db()->select('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]));
        foreach ($values as $name => $value) {
            if (!array_key_exists($name, $row) && in_array($name, $physical, true)) {
                $row[$name] = $value;
            }
        }
        foreach ($this->physicalOnlyRequired($table, $row) as $name => $value) {
            $row[$name] = $value;
        }
        return (int) $this->db()->table($table)->insertGetId($row);
    }

    /**
     * P0.5-I: approved deltas may add NOT NULL columns that model_catalog.json (the per-wave approved
     * model) does not list, e.g. relationship_types.semantics. Such columns are discovered from the live
     * schema and filled from their closed CHECK enum, so catalog-driven fixtures keep working.
     */
    private function physicalOnlyRequired(string $table, array $row): array
    {
        $out = [];
        foreach ($this->db()->select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'", [$table]) as $column) {
            if (!array_key_exists($column->c, $row) && ($allowed = $this->enumValuesFor($table, $column->c))) {
                $out[$column->c] = $allowed[0];
            }
        }
        return $out;
    }

    /**
     * Discovers, from the live schema (not hardcoded per-table knowledge), whether
     * `table`.`column` is constrained by a closed `column IN ('A','B',...)` CHECK
     * -- used so row() can satisfy pre-existing Wave 1-4 enum columns generically
     * instead of accumulating one hardcoded special-case per column encountered.
     * Cached per (table, column) for the life of the process.
     */
    private static array $enumCache = [];

    protected function enumValuesFor(string $table, string $column): ?array
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$enumCache)) {
            return self::$enumCache[$key];
        }
        $rows = $this->db()->select("
            SELECT cc.check_clause AS check_clause
            FROM information_schema.table_constraints tc
            JOIN information_schema.check_constraints cc
              ON cc.constraint_name = tc.constraint_name AND cc.constraint_schema = tc.constraint_schema
            WHERE tc.table_schema = DATABASE() AND tc.table_name = ? AND tc.constraint_type = 'CHECK'
        ", [$table]);
        $result = null;
        foreach ($rows as $row) {
            $clause = $row->check_clause;
            if (!preg_match('/`' . preg_quote($column, '/') . '`\s+in\s*\(([^)]+)\)/i', $clause, $m)) {
                continue;
            }
            // MySQL 8.4's information_schema.CHECK_CONSTRAINTS.CHECK_CLAUSE stores the
            // literal's quotes backslash-escaped (`_utf8mb4\'VALUE\'`), not plain quotes.
            preg_match_all("/_utf8mb4\\\\?'((?:[^'\\\\]|\\\\.)*?)\\\\?'/", $m[1], $literals);
            if ($literals[1] !== []) {
                $result = $literals[1];
                break;
            }
        }
        return self::$enumCache[$key] = $result;
    }
}
