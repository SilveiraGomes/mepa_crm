<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * ADR 0021 D19: finance audit rows in the existing audit_logs, inside the business transaction. unit_id = the
 * authorising (owner) unit of the stage; the counterpart unit goes in the metadata as a public id. Metadata carries
 * public identifiers, codes and states only: never a primary key, never a person's data. One correlation id per
 * operation; an interunit transfer carries the transfer public_id in every stage so origin and destination correlate.
 */
final class FinanceAudit
{
    private const FORBIDDEN_KEYS = ['id', 'unit_id', 'person_id', 'party_id', 'account_id', 'document_id', 'entry_id', 'transfer_id', 'full_name', 'name', 'display_name', 'title', 'account_number', 'bank_account', 'external_name'];

    public static function correlation(): string
    {
        return (string) Str::ulid();
    }

    /** @param array<string, mixed> $after */
    public static function write(Connection $db, int $actor, string $action, string $entityType, int $entityId, int $unitId, string $correlation, array $after = [], ?string $reason = null, ?int $session = null): void
    {
        self::assertSafe($after);
        $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
        $db->table('audit_logs')->insert([
            'actor_id' => $actor, 'actor_kind' => 'USER', 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'unit_id' => $unitId, 'department_instance_id' => null, 'before_metadata' => null,
            'after_metadata' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'source' => FinanceCatalog::AUDIT_SOURCE, 'occurred_at' => $now, 'session_id' => $session, 'ip_hash' => null,
            'correlation_id' => $correlation, 'created_at' => $now, 'lock_version' => 0,
        ]);
    }

    private static function assertSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new FinanceError('INVARIANT_VIOLATION', [], ['reason' => 'audit_metadata_key', 'key' => $key]);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
