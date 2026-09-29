<?php

declare(strict_types=1);

namespace App\Domain\Physical;

// Lookups and projections shared by the Physical services. Unknown and malformed public ids are TARGET_NOT_FOUND
// (F-06). Projections expose public_id / opaque refs only, never a numeric key.
final class PhysicalRecords
{
    private const ULID = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    public function __construct(private PhysicalRuntime $rt)
    {
    }

    public function locationId(mixed $publicId): int
    {
        return $this->idOf('physical_locations', $publicId);
    }

    public function propertyId(mixed $publicId): int
    {
        return $this->idOf('properties', $publicId);
    }

    public function templeId(mixed $publicId): int
    {
        return $this->idOf('temples', $publicId);
    }

    public function unitId(mixed $publicId): int
    {
        return $this->idOf('organizational_units', $publicId);
    }

    public function location(int $id): object
    {
        $row = $this->rt->db->table('physical_locations as pl')->join('addresses as a', 'a.id', '=', 'pl.address_id')->where('pl.id', $id)
            ->first(['pl.*', 'a.country_code']);
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'physical_locations']);
        }
        return $row;
    }

    public function unit(int $id): object
    {
        $row = $this->rt->db->table('organizational_units as ou')->join('organizational_unit_types as ut', 'ut.id', '=', 'ou.unit_type_id')->where('ou.id', $id)
            ->first(['ou.id', 'ou.public_id', 'ou.name', 'ou.status', 'ut.code as type_code']);
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'organizational_units']);
        }
        return $row;
    }

    public function occupationType(mixed $code): object
    {
        $row = is_string($code) ? $this->rt->db->table('occupation_types')->where('code', $code)->where('is_active', 1)->first() : null;
        if (!$row) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'occupation_type_code']);
        }
        return $row;
    }

    /** Property that must belong to $location (D10.6); unknown and foreign properties are the same INVALID_INPUT. */
    public function propertyOnLocation(mixed $publicId, int $location): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        $id = is_string($publicId) && preg_match(self::ULID, $publicId) === 1
            ? $this->rt->db->table('properties')->where('public_id', $publicId)->where('location_id', $location)->value('id') : null;
        if ($id === null) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'property_public_id']);
        }
        return (int) $id;
    }

    /** A link of $location addressed by its opaque ref. */
    public function linkOf(int $location, mixed $ref): object
    {
        $ids = $this->rt->db->table('unit_location_links')->where('location_id', $location)->pluck('id')->all();
        $id = is_string($ref) ? $this->rt->refs->resolve('unit_location_links', $ref, $ids) : null;
        $row = $id === null ? null : $this->rt->db->table('unit_location_links')->where('id', $id)->first();
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'unit_location_links']);
        }
        return $row;
    }

    public function assertVersion(object $row, mixed $expected, string $entity): void
    {
        if (!is_numeric($expected) || (int) $expected !== (int) $row->lock_version) {
            throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => $entity]);
        }
    }

    public static function requireReason(mixed $reason): string
    {
        $value = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($value) < 3) {
            throw new PhysicalError(PhysicalReason::REASON_REQUIRED);
        }
        return $value;
    }

    public static function optionalReason(mixed $reason): ?string
    {
        $value = is_string($reason) ? trim($reason) : '';
        return $value === '' ? null : $value;
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    // ---- projections ----------------------------------------------------------------------------------------

    public function projectLocation(object $row): array
    {
        return [
            'public_id' => (string) $row->public_id,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
            'status_label' => PhysicalCatalog::STATUSES[(string) $row->status] ?? (string) $row->status,
            'public_visibility' => (string) $row->public_visibility,
            'latitude' => $row->latitude === null ? null : (float) $row->latitude,
            'longitude' => $row->longitude === null ? null : (float) $row->longitude,
            'country_code' => isset($row->country_code) ? (string) $row->country_code : null,
            'created_at' => (string) $row->created_at,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    /**
     * The public projection contract (ADR 0018 D08): ONLY public_id, name, latitude and longitude of an ACTIVE +
     * APPROVED_PUBLIC location; null for anything else. Never address, locality, owner, key_version, documentary
     * status, links or internal keys. No anonymous endpoint serves it in V1.
     */
    public static function publicProjection(object $row): ?array
    {
        if ((string) $row->status !== PhysicalCatalog::ACTIVE || (string) $row->public_visibility !== PhysicalCatalog::APPROVED_PUBLIC
            || $row->latitude === null || $row->longitude === null) {
            return null;
        }
        return [
            'public_id' => (string) $row->public_id,
            'name' => (string) $row->name,
            'latitude' => (float) $row->latitude,
            'longitude' => (float) $row->longitude,
        ];
    }

    public function projectUnit(object $unit): array
    {
        return ['public_id' => (string) $unit->public_id, 'name' => (string) $unit->name];
    }

    public function projectLink(object $row, array $unitsById = [], array $locationsById = [], array $propertiesById = [], array $typesById = []): array
    {
        $unit = $unitsById[(int) $row->unit_id] ?? null;
        $location = $locationsById[(int) $row->location_id] ?? null;
        $property = $row->property_id === null ? null : ($propertiesById[(int) $row->property_id] ?? null);
        $type = $typesById[(int) $row->occupation_type_id] ?? null;
        return [
            'ref' => $this->rt->refs->for('unit_location_links', (int) $row->id),
            'unit' => $unit === null ? null : $this->projectUnit($unit),
            'location' => $location === null ? null : ['public_id' => (string) $location->public_id, 'name' => (string) $location->name],
            'property' => $property === null ? null : ['public_id' => (string) $property->public_id, 'code' => (string) $property->code],
            'occupation_type' => $type === null ? null : ['code' => (string) $type->code, 'label' => (string) $type->name],
            'is_primary' => (int) $row->is_primary === 1,
            'status' => (string) $row->status,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => $row->ends_at === null ? null : (string) $row->ends_at,
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    /** @param list<object> $links @return list<array> */
    public function projectLinks(array $links): array
    {
        if ($links === []) {
            return [];
        }
        $db = $this->rt->db;
        $units = $db->table('organizational_units')->whereIn('id', array_unique(array_map(fn ($l) => (int) $l->unit_id, $links)))->get(['id', 'public_id', 'name'])->keyBy('id')->all();
        $locations = $db->table('physical_locations')->whereIn('id', array_unique(array_map(fn ($l) => (int) $l->location_id, $links)))->get(['id', 'public_id', 'name'])->keyBy('id')->all();
        $propertyIds = array_values(array_filter(array_map(fn ($l) => $l->property_id === null ? null : (int) $l->property_id, $links)));
        $properties = $propertyIds === [] ? [] : $db->table('properties')->whereIn('id', array_unique($propertyIds))->get(['id', 'public_id', 'code'])->keyBy('id')->all();
        $types = $db->table('occupation_types')->get(['id', 'code', 'name'])->keyBy('id')->all();
        return array_map(fn (object $l): array => $this->projectLink($l, $units, $locations, $properties, $types), $links);
    }

    public function projectProperty(object $row, object $location, ?array $owner): array
    {
        return [
            'public_id' => (string) $row->public_id,
            'code' => (string) $row->code,
            'location' => ['public_id' => (string) $location->public_id, 'name' => (string) $location->name],
            'ownership_status' => (string) $row->ownership_status,
            'ownership_status_label' => PhysicalCatalog::OWNERSHIP_STATUSES[(string) $row->ownership_status] ?? (string) $row->ownership_status,
            'status' => (string) $row->status,
            'status_label' => PhysicalCatalog::STATUSES[(string) $row->status] ?? (string) $row->status,
            'owner' => $owner,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    public function projectTemple(object $row, object $location): array
    {
        return [
            'public_id' => (string) $row->public_id,
            'name' => (string) $row->name,
            'capacity' => $row->capacity === null ? null : (int) $row->capacity,
            'location' => ['public_id' => (string) $location->public_id, 'name' => (string) $location->name],
            'status' => (string) $row->status,
            'status_label' => PhysicalCatalog::STATUSES[(string) $row->status] ?? (string) $row->status,
            'created_at' => (string) $row->created_at,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function idOf(string $table, mixed $publicId): int
    {
        if (!is_string($publicId) || preg_match(self::ULID, $publicId) !== 1) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        $id = $this->rt->db->table($table)->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        return (int) $id;
    }
}
