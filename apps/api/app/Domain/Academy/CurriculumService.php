<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Curricula are versioned and frozen once published: a change is a NEW version (createVersion), and a
// class keeps pointing at the course version it was opened on, so a student's history stays on the
// version they followed (C8). The publication itself is a policy-approved transition. Adding courses
// to a published curriculum is refused. Scope is derived from the program's academic unit.
final class CurriculumService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function createVersion(int $actor, int $session, int $programId, ?array $claimed = null): array
    {
        return $this->rt->write(
            'structure.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forProgram($programId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($programId) {
                $last = $this->rt->db->table('curricula')->where('program_id', $programId)->orderByDesc('version')->lockForUpdate()->first();
                $version = $last ? (int) $last->version + 1 : 1;
                $status = $this->rt->policy->initial('curricula');
                $id = (int) $this->rt->db->table('curricula')->insertGetId(['program_id' => $programId, 'version' => $version, 'status' => $status, 'published_at' => null, 'created_at' => AcademyRuntime::ts($this->rt->now())]);
                $this->rt->audit($decision, $target, 'curriculum.version_created', 'curricula', $id, null, ['program_id' => $programId, 'version' => $version, 'status' => $status]);
                return ['curriculum_id' => $id, 'version' => $version];
            },
            null,
            $claimed,
            AcademyReason::STALE_WRITE
        );
    }

    public function publish(int $actor, int $session, int $curriculumId, ?int $expectedLockVersion = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'structure.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forCurriculum($curriculumId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($curriculumId, $expectedLockVersion) {
                if ($target->rows['curriculum']->published_at !== null) {
                    throw new AcademyError(AcademyReason::CURRICULUM_ALREADY_PUBLISHED, ['curriculum_id' => $curriculumId]);
                }
                $now = AcademyRuntime::ts($this->rt->now());
                $moved = $this->rt->machine->move('curricula', 'curricula', $curriculumId, $this->rt->policy->target('curricula', 'published'), $expectedLockVersion, ['published_at' => $now]);
                $this->rt->audit($decision, $target, 'curriculum.published', 'curricula', $curriculumId, ['status' => $moved['from'], 'published_at' => null], ['status' => $moved['to'], 'published_at' => $now]);
                return ['curriculum_id' => $curriculumId, 'from' => $moved['from'], 'to' => $moved['to'], 'published_at' => $now];
            },
            null,
            $claimed
        );
    }

    public function addCourse(int $actor, int $session, int $curriculumId, int $courseId, int $sequence, bool $required, ?array $claimed = null): array
    {
        if ($sequence < 1) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'sequence']);
        }
        return $this->rt->write(
            'structure.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forCurriculum($curriculumId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($curriculumId, $courseId, $sequence, $required) {
                if ($target->rows['curriculum']->published_at !== null) {
                    throw new AcademyError(AcademyReason::CURRICULUM_PUBLISHED_IMMUTABLE, ['curriculum_id' => $curriculumId]);
                }
                $id = (int) $this->rt->db->table('curriculum_courses')->insertGetId([
                    'curriculum_id' => $curriculumId, 'course_id' => $courseId, 'sequence' => $sequence, 'required' => $required ? 1 : 0, 'created_at' => AcademyRuntime::ts($this->rt->now()),
                ]);
                $this->rt->audit($decision, $target, 'curriculum.course_added', 'curriculum_courses', $id, null, ['curriculum_id' => $curriculumId, 'course_id' => $courseId, 'sequence' => $sequence, 'required' => $required]);
                return ['curriculum_course_id' => $id];
            },
            null,
            $claimed
        );
    }
}
