<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\People\PeopleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * P0.5-I People / Families HTTP test base (Test Infrastructure V2 Wave 5 pool, TRUNCATE reset once per
 * process by HttpWaveFiveCase). After the reset the ADR-0017 controlled catalog is reinstalled exactly as
 * migration 2026_09_23_000004 installs it. Actors, units and People are synthetic fixtures; the key ring is
 * generated per process in the system temp directory (never in the repository).
 */
abstract class PeopleHttpCase extends HttpWaveFiveCase
{
    protected static ?string $keyring = null;

    protected function setUp(): void
    {
        parent::setUp();
        PeopleCatalog::install(DB::connection());
        if (self::$keyring === null) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-people-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($dir, 0700, true);
            self::$keyring = $dir . DIRECTORY_SEPARATOR . 'keyring.json';
            file_put_contents(self::$keyring, json_encode(['active_version' => 1, 'keys' => ['1' => ['encryption' => base64_encode(random_bytes(32)), 'blind_index' => base64_encode(random_bytes(32))]]], JSON_THROW_ON_ERROR));
        }
        config(['people.keyring_path' => self::$keyring]);
        if ((string) config('app.key') === '') {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    protected function unit(?int $parent = null): int
    {
        return $this->row('organizational_units', ['parent_id' => $parent]);
    }

    /** A signed-in staff account: one role granting $permissions on one UNIT scope. */
    protected function staff(array $permissions, ?int $unit = null, bool $descendants = false): array
    {
        $unit ??= $this->unit();
        $person = $this->row('people', ['full_name' => 'Staff ' . Str::random(6)]);
        $user = $this->row('users', ['person_id' => $person, 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $token = 'people-test-' . bin2hex(random_bytes(20));
        $session = $this->row('auth_sessions', ['user_id' => $user, 'token_hash' => hash('sha256', $token, true), 'expires_at' => now('UTC')->addHours(4)->format('Y-m-d H:i:s.u'), 'revoked_at' => null]);
        $role = $this->row('roles', ['is_active' => 1]);
        $scope = $this->row('scopes', ['unit_id' => $unit, 'include_descendants' => $descendants ? 1 : 0, 'scope_kind' => 'UNIT', 'department_instance_id' => null]);
        foreach ($permissions as $code) {
            $permission = (int) DB::table('permissions')->where('code', $code)->value('id');
            self::assertGreaterThan(0, $permission, 'catalog permission missing: ' . $code);
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        $link = $this->row('user_role_scopes', ['user_id' => $user, 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $user, 'status' => 'SYNTHETIC_READY', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null]);
        return compact('unit', 'person', 'user', 'session', 'token', 'role', 'scope', 'link');
    }

    /** Existing Person with a GENERAL context (person_unit_contexts ONBOARDING) at $unit. */
    protected function personAt(int $unit, array $values = []): array
    {
        $status = (int) DB::table('person_statuses')->where('code', $values['status'] ?? 'ACTIVE')->value('id');
        unset($values['status']);
        $id = $this->row('people', $values + ['full_name' => 'Pessoa ' . Str::random(8), 'status_id' => $status, 'birth_precision' => 'UNKNOWN', 'lock_version' => 0]);
        DB::table('person_unit_contexts')->insert(['person_id' => $id, 'unit_id' => $unit, 'context_kind' => 'ONBOARDING', 'status' => 'ACTIVE', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null, 'reason' => null, 'source_document_id' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        return ['id' => $id, 'public_id' => (string) DB::table('people')->where('id', $id)->value('public_id')];
    }

    protected function publicUnit(int $unit): string
    {
        return (string) DB::table('organizational_units')->where('id', $unit)->value('public_id');
    }

    protected function api(array $actor, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'])->json($method, '/api/v1/' . ltrim($uri, '/'), $body);
    }

    /** No numeric id, foreign key, member number or crypto material anywhere in a JSON payload. */
    protected function assertNoInternalFields(array $payload): void
    {
        array_walk_recursive($payload, function ($value, $key): void {
            if (is_string($key)) {
                self::assertNotSame('id', $key);
                self::assertFalse(str_ends_with($key, '_id') && $key !== 'public_id', 'internal key exposed: ' . $key);
                self::assertNotContains($key, ['member_number', 'key_version', 'value_ciphertext', 'value_blind_index', 'line1_ciphertext', 'number_ciphertext']);
            }
        });
    }

    protected function audits(string $action, ?string $entityType = null, ?int $entityId = null): array
    {
        $query = DB::table('audit_logs')->where('action', $action)->where('source', 'P05_PEOPLE');
        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }
        return $query->orderBy('id')->get()->all();
    }

    protected function notFoundBody(): array
    {
        return ['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']];
    }
}
