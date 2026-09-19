<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Completion is an explicit, authorized act that depends on a VALID policy (D-09), never on "a row
// exists" or "a grade was recorded". Order of gates: CompletionEligibilityGuard (context, authority,
// policy configured -> POLICY_NOT_CONFIGURED, every criterion met -> COMPLETION_CRITERIA_NOT_MET) ->
// the transition is APPROVED by the state policy and declares the COMPLETION effect
// (STATE_POLICY_PENDING / INVALID_TRANSITION). The guard is shared with every generic transition the
// policy declares as a completion (EnrollmentService::transition): there is one rule, not two.
// No automatic completion exists in this layer.
final class CompletionService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function complete(int $actor, int $session, int $enrollmentId, ?string $reason = null, ?int $expectedLockVersion = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'enrollment.complete',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment($enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($enrollmentId, $reason, $expectedLockVersion) {
                $results = $this->rt->completion->assertEligible($decision, $target);
                $moved = $this->rt->machine->move('enrollments', 'enrollments', $enrollmentId, $this->rt->policy->target('enrollments', 'completed'), $expectedLockVersion, [], 'status', AcademyPolicy::EFFECT_COMPLETION);
                $this->rt->audit($decision, $target, 'enrollment.completed', 'enrollments', $enrollmentId, ['status' => $moved['from']], ['status' => $moved['to'], 'criteria' => array_column($results, 'type')], $reason);
                return ['enrollment_id' => $enrollmentId, 'from' => $moved['from'], 'to' => $moved['to'], 'criteria' => $results];
            },
            $overrideReason,
            $claimed
        );
    }
}
