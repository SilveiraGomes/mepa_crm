<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use App\Domain\WaveFour\DomainClock;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

// Reuses the polymorphic audit_logs table (no academy_audit_logs). Every record stores actor,
// authenticated session, unit, entity, before/after metadata, reason and a correlation id.
// An ADMIN_OVERRIDE decision additionally writes a dedicated ACADEMY_ADMIN_OVERRIDE row, so every
// use of the override is queryable on its own and the operation row says which authority it used.
final class DatabaseAcademyAudit implements AcademyAuditWriter
{
    public const SOURCE = 'WAVE5_DOMAIN';

    public function __construct(private Connection $db)
    {
    }

    public function record(AcademyDecision $decision, int $unitId, string $action, string $entityType, int $entityId, ?array $before, ?array $after, ?string $reason): void
    {
        $at = DomainClock::now($this->db)->format('Y-m-d H:i:s.u');
        $correlation = (string) Str::ulid();
        $authority = $decision->isOverride() ? ['authority' => AcademyDecision::ADMIN_OVERRIDE, 'permission' => $decision->permission] : ['authority' => AcademyDecision::DIRECT];
        $this->insert($decision, $unitId, $action, $entityType, $entityId, $before, ($after ?? []) + $authority, $decision->isOverride() ? $decision->overrideReason : $reason, $at, $correlation);
        if ($decision->isOverride()) {
            $this->insert($decision, $unitId, 'ACADEMY_ADMIN_OVERRIDE', $entityType, $entityId, null, ['operation_action' => $action], $decision->overrideReason, $at, $correlation);
        }
    }

    private function insert(AcademyDecision $decision, int $unitId, string $action, string $entityType, int $entityId, ?array $before, array $after, ?string $reason, string $at, string $correlation): void
    {
        $this->db->table('audit_logs')->insert([
            'actor_id' => $decision->actor,
            'actor_kind' => 'USER',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'unit_id' => $unitId,
            'before_metadata' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_metadata' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'source' => self::SOURCE,
            'occurred_at' => $at,
            'session_id' => $decision->session,
            'correlation_id' => $correlation,
            'created_at' => $at,
        ]);
    }
}
