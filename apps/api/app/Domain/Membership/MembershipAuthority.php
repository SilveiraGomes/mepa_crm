<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;
use App\Domain\Territorial\TerritorialAuthority;
use App\Domain\Territorial\TerritorialError;

// Authority resolver for Membership (ADR 0020 D05).
//
//   permission (data_type MEMBERSHIP, action = code)
//   + active role and institutional UNIT scope of the SAME grant (TerritorialAuthority: the one scope engine)
//   + the unit is the Congregation of the membership's OPEN period (or the transfer origin/destination for the stage)
//   = decision; that unit is the audit unit.
//
// A permission never replaces the scope, and a known public_id or URI never grants anything.
final class MembershipAuthority
{
    /** @var array<string, array<int, true>> non-locking coverage cache (one request) */
    private array $cache = [];

    public function __construct(private TerritorialAuthority $scope)
    {
    }

    public function actor(int $user, int $session, bool $lock = false): TerritorialActor
    {
        try {
            return $this->scope->actor($user, $session, $lock);
        } catch (TerritorialError) {
            throw new MembershipError(MembershipReason::NOT_AUTHORIZED, ['reason' => 'actor']);
        }
    }

    /** @return array<int, true> units covered by the actor's active grants of $permission */
    public function covered(TerritorialActor $actor, string $permission, bool $lock = false): array
    {
        if ($lock) {
            return $this->scope->coveredUnits($actor, $permission, true);
        }
        $key = $actor->user . '|' . $actor->session . '|' . $permission;
        return $this->cache[$key] ??= $this->scope->coveredUnits($actor, $permission);
    }

    public function holdsAnywhere(TerritorialActor $actor, string $permission): bool
    {
        return $this->covered($actor, $permission) !== [];
    }

    public function holdsOn(TerritorialActor $actor, string $permission, int $unit): bool
    {
        return isset($this->covered($actor, $permission)[$unit]);
    }

    /** Lowest scope root of $permission: the audit unit of a scope-wide operation (a search), null without grant. */
    public function rootUnit(TerritorialActor $actor, string $permission): ?int
    {
        $roots = array_keys($this->scope->scopeRoots($actor, $permission));
        sort($roots);
        return $roots[0] ?? null;
    }

    /** @return list<string> Membership permission codes held on at least one unit (roles/grants never leave the server) */
    public function effectivePermissions(TerritorialActor $actor): array
    {
        return array_values(array_filter(array_keys(MembershipCatalog::PERMISSIONS), fn (string $p): bool => $this->holdsAnywhere($actor, $p)));
    }

    /** @return list<string> Membership permission codes held on $unit */
    public function permissionsOn(TerritorialActor $actor, int $unit): array
    {
        return array_values(array_filter(array_keys(MembershipCatalog::PERMISSIONS), fn (string $p): bool => $this->holdsOn($actor, $p, $unit)));
    }

    public function forUnit(TerritorialActor $actor, string $permission, int $unit, bool $lock = false): MembershipDecision
    {
        $covered = $this->covered($actor, $permission, $lock);
        if ($covered === []) {
            throw new MembershipError(MembershipReason::NOT_AUTHORIZED, ['permission' => $permission]);
        }
        if (!isset($covered[$unit])) {
            throw new MembershipError(MembershipReason::OUT_OF_SCOPE, ['permission' => $permission]);
        }
        return new MembershipDecision($permission, $unit);
    }

    /**
     * Final, locking re-verification before commit: the grant covering the decision unit is re-read FOR SHARE. A
     * revoked grant, an expired scope or a unit moved out of the scope fails the whole transaction.
     */
    public function recheck(TerritorialActor $actor, MembershipDecision $decision): void
    {
        $covered = $this->covered($actor, $decision->permission, true);
        if ($covered === []) {
            throw new MembershipError(MembershipReason::NOT_AUTHORIZED, ['stage' => 'final', 'permission' => $decision->permission]);
        }
        if (!isset($covered[$decision->unit])) {
            throw new MembershipError(MembershipReason::OUT_OF_SCOPE, ['stage' => 'final', 'permission' => $decision->permission]);
        }
    }
}
