<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Assessment definitions, policy-neutral (D-09). assessments.max_score / pass_score / max_attempts /
// weight are NOT NULL in the schema, so every value must be supplied by an authorized caller; this
// service never defaults, suggests or hardcodes one, and a missing value is POLICY_NOT_CONFIGURED.
// Only technical invariants the schema itself declares are checked (pass_score <= max_score,
// max_attempts >= 1, decimal shape). Because the row cannot tell an approved institutional value
// from a placeholder, max_attempts and pass_score are not used anywhere as enforcement (see the A2 doc).
final class AssessmentService
{
    private const FIELDS = ['name', 'lesson_id', 'max_score', 'pass_score', 'max_attempts', 'weight'];
    private const SCORING = ['max_score', 'pass_score', 'weight'];

    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function create(int $actor, int $session, int $courseVersionId, ?int $lessonId, mixed $name, mixed $maxScore, mixed $passScore, mixed $maxAttempts, mixed $weight, ?array $claimed = null): array
    {
        foreach ([$maxScore, $passScore, $maxAttempts, $weight] as $value) {
            if ($value === null) {
                throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['missing' => 'assessment_policy_values']);
            }
        }
        $values = $this->validated(['name' => $name, 'max_score' => $maxScore, 'pass_score' => $passScore, 'max_attempts' => $maxAttempts, 'weight' => $weight]);
        return $this->rt->write(
            'structure.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forCourseVersion($courseVersionId, 'share'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($courseVersionId, $lessonId, $values) {
                $this->assertLesson($lessonId, $courseVersionId);
                $status = $this->rt->policy->initial('assessments');
                $id = (int) $this->rt->db->table('assessments')->insertGetId($values + [
                    'public_id' => (string) \Illuminate\Support\Str::ulid(), 'course_version_id' => $courseVersionId, 'lesson_id' => $lessonId,
                    'status' => $status, 'created_at' => AcademyRuntime::ts($this->rt->now()),
                ]);
                $this->rt->audit($decision, $target, 'assessment.created', 'assessments', $id, null, ['course_version_id' => $courseVersionId, 'status' => $status] + $values);
                return ['assessment_id' => $id];
            },
            null,
            $claimed
        );
    }

    // $changes is a strict allow-list (mass assignment is rejected, not ignored).
    public function update(int $actor, int $session, int $assessmentId, array $changes, int $expectedLockVersion, ?array $claimed = null): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'changes']);
        }
        $values = $this->validated($changes);
        return $this->rt->write(
            'structure.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forAssessment($assessmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($assessmentId, $changes, $values, $expectedLockVersion) {
                $row = $target->rows['assessment'];
                if ((int) $row->lock_version !== $expectedLockVersion) {
                    throw new AcademyError(AcademyReason::STALE_WRITE, ['entity' => 'assessments', 'current_lock_version' => (int) $row->lock_version]);
                }
                if (array_diff(array_keys($values), ['name', 'lesson_id', 'max_attempts']) !== [] && $this->rt->db->table('assessment_attempts')->where('assessment_id', $assessmentId)->lockForUpdate()->exists()) {
                    throw new AcademyError(AcademyReason::ASSESSMENT_IN_USE, ['assessment_id' => $assessmentId]);
                }
                if (array_key_exists('lesson_id', $changes)) {
                    $this->assertLesson($changes['lesson_id'] === null ? null : (int) $changes['lesson_id'], (int) $row->course_version_id);
                }
                $merged = $values + ['max_score' => (string) $row->max_score, 'pass_score' => (string) $row->pass_score];
                if (!$this->rt->resolver->decimalGte($merged['max_score'], $merged['pass_score'])) {
                    throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'pass_score']);
                }
                $before = [];
                foreach (array_keys($values) as $field) {
                    $before[$field] = $row->{$field};
                }
                $this->rt->db->table('assessments')->where('id', $assessmentId)->update($values + ['lock_version' => (int) $row->lock_version + 1]);
                $this->rt->audit($decision, $target, 'assessment.updated', 'assessments', $assessmentId, $before, $values);
                return ['assessment_id' => $assessmentId, 'lock_version' => (int) $row->lock_version + 1];
            },
            null,
            $claimed
        );
    }

    private function validated(array $input): array
    {
        $out = [];
        foreach ($input as $field => $value) {
            $out[$field] = match ($field) {
                'name' => AcademyInput::text($value),
                'lesson_id' => $value === null ? null : (is_int($value) && $value > 0 ? $value : throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'lesson_id'])),
                'max_score', 'pass_score' => AcademyInput::decimal($value, 5, 4),
                'weight' => AcademyInput::decimal($value, 3, 4),
                'max_attempts' => is_int($value) && $value >= 1 ? $value : throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'max_attempts']),
            };
        }
        if (isset($out['max_score'], $out['pass_score']) && !$this->rt->resolver->decimalGte($out['max_score'], $out['pass_score'])) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'pass_score']);
        }
        return $out;
    }

    private function assertLesson(?int $lessonId, int $courseVersionId): void
    {
        if ($lessonId === null) {
            return;
        }
        $version = $this->rt->db->table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.module_id')->where('l.id', $lessonId)->sharedLock()->value('m.course_version_id');
        if ($version === null || (int) $version !== $courseVersionId) {
            throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'lessons']);
        }
    }
}
