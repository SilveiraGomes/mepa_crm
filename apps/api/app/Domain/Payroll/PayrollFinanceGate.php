<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceAuthority;
use App\Domain\Finance\FinanceDecision;
use App\Domain\Finance\FinanceError;
use App\Domain\Territorial\TerritorialActor;

/**
 * Cumulative FINANCE authority of the payroll operations that write the ledger (ADR 0021 D26/D31: post / pay / reverse =
 * PAYROLL_POST (HR) + FINANCE_POST (FINANCE) on the employing unit). The payroll runtime evaluates the HR permission
 * domain; this gate evaluates the FINANCE domain through the SAME scope engine (TerritorialAuthority with data_type
 * FINANCE) — no second authority model. Decisions taken under lock are re-verified by recheck(), which the payroll
 * runtime runs as its before-commit step (after every write, with every lock held), exactly like the HR decisions.
 * FINANCE_PAYROLL_SUMMARY_VIEW (D28) is also read here: the Finance side sees only run totals.
 */
final class PayrollFinanceGate
{
    /** @var list<array{0: int, 1: int, 2: FinanceDecision}> [user, session, decision] */
    private array $pending = [];

    public function __construct(private FinanceAuthority $finance)
    {
    }

    public function holdsAnywhere(TerritorialActor $actor, string $permission): bool
    {
        return $this->finance->holdsAnywhere($this->financeActor($actor), $permission);
    }

    public function holdsOn(TerritorialActor $actor, string $permission, int $unit): bool
    {
        return $this->finance->holdsOn($this->financeActor($actor), $permission, $unit);
    }

    /** F-06: NOT_AUTHORIZED (403) when the FINANCE permission is held nowhere; decided before any target. */
    public function requires(TerritorialActor $actor, string $permission): void
    {
        if (!$this->holdsAnywhere($actor, $permission)) {
            throw new FinanceError('NOT_AUTHORIZED', [], ['permission' => $permission]);
        }
    }

    /** Non-locking scope decision on the owner unit (concealed OUT_OF_SCOPE), before any lock or state check. */
    public function preauthorize(TerritorialActor $actor, string $permission, int $unit): void
    {
        $this->finance->forUnit($this->financeActor($actor), $permission, $unit);
    }

    /** Locking decision on the owner unit, recorded for the commit-time recheck. */
    public function unit(TerritorialActor $actor, string $permission, int $unit): FinanceDecision
    {
        $decision = $this->finance->forUnit($this->financeActor($actor), $permission, $unit, true);
        $this->pending[] = [$actor->user, $actor->session, $decision];
        return $decision;
    }

    /** Commit-time re-verification (grant revoked, scope expired, unit moved while the operation waited => rolled back). */
    public function recheck(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$user, $session, $decision]) {
            $this->finance->recheck($this->finance->actor($user, $session, true), $decision);
        }
    }

    /** The actor (user + session) was already validated by the payroll runtime; only the permission domain differs. */
    private function financeActor(TerritorialActor $actor): TerritorialActor
    {
        return $actor;
    }
}
