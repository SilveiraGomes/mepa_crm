<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

// Properties (ADR 0018 D01, D05-D07). Authority over a property is the authority over its location_id; a property
// never grants authority, and neither does ownership_status (documentary only; default UNKNOWN).
//  - owner_person_id: projected through People only ({public_id, display_name} when the actor independently holds
//    People authority over that Person; otherwise only "a Person owner is registered"). Setting it requires the same
//    People authority, so a known Person public_id never attaches a foreign Person.
//  - owner_name_external: Restricted third-party data, only through the audited read (PROPERTY_MANAGE); never in
//    list, search, export or public projection. The two owner fields are mutually exclusive.
final class PropertyService
{
    private PhysicalRecords $records;

    public function __construct(private PhysicalRuntime $rt)
    {
        $this->records = new PhysicalRecords($rt);
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($q): array {
            $covered = $this->rt->authority->covered($actor, PhysicalCatalog::PROPERTY_VIEW);
            if ($covered === []) {
                throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['permission' => PhysicalCatalog::PROPERTY_VIEW]);
            }
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            [$predicate, $bindings] = $this->rt->authority->visiblePredicate('pl', $covered);
            $query = $this->rt->db->table('properties as p')->join('physical_locations as pl', 'pl.id', '=', 'p.location_id')->whereRaw($predicate, $bindings);
            if (($q['location_public_id'] ?? '') !== '') {
                $query->where('pl.public_id', (string) $q['location_public_id']);
            }
            if (($q['search'] ?? '') !== '') {
                $query->where('p.code', 'like', '%' . PhysicalRecords::escapeLike((string) $q['search']) . '%');
            }
            if (($q['status'] ?? '') !== '') {
                $query->where('p.status', (string) $q['status']);
            }
            $total = (clone $query)->count('p.id');
            $rows = $query->orderBy('p.code')->orderBy('p.id')->forPage($page, $per)
                ->get(['p.*', 'pl.public_id as location_public_id', 'pl.name as location_name'])->all();
            $items = array_map(fn (object $r): array => $this->records->projectProperty($r, (object) ['public_id' => $r->location_public_id, 'name' => $r->location_name], ['kind' => self::ownerKind($r), 'person' => null]), $rows);
            return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(PhysicalCatalog::PROPERTY_VIEW);
            $id = $this->records->propertyId($publicId);
            $row = $this->row($id);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::PROPERTY_VIEW, (int) $row->location_id);
            return $this->project($actor, $row);
        });
    }

    public function create(int $user, int $session, array $in): array
    {
        $id = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($in): int {
            $guard->requires(PhysicalCatalog::PROPERTY_MANAGE);
            $location = $this->records->locationId($in['location_public_id'] ?? null);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::PROPERTY_MANAGE, $location, false);
            $loc = $this->rt->lockLocation($location);
            $this->rt->lockLinks($location);
            $decision = $guard->location(PhysicalCatalog::PROPERTY_MANAGE, $location);
            if ($loc->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::LOCATION_CLOSED);
            }
            $code = $this->code($in['code'] ?? null, null);
            [$person, $external] = $this->owner($actor, $in, null);
            $ownership = $this->ownership($in['ownership_status'] ?? PhysicalCatalog::OWNERSHIP_DEFAULT);
            $now = $this->rt->ts();
            $id = (int) $this->rt->db->table('properties')->insertGetId([
                'public_id' => (string) Str::ulid(), 'code' => $code, 'location_id' => $location, 'owner_person_id' => $person,
                'owner_name_external' => $external, 'ownership_status' => $ownership, 'status' => PhysicalCatalog::DRAFT,
                'created_at' => $now, 'lock_version' => 0,
            ]);
            $guard->touch(null, $location);
            $this->rt->audit->record($actor, $decision->unit, 'PROPERTY_CREATE', 'PROPERTY', $id, null,
                ['location_entity' => $location, 'owner_kind' => self::kindOf($person, $external), 'ownership_status' => $ownership, 'status' => PhysicalCatalog::DRAFT],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
            return $id;
        });
        return $this->detail($user, $session, (string) $this->rt->db->table('properties')->where('id', $id)->value('public_id'));
    }

    public function update(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::PROPERTY_MANAGE);
            $id = $this->records->propertyId($publicId);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'properties');
            if ($row->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'properties']);
            }
            $code = array_key_exists('code', $in) ? $this->code($in['code'], (int) $row->id) : (string) $row->code;
            [$person, $external] = $this->owner($actor, $in, $row);
            $this->versioned((int) $row->id, (int) $row->lock_version, ['code' => $code, 'owner_person_id' => $person, 'owner_name_external' => $external]);
            $this->rt->audit->record($actor, $decision->unit, 'PROPERTY_UPDATE', 'PROPERTY', (int) $row->id,
                ['owner_kind' => self::ownerKind($row), 'lock_version' => (int) $row->lock_version],
                ['code_changed' => $code !== (string) $row->code, 'owner_kind' => self::kindOf($person, $external),
                 'owner_changed' => $person !== ($row->owner_person_id === null ? null : (int) $row->owner_person_id) || $external !== $row->owner_name_external, 'lock_version' => (int) $row->lock_version + 1],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
        });
        return $this->detail($user, $session, $publicId);
    }

    /** Explicit, audited, versioned change of the documentary ownership status (D06). */
    public function ownershipStatus(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::PROPERTY_MANAGE);
            $id = $this->records->propertyId($publicId);
            $reason = PhysicalRecords::requireReason($in['reason'] ?? null);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'properties');
            if ($row->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'properties']);
            }
            $to = $this->ownership($in['ownership_status'] ?? null);
            $this->versioned((int) $row->id, (int) $row->lock_version, ['ownership_status' => $to]);
            $this->rt->audit->record($actor, $decision->unit, 'PROPERTY_OWNERSHIP_STATUS', 'PROPERTY', (int) $row->id,
                ['ownership_status' => (string) $row->ownership_status, 'lock_version' => (int) $row->lock_version],
                ['ownership_status' => $to, 'lock_version' => (int) $row->lock_version + 1], $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    public function activate(int $user, int $session, string $publicId, array $in): array
    {
        return $this->lifecycle($user, $session, $publicId, $in, PhysicalCatalog::ACTIVE);
    }

    public function close(int $user, int $session, string $publicId, array $in): array
    {
        return $this->lifecycle($user, $session, $publicId, $in, PhysicalCatalog::CLOSED);
    }

    /** Audited sensitive read of the external owner name (PROPERTY_MANAGE in scope). */
    public function externalOwner(int $user, int $session, string $publicId): array
    {
        return $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(PhysicalCatalog::PROPERTY_MANAGE);
            $id = $this->records->propertyId($publicId);
            $row = $this->row($id);
            $decision = $guard->location(PhysicalCatalog::PROPERTY_MANAGE, (int) $row->location_id, false);
            $this->rt->audit->record($actor, $decision->unit, 'PROPERTY_EXTERNAL_OWNER_READ', 'PROPERTY', $id, null,
                ['area' => 'owner_name_external', 'present' => $row->owner_name_external !== null, 'sensitive' => true]);
            return ['name' => $row->owner_name_external === null ? null : (string) $row->owner_name_external];
        });
    }

    private function lifecycle(int $user, int $session, string $publicId, array $in, string $to): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in, $to): void {
            $guard->requires(PhysicalCatalog::PROPERTY_MANAGE);
            $id = $this->records->propertyId($publicId);
            $reason = $to === PhysicalCatalog::CLOSED ? PhysicalRecords::requireReason($in['reason'] ?? null) : PhysicalRecords::optionalReason($in['reason'] ?? null);
            [$decision, $row, $location] = $this->lockForManage($guard, $actor, $id);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'properties');
            $from = (string) $row->status;
            $allowed = ($from === PhysicalCatalog::DRAFT && in_array($to, [PhysicalCatalog::ACTIVE, PhysicalCatalog::CLOSED], true)) || ($from === PhysicalCatalog::ACTIVE && $to === PhysicalCatalog::CLOSED);
            if (!$allowed) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'properties']);
            }
            if ($to === PhysicalCatalog::ACTIVE && $location->status !== PhysicalCatalog::ACTIVE) {
                throw new PhysicalError(PhysicalReason::LOCATION_NOT_ACTIVE);
            }
            $this->versioned((int) $row->id, (int) $row->lock_version, ['status' => $to]);
            $this->rt->audit->record($actor, $decision->unit, $to === PhysicalCatalog::ACTIVE ? 'PROPERTY_ACTIVATE' : 'PROPERTY_CLOSE', 'PROPERTY', (int) $row->id,
                ['status' => $from, 'lock_version' => (int) $row->lock_version], ['status' => $to, 'lock_version' => (int) $row->lock_version + 1], $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    /** @return array{0: PhysicalDecision, 1: object, 2: object} lock order: location -> property -> links */
    private function lockForManage(PhysicalGuard $guard, TerritorialActor $actor, int $id): array
    {
        $location = (int) $this->row($id)->location_id;
        $this->rt->authority->forLocation($actor, PhysicalCatalog::PROPERTY_MANAGE, $location, false);
        $loc = $this->rt->lockLocation($location);
        $row = $this->rt->db->table('properties')->where('id', $id)->lockForUpdate()->first();
        $this->rt->lockLinks($location);
        return [$guard->location(PhysicalCatalog::PROPERTY_MANAGE, $location), $row, $loc];
    }

    private function project(TerritorialActor $actor, object $row): array
    {
        $location = $this->rt->db->table('physical_locations')->where('id', $row->location_id)->first(['public_id', 'name']);
        $kind = self::ownerKind($row);
        $person = $kind === 'PERSON' ? $this->rt->ownerPerson($actor, (int) $row->owner_person_id) : null;
        return $this->records->projectProperty($row, $location, ['kind' => $kind, 'person' => $person]) + [
            'can' => array_values(array_filter([PhysicalCatalog::PROPERTY_VIEW, PhysicalCatalog::PROPERTY_MANAGE], fn (string $p): bool => $this->rt->authority->holdsOnLocation($actor, $p, (int) $row->location_id))),
        ];
    }

    private function row(int $id): object
    {
        $row = $this->rt->db->table('properties')->where('id', $id)->first();
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'properties']);
        }
        return $row;
    }

    private function versioned(int $id, int $version, array $changes): void
    {
        $changed = $this->rt->db->table('properties')->where('id', $id)->where('lock_version', $version)->update($changes + ['lock_version' => $version + 1]);
        if ($changed !== 1) {
            throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'properties']);
        }
    }

    private function code(mixed $value, ?int $except): string
    {
        $code = is_string($value) ? trim($value) : '';
        if ($code === '' || mb_strlen($code) > 64) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'code']);
        }
        $query = $this->rt->db->table('properties')->where('code', $code);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            throw new PhysicalError(PhysicalReason::CODE_EXISTS);
        }
        return $code;
    }

    private function ownership(mixed $value): string
    {
        if (!is_string($value) || !isset(PhysicalCatalog::OWNERSHIP_STATUSES[$value])) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'ownership_status']);
        }
        return $value;
    }

    /** @return array{0: ?int, 1: ?string} [owner_person_id, owner_name_external], mutually exclusive */
    private function owner(TerritorialActor $actor, array $in, ?object $current): array
    {
        $person = $current?->owner_person_id === null ? null : (int) $current->owner_person_id;
        $external = $current?->owner_name_external === null ? null : (string) $current->owner_name_external;
        $hasPerson = array_key_exists('owner_person_public_id', $in);
        $hasExternal = array_key_exists('owner_name_external', $in);
        $personRef = $hasPerson && is_string($in['owner_person_public_id']) && $in['owner_person_public_id'] !== '' ? $in['owner_person_public_id'] : null;
        $externalName = $hasExternal && is_string($in['owner_name_external']) && trim($in['owner_name_external']) !== '' ? trim($in['owner_name_external']) : null;
        if ($personRef !== null && $externalName !== null) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'owner']);
        }
        if ($hasPerson) {
            $person = $personRef === null ? null : $this->ownerPersonId($actor, $personRef);
            if ($person !== null) {
                $external = null;
            }
        }
        if ($hasExternal) {
            if ($externalName !== null && mb_strlen($externalName) > 191) {
                throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'owner_name_external']);
            }
            $external = $externalName;
            if ($external !== null) {
                $person = null;
            }
        }
        return [$person, $external];
    }

    /** Unknown, malformed and People-out-of-scope Persons are the same INVALID_INPUT. */
    private function ownerPersonId(TerritorialActor $actor, string $publicId): int
    {
        $id = preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $publicId) === 1 ? $this->rt->db->table('people')->where('public_id', $publicId)->value('id') : null;
        if ($id === null || $this->rt->ownerPerson($actor, (int) $id) === null) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'owner_person_public_id']);
        }
        return (int) $id;
    }

    private static function ownerKind(object $row): string
    {
        return self::kindOf($row->owner_person_id === null ? null : (int) $row->owner_person_id, $row->owner_name_external === null ? null : (string) $row->owner_name_external);
    }

    private static function kindOf(?int $person, ?string $external): string
    {
        return $person !== null ? 'PERSON' : ($external !== null ? 'EXTERNAL' : 'NONE');
    }
}
