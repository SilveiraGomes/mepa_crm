<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// progress / resource_progress are reproducible caches, not diplomas. Their scope is always derived
// from the real chain enrollment -> class -> course version; the lesson/resource must belong to that
// same course version. This layer records the facts it is given: it never decides that a lesson or a
// resource "counts as done" (that is D-09); a state change happens only through an approved transition.
final class ProgressService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function recordLesson(int $actor, int $session, int $enrollmentId, int $lessonId, mixed $ratio, ?string $toState = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        $ratio = AcademyInput::decimal($ratio, 1, 4);
        if (!$this->rt->resolver->decimalGte('1', $ratio)) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'completion_ratio']);
        }
        return $this->rt->write(
            'progress.record',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment($enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($enrollmentId, $lessonId, $ratio, $toState) {
                $this->assertOperational($target);
                $version = $this->rt->db->table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.module_id')->where('l.id', $lessonId)->sharedLock()->value('m.course_version_id');
                if ($version === null || (int) $version !== (int) $target->rows['class']->course_version_id) {
                    throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'lessons']);
                }
                $row = $this->rt->db->table('progress')->where('enrollment_id', $enrollmentId)->where('lesson_id', $lessonId)->lockForUpdate()->first();
                $now = AcademyRuntime::ts($this->rt->now());
                if ($row) {
                    $this->rt->db->table('progress')->where('id', $row->id)->update(['completion_ratio' => $ratio, 'lock_version' => (int) $row->lock_version + 1]);
                    $id = (int) $row->id;
                } else {
                    $id = (int) $this->rt->db->table('progress')->insertGetId([
                        'enrollment_id' => $enrollmentId, 'lesson_id' => $lessonId, 'completed_at' => null, 'completion_ratio' => $ratio,
                        'source_version' => $this->rt->policy->version(), 'status' => $this->rt->policy->initial('progress'), 'created_at' => $now,
                    ]);
                }
                if ($toState !== null) {
                    $extra = $this->rt->policy->inOptionalSet('progress', 'completed', $toState) ? ['completed_at' => $now] : [];
                    $this->rt->machine->move('progress', 'progress', $id, $toState, null, $extra);
                }
                return ['progress_id' => $id];
            },
            $overrideReason,
            $claimed
        );
    }

    public function recordResource(int $actor, int $session, int $enrollmentId, int $resourceId, int $watchedSeconds, ?string $toState = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        if ($watchedSeconds < 0) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'watched_seconds']);
        }
        return $this->rt->write(
            'progress.record',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment($enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($enrollmentId, $resourceId, $watchedSeconds, $toState) {
                $this->assertOperational($target);
                $versions = $this->rt->db->table('lesson_resources as lr')->join('lessons as l', 'l.id', '=', 'lr.lesson_id')->join('course_modules as m', 'm.id', '=', 'l.module_id')
                    ->where('lr.resource_id', $resourceId)->sharedLock()->pluck('m.course_version_id')->map(fn ($v) => (int) $v)->all();
                if (!in_array((int) $target->rows['class']->course_version_id, $versions, true)) {
                    throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'resources']);
                }
                $row = $this->rt->db->table('resource_progress')->where('enrollment_id', $enrollmentId)->where('resource_id', $resourceId)->lockForUpdate()->first();
                $now = AcademyRuntime::ts($this->rt->now());
                if ($row) {
                    $this->rt->db->table('resource_progress')->where('id', $row->id)->update(['watched_seconds' => $watchedSeconds, 'lock_version' => (int) $row->lock_version + 1]);
                    $id = (int) $row->id;
                } else {
                    $id = (int) $this->rt->db->table('resource_progress')->insertGetId([
                        'enrollment_id' => $enrollmentId, 'resource_id' => $resourceId, 'watched_seconds' => $watchedSeconds, 'verified_at' => null,
                        'status' => $this->rt->policy->initial('resource_progress'), 'created_at' => $now,
                    ]);
                }
                if ($toState !== null) {
                    $extra = $this->rt->policy->inOptionalSet('resource_progress', 'verified', $toState) ? ['verified_at' => $now] : [];
                    $this->rt->machine->move('resource_progress', 'resource_progress', $id, $toState, null, $extra);
                }
                return ['resource_progress_id' => $id];
            },
            $overrideReason,
            $claimed
        );
    }

    private function assertOperational(AcademyTarget $target): void
    {
        if (!$this->rt->policy->inSet('enrollments', 'operational', (string) $target->rows['enrollment']->status)) {
            throw new AcademyError(AcademyReason::ENROLLMENT_NOT_ACTIVE, ['enrollment_id' => (int) $target->rows['enrollment']->id]);
        }
    }
}
