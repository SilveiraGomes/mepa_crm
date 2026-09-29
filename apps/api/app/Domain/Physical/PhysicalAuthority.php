<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;
use App\Domain\Territorial\TerritorialAuthority;
use App\Domain\Territorial\TerritorialError;
use Illuminate\Database\Connection;

// Authority resolver for Physical Locations, Properties, Temples and links (ADR 0018 D03).
//
//   permission (data_type PHYSICAL, action = code)
//   + active role and institutional UNIT scope of the SAME grant (TerritorialAuthority: the one scope engine)
//   + an ACTIVE unit_location_links row, vigente now ([starts_at, ends_at)), whose unit is covered by that scope
//   = decision; the link's unit is the audit unit.
//
// A location grants nothing by itself, has no owner unit, and a known public_id is never authority. Property and
// Temple authority is the authority over their location_id. Unit decisions (create location, link targets,
// transfer destinations) require the unit itself to be covered.
final class PhysicalAuthority
{
    public function __construct(private Connection $db, private TerritorialAuthority $scope)
    {
    }

    public function actor(int $user, int $session, bool $lock = false): TerritorialActor
    {
        try {
            return $this->scope->actor($user, $session, $lock);
        } catch (TerritorialError) {
            throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['reason' => 'actor']);
        }
    }

    /** @return array<int, true> units covered by the actor's active grants of $permission */
    public function covered(TerritorialActor $actor, string $permission, bool $lock = false): array
    {
        return $this->scope->coveredUnits($actor, $permission, $lock);
    }

    public function holdsAnywhere(TerritorialActor $actor, string $permission): bool
    {
        return $this->scope->scopeRoots($actor, $permission) !== [];
    }

    /** @return list<string> Physical permission codes held on at least one unit (roles/grants never leave the server) */
    public function effectivePermissions(TerritorialActor $actor): array
    {
        return array_values(array_filter(array_keys(PhysicalCatalog::PERMISSIONS), fn (string $p): bool => $this->holdsAnywhere($actor, $p)));
    }

    public function forUnit(TerritorialActor $actor, string $permission, int $unit, bool $lock = false): PhysicalDecision
    {
        $covered = $this->covered($actor, $permission, $lock);
        if ($covered === []) {
            throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['permission' => $permission]);
        }
        if (!isset($covered[$unit])) {
            throw new PhysicalError(PhysicalReason::OUT_OF_SCOPE, ['permission' => $permission]);
        }
        return new PhysicalDecision($permission, $unit);
    }

    /** Authority over a location through one of its active vigente links inside the actor's scope. */
    public function forLocation(TerritorialActor $actor, string $permission, int $location, bool $lock = false): PhysicalDecision
    {
        $covered = $this->covered($actor, $permission, $lock);
        if ($covered === []) {
            throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['permission' => $permission]);
        }
        foreach ($this->activeLinks($location, $lock) as $link) {
            if (isset($covered[(int) $link->unit_id])) {
                return new PhysicalDecision($permission, (int) $link->unit_id, $location, (int) $link->id);
            }
        }
        throw new PhysicalError(PhysicalReason::OUT_OF_SCOPE, ['permission' => $permission]);
    }

    public function holdsOnLocation(TerritorialActor $actor, string $permission, int $location): bool
    {
        try {
            $this->forLocation($actor, $permission, $location);
            return true;
        } catch (PhysicalError) {
            return false;
        }
    }

    /**
     * Final, locking re-verification before commit: actor/session and the grant covering the decision unit are
     * re-read FOR SHARE. The authorizing link rows are held FOR UPDATE by the operation itself, so only this
     * transaction could have changed them.
     */
    public function recheck(TerritorialActor $actor, PhysicalDecision $decision): void
    {
        $covered = $this->covered($actor, $decision->permission, true);
        if ($covered === []) {
            throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['stage' => 'final', 'permission' => $decision->permission]);
        }
        if (!isset($covered[$decision->unit])) {
            throw new PhysicalError(PhysicalReason::OUT_OF_SCOPE, ['stage' => 'final', 'permission' => $decision->permission]);
        }
    }

    /**
     * SQL predicate for list/search: the location aliased $alias has an active vigente link in $units.
     * @param array<int, true> $units
     * @return array{0: string, 1: array}
     */
    public function visiblePredicate(string $alias, array $units): array
    {
        if ($units === []) {
            return ['1 = 0', []];
        }
        $now = $this->now();
        $list = implode(',', array_map('intval', array_keys($units)));
        return ["EXISTS (SELECT 1 FROM unit_location_links vis WHERE vis.location_id = {$alias}.id AND vis.status = 'ACTIVE' AND vis.starts_at <= ? AND (vis.ends_at IS NULL OR vis.ends_at > ?) AND vis.unit_id IN ({$list}))", [$now, $now]];
    }

    /** @return list<object> active vigente links of a location, primary first, then oldest */
    public function activeLinks(int $location, bool $lock = false): array
    {
        $now = $this->now();
        $sql = "SELECT id, unit_id, is_primary FROM unit_location_links WHERE location_id = ? AND status = 'ACTIVE' AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY is_primary DESC, id" . ($lock ? ' FOR SHARE' : '');
        return $this->db->select($sql, [$location, $now, $now]);
    }

    public function now(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }
}
