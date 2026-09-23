<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

abstract class HttpWaveFiveCase extends TestCase
{
    private static bool $initialized = false;
    private static array $catalog = [];
    private static array $enumCache = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = (string) getenv('WAVE5_DSN');
        parse_str(str_replace(';', '&', substr($dsn, 6)), $parts);
        if (getenv('WAVE5_ALLOW_SYNTHETIC') !== '1' || !preg_match('/^mepa_wave5_test_[a-z0-9_]+$/D', $parts['dbname'] ?? '')) {
            throw new RuntimeException('Explicit isolated Wave 5 synthetic DB required');
        }
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $parts['host'] ?? '127.0.0.1',
            'database.connections.mysql.port' => $parts['port'] ?? 3306,
            'database.connections.mysql.database' => $parts['dbname'] ?? '',
            'database.connections.mysql.username' => getenv('WAVE5_USER') ?: 'root',
            'database.connections.mysql.password' => getenv('WAVE5_PASSWORD') ?: '',
            'auth_contract.active_user_statuses' => ['SYNTHETIC_READY'],
            'auth_contract.active_grant_statuses' => ['SYNTHETIC_READY'],
            'auth_contract.session_ttl_minutes' => 480,
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        if (!self::$initialized) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (DB::select('SELECT TABLE_NAME AS t FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_NAME != ?', [DB::getDatabaseName(), 'migrations']) as $row) {
                DB::statement('TRUNCATE TABLE `' . $row->t . '`');
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            self::$catalog = json_decode(file_get_contents(dirname(__DIR__, 5) . '/docs/database/model_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$initialized = true;
        }
    }

    protected function row(string $table, array $values = []): int
    {
        $definition = null;
        foreach (self::$catalog['tables'] as $candidate) {
            if ($candidate['name'] === $table) { $definition = $candidate; break; }
        }
        if (!$definition) throw new RuntimeException('Unknown fixture table: ' . $table);
        $row = [];
        foreach ($definition['columns'] as $column) {
            $name = $column['name'];
            if ($name === 'id') continue;
            if (array_key_exists($name, $values)) { $row[$name] = $values[$name]; continue; }
            if ($column['nullable']) continue;
            if (!empty($column['fk'])) { $row[$name] = $this->row($column['fk']); continue; }
            $type = $column['type'];
            if ($name === 'country_code') $value = 'ZZ';
            elseif ($name === 'public_id') $value = (string) Str::ulid();
            elseif (in_array($name, ['code', 'login', 'client_key', 'storage_key'], true)) $value = 'SYNTHETIC_' . Str::ulid();
            elseif ($name === 'account_kind') $value = 'HUMAN';
            elseif (in_array($name, ['date_precision', 'birth_precision'], true)) $value = 'UNKNOWN';
            elseif ($name === 'status' && $table === 'organizational_units') $value = 'DRAFT';
            elseif ($name === 'status' && $table === 'department_instances') $value = 'ACTIVE';
            elseif ($name === 'scope_kind') $value = 'UNIT';
            elseif ($name === 'occupancy_status') $value = 'VACANT';
            elseif ($name === 'appointment_kind') $value = 'SUBSTANTIVE';
            elseif (in_array($name, ['eligibility_policy_version', 'checkin_policy', 'source_version'], true)) $value = 'SYNTHETIC_V1';
            elseif ($name === 'token_hash') $value = random_bytes(32);
            elseif (str_contains($type, 'DATETIME')) $value = $name === 'ends_at' ? now('UTC')->addHours(6)->format('Y-m-d H:i:s.u') : now('UTC')->subHours(2)->format('Y-m-d H:i:s.u');
            elseif ($type === 'DATE') $value = '2026-09-15';
            elseif ($type === 'JSON') $value = '{}';
            elseif (str_starts_with($type, 'BINARY')) $value = random_bytes(32);
            elseif (str_starts_with($type, 'DECIMAL')) $value = 1;
            elseif (str_contains($type, 'INT')) $value = 1;
            elseif ($allowed = $this->enumValuesFor($table, $name)) $value = $allowed[0];
            else $value = 'SYNTHETIC_READY';
            $row[$name] = $value;
        }
        // P0.5-I: explicit values for PHYSICAL delta columns absent from the catalog (e.g. people.birth_month) are
        // kept; values for columns that do not exist are still ignored, exactly as before.
        $physical = array_map(fn (object $c): string => $c->c, DB::select('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]));
        foreach ($values as $name => $value) if (!array_key_exists($name, $row) && in_array($name, $physical, true)) $row[$name] = $value;
        // P0.5-I: physical-only NOT NULL columns of approved deltas (e.g. relationship_types.semantics).
        foreach (DB::select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'", [$table]) as $column) {
            if (!array_key_exists($column->c, $row) && ($allowed = $this->enumValuesFor($table, $column->c))) $row[$column->c] = $allowed[0];
        }
        return (int) DB::table($table)->insertGetId($row);
    }

    /** @return array{user:int,login:string,password:string} */
    protected function authAccount(array $permissions = ['ACADEMY_VIEW']): array
    {
        $unit = $this->row('organizational_units');
        $person = $this->row('people');
        $password = 'Correct-Horse-42!';
        $login = 'http_' . bin2hex(random_bytes(5));
        $user = $this->row('users', ['person_id' => $person, 'login' => $login, 'password_hash' => bcrypt($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $role = $this->row('roles', ['is_active' => 1]);
        $scope = $this->row('scopes', ['unit_id' => $unit, 'include_descendants' => 0, 'scope_kind' => 'UNIT']);
        foreach ($permissions as $code) {
            $permission = (int) DB::table('permissions')->where('code', $code)->value('id');
            if (!$permission) $permission = $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => 'ACADEMY']);
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        $this->row('user_role_scopes', ['user_id' => $user, 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $user, 'status' => 'SYNTHETIC_READY', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null]);
        return compact('user', 'login', 'password');
    }

    private function enumValuesFor(string $table, string $column): ?array
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$enumCache)) return self::$enumCache[$key];
        $result = null;
        foreach (DB::select("SELECT cc.check_clause AS check_clause FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc ON cc.constraint_name=tc.constraint_name AND cc.constraint_schema=tc.constraint_schema WHERE tc.table_schema=DATABASE() AND tc.table_name=? AND tc.constraint_type='CHECK'", [$table]) as $row) {
            if (!preg_match('/`' . preg_quote($column, '/') . '`\s+in\s*\(([^)]+)\)/i', $row->check_clause, $match)) continue;
            preg_match_all("/_utf8mb4\\\\?'((?:[^'\\\\]|\\\\.)*?)\\\\?'/", $match[1], $literals);
            if ($literals[1] !== []) { $result = $literals[1]; break; }
        }
        return self::$enumCache[$key] = $result;
    }
}
