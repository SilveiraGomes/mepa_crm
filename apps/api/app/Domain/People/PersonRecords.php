<?php

declare(strict_types=1);

namespace App\Domain\People;

use Illuminate\Database\Connection;

// Person lookups and the common / minor-safe projections shared by every People service.
final class PersonRecords
{
    private const ULID = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    public function __construct(private PeopleRuntime $rt)
    {
    }

    /** public_id -> internal id. Unknown or malformed identifiers are TARGET_NOT_FOUND (F-06). */
    public function id(mixed $publicId): int
    {
        if (!is_string($publicId) || preg_match(self::ULID, $publicId) !== 1) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'people']);
        }
        $id = $this->rt->db->table('people')->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'people']);
        }
        return (int) $id;
    }

    public function row(int $id, bool $forUpdate = false): object
    {
        $query = $this->rt->db->table('people as p')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')->where('p.id', $id);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first(['p.*', 'ps.code as status_code']);
        if (!$row) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'people']);
        }
        return $row;
    }

    public function statusId(string $code): int
    {
        $id = $this->rt->db->table('person_statuses')->where('code', $code)->where('is_active', 1)->value('id');
        if ($id === null) {
            throw new PeopleError(PeopleReason::CATALOG_INVALID, ['catalog' => 'person_statuses']);
        }
        return (int) $id;
    }

    /** @param list<int> $ids @return array<int, true> */
    public function childProfiles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_fill_keys(array_map('intval', $this->rt->db->table('child_profiles')->whereIn('person_id', $ids)->distinct()->pluck('person_id')->all()), true);
    }

    public function ageBand(object $row): ?string
    {
        return BirthDate::ageBand($row, $this->rt->today(), $this->rt->majority());
    }

    /**
     * Protected minor: a Children profile exists, or the stored birth proves or cannot exclude minority.
     * Its People projection is minimal whatever the actor's permissions (ADR-0017 "Menores").
     */
    public function isProtected(object $row, bool $childProfile): bool
    {
        return $childProfile || in_array($this->ageBand($row), [BirthDate::MINOR, BirthDate::UNCERTAIN], true);
    }

    /** Minimal projection: the only fields a minor (or any Person seen outside a GENERAL context) exposes. */
    public function minimal(object $row, bool $childProfile): array
    {
        $band = $this->ageBand($row);
        $protected = $this->isProtected($row, $childProfile);
        return [
            'public_id' => (string) $row->public_id,
            'display_name' => (string) $row->full_name,
            'birth_precision' => (string) $row->birth_precision,
            'status' => (string) $row->status_code,
            'age_band' => $protected && $band === BirthDate::UNCERTAIN ? BirthDate::MINOR : $band,
            'protected_minor' => $protected,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
