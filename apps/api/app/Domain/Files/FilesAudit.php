<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

// ADR 0019 D12: audit rows (source P08_FILES) written inside the business transaction (a failed audit rolls the
// operation back). unit_id is the file/document owner unit (ownership changes: one row per unit, same correlation).
// Allowed metadata: public ids, classification, MIME, size, key_version, states, reason codes, inspection level.
// NEVER: content, original_name, document titles, checksum, storage_key, disk, paths, DEK/KEK, ciphertext.
// Cron/reconciler rows have no actor (actor_kind SYSTEM).
final class FilesAudit
{
    public const FORBIDDEN_KEYS = [
        'original_name', 'file_name', 'name', 'title', 'checksum', 'sha256', 'storage_key', 'staging', 'disk', 'path',
        'dek', 'kek', 'key', 'secret', 'content', 'plaintext', 'ciphertext', 'bytes', 'token',
    ];

    public function __construct(private Connection $db)
    {
    }

    public function record(?TerritorialActor $actor, int $unit, string $action, string $entityType, int $entityId, ?array $before, array $after, ?string $reason = null, ?string $correlation = null): string
    {
        foreach ([$before ?? [], $after] as $metadata) {
            self::assertSafe($metadata);
        }
        $at = (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
        $correlation ??= (string) Str::ulid();
        $this->db->table('audit_logs')->insert([
            'actor_id' => $actor?->user, 'actor_kind' => $actor === null ? 'SYSTEM' : 'USER', 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'unit_id' => $unit,
            'before_metadata' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_metadata' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $reason,
            'source' => FilesCatalog::AUDIT_SOURCE, 'occurred_at' => $at, 'session_id' => $actor?->session,
            'correlation_id' => $correlation, 'created_at' => $at, 'lock_version' => 0,
        ]);
        return $correlation;
    }

    public static function assertSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new FilesError(FilesReason::INVARIANT_VIOLATION, ['reason' => 'audit_metadata_key']);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
