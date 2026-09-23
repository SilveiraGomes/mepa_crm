<?php

declare(strict_types=1);

namespace App\Domain\People;

// Per-operation authorization recorder. Every decision taken during a write is re-verified by
// PeopleRuntime with locking reads as the last statement before commit.
//
// F-06: when a target-bound check fails, the external outcome depends only on whether the actor can
// see the target at all. Not visible (unknown, other scope, malformed) -> TARGET_NOT_FOUND, which
// the HTTP layer renders exactly like a nonexistent resource. Visible but lacking the operation
// permission -> FORBIDDEN (reveals nothing the actor cannot already read).
final class PeopleGuard
{
    /** @var list<PeopleDecision> */
    private array $decisions = [];

    public function __construct(public PeopleAuthority $authority, public PeopleActor $actor)
    {
    }

    public function require(string $permission, int $personId, ?int $preferredUnit = null, array $tiers = [PeopleAuthority::GENERAL]): PeopleDecision
    {
        try {
            $decision = $this->authority->authorize($this->actor, $permission, $personId, $preferredUnit, $tiers);
        } catch (PeopleError $e) {
            if (in_array($e->reason, [PeopleReason::NOT_AUTHORIZED, PeopleReason::OUT_OF_SCOPE], true)) {
                $visible = $this->authority->canView($this->actor, $personId);
                throw new PeopleError($visible ? PeopleReason::FORBIDDEN : PeopleReason::TARGET_NOT_FOUND, ['permission' => $permission], $e);
            }
            throw $e;
        }
        $this->decisions[] = $decision;
        return $decision;
    }

    /** Units covered by the actor's grants of $permission; NOT_AUTHORIZED when there are none. */
    public function covered(string $permission): array
    {
        $units = $this->authority->coveredUnits($this->actor, $permission);
        if ($units === []) {
            throw new PeopleError(PeopleReason::NOT_AUTHORIZED, ['permission' => $permission]);
        }
        return $units;
    }

    public function holds(string $permission, int $personId, array $tiers = [PeopleAuthority::GENERAL]): bool
    {
        return $this->authority->holds($this->actor, $permission, $personId, $tiers);
    }

    public function record(PeopleDecision $decision): void
    {
        $this->decisions[] = $decision;
    }

    /** @return list<PeopleDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }
}
