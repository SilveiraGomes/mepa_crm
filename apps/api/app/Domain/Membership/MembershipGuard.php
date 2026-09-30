<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;

// Collects the decisions, the memberships and the source documents an operation touched, plus the official number of
// each touched membership as it was when first locked, so the runtime can re-verify authority, the Files authority of
// every referenced document and the period/number invariants as the last statements before commit.
final class MembershipGuard
{
    /** @var list<MembershipDecision> */
    private array $decisions = [];
    /** @var array<int, ?string> membership id => number when first touched (null = none yet) */
    private array $memberships = [];
    /** @var array<int, true> memberships that legitimately receive their first number in this transaction */
    private array $issuing = [];
    /** @var array<int, object> legal document id => row */
    private array $documents = [];

    public function __construct(public MembershipAuthority $authority, public TerritorialActor $actor)
    {
    }

    /**
     * F-06 ordering (lesson P07-I-02): the permission is checked BEFORE any target is resolved, so a 403 depends only
     * on the actor's grants and never reveals whether a public id exists; every target failure after this point is
     * the same 404.
     */
    public function requires(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->authority->holdsAnywhere($this->actor, $permission)) {
                throw new MembershipError(MembershipReason::NOT_AUTHORIZED, ['permission' => $permission]);
            }
        }
    }

    /** Authority over $unit, read FOR SHARE, recorded for the commit-time recheck. */
    public function unit(string $permission, int $unit): MembershipDecision
    {
        $decision = $this->authority->forUnit($this->actor, $permission, $unit, true);
        $this->decisions[] = $decision;
        return $decision;
    }

    public function touch(int $membership, ?string $number): void
    {
        if (!array_key_exists($membership, $this->memberships)) {
            $this->memberships[$membership] = $number;
        }
    }

    public function issuing(int $membership): void
    {
        $this->issuing[$membership] = true;
    }

    public function document(object $document): void
    {
        $this->documents[(int) $document->id] = $document;
    }

    /** @return list<MembershipDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /** @return array<int, ?string> */
    public function memberships(): array
    {
        return $this->memberships;
    }

    public function isIssuing(int $membership): bool
    {
        return isset($this->issuing[$membership]);
    }

    /** @return array<int, object> */
    public function documents(): array
    {
        return $this->documents;
    }
}
