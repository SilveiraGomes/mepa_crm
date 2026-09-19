<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// The single definition of "this enrollment may be completed". CompletionService and every generic
// transition the policy declares with AcademyPolicy::EFFECT_COMPLETION (EnrollmentService::transition)
// run THIS guard before the status changes, so a caller holding only the generic transition authority
// (ACADEMY_ENROLL) can never reach the outcome of the specialized operation without its guard.
//
// The guard decides, in this order:
//   1. context: the target really is an enrollment together with its own class;
//   2. authority: the actor holds the completion operation's authority (permission, institutional
//      scope and active class assignment) -- attached to the decision so the final, locking check
//      before commit re-verifies it as well;
//   3. policy: a valid completion policy exists for the class's course version (D-09, never a default);
//   4. every configured criterion is met (COMPLETION_CRITERIA_NOT_MET).
// It never names a state and never carries a threshold: states come from the server policy, criteria
// values from course_versions.completion_policy_metadata.
final class CompletionEligibilityGuard
{
    public const OPERATION = 'enrollment.complete';

    public function __construct(private AcademyAccess $access, private AcademicPolicyResolver $resolver)
    {
    }

    // Returns the evaluated criteria [['type' => ..., 'met' => true], ...]; throws otherwise.
    public function assertEligible(AcademyDecision $decision, AcademyTarget $target): array
    {
        $enrollment = $target->rows['enrollment'] ?? null;
        $class = $target->rows['class'] ?? null;
        if ($enrollment === null || $class === null || (int) $enrollment->class_id !== (int) $class->id || $target->classId !== (int) $class->id) {
            throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['reason' => 'completion_target']);
        }
        $this->authorize($decision, $target);
        $policy = $this->resolver->completionPolicy((int) $class->course_version_id);
        $results = $this->resolver->evaluateCompletion($enrollment, $class, $policy);
        $unmet = array_values(array_column(array_filter($results, fn ($r) => !$r['met']), 'type'));
        if ($unmet !== []) {
            throw new AcademyError(AcademyReason::COMPLETION_CRITERIA_NOT_MET, ['unmet' => $unmet]);
        }
        return $results;
    }

    // The specialized operation already authorized itself. Any other operation reaching a completion
    // effect needs the completion authority in addition to its own, as a DIRECT grant (an override is
    // never inherited from another operation).
    private function authorize(AcademyDecision $decision, AcademyTarget $target): void
    {
        if ($decision->operation === self::OPERATION) {
            return;
        }
        $op = AcademyOperation::get(self::OPERATION);
        $decision->attach($op, $this->access->authorize($op, $decision->actor, $decision->session, $target, null));
    }
}
