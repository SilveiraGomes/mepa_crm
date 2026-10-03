<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;

/**
 * Collects the authority decisions and the supporting documents an operation used, so the runtime re-verifies them as
 * the last statements before commit (grants revoked, scopes expired, units moved, document authority lost).
 */
final class FinanceGuard
{
    /** @var list<FinanceDecision> */
    private array $decisions = [];
    /** @var array<int, object> legal document id => row */
    private array $documents = [];

    public function __construct(public FinanceAuthority $authority, public TerritorialActor $actor)
    {
    }

    /**
     * F-06 ordering (lesson P07-I-02): the permissions are checked BEFORE any target is resolved, so a 403 depends only
     * on the actor's grants; every target failure after this point is the same concealed 404.
     */
    public function requires(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->authority->holdsAnywhere($this->actor, $permission)) {
                throw new FinanceError('NOT_AUTHORIZED', [], ['permission' => $permission]);
            }
        }
    }

    /** Authority over $unit, read FOR SHARE, recorded for the commit-time recheck. */
    public function unit(string $permission, int $unit): FinanceDecision
    {
        $decision = $this->authority->forUnit($this->actor, $permission, $unit, true);
        $this->decisions[] = $decision;
        return $decision;
    }

    /** Every permission of $permissions on $unit (cumulative authority, D31). */
    public function all(array $permissions, int $unit): FinanceDecision
    {
        $decision = null;
        foreach ($permissions as $permission) {
            $decision = $this->unit($permission, $unit);
        }
        return $decision;
    }

    public function document(object $document): void
    {
        $this->documents[(int) $document->id] = $document;
    }

    /** @return list<FinanceDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /** @return array<int, object> */
    public function documents(): array
    {
        return $this->documents;
    }
}
