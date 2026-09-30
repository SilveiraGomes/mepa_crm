<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

// Writes Membership audit rows into audit_logs inside the business transaction (a failed audit rolls the operation
// back). source = P09_MEMBERSHIP; unit_id is ALWAYS the unit that authorized the operation (D05/D06), the other units
// go in the metadata as public ids (D11). Allowed metadata: public ids (membership, Person, unit, transfer, document),
// states before/after, the official number, source_system + normalized legacy value, reason codes. Never: People
// data (names, birth, contacts), document titles, or primary keys as external identity.
final class MembershipAudit
{
    private const FORBIDDEN_KEYS = ['full_name', 'display_name', 'name', 'birth_date', 'birth_year', 'title', 'contact', 'phone', 'email', 'line1', 'address', 'person_id', 'unit_id', 'document_id', 'source_document_id', 'membership_id', 'id'];

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
            'before_metadata' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after_metadata' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'reason' => $reason,
            'source' => MembershipCatalog::AUDIT_SOURCE, 'occurred_at' => $at, 'session_id' => $actor->session,
            'correlation_id' => $correlation, 'created_at' => $at, 'lock_version' => 0,
        ]);
        return $correlation;
    }

    private function assertSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['reason' => 'audit_metadata_key', 'key' => $key]);
            }
            if (is_array($value)) {
                $this->assertSafe($value);
            }
        }
    }
}
