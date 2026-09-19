<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Results are append-only versions of an attempt (UNIQUE(attempt_id, version)); a stored score is
// never updated. record = version 1, revise = version N+1, finalize = a policy-approved transition
// of the latest version. The attempt row is locked exclusively for every write, and a writer must
// present the version it read (expectedVersion): a stale writer gets STALE_WRITE and nothing is
// stored, so an earlier revision (and its audit record) can never be silently overwritten.
// The only score bound applied is the assessment's own max_score (its declared data, not a policy
// value of this layer); pass/fail is never decided here.
final class GradeService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function record(int $actor, int $session, int $attemptId, mixed $score, ?string $reason = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        $score = AcademyInput::decimal($score);
        return $this->rt->write(
            'grade.record',
            $actor,
            $session,
            fn () => $this->rt->scope->forAttempt($attemptId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($attemptId, $score, $reason) {
                $this->assertGradable($target->rows['attempt'], $score);
                $latest = $this->latest($attemptId);
                if ($latest) {
                    throw new AcademyError(AcademyReason::GRADE_ALREADY_RECORDED, ['latest_version' => (int) $latest->version]);
                }
                $id = $this->insertVersion($decision, $attemptId, 1, $score, $reason);
                $this->rt->audit($decision, $target, 'grade.recorded', 'grades', $id, null, ['attempt_id' => $attemptId, 'version' => 1, 'score' => $score], $reason);
                return ['grade_id' => $id, 'version' => 1];
            },
            $overrideReason,
            $claimed,
            AcademyReason::GRADE_ALREADY_RECORDED
        );
    }

    public function revise(int $actor, int $session, int $attemptId, int $expectedVersion, mixed $score, ?string $reason, ?string $overrideReason = null, ?array $claimed = null): array
    {
        $score = AcademyInput::decimal($score);
        $reason = AcademyInput::reason($reason);
        return $this->rt->write(
            'grade.revise',
            $actor,
            $session,
            fn () => $this->rt->scope->forAttempt($attemptId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($attemptId, $expectedVersion, $score, $reason) {
                $this->assertGradable($target->rows['attempt'], $score);
                $latest = $this->latest($attemptId);
                if (!$latest) {
                    throw new AcademyError(AcademyReason::GRADE_NOT_FOUND, ['attempt_id' => $attemptId]);
                }
                if ((int) $latest->version !== $expectedVersion) {
                    throw new AcademyError(AcademyReason::STALE_WRITE, ['entity' => 'grades', 'latest_version' => (int) $latest->version]);
                }
                $version = (int) $latest->version + 1;
                $id = $this->insertVersion($decision, $attemptId, $version, $score, $reason);
                $this->rt->audit($decision, $target, 'grade.revised', 'grades', $id, ['version' => (int) $latest->version, 'score' => (string) $latest->score, 'status' => $latest->status], ['attempt_id' => $attemptId, 'version' => $version, 'score' => $score], $reason);
                return ['grade_id' => $id, 'version' => $version];
            },
            $overrideReason,
            $claimed,
            AcademyReason::STALE_WRITE
        );
    }

    // The final state is a D-11 policy transition: until the owners approve it this is STATE_POLICY_PENDING.
    public function finalize(int $actor, int $session, int $attemptId, int $expectedVersion, ?string $reason = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'grade.finalize',
            $actor,
            $session,
            fn () => $this->rt->scope->forAttempt($attemptId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($attemptId, $expectedVersion, $reason) {
                $latest = $this->latest($attemptId);
                if (!$latest) {
                    throw new AcademyError(AcademyReason::GRADE_NOT_FOUND, ['attempt_id' => $attemptId]);
                }
                if ((int) $latest->version !== $expectedVersion) {
                    throw new AcademyError(AcademyReason::STALE_WRITE, ['entity' => 'grades', 'latest_version' => (int) $latest->version]);
                }
                $moved = $this->rt->machine->move('grades', 'grades', (int) $latest->id, $this->rt->policy->target('grades', 'final'));
                $this->rt->audit($decision, $target, 'grade.finalized', 'grades', (int) $latest->id, ['status' => $moved['from']], ['status' => $moved['to'], 'version' => (int) $latest->version], $reason);
                return ['grade_id' => (int) $latest->id, 'version' => (int) $latest->version, 'from' => $moved['from'], 'to' => $moved['to']];
            },
            $overrideReason,
            $claimed
        );
    }

    // Full version history of an attempt, oldest first (ACADEMY_GRADES_VIEW within scope).
    public function history(int $actor, int $session, int $attemptId, ?array $claimed = null): array
    {
        return $this->rt->read(
            'grade.view',
            $actor,
            $session,
            fn () => $this->rt->scope->forAttempt($attemptId, null),
            fn (AcademyTarget $target) => $this->rt->db->table('grades')->where('attempt_id', $attemptId)->orderBy('version')->get(['id', 'version', 'score', 'status', 'graded_by', 'graded_at', 'reason', 'lock_version'])->map(fn ($r) => (array) $r)->all(),
            $claimed
        );
    }

    private function latest(int $attemptId): ?object
    {
        return $this->rt->db->table('grades')->where('attempt_id', $attemptId)->orderByDesc('version')->lockForUpdate()->first();
    }

    private function assertGradable(object $attempt, string $score): void
    {
        if (!$this->rt->policy->inSet('assessment_attempts', 'gradable', (string) $attempt->status)) {
            throw new AcademyError(AcademyReason::ATTEMPT_NOT_GRADABLE, ['attempt_id' => (int) $attempt->id]);
        }
        $max = $this->rt->db->table('assessments')->where('id', $attempt->assessment_id)->sharedLock()->value('max_score');
        if ($max === null || !$this->rt->resolver->decimalGte((string) $max, $score)) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'score']);
        }
    }

    private function insertVersion(AcademyDecision $decision, int $attemptId, int $version, string $score, ?string $reason): int
    {
        $now = AcademyRuntime::ts($this->rt->now());
        return (int) $this->rt->db->table('grades')->insertGetId([
            'attempt_id' => $attemptId, 'version' => $version, 'score' => $score, 'graded_by' => $decision->actor, 'graded_at' => $now,
            'status' => $this->rt->policy->initial('grades'), 'reason' => $reason, 'created_at' => $now,
        ]);
    }
}
