<?php

declare (strict_types=1);
namespace App\Domain\Events;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
// Every sensitive write revalidates the current actor under the users revocation anchor.
final class EventAccess
{
    public function __construct(private Connection $db, private EventPolicy $policy)
    {
    }
    public function authorize(int $actor, int $authSession, int $unit, string $permission, DateTimeImmutable $now): void
    {
        $t = $now->format('Y-m-d H:i:s.u');
        $u = $this->db->table('users')->where('id', $actor)->sharedLock()->first();
        if (!$u || $u->archived_at !== null || !$this->policy->permits('users', $u->status)) {
            throw new EventError('ACTOR_NOT_AUTHORIZED');
        }
        $session = $this->db->table('auth_sessions')->where('id', $authSession)->sharedLock()->first();
        if (!$session || (int) $session->user_id !== $actor || $session->revoked_at !== null || $session->expires_at <= $t) {
            throw new EventError('ACTOR_NOT_AUTHORIZED');
        }
        $links = $this->db->table('user_role_scopes')->where('user_id', $actor)->orderBy('id')->sharedLock()->get();
        foreach ($links as $link) {
            if (!$this->policy->permits('auth_grants', $link->status) || $link->starts_at > $t || $link->ends_at !== null && $link->ends_at <= $t) {
                continue;
            }
            $scope = $this->db->table('scopes')->where('id', $link->scope_id)->sharedLock()->first();
            // Minimal explicit UNIT scope. Department-only grants do not authorize generic event administration.
            if (!$scope || $scope->scope_kind !== 'UNIT' || $scope->department_instance_id !== null) {
                continue;
            }
            $covers = (int) $scope->unit_id === $unit;
            if (!$covers && (int) $scope->include_descendants === 1) {
                $seen = [];
                $cursor = $unit;
                while ($cursor) {
                    if (isset($seen[$cursor])) {
                        throw new EventError('CONTEXT_MISMATCH');
                    }
                    $seen[$cursor] = true;
                    if ($cursor === (int) $scope->unit_id) {
                        $covers = true;
                        break;
                    }
                    $parent = $this->db->table('organizational_units')->where('id', $cursor)->sharedLock()->value('parent_id');
                    $cursor = (int) $parent;
                }
            }
            if (!$covers) {
                continue;
            }
            $role = $this->db->table('roles')->where('id', $link->role_id)->sharedLock()->first();
            if (!$role || (int) $role->is_active !== 1) {
                continue;
            }
            $permissions = $this->db->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $link->role_id)->where('p.code', $permission)->where('p.data_type', 'EVENTS')->where('p.action', $permission)->sharedLock()->get();
            if ($permissions->isNotEmpty()) {
                return;
            }
        }
        throw new EventError('ACTOR_NOT_AUTHORIZED');
    }
    public function audit(int $actor, int $authSession, int $unit, string $action, string $entity, int $id, string $reason, DateTimeImmutable $now, ?string $correlation = null, ?int $person = null): void
    {
        $this->db->table('audit_logs')->insert(['actor_id' => $actor, 'actor_kind' => 'USER', 'action' => $action, 'entity_type' => $entity, 'entity_id' => $id, 'unit_id' => $unit, 'reason' => $reason, 'source' => 'WAVE3_DOMAIN', 'occurred_at' => $now->format('Y-m-d H:i:s.u'), 'session_id' => $authSession, 'correlation_id' => $correlation ?? (string) Str::ulid(), 'after_metadata' => $person === null ? null : json_encode(['person_id' => $person, 'reason_code' => $reason, 'correlation_id' => $correlation], JSON_THROW_ON_ERROR), 'created_at' => $now->format('Y-m-d H:i:s.u')]);
    }
}
