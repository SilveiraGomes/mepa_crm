<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final class TerritorialAudit
{
    public const SOURCE = 'P06_TERRITORIAL';

    public function __construct(private Connection $db)
    {
    }

    public function record(TerritorialActor $actor, int $contextUnit, string $action, int $unit, ?array $before, array $after, ?string $reason = null): void
    {
        $at = (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
        $this->db->table('audit_logs')->insert([
            'actor_id' => $actor->user, 'actor_kind' => 'USER', 'action' => $action,
            'entity_type' => 'ORGANIZATIONAL_UNIT', 'entity_id' => $unit, 'unit_id' => $contextUnit,
            'before_metadata' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_metadata' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $reason,
            'source' => self::SOURCE, 'occurred_at' => $at, 'session_id' => $actor->session,
            'correlation_id' => (string) Str::ulid(), 'created_at' => $at, 'lock_version' => 0,
        ]);
    }
}
