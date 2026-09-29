<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

// Writes Physical audit rows into audit_logs inside the business transaction (a failed audit rolls the operation
// back). unit_id is ALWAYS the unit of the institutional context that authorized the operation (the active link's
// unit; for link operations the unit of the link changed), never a property of the location. Metadata carries
// codes, flags, versions and internal ids of audited rows only: never address lines, locality, owner names,
// ciphertext or keys.
final class PhysicalAudit
{
    public const SOURCE = 'P07_PHYSICAL';

    private const FORBIDDEN_KEYS = ['line1', 'locality', 'owner_name_external', 'full_name', 'display_name', 'ciphertext', 'line1_ciphertext', 'key', 'secret', 'token', 'plaintext'];

    public function __construct(private Connection $db)
    {
    }

    public function record(TerritorialActor $actor, int $unit, string $action, string $entityType, int $entityId, ?array $before, array $after, ?string $reason = null, ?string $correlation = null): string
    {
        foreach ([$before ?? [], $after] as $metadata) {
            $this->assertSafe($metadata);
        }
        $at = (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
        $correlation ??= (string) Str::ulid();
        $this->db->table('audit_logs')->insert([
            'actor_id' => $actor->user, 'actor_kind' => 'USER', 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'unit_id' => $unit,
            'before_metadata' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_metadata' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $reason,
            'source' => self::SOURCE, 'occurred_at' => $at, 'session_id' => $actor->session,
            'correlation_id' => $correlation, 'created_at' => $at, 'lock_version' => 0,
        ]);
        return $correlation;
    }

    private function assertSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['reason' => 'audit_metadata_key']);
            }
            if (is_array($value)) {
                $this->assertSafe($value);
            }
        }
    }
}
