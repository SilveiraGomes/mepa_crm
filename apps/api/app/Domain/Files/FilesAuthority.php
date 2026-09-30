<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;
use App\Domain\Territorial\TerritorialAuthority;
use App\Domain\Territorial\TerritorialError;
use Illuminate\Database\Connection;

// Authority resolver for Documents/Files (ADR 0019 D01, D05, D09, D10).
//
//   permission (data_type FILES, action = code) + active role and institutional UNIT scope of the SAME grant that covers
//   files.owner_unit_id (TerritorialAuthority with data_type FILES: the one scope engine, no second one)
//   + clearance >= classification, where clearance is computed PER OWNER UNIT:
//       any operation permission in scope          -> RESTRICTED
//       FILES_CONFIDENTIAL_ACCESS covering the unit -> CONFIDENTIAL
//       FILES_HIGHLY_SENSITIVE_ACCESS covering it   -> HIGHLY_SENSITIVE
//   A clearance permission alone grants no operation. A permission never replaces the scope.
final class FilesAuthority
{
    /** @var array<string, array<int, true>> non-locking coverage cache (one request) */
    private array $cache = [];

    public function __construct(private Connection $db, private TerritorialAuthority $scope)
    {
    }

    public function actor(int $user, int $session, bool $lock = false): TerritorialActor
    {
        try {
            return $this->scope->actor($user, $session, $lock);
        } catch (TerritorialError) {
            throw new FilesError(FilesReason::NOT_AUTHORIZED, ['reason' => 'actor']);
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

    /**
     * F-06 ordering (lesson P07-I-02): the permission is checked BEFORE any target is resolved, so a 403 depends only
     * on the actor's grants and never reveals whether a public id exists.
     */
    public function requireAnywhere(TerritorialActor $actor, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->holdsAnywhere($actor, $permission)) {
                throw new FilesError(FilesReason::NOT_AUTHORIZED, ['permission' => $permission]);
            }
        }
    }

    /** Scope over a resolved unit; out of scope is concealed (F-06). */
    public function requireUnit(TerritorialActor $actor, string $permission, int $unit, bool $lock = false): void
    {
        if (!isset($this->covered($actor, $permission, $lock)[$unit])) {
            throw new FilesError(FilesReason::OUT_OF_SCOPE, ['permission' => $permission]);
        }
    }

    public function holdsOn(TerritorialActor $actor, string $permission, int $unit): bool
    {
        return isset($this->covered($actor, $permission)[$unit]);
    }

    /** Clearance rank of the actor over files owned by $unit (see header). */
    public function clearance(TerritorialActor $actor, int $unit, bool $lock = false): int
    {
        if (isset($this->covered($actor, FilesCatalog::FILES_HIGHLY_SENSITIVE_ACCESS, $lock)[$unit])) {
            return FileClassification::ORDER[FileClassification::HIGHLY_SENSITIVE];
        }
        if (isset($this->covered($actor, FilesCatalog::FILES_CONFIDENTIAL_ACCESS, $lock)[$unit])) {
            return FileClassification::ORDER[FileClassification::CONFIDENTIAL];
        }
        return FileClassification::ORDER[FileClassification::BASE_CLEARANCE];
    }

    /**
     * Groups units by the actor's clearance rank, for list predicates.
     * @param array<int, true> $units @return array<int, list<int>> rank => units
     */
    public function clearanceGroups(TerritorialActor $actor, array $units): array
    {
        $groups = [];
        foreach (array_keys($units) as $unit) {
            $groups[$this->clearance($actor, (int) $unit)][] = (int) $unit;
        }
        return $groups;
    }

    /** @return list<string> Files/Documents permission codes held on at least one unit */
    public function effectivePermissions(TerritorialActor $actor): array
    {
        return array_values(array_filter(array_keys(FilesCatalog::PERMISSIONS), fn (string $p): bool => $this->holdsAnywhere($actor, $p)));
    }

    public function now(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }
}
