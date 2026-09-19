<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Connection;

// Generic transition engine for Enrollment, Class, Attempt, Grade, Certificate (and the other
// status-bearing tables). Only transitions the policy marks APPROVED can execute; a transition
// parked as PENDING is denied explicitly (STATE_POLICY_PENDING); anything else is INVALID_TRANSITION.
// Every approved transition also declares its business EFFECT (AcademyPolicy::effect). A caller that
// has not enforced the guard of that effect can only execute EFFECT_NONE transitions, so no generic
// entry point can reach the outcome of a specialized operation (e.g. completion) without that
// operation's guard. Authorization and audit belong to the caller (AcademyRuntime::write); this
// class owns the current-state read, the guard and the optimistic-locked persistence.
final class AcademyStateMachine
{
    public function __construct(private Connection $db, private AcademyPolicy $policy)
    {
    }

    // Verdict + declared effect of the transition from -> to. Returns the effect the caller has to
    // enforce (EFFECT_NONE = nothing beyond authorization); throws for anything that is not APPROVED.
    public function approvedEffect(string $kind, string $from, string $to): string
    {
        if ($from === $to) {
            throw new AcademyError(AcademyReason::INVALID_TRANSITION, ['kind' => $kind]);
        }
        $verdict = $this->policy->transition($kind, $from, $to);
        if ($verdict === AcademyPolicy::PENDING) {
            throw new AcademyError(AcademyReason::STATE_POLICY_PENDING, ['kind' => $kind]);
        }
        if ($verdict !== AcademyPolicy::APPROVED) {
            throw new AcademyError(AcademyReason::INVALID_TRANSITION, ['kind' => $kind]);
        }
        return $this->policy->effect($kind, $from, $to);
    }

    // $enforced = the effect whose guard the caller has already run (default: none).
    public function assertTransition(string $kind, string $from, string $to, string $enforced = AcademyPolicy::EFFECT_NONE): void
    {
        $declared = $this->approvedEffect($kind, $from, $to);
        if ($declared !== $enforced) {
            throw new AcademyError(AcademyReason::INVALID_TRANSITION, ['kind' => $kind, 'reason' => 'effect_guard_not_enforced', 'effect' => $declared]);
        }
    }

    // Locks the row, guards the transition, and updates status (+ extra columns) with lock_version.
    // Returns ['from', 'to', 'lock_version'] for the caller's audit record.
    public function move(string $kind, string $table, int $id, string $to, ?int $expectedLockVersion = null, array $extra = [], string $column = 'status', string $enforced = AcademyPolicy::EFFECT_NONE): array
    {
        $row = $this->db->table($table)->where('id', $id)->lockForUpdate()->first();
        if (!$row) {
            throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        if ($expectedLockVersion !== null && (int) $row->lock_version !== $expectedLockVersion) {
            throw new AcademyError(AcademyReason::STALE_WRITE, ['entity' => $table, 'current_lock_version' => (int) $row->lock_version]);
        }
        $this->assertTransition($kind, (string) $row->{$column}, $to, $enforced);
        $updated = $this->db->table($table)->where('id', $id)->where('lock_version', $row->lock_version)
            ->update([$column => $to, 'lock_version' => (int) $row->lock_version + 1] + $extra);
        if ($updated !== 1) {
            throw new AcademyError(AcademyReason::STALE_WRITE, ['entity' => $table]);
        }
        return ['from' => (string) $row->{$column}, 'to' => $to, 'lock_version' => (int) $row->lock_version + 1];
    }
}
