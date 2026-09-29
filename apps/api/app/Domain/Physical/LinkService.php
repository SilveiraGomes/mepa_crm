<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;

// Unit <-> Location links (ADR 0018 D03, D04, D10). Explicit actions only: link, end-link, transfer, set-primary.
//  - at most one ACTIVE link per (unit, location); at most one ACTIVE primary per unit (promotion demotes the
//    previous primary in the same transaction); the first active link of a unit is primary;
//  - property_id, when used, belongs to the same location; OTHER occupation requires a reason;
//  - source_document_id is never accepted and new links keep it NULL; historic rows are never rewritten;
//  - end-link / transfer close with ends_at + ENDED and a mandatory reason; starts_at is never edited;
//  - the last active link of an operational location ends only by transfer or together with CLOSE.
// Linking an EXISTING location needs UNIT_LINK_MANAGE on the target unit AND prior authority over the location
// through another active link in scope (anti-escalation): a known public_id never attaches a foreign location.
final class LinkService
{
    private PhysicalRecords $records;

    public function __construct(private PhysicalRuntime $rt)
    {
        $this->records = new PhysicalRecords($rt);
    }

    public function listForLocation(int $user, int $session, string $locationPublic, array $q): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($locationPublic, $q): array {
            $guard->requires(PhysicalCatalog::LOCATION_VIEW);
            $location = $this->records->locationId($locationPublic);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::LOCATION_VIEW, $location);
            $visible = $this->rt->authority->covered($actor, PhysicalCatalog::LOCATION_VIEW) + $this->rt->authority->covered($actor, PhysicalCatalog::LINK_MANAGE);
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $query = $this->rt->db->table('unit_location_links')->where('location_id', $location)->whereIn('unit_id', array_keys($visible));
            if (!filter_var($q['history'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $query->where('status', PhysicalCatalog::LINK_ACTIVE);
            }
            $total = (clone $query)->count();
            $rows = $query->orderByRaw("status = 'ACTIVE' DESC")->orderByDesc('is_primary')->orderByDesc('starts_at')->orderByDesc('id')->forPage($page, $per)->get()->all();
            $others = $this->rt->db->table('unit_location_links')->where('location_id', $location)->where('status', PhysicalCatalog::LINK_ACTIVE)->whereNotIn('unit_id', array_keys($visible))->count();
            return ['items' => $this->records->projectLinks($rows), 'page' => $page, 'per_page' => $per, 'total' => $total, 'other_active_links' => $others];
        });
    }

    public function listForUnit(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($q): array {
            $visible = $this->rt->authority->covered($actor, PhysicalCatalog::LOCATION_VIEW) + $this->rt->authority->covered($actor, PhysicalCatalog::LINK_MANAGE);
            if ($visible === []) {
                throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED);
            }
            $unit = $this->records->unitId($q['unit_public_id'] ?? null);
            if (!isset($visible[$unit])) {
                throw new PhysicalError(PhysicalReason::OUT_OF_SCOPE);
            }
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $query = $this->rt->db->table('unit_location_links')->where('unit_id', $unit);
            $status = (string) ($q['status'] ?? PhysicalCatalog::LINK_ACTIVE);
            if ($status !== 'ALL') {
                $query->where('status', $status);
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('is_primary')->orderByDesc('starts_at')->orderByDesc('id')->forPage($page, $per)->get()->all();
            return ['items' => $this->records->projectLinks($rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    /** Link an EXISTING location to another unit. */
    public function link(int $user, int $session, string $locationPublic, array $in): array
    {
        [$location, $linkId] = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($locationPublic, $in): array {
            $guard->requires(PhysicalCatalog::LINK_MANAGE);
            $location = $this->records->locationId($locationPublic);
            $unit = $this->records->unitId($in['unit_public_id'] ?? null);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::LINK_MANAGE, $location, false);
            $this->rt->authority->forUnit($actor, PhysicalCatalog::LINK_MANAGE, $unit, false);
            $units = $this->rt->lockUnits([$unit]);
            $loc = $this->rt->lockLocation($location);
            $this->rt->lockLinks($location, [$unit]);
            $guard->location(PhysicalCatalog::LINK_MANAGE, $location);
            $guard->unit(PhysicalCatalog::LINK_MANAGE, $unit);
            if ($loc->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::LOCATION_CLOSED);
            }
            $type = $this->records->occupationType($in['occupation_type_code'] ?? null);
            $property = $this->records->propertyOnLocation($in['property_public_id'] ?? null, $location);
            return [$location, $this->insertLink($guard, $actor, $this->operationalUnit($units, $unit), $location, $type, $property, (bool) ($in['is_primary'] ?? false), PhysicalRecords::optionalReason($in['reason'] ?? null), $this->rt->ts())];
        });
        return $this->one($location, $linkId);
    }

    public function end(int $user, int $session, string $locationPublic, string $ref, array $in): array
    {
        [$location, $linkId] = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($locationPublic, $ref, $in): array {
            $guard->requires(PhysicalCatalog::LINK_MANAGE);
            $location = $this->records->locationId($locationPublic);
            $link = $this->records->linkOf($location, $ref);
            $this->rt->authority->forUnit($actor, PhysicalCatalog::LINK_MANAGE, (int) $link->unit_id, false);
            $reason = PhysicalRecords::requireReason($in['reason'] ?? null);
            $close = filter_var($in['close_location'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $this->rt->lockUnits([(int) $link->unit_id]);
            $loc = $this->rt->lockLocation($location);
            if ($close) {
                $this->lockDependents($location);
            }
            $links = $this->rt->lockLinks($location);
            $decision = $guard->unit(PhysicalCatalog::LINK_MANAGE, (int) $link->unit_id);
            $current = $links[(int) $link->id];
            $this->records->assertVersion($current, $in['lock_version'] ?? null, 'unit_location_links');
            if ($current->status !== PhysicalCatalog::LINK_ACTIVE) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'unit_location_links']);
            }
            $now = $this->rt->ts();
            $remaining = array_filter($links, fn (object $l): bool => (int) $l->id !== (int) $current->id && $l->status === PhysicalCatalog::LINK_ACTIVE
                && (string) $l->starts_at <= $now && ($l->ends_at === null || (string) $l->ends_at > $now));
            $correlation = null;
            if ($close) {
                if ($remaining !== []) {
                    throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'close_location']);
                }
                $manage = $guard->location(PhysicalCatalog::LOCATION_MANAGE, $location);
                $correlation = (new LocationService($this->rt))->closeLocked($guard, $actor, $manage, $loc, $reason);
            } elseif ($remaining === [] && $loc->status !== PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::LAST_ACTIVE_LINK_REQUIRED);
            }
            $this->endRow($current, $now);
            $guard->touch((int) $current->unit_id, $location);
            $this->rt->audit->record($actor, $decision->unit, 'UNIT_LOCATION_LINK_END', 'UNIT_LOCATION_LINK', (int) $current->id,
                ['status' => PhysicalCatalog::LINK_ACTIVE, 'is_primary' => (int) $current->is_primary, 'lock_version' => (int) $current->lock_version],
                ['status' => PhysicalCatalog::LINK_ENDED, 'ends_at' => $now, 'location_entity' => $location, 'unit_entity' => (int) $current->unit_id, 'location_closed' => $close, 'lock_version' => (int) $current->lock_version + 1],
                $reason, $correlation);
            return [$location, (int) $current->id];
        });
        return $this->one($location, $linkId, true);
    }

    public function transfer(int $user, int $session, string $locationPublic, string $ref, array $in): array
    {
        [$location, $newId] = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($locationPublic, $ref, $in): array {
            $guard->requires(PhysicalCatalog::LINK_MANAGE);
            $location = $this->records->locationId($locationPublic);
            $link = $this->records->linkOf($location, $ref);
            $target = $this->records->unitId($in['to_unit_public_id'] ?? null);
            // Source authority, then Territorial validation of the destination (scope + operational unit).
            $this->rt->authority->forUnit($actor, PhysicalCatalog::LINK_MANAGE, (int) $link->unit_id, false);
            $this->rt->authority->forUnit($actor, PhysicalCatalog::LINK_MANAGE, $target, false);
            $reason = PhysicalRecords::requireReason($in['reason'] ?? null);
            if ($target === (int) $link->unit_id) {
                throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'to_unit_public_id']);
            }
            $units = $this->rt->lockUnits([(int) $link->unit_id, $target]);
            $loc = $this->rt->lockLocation($location);
            $links = $this->rt->lockLinks($location, [$target]);
            $source = $guard->unit(PhysicalCatalog::LINK_MANAGE, (int) $link->unit_id);
            $guard->unit(PhysicalCatalog::LINK_MANAGE, $target);
            $current = $links[(int) $link->id];
            $this->records->assertVersion($current, $in['lock_version'] ?? null, 'unit_location_links');
            if ($current->status !== PhysicalCatalog::LINK_ACTIVE) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'unit_location_links']);
            }
            if ($loc->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::LOCATION_CLOSED);
            }
            $destination = $this->operationalUnit($units, $target);
            $type = array_key_exists('occupation_type_code', $in) ? $this->records->occupationType($in['occupation_type_code']) : $this->rt->db->table('occupation_types')->where('id', $current->occupation_type_id)->first();
            if ((int) $type->is_active !== 1) {
                throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'occupation_type_code']);
            }
            $property = array_key_exists('property_public_id', $in) ? $this->records->propertyOnLocation($in['property_public_id'], $location) : ($current->property_id === null ? null : (int) $current->property_id);
            // One instant closes the old period and opens the new one: [old.starts_at, now) + [now, ...), no gap.
            $now = $this->rt->ts();
            $correlation = $this->rt->audit->record($actor, $source->unit, 'UNIT_LOCATION_LINK_TRANSFER', 'UNIT_LOCATION_LINK', (int) $current->id,
                ['status' => PhysicalCatalog::LINK_ACTIVE, 'unit_entity' => (int) $current->unit_id, 'is_primary' => (int) $current->is_primary, 'lock_version' => (int) $current->lock_version],
                ['status' => PhysicalCatalog::LINK_ENDED, 'ends_at' => $now, 'old_unit_entity' => (int) $current->unit_id, 'new_unit_entity' => $target, 'location_entity' => $location, 'lock_version' => (int) $current->lock_version + 1],
                $reason);
            $this->endRow($current, $now);
            $guard->touch((int) $current->unit_id, $location);
            return [$location, $this->insertLink($guard, $actor, $destination, $location, $type, $property, (bool) ($in['is_primary'] ?? false), $reason, $now, $correlation)];
        });
        return $this->one($location, $newId, true);
    }

    public function setPrimary(int $user, int $session, string $locationPublic, string $ref, array $in): array
    {
        [$location, $linkId] = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($locationPublic, $ref, $in): array {
            $guard->requires(PhysicalCatalog::LINK_MANAGE);
            $location = $this->records->locationId($locationPublic);
            $link = $this->records->linkOf($location, $ref);
            $this->rt->authority->forUnit($actor, PhysicalCatalog::LINK_MANAGE, (int) $link->unit_id, false);
            $this->rt->lockUnits([(int) $link->unit_id]);
            $this->rt->lockLocation($location);
            $links = $this->rt->lockLinks($location, [(int) $link->unit_id]);
            $decision = $guard->unit(PhysicalCatalog::LINK_MANAGE, (int) $link->unit_id);
            $current = $links[(int) $link->id];
            $this->records->assertVersion($current, $in['lock_version'] ?? null, 'unit_location_links');
            $now = $this->rt->ts();
            if ($current->status !== PhysicalCatalog::LINK_ACTIVE || (string) $current->starts_at > $now) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'unit_location_links']);
            }
            $guard->touch((int) $current->unit_id, $location);
            if ((int) $current->is_primary === 1) {
                return [$location, (int) $current->id];
            }
            $demoted = $this->demotePrimary((int) $current->unit_id, (int) $current->id);
            $changed = $this->rt->db->table('unit_location_links')->where('id', $current->id)->where('lock_version', $current->lock_version)
                ->update(['is_primary' => 1, 'lock_version' => (int) $current->lock_version + 1]);
            if ($changed !== 1) {
                throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'unit_location_links']);
            }
            $this->rt->audit->record($actor, $decision->unit, 'UNIT_LOCATION_LINK_SET_PRIMARY', 'UNIT_LOCATION_LINK', (int) $current->id,
                ['is_primary' => 0, 'lock_version' => (int) $current->lock_version, 'previous_primary_entities' => $demoted],
                ['is_primary' => 1, 'lock_version' => (int) $current->lock_version + 1, 'location_entity' => $location, 'unit_entity' => (int) $current->unit_id],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
            return [$location, (int) $current->id];
        });
        return $this->one($location, $linkId, true);
    }

    /**
     * The single link-insertion primitive (location creation, link, transfer). The caller holds the unit, location
     * and link locks in D11 order and has authorized the unit.
     */
    public function insertLink(PhysicalGuard $guard, TerritorialActor $actor, object $unit, int $location, object $type, ?int $property, bool $requestPrimary, ?string $reason, string $now, ?string $correlation = null): int
    {
        if ((string) $type->code === PhysicalCatalog::OCCUPATION_REQUIRES_REASON && $reason === null) {
            throw new PhysicalError(PhysicalReason::REASON_REQUIRED, ['field' => 'reason']);
        }
        $pair = $this->rt->db->table('unit_location_links')->where('unit_id', $unit->id)->where('location_id', $location)->where('status', PhysicalCatalog::LINK_ACTIVE)->lockForUpdate()->exists();
        if ($pair) {
            throw new PhysicalError(PhysicalReason::LINK_EXISTS);
        }
        $hasPrimary = $this->rt->db->table('unit_location_links')->where('unit_id', $unit->id)->where('status', PhysicalCatalog::LINK_ACTIVE)->where('is_primary', 1)->lockForUpdate()->exists();
        $primary = !$hasPrimary || $requestPrimary;
        $demoted = $primary && $hasPrimary ? $this->demotePrimary((int) $unit->id, null) : [];
        $id = (int) $this->rt->db->table('unit_location_links')->insertGetId([
            'unit_id' => (int) $unit->id, 'location_id' => $location, 'property_id' => $property,
            'occupation_type_id' => (int) $type->id, 'is_primary' => $primary ? 1 : 0, 'status' => PhysicalCatalog::LINK_ACTIVE,
            'starts_at' => $now, 'ends_at' => null, 'reason' => $reason, 'source_document_id' => null,
            'created_at' => $now, 'lock_version' => 0,
        ]);
        $guard->touch((int) $unit->id, $location);
        $this->rt->audit->record($actor, (int) $unit->id, 'UNIT_LOCATION_LINK_CREATE', 'UNIT_LOCATION_LINK', $id, null,
            ['location_entity' => $location, 'unit_entity' => (int) $unit->id, 'property_entity' => $property, 'occupation_type' => (string) $type->code,
             'is_primary' => $primary, 'previous_primary_entities' => $demoted, 'status' => PhysicalCatalog::LINK_ACTIVE], $reason, $correlation);
        return $id;
    }

    /** @param array<int, object> $units */
    public function operationalUnit(array $units, int $unit): object
    {
        $row = $units[$unit] ?? null;
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'organizational_units']);
        }
        if ((string) $row->status === 'CLOSED') {
            throw new PhysicalError(PhysicalReason::UNIT_NOT_OPERATIONAL);
        }
        return $row;
    }

    /** @return list<int> demoted link ids */
    private function demotePrimary(int $unit, ?int $except): array
    {
        $query = $this->rt->db->table('unit_location_links')->where('unit_id', $unit)->where('status', PhysicalCatalog::LINK_ACTIVE)->where('is_primary', 1);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        // Locking read: a plain read would use this transaction's older snapshot and miss a primary promoted by a
        // concurrent set-primary that committed while we waited for the unit lock (C1).
        $ids = array_map('intval', (clone $query)->lockForUpdate()->pluck('id')->all());
        if ($ids !== []) {
            $this->rt->db->table('unit_location_links')->whereIn('id', $ids)->update(['is_primary' => 0, 'lock_version' => $this->rt->db->raw('lock_version + 1')]);
        }
        return $ids;
    }

    private function endRow(object $link, string $now): void
    {
        $changed = $this->rt->db->table('unit_location_links')->where('id', $link->id)->where('lock_version', $link->lock_version)->where('status', PhysicalCatalog::LINK_ACTIVE)
            ->update(['status' => PhysicalCatalog::LINK_ENDED, 'ends_at' => $now, 'lock_version' => (int) $link->lock_version + 1]);
        if ($changed !== 1) {
            throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'unit_location_links']);
        }
    }

    private function lockDependents(int $location): void
    {
        $this->rt->db->table('properties')->where('location_id', $location)->orderBy('id')->lockForUpdate()->get(['id']);
        $this->rt->db->table('temples')->where('location_id', $location)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function one(int $location, int $linkId, bool $allowEnded = false): array
    {
        $row = $this->rt->db->table('unit_location_links')->where('id', $linkId)->where('location_id', $location)->first();
        if (!$row || (!$allowEnded && $row->status !== PhysicalCatalog::LINK_ACTIVE)) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'unit_location_links']);
        }
        return $this->records->projectLinks([$row])[0];
    }
}
