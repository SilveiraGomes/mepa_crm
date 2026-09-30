<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * ADR 0021 D19: finance audit rows in the existing audit_logs. unit_id = the authorising (owner) unit; metadata carries
 * public identifiers and state only, never monetary values of people. One correlation id per operation.
 */
final class FinanceAudit
{
    public static function correlation(): string
    {
        return (string) Str::ulid();
    }

    /** @param array<string, scalar|null> $after */
    public static function write(Connection $db, int $actor, string $action, string $entityType, int $entityId, int $unitId, string $correlation, array $after = [], ?string $reason = null): void
    {
        $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
        $db->table('audit_logs')->insert([
            'actor_id' => $actor, 'actor_kind' => 'USER', 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'unit_id' => $unitId, 'department_instance_id' => null, 'before_metadata' => null,
            'after_metadata' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'source' => FinanceCatalog::AUDIT_SOURCE, 'occurred_at' => $now, 'session_id' => null, 'ip_hash' => null,
            'correlation_id' => $correlation, 'created_at' => $now, 'lock_version' => 0,
        ]);
    }
}
