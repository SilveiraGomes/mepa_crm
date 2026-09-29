<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;

// Collects the decisions and the units/locations an operation touched, so the runtime can re-verify authority
// and the link invariants as the last statements before commit.
final class PhysicalGuard
{
    /** @var list<PhysicalDecision> */
    private array $decisions = [];
    /** @var array<int, true> */
    private array $units = [];
    /** @var array<int, true> */
    private array $locations = [];

    public function __construct(public PhysicalAuthority $authority, public TerritorialActor $actor)
    {
    }

    /**
     * F-06 ordering: the permission is checked BEFORE any target is resolved, so a 403 depends only on the actor's
     * grants and never reveals whether a public id exists; every target failure after this point is the same 404.
     */
    public function requires(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->authority->holdsAnywhere($this->actor, $permission)) {
                throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['permission' => $permission]);
            }
        }
    }

    public function unit(string $permission, int $unit, bool $lock = true): PhysicalDecision
    {
        return $this->record($this->authority->forUnit($this->actor, $permission, $unit, $lock));
    }

    public function location(string $permission, int $location, bool $lock = true): PhysicalDecision
    {
        return $this->record($this->authority->forLocation($this->actor, $permission, $location, $lock));
    }

    public function record(PhysicalDecision $decision): PhysicalDecision
    {
        $this->decisions[] = $decision;
        return $decision;
    }

    public function touch(?int $unit = null, ?int $location = null): void
    {
        if ($unit !== null) {
            $this->units[$unit] = true;
        }
        if ($location !== null) {
            $this->locations[$location] = true;
        }
    }

    /** @return list<PhysicalDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /** @return list<int> */
    public function units(): array
    {
        return array_keys($this->units);
    }

    /** @return list<int> */
    public function locations(): array
    {
        return array_keys($this->locations);
    }
}
