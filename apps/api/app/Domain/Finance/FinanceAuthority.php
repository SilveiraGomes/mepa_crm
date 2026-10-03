<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use App\Domain\Territorial\TerritorialAuthority;
use App\Domain\Territorial\TerritorialError;

/**
 * Authority resolver for Finance (ADR 0021 D17).
 *
 *   permission (data_type FINANCE, action = code)
 *   + active role and institutional UNIT scope of the SAME grant (TerritorialAuthority: the one scope engine)
 *   + the OWNER UNIT of the object, read by the service under lock (account unit, transfer origin/destination for the
 *     stage, contribution receiving unit)
 *   = decision; that unit is the audit unit.
 *
 * A permission never replaces the scope; a public_id, a URI or a unit sent by the client never grants anything; a
 * department is never an economic owner (D18).
 */
final class FinanceAuthority
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
            throw new FinanceError('NOT_AUTHORIZED', [], ['reason' => 'actor']);
        }
    }

    /** @return array<int, true> units covered by the actor's active grants of $permission */
    public function covered(TerritorialActor $actor, string $permission, bool $lock = false): array
    {
        if ($lock) {
            return $this->scope->coveredUnits($actor, $permission, true);
        }
        return $this->cache[$actor->user . '|' . $actor->session . '|' . $permission] ??= $this->scope->coveredUnits($actor, $permission);
    }

    public function holdsAnywhere(TerritorialActor $actor, string $permission): bool
    {
        return $this->covered($actor, $permission) !== [];
    }

    public function holdsOn(TerritorialActor $actor, string $permission, int $unit): bool
    {
        return isset($this->covered($actor, $permission)[$unit]);
    }

    /** @return array<int, int> scope roots (unit => include descendants) of $permission */
    public function roots(TerritorialActor $actor, string $permission): array
    {
        return $this->scope->scopeRoots($actor, $permission);
    }

    /** @return list<string> FINANCE permission codes held on at least one unit */
    public function effectivePermissions(TerritorialActor $actor): array
    {
        return array_values(array_filter(FinanceCatalog::PERMISSIONS, fn (string $p): bool => $this->holdsAnywhere($actor, $p)));
    }

    public function forUnit(TerritorialActor $actor, string $permission, int $unit, bool $lock = false): FinanceDecision
    {
        $covered = $this->covered($actor, $permission, $lock);
        if ($covered === []) {
            throw new FinanceError('NOT_AUTHORIZED', [], ['permission' => $permission]);
        }
        if (!isset($covered[$unit])) {
            throw new FinanceError('OUT_OF_SCOPE', [], ['permission' => $permission]);
        }
        return new FinanceDecision($permission, $unit);
    }

    /** Commit-time re-verification: the grant covering the decision unit is re-read FOR SHARE. */
    public function recheck(TerritorialActor $actor, FinanceDecision $decision): void
    {
        $covered = $this->covered($actor, $decision->permission, true);
        if ($covered === []) {
            throw new FinanceError('NOT_AUTHORIZED', [], ['stage' => 'final', 'permission' => $decision->permission]);
        }
        if (!isset($covered[$decision->unit])) {
            throw new FinanceError('OUT_OF_SCOPE', [], ['stage' => 'final', 'permission' => $decision->permission]);
        }
    }
}
