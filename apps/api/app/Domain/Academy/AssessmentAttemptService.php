<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Attempts are numbered per (assessment, enrollment) by the service under an exclusive lock on the
// enrollment row (the anchor for that numbering), inside the same transaction as the insert; the
// UNIQUE(assessment_id, enrollment_id, attempt_number) index is the last line of defence. A caller
// that already knows the number it wants passes it: the same request repeated or racing then
// converges deterministically (ATTEMPT_ALREADY_EXISTS) instead of creating a second attempt.
// The maximum number of attempts is NOT enforced: it is a D-09 value not yet approved.
final class AssessmentAttemptService
{
    private const MAX_ANSWERS_BYTES = 65535;

    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function start(int $actor, int $session, int $assessmentId, int $enrollmentId, ?int $attemptNumber = null, ?array $answersMetadata = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        if ($attemptNumber !== null && $attemptNumber < 1) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'attempt_number']);
        }
        $answers = $answersMetadata === null ? null : json_encode($answersMetadata, JSON_THROW_ON_ERROR);
        if ($answers !== null && strlen($answers) > self::MAX_ANSWERS_BYTES) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'answers_metadata']);
        }
        return $this->rt->write(
            'attempt.record',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment($enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($assessmentId, $enrollmentId, $attemptNumber, $answers) {
                if (!$this->rt->policy->inSet('enrollments', 'operational', (string) $target->rows['enrollment']->status)) {
                    throw new AcademyError(AcademyReason::ENROLLMENT_NOT_ACTIVE, ['enrollment_id' => $enrollmentId]);
                }
                $assessment = $this->rt->db->table('assessments')->where('id', $assessmentId)->sharedLock()->first();
                if (!$assessment) {
                    throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => 'assessments']);
                }
                if ((int) $assessment->course_version_id !== (int) $target->rows['class']->course_version_id) {
                    throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'assessments']);
                }
                if (!$this->rt->policy->inSet('assessments', 'open', (string) $assessment->status)) {
                    throw new AcademyError(AcademyReason::ASSESSMENT_NOT_OPEN, ['assessment_id' => $assessmentId]);
                }
                $last = $this->rt->db->table('assessment_attempts')->where('assessment_id', $assessmentId)->where('enrollment_id', $enrollmentId)->orderByDesc('attempt_number')->lockForUpdate()->first();
                $next = $last ? (int) $last->attempt_number + 1 : 1;
                if ($attemptNumber !== null && $attemptNumber < $next) {
                    throw new AcademyError(AcademyReason::ATTEMPT_ALREADY_EXISTS, ['attempt_number' => $attemptNumber]);
                }
                if ($attemptNumber !== null && $attemptNumber > $next) {
                    throw new AcademyError(AcademyReason::ATTEMPT_NUMBER_CONFLICT, ['expected_next' => $next]);
                }
                $now = AcademyRuntime::ts($this->rt->now());
                $status = $this->rt->policy->initial('assessment_attempts');
                $id = (int) $this->rt->db->table('assessment_attempts')->insertGetId([
                    'assessment_id' => $assessmentId, 'enrollment_id' => $enrollmentId, 'attempt_number' => $next, 'started_at' => $now,
                    'submitted_at' => null, 'status' => $status, 'answers_metadata' => $answers, 'created_at' => $now,
                ]);
                $this->rt->audit($decision, $target, 'attempt.recorded', 'assessment_attempts', $id, null, ['assessment_id' => $assessmentId, 'enrollment_id' => $enrollmentId, 'attempt_number' => $next, 'status' => $status]);
                return ['attempt_id' => $id, 'attempt_number' => $next];
            },
            $overrideReason,
            $claimed,
            AcademyReason::ATTEMPT_ALREADY_EXISTS
        );
    }

    // submit / expire / ...: policy-approved transitions only.
    public function transition(int $actor, int $session, int $attemptId, string $toState, ?string $reason = null, ?int $expectedLockVersion = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'attempt.transition',
            $actor,
            $session,
            fn () => $this->rt->scope->forAttempt($attemptId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($attemptId, $toState, $reason, $expectedLockVersion) {
                $extra = $this->rt->policy->inOptionalSet('assessment_attempts', 'submitted', $toState) ? ['submitted_at' => AcademyRuntime::ts($this->rt->now())] : [];
                $moved = $this->rt->machine->move('assessment_attempts', 'assessment_attempts', $attemptId, $toState, $expectedLockVersion, $extra);
                $this->rt->audit($decision, $target, 'attempt.transitioned', 'assessment_attempts', $attemptId, ['status' => $moved['from']], ['status' => $moved['to']], $reason);
                return ['attempt_id' => $attemptId, 'from' => $moved['from'], 'to' => $moved['to']];
            },
            $overrideReason,
            $claimed
        );
    }
}
