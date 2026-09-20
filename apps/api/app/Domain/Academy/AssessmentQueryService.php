<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Query\Builder;

final class AssessmentQueryService
{
    public function __construct(private AcademyRuntime $rt) {}

    public function assessments(int $actor, int $session, int $classId, array $input): array
    {
        return $this->rt->read('assessment.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function (AcademyTarget $target) use ($input): array {
            $q = $this->rt->db->table('assessments as a')->leftJoin('lessons as l', 'l.id', '=', 'a.lesson_id')->where('a.course_version_id', $target->rows['class']->course_version_id);
            if (isset($input['status'])) { $q->where('a.status', $input['status']); }
            if (!empty($input['search'])) { $q->where('a.name', 'like', '%' . addcslashes(trim($input['search']), '%_\\') . '%'); }
            return $this->page($q, $input, ['a.public_id', 'a.name', 'a.max_score', 'a.pass_score', 'a.max_attempts', 'a.weight', 'a.status', 'a.lock_version', 'l.name as lesson_name'], ['name'=>'a.name','status'=>'a.status']);
        });
    }

    public function assessment(int $actor, int $session, int $classId, int $assessmentId): array
    {
        return $this->rt->read('assessment.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function (AcademyTarget $target) use ($assessmentId): array {
            $row = $this->rt->db->table('assessments as a')->leftJoin('lessons as l', 'l.id', '=', 'a.lesson_id')->where('a.id', $assessmentId)
                ->where('a.course_version_id', $target->rows['class']->course_version_id)->first(['a.public_id', 'a.name', 'a.max_score', 'a.pass_score', 'a.max_attempts', 'a.weight', 'a.status', 'a.lock_version', 'l.name as lesson_name']);
            if (!$row) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            return (array) $row;
        });
    }

    public function attempts(int $actor, int $session, int $classId, int $assessmentId, array $input): array
    {
        return $this->rt->read('attempt.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function (AcademyTarget $target) use ($assessmentId, $input): array {
            $assessment = $this->rt->db->table('assessments')->where('id', $assessmentId)->where('course_version_id', $target->rows['class']->course_version_id)->first();
            if (!$assessment) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            $q = $this->rt->db->table('assessment_attempts as aa')->join('enrollments as e', 'e.id', '=', 'aa.enrollment_id')->join('people as p', 'p.id', '=', 'e.person_id')
                ->where('aa.assessment_id', $assessmentId)->where('e.class_id', $classId);
            if (isset($input['status'])) { $q->where('aa.status', $input['status']); }
            return $this->page($q, $input, ['aa.id', 'aa.attempt_number', 'aa.started_at', 'aa.submitted_at', 'aa.status', 'aa.lock_version', 'e.public_id as enrollment_public_id', 'p.public_id as person_public_id', 'p.full_name as display_name'], ['name'=>'p.full_name','status'=>'aa.status','starts_at'=>'aa.started_at']);
        });
    }

    public function attempt(int $actor, int $session, int $classId, int $attemptId): array
    {
        return $this->rt->read('attempt.view', $actor, $session, fn () => $this->rt->scope->forAttempt($attemptId, null), function (AcademyTarget $target) use ($classId, $attemptId): array {
            if ($target->classId !== $classId) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            return (array) $this->rt->db->table('assessment_attempts as aa')->join('assessments as a', 'a.id', '=', 'aa.assessment_id')->join('enrollments as e', 'e.id', '=', 'aa.enrollment_id')->join('people as p', 'p.id', '=', 'e.person_id')->where('aa.id', $attemptId)
                ->first(['aa.id', 'aa.attempt_number', 'aa.started_at', 'aa.submitted_at', 'aa.status', 'aa.lock_version', 'a.public_id as assessment_public_id', 'a.name as assessment_name', 'e.public_id as enrollment_public_id', 'p.public_id as person_public_id', 'p.full_name as display_name']);
        });
    }

    private function page(Builder $q, array $input, array $columns, array $sorts = []): array
    {
        [$page, $perPage] = AcademyInput::page((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 50));
        $total = (clone $q)->count();
        $default = preg_split('/\s+as\s+/i', $columns[0])[0];
        $order = isset($input['sort'], $sorts[$input['sort']]) ? $sorts[$input['sort']] : $default;
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $q->orderBy($order, $input['direction'] ?? 'asc')->forPage($page, $perPage)->get($columns)->map(fn ($r) => (array) $r)->all()];
    }
}
