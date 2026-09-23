<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\WaveFour\DomainClock;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

// Writes People / Families audit rows into the shared audit_logs table inside the business
// transaction (a failed audit rolls the operation back). unit_id is ALWAYS the unit of the persisted
// context that authorized the operation (PeopleDecision), never a property of a Person or Household.
// Metadata carries codes, flags, counts, versions and internal ids of the audited rows only: never
// names, contact values, address lines, ciphertext, blind indexes or keys.
final class PeopleAudit
{
    public const SOURCE = 'P05_PEOPLE';

    private const FORBIDDEN_KEYS = ['full_name', 'value', 'line1', 'birth_date', 'ciphertext', 'blind_index', 'value_ciphertext', 'value_blind_index', 'line1_ciphertext', 'number', 'key', 'secret', 'token'];

    public function __construct(private Connection $db)
    {
    }

    public function record(PeopleActor $actor, PeopleDecision|int $authority, string $action, string $entityType, int $entityId, ?array $before, array $after, ?string $reason = null, ?string $correlation = null): string
    {
        $unit = $authority instanceof PeopleDecision ? $authority->unitId : $authority;
        $after = $authority instanceof PeopleDecision ? $after + ['authority' => $authority->metadata()] : $after;
        foreach ([$before ?? [], $after] as $metadata) {
            $this->assertSafe($metadata);
        }
        $at = DomainClock::now($this->db)->format('Y-m-d H:i:s.u');
        $correlation ??= (string) Str::ulid();
        $this->db->table('audit_logs')->insert([
            'actor_id' => $actor->user,
            'actor_kind' => 'USER',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'unit_id' => $unit,
            'before_metadata' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_metadata' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'source' => self::SOURCE,
            'occurred_at' => $at,
            'session_id' => $actor->session,
            'correlation_id' => $correlation,
            'created_at' => $at,
        ]);
        return $correlation;
    }

    private function assertSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new PeopleError(PeopleReason::INVARIANT_VIOLATION, ['reason' => 'audit_metadata_key']);
            }
            if (is_array($value)) {
                $this->assertSafe($value);
            }
        }
    }
}
