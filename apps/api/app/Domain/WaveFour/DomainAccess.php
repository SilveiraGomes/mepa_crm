<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

use App\Domain\Events\EventPolicy;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
// Every sensitive write revalidates the current actor under the users revocation anchor.
final class DomainAccess
{
    public function __construct(private Connection $db, private EventPolicy $policy)
    {
    }
    public function authorize(int $actor, int $authSession, int $unit, string $permission, ?DateTimeImmutable $now, string $dataType): DateTimeImmutable
    {
        return $this->authorizeMany($actor, $authSession, $unit, [$permission], $now, $dataType);
    }
    public function authorizeMany(int $actor, int $authSession, int $unit, array $permissions, ?DateTimeImmutable $now, string $dataType): DateTimeImmutable
    {
        $permissions = array_values(array_unique($permissions));
        if (!$permissions) throw new DomainError('ACTOR_NOT_AUTHORIZED');
        $u = $this->db->table('users')->where('id', $actor)->sharedLock()->first();
        $session = $this->db->table('auth_sessions')->where('id', $authSession)->sharedLock()->first();
        $links = $this->db->table('user_role_scopes')->where('user_id', $actor)->orderBy('id')->sharedLock()->get();
        $candidates = [];
        foreach ($links as $link) {
            $scope = $this->db->table('scopes')->where('id', $link->scope_id)->sharedLock()->first();
            if (!$scope || $scope->scope_kind !== 'UNIT' || $scope->department_instance_id !== null) {
                continue;
            }
            $covers = (int) $scope->unit_id === $unit;
            if (!$covers && $dataType !== 'CHILDREN' && (int) $scope->include_descendants === 1) {
                $seen = [];
                $cursor = $unit;
                while ($cursor) {
                    if (isset($seen[$cursor])) throw new DomainError('CONTEXT_MISMATCH');
                    $seen[$cursor] = true;
                    if ($cursor === (int) $scope->unit_id) {
                        $covers = true;
                        break;
                    }
                    $cursor = (int) $this->db->table('organizational_units')->where('id', $cursor)->sharedLock()->value('parent_id');
                }
            }
            if (!$covers) continue;
            $role = $this->db->table('roles')->where('id', $link->role_id)->sharedLock()->first();
            $rows = $this->db->table('role_permissions as rp')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $link->role_id)
                ->whereIn('p.code', $permissions)
                ->where('p.data_type', $dataType)
                ->whereColumn('p.action', 'p.code')
                ->sharedLock()->get(['p.code']);
            $permitted = [];
            foreach ($rows as $row) $permitted[$row->code] = true;
            $candidates[] = [$link, $role, $permitted];
        }
        // Every lock and permission query above may wait. Sample one authoritative
        // UTC_TIMESTAMP(6) after all of them, then decide all permissions together.
        $now = $now ?? DomainClock::now($this->db);
        $t = $now->format('Y-m-d H:i:s.u');
        if (!$u || $u->archived_at !== null || !$this->policy->permits('users', $u->status) || !$session || (int) $session->user_id !== $actor || $session->revoked_at !== null || $session->expires_at <= $t) {
            throw new DomainError('ACTOR_NOT_AUTHORIZED');
        }
        foreach ($permissions as $permission) {
            $allowed = false;
            foreach ($candidates as [$link, $role, $permitted]) {
                if ($role && (int) $role->is_active === 1 && isset($permitted[$permission]) && $this->policy->permits('auth_grants', $link->status) && $link->starts_at <= $t && ($link->ends_at === null || $link->ends_at > $t)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) throw new DomainError('ACTOR_NOT_AUTHORIZED');
        }
        return $now;
    }
    public function audit(int $actor, int $authSession, int $unit, string $action, string $entity, int $id, string $reason, DateTimeImmutable $now, ?string $correlation = null, ?int $person = null, array $metadata = []): void
    {
        $this->db->table('audit_logs')->insert(['actor_id' => $actor, 'actor_kind' => 'USER', 'action' => $action, 'entity_type' => $entity, 'entity_id' => $id, 'unit_id' => $unit, 'reason' => $reason, 'source' => 'WAVE4_DOMAIN', 'occurred_at' => $now->format('Y-m-d H:i:s.u'), 'session_id' => $authSession, 'correlation_id' => $correlation ?? (string) Str::ulid(), 'after_metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : ($person === null ? null : json_encode(['person_id' => $person, 'reason_code' => $reason, 'correlation_id' => $correlation], JSON_THROW_ON_ERROR)), 'created_at' => $now->format('Y-m-d H:i:s.u')]);
    }
}
