<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

// Physical Locations (ADR 0018 D01, D03-D05, D08, D09).
//  - creation is ONE transaction: addresses + physical_locations + the first authorized unit_location_links row;
//    any failure (first link included) rolls everything back. There is no loose location in V1;
//  - a location without an ACTIVE vigente link is absent from list/search and its detail is concealed (404);
//  - lifecycle DRAFT -> ACTIVE -> CLOSED (DRAFT -> CLOSED), CLOSED terminal, no hard delete; CLOSED forces PRIVATE;
//  - public_visibility defaults to PRIVATE; APPROVED_PUBLIC only through publish; a coordinate change on a published
//    location returns it to PRIVATE in the same transaction;
//  - address line1 is AES-256-GCM encrypted (AAD mepa.physical.address.line1.v1|<location public_id>, public_id
//    generated before encryption, key_version per row, no blind index); plaintext line1 and locality are only
//    returned by the audited sensitive read, to PHYSICAL_LOCATION_MANAGE in scope.
final class LocationService
{
    private PhysicalRecords $records;

    public function __construct(private PhysicalRuntime $rt)
    {
        $this->records = new PhysicalRecords($rt);
    }

    public static function aad(string $locationPublicId): string
    {
        return 'mepa.physical.address.line1.v1|' . $locationPublicId;
    }

    public function context(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor): array {
            $byUnit = [];
            foreach ([PhysicalCatalog::LOCATION_VIEW, PhysicalCatalog::LOCATION_MANAGE, PhysicalCatalog::LINK_MANAGE] as $permission) {
                foreach ($this->rt->authority->covered($actor, $permission) as $unit => $_) {
                    $byUnit[$unit][] = $permission;
                }
            }
            $units = [];
            if ($byUnit !== []) {
                $rows = $this->rt->db->table('organizational_units')->whereIn('id', array_keys($byUnit))->where('status', '!=', 'CLOSED')->orderBy('name')->orderBy('id')->limit(200)->get(['id', 'public_id', 'name', 'status']);
                foreach ($rows as $row) {
                    $units[] = ['public_id' => (string) $row->public_id, 'name' => (string) $row->name, 'status' => (string) $row->status, 'permissions' => $byUnit[(int) $row->id]];
                }
            }
            return [
                'permissions' => $this->rt->authority->effectivePermissions($actor),
                'units' => $units,
                'occupation_types' => array_map(fn ($code, $label) => ['code' => $code, 'label' => $label, 'requires_reason' => $code === PhysicalCatalog::OCCUPATION_REQUIRES_REASON], array_keys(PhysicalCatalog::OCCUPATION_TYPES), PhysicalCatalog::OCCUPATION_TYPES),
                'ownership_statuses' => array_map(fn ($code, $label) => ['code' => $code, 'label' => $label], array_keys(PhysicalCatalog::OWNERSHIP_STATUSES), PhysicalCatalog::OWNERSHIP_STATUSES),
                'statuses' => array_map(fn ($code, $label) => ['code' => $code, 'label' => $label], array_keys(PhysicalCatalog::STATUSES), PhysicalCatalog::STATUSES),
                'pagination' => ['default' => (int) ($this->rt->settings['pagination']['default'] ?? 50), 'max' => (int) ($this->rt->settings['pagination']['max'] ?? 100)],
            ];
        });
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($q): array {
            $covered = $this->rt->authority->covered($actor, PhysicalCatalog::LOCATION_VIEW);
            if ($covered === []) {
                throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['permission' => PhysicalCatalog::LOCATION_VIEW]);
            }
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $units = $covered;
            if (($q['unit_public_id'] ?? '') !== '') {
                $unit = $this->rt->db->table('organizational_units')->where('public_id', (string) $q['unit_public_id'])->value('id');
                $units = $unit !== null && isset($covered[(int) $unit]) ? [(int) $unit => true] : [];
            }
            [$predicate, $bindings] = $this->rt->authority->visiblePredicate('pl', $units);
            $query = $this->rt->db->table('physical_locations as pl')->whereRaw($predicate, $bindings);
            if (($q['search'] ?? '') !== '') {
                $query->where('pl.name', 'like', '%' . PhysicalRecords::escapeLike((string) $q['search']) . '%');
            }
            if (($q['status'] ?? '') !== '') {
                $query->where('pl.status', (string) $q['status']);
            }
            $total = (clone $query)->count('pl.id');
            $rows = $query->orderBy('pl.name')->orderBy('pl.id')->forPage($page, $per)->get(['pl.*'])->all();
            $items = array_map(fn (object $row): array => $this->records->projectLocation($row) + ['unit' => $this->scopeUnit((int) $row->id, $covered)], $rows);
            return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(PhysicalCatalog::LOCATION_VIEW);
            $id = $this->records->locationId($publicId);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::LOCATION_VIEW, $id);
            return $this->project($actor, $id);
        });
    }

    public function create(int $user, int $session, array $in): array
    {
        $id = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($in): int {
            $guard->requires(PhysicalCatalog::LOCATION_MANAGE);
            $unit = $this->records->unitId($in['unit_public_id'] ?? null);
            $this->rt->authority->forUnit($actor, PhysicalCatalog::LOCATION_MANAGE, $unit, false);
            $units = $this->rt->lockUnits([$unit]);
            $guard->unit(PhysicalCatalog::LOCATION_MANAGE, $unit);
            $links = new LinkService($this->rt);
            $target = $links->operationalUnit($units, $unit);
            $name = $this->name($in['name'] ?? null);
            [$latitude, $longitude] = $this->coordinates($in);
            $address = $this->addressInput($in['address'] ?? null);
            $now = $this->rt->ts();
            // public_id first: it is part of the AAD of the encrypted line (same transaction).
            $publicId = (string) Str::ulid();
            [$ciphertext, $version] = $this->rt->encrypt($address['line1'], self::aad($publicId));
            $addressId = (int) $this->rt->db->table('addresses')->insertGetId([
                'country_code' => $address['country_code'], 'province_id' => null, 'municipality_id' => null,
                'line1_ciphertext' => $ciphertext, 'locality' => $address['locality'], 'key_version' => $version,
                'created_at' => $now, 'lock_version' => 0,
            ]);
            $id = (int) $this->rt->db->table('physical_locations')->insertGetId([
                'public_id' => $publicId, 'address_id' => $addressId, 'name' => $name, 'latitude' => $latitude, 'longitude' => $longitude,
                'geocode_accuracy' => null, 'public_visibility' => PhysicalCatalog::PRIVATE, 'status' => PhysicalCatalog::DRAFT,
                'created_at' => $now, 'lock_version' => 0,
            ]);
            $this->rt->lockLocation($id);
            $this->rt->lockLinks($id, [$unit]);
            $correlation = $this->rt->audit->record($actor, $unit, 'PHYSICAL_LOCATION_CREATE', 'PHYSICAL_LOCATION', $id, null,
                ['status' => PhysicalCatalog::DRAFT, 'public_visibility' => PhysicalCatalog::PRIVATE, 'address_entity' => $addressId, 'key_version' => $version,
                 'has_coordinates' => $latitude !== null], PhysicalRecords::optionalReason($in['reason'] ?? null));
            // The first link: same transaction, same correlation. Its failure rolls back address and location.
            $type = $this->records->occupationType($in['occupation_type_code'] ?? null);
            $links->insertLink($guard, $actor, $target, $id, $type, null, false, PhysicalRecords::optionalReason($in['reason'] ?? null), $now, $correlation);
            $guard->touch($unit, $id);
            return $id;
        });
        return $this->detail($user, $session, (string) $this->rt->db->table('physical_locations')->where('id', $id)->value('public_id'));
    }

    public function update(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::LOCATION_MANAGE);
            $id = $this->records->locationId($publicId);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id, PhysicalCatalog::LOCATION_MANAGE);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'physical_locations');
            if ($row->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'physical_locations']);
            }
            $name = $this->name($in['name'] ?? $row->name);
            [$latitude, $longitude] = array_key_exists('latitude', $in) || array_key_exists('longitude', $in)
                ? $this->coordinates($in) : [$row->latitude === null ? null : (float) $row->latitude, $row->longitude === null ? null : (float) $row->longitude];
            $moved = self::coord($latitude) !== self::coord($row->latitude) || self::coord($longitude) !== self::coord($row->longitude);
            // D08: any coordinate change on a published location returns it to PRIVATE in the same transaction.
            $visibility = $moved ? PhysicalCatalog::PRIVATE : (string) $row->public_visibility;
            $changed = $this->rt->db->table('physical_locations')->where('id', $id)->where('lock_version', $row->lock_version)->update([
                'name' => $name, 'latitude' => $latitude, 'longitude' => $longitude, 'public_visibility' => $visibility, 'lock_version' => (int) $row->lock_version + 1,
            ]);
            if ($changed !== 1) {
                throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'physical_locations']);
            }
            $guard->touch(null, $id);
            $this->rt->audit->record($actor, $decision->unit, 'PHYSICAL_LOCATION_UPDATE', 'PHYSICAL_LOCATION', $id,
                ['public_visibility' => (string) $row->public_visibility, 'lock_version' => (int) $row->lock_version],
                ['name_changed' => $name !== (string) $row->name, 'coordinates_changed' => $moved, 'public_visibility' => $visibility,
                 'visibility_reset' => $moved && $row->public_visibility === PhysicalCatalog::APPROVED_PUBLIC, 'lock_version' => (int) $row->lock_version + 1],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
        });
        return $this->detail($user, $session, $publicId);
    }

    /** Audited sensitive read of the institutional address (PHYSICAL_LOCATION_MANAGE in scope). */
    public function address(int $user, int $session, string $publicId): array
    {
        return $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(PhysicalCatalog::LOCATION_MANAGE);
            $id = $this->records->locationId($publicId);
            $decision = $guard->location(PhysicalCatalog::LOCATION_MANAGE, $id, false);
            $row = $this->rt->db->table('physical_locations as pl')->join('addresses as a', 'a.id', '=', 'pl.address_id')->where('pl.id', $id)
                ->first(['pl.public_id', 'a.id as address_id', 'a.country_code', 'a.line1_ciphertext', 'a.locality', 'a.key_version']);
            $line1 = $this->rt->decrypt((string) $row->line1_ciphertext, (int) $row->key_version, self::aad((string) $row->public_id));
            $this->rt->audit->record($actor, $decision->unit, 'PHYSICAL_LOCATION_ADDRESS_READ', 'PHYSICAL_LOCATION', $id, null,
                ['area' => 'address', 'address_entity' => (int) $row->address_id, 'key_version' => (int) $row->key_version, 'sensitive' => true]);
            return ['country_code' => (string) $row->country_code, 'line1' => $line1, 'locality' => $row->locality === null ? null : (string) $row->locality];
        });
    }

    /** Replaces the address: a NEW addresses row is encrypted and linked; the previous row is preserved. */
    public function updateAddress(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::LOCATION_MANAGE);
            $id = $this->records->locationId($publicId);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id, PhysicalCatalog::LOCATION_MANAGE);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'physical_locations');
            if ($row->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'physical_locations']);
            }
            $address = $this->addressInput($in);
            $now = $this->rt->ts();
            [$ciphertext, $version] = $this->rt->encrypt($address['line1'], self::aad((string) $row->public_id));
            $addressId = (int) $this->rt->db->table('addresses')->insertGetId([
                'country_code' => $address['country_code'], 'province_id' => null, 'municipality_id' => null,
                'line1_ciphertext' => $ciphertext, 'locality' => $address['locality'], 'key_version' => $version, 'created_at' => $now, 'lock_version' => 0,
            ]);
            $changed = $this->rt->db->table('physical_locations')->where('id', $id)->where('lock_version', $row->lock_version)
                ->update(['address_id' => $addressId, 'lock_version' => (int) $row->lock_version + 1]);
            if ($changed !== 1) {
                throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'physical_locations']);
            }
            $guard->touch(null, $id);
            $this->rt->audit->record($actor, $decision->unit, 'PHYSICAL_LOCATION_ADDRESS_UPDATE', 'PHYSICAL_LOCATION', $id,
                ['address_entity' => (int) $row->address_id, 'lock_version' => (int) $row->lock_version],
                ['address_entity' => $addressId, 'key_version' => $version, 'history_preserved' => true, 'lock_version' => (int) $row->lock_version + 1],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
        });
        return $this->detail($user, $session, $publicId);
    }

    public function activate(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::LOCATION_MANAGE);
            $id = $this->records->locationId($publicId);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id, PhysicalCatalog::LOCATION_MANAGE);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'physical_locations');
            if ($row->status !== PhysicalCatalog::DRAFT) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'physical_locations']);
            }
            $this->versioned($id, (int) $row->lock_version, ['status' => PhysicalCatalog::ACTIVE]);
            $guard->touch(null, $id);
            $this->rt->audit->record($actor, $decision->unit, 'PHYSICAL_LOCATION_ACTIVATE', 'PHYSICAL_LOCATION', $id,
                ['status' => PhysicalCatalog::DRAFT, 'lock_version' => (int) $row->lock_version], ['status' => PhysicalCatalog::ACTIVE, 'lock_version' => (int) $row->lock_version + 1],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
        });
        return $this->detail($user, $session, $publicId);
    }

    public function close(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::LOCATION_MANAGE);
            $id = $this->records->locationId($publicId);
            $reason = PhysicalRecords::requireReason($in['reason'] ?? null);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::LOCATION_MANAGE, $id, false);
            $row = $this->rt->lockLocation($id);
            $this->rt->db->table('properties')->where('location_id', $id)->orderBy('id')->lockForUpdate()->get(['id']);
            $this->rt->db->table('temples')->where('location_id', $id)->orderBy('id')->lockForUpdate()->get(['id']);
            $this->rt->lockLinks($id);
            $decision = $guard->location(PhysicalCatalog::LOCATION_MANAGE, $id);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'physical_locations');
            $this->closeLocked($guard, $actor, $decision, $row, $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    /**
     * Closes a location whose row (and properties/temples) the caller already holds FOR UPDATE. Shared by the close
     * action and by end-link(close_location) so the last link can end together with CLOSE in one transaction.
     */
    public function closeLocked(PhysicalGuard $guard, TerritorialActor $actor, PhysicalDecision $decision, object $row, string $reason): string
    {
        if (!in_array($row->status, [PhysicalCatalog::DRAFT, PhysicalCatalog::ACTIVE], true)) {
            throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'physical_locations']);
        }
        $dependents = $this->rt->db->table('properties')->where('location_id', $row->id)->where('status', PhysicalCatalog::ACTIVE)->sharedLock()->exists()
            || $this->rt->db->table('temples')->where('location_id', $row->id)->where('status', PhysicalCatalog::ACTIVE)->sharedLock()->exists();
        if ($dependents) {
            throw new PhysicalError(PhysicalReason::ACTIVE_DEPENDENCIES);
        }
        $this->versioned((int) $row->id, (int) $row->lock_version, ['status' => PhysicalCatalog::CLOSED, 'public_visibility' => PhysicalCatalog::PRIVATE]);
        $guard->touch(null, (int) $row->id);
        return $this->rt->audit->record($actor, $decision->unit, 'PHYSICAL_LOCATION_CLOSE', 'PHYSICAL_LOCATION', (int) $row->id,
            ['status' => (string) $row->status, 'public_visibility' => (string) $row->public_visibility, 'lock_version' => (int) $row->lock_version],
            ['status' => PhysicalCatalog::CLOSED, 'public_visibility' => PhysicalCatalog::PRIVATE, 'lock_version' => (int) $row->lock_version + 1], $reason);
    }

    public function publish(int $user, int $session, string $publicId, array $in): array
    {
        return $this->visibility($user, $session, $publicId, $in, true);
    }

    public function unpublish(int $user, int $session, string $publicId, array $in): array
    {
        return $this->visibility($user, $session, $publicId, $in, false);
    }

    private function visibility(int $user, int $session, string $publicId, array $in, bool $publish): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in, $publish): void {
            $guard->requires(PhysicalCatalog::LOCATION_PUBLISH);
            $id = $this->records->locationId($publicId);
            $reason = PhysicalRecords::requireReason($in['reason'] ?? null);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id, PhysicalCatalog::LOCATION_PUBLISH);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'physical_locations');
            $from = (string) $row->public_visibility;
            if ($publish) {
                if ($row->status !== PhysicalCatalog::ACTIVE) {
                    throw new PhysicalError(PhysicalReason::LOCATION_NOT_ACTIVE);
                }
                if ($row->latitude === null || $row->longitude === null) {
                    throw new PhysicalError(PhysicalReason::COORDINATES_REQUIRED);
                }
                if ($from !== PhysicalCatalog::PRIVATE) {
                    throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'physical_locations']);
                }
            } elseif ($from !== PhysicalCatalog::APPROVED_PUBLIC) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'physical_locations']);
            }
            $to = $publish ? PhysicalCatalog::APPROVED_PUBLIC : PhysicalCatalog::PRIVATE;
            $this->versioned($id, (int) $row->lock_version, ['public_visibility' => $to]);
            $guard->touch(null, $id);
            $this->rt->audit->record($actor, $decision->unit, $publish ? 'PHYSICAL_LOCATION_PUBLISH' : 'PHYSICAL_LOCATION_UNPUBLISH', 'PHYSICAL_LOCATION', $id,
                ['public_visibility' => $from, 'lock_version' => (int) $row->lock_version], ['public_visibility' => $to, 'lock_version' => (int) $row->lock_version + 1], $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    /** @return array{0: PhysicalDecision, 1: object} lock order: location -> links, then the locked decision */
    private function lockForManage(PhysicalGuard $guard, TerritorialActor $actor, int $id, string $permission): array
    {
        $this->rt->authority->forLocation($actor, $permission, $id, false);
        $row = $this->rt->lockLocation($id);
        $this->rt->lockLinks($id);
        return [$guard->location($permission, $id), $row];
    }

    private function versioned(int $id, int $version, array $changes): void
    {
        $changed = $this->rt->db->table('physical_locations')->where('id', $id)->where('lock_version', $version)->update($changes + ['lock_version' => $version + 1]);
        if ($changed !== 1) {
            throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'physical_locations']);
        }
    }

    private function project(TerritorialActor $actor, int $id): array
    {
        $row = $this->records->location($id);
        $can = array_values(array_filter(array_keys(PhysicalCatalog::PERMISSIONS), fn (string $p): bool => $this->rt->authority->holdsOnLocation($actor, $p, $id)));
        $covered = $this->rt->authority->covered($actor, PhysicalCatalog::LOCATION_VIEW);
        return $this->records->projectLocation($row) + [
            'unit' => $this->scopeUnit($id, $covered),
            'active_links' => count($this->rt->authority->activeLinks($id)),
            'public_projection' => PhysicalRecords::publicProjection($row),
            'can' => $can,
        ];
    }

    /** The unit through which the actor sees the location (primary first), for display only. */
    private function scopeUnit(int $location, array $covered): ?array
    {
        foreach ($this->rt->authority->activeLinks($location) as $link) {
            if (isset($covered[(int) $link->unit_id])) {
                $unit = $this->rt->db->table('organizational_units')->where('id', $link->unit_id)->first(['public_id', 'name']);
                return $unit ? ['public_id' => (string) $unit->public_id, 'name' => (string) $unit->name, 'is_primary' => (int) $link->is_primary === 1] : null;
            }
        }
        return null;
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 191) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'name']);
        }
        return $name;
    }

    /** @return array{0: ?float, 1: ?float} both or none, inside the existing CHECK ranges */
    private function coordinates(array $in): array
    {
        $lat = $in['latitude'] ?? null;
        $lng = $in['longitude'] ?? null;
        if ($lat === null && $lng === null) {
            return [null, null];
        }
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'coordinates']);
        }
        return [round((float) $lat, 6), round((float) $lng, 6)];
    }

    private static function coord(mixed $value): ?string
    {
        return $value === null ? null : sprintf('%.6f', (float) $value);
    }

    /** @return array{country_code: string, line1: string, locality: ?string} */
    private function addressInput(mixed $in): array
    {
        if (!is_array($in)) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'address']);
        }
        $country = is_string($in['country_code'] ?? null) ? strtoupper(trim($in['country_code'])) : '';
        $line1 = is_string($in['line1'] ?? null) ? trim($in['line1']) : '';
        $locality = is_string($in['locality'] ?? null) && trim($in['locality']) !== '' ? trim($in['locality']) : null;
        if (preg_match('/^[A-Z]{2,3}$/D', $country) !== 1) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'address.country_code']);
        }
        if (mb_strlen($line1) < 3 || mb_strlen($line1) > 500) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'address.line1']);
        }
        if ($locality !== null && mb_strlen($locality) > 191) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'address.locality']);
        }
        return ['country_code' => $country, 'line1' => $line1, 'locality' => $locality];
    }
}
