<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Query\Builder;

final class ClassQueryService
{
    public function __construct(private AcademyRuntime $rt) {}

    public function classes(int $actor, int $session, array $input): array
    {
        $allowed = $this->rt->access->authorizedAcademicUnitIds('class.view', $actor, $session);
        $q = $this->rt->db->table('classes as c')->join('academic_units as au', 'au.id', '=', 'c.academic_unit_id')
            ->join('course_versions as cv', 'cv.id', '=', 'c.course_version_id')->join('courses as co', 'co.id', '=', 'cv.course_id')->whereIn('c.academic_unit_id', $allowed);
        foreach (['academic_unit_id' => 'c.academic_unit_id', 'cohort_id' => 'c.cohort_id', 'course_version_id' => 'c.course_version_id'] as $key => $column) {
            if (isset($input[$key])) { $q->where($column, (int) $input[$key]); }
        }
        if (isset($input['status'])) { $q->where('c.status', $input['status']); }
        if (!empty($input['search'])) { $needle = '%' . addcslashes(trim($input['search']), '%_\\') . '%'; $q->where(fn (Builder $x) => $x->where('c.code', 'like', $needle)->orWhere('co.name', 'like', $needle)); }
        return $this->page($q, $input, ['c.public_id', 'c.code', 'c.capacity', 'c.status', 'c.lock_version', 'au.code as academic_unit_code', 'co.public_id as course_public_id', 'co.code as course_code', 'co.name as course_name', 'cv.version as course_version'], ['code'=>'c.code','name'=>'co.name','status'=>'c.status']);
    }

    public function detail(int $actor, int $session, int $classId): array
    {
        return $this->rt->read('class.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), fn () => (array) $this->rt->db->table('classes as c')
            ->join('academic_units as au', 'au.id', '=', 'c.academic_unit_id')->join('course_versions as cv', 'cv.id', '=', 'c.course_version_id')->join('courses as co', 'co.id', '=', 'cv.course_id')
            ->leftJoin('cohorts as h', 'h.id', '=', 'c.cohort_id')->leftJoin('physical_locations as pl', 'pl.id', '=', 'c.location_id')->where('c.id', $classId)
            ->first(['c.public_id', 'c.code', 'c.capacity', 'c.status', 'c.lock_version', 'au.code as academic_unit_code', 'au.name as academic_unit_name', 'co.public_id as course_public_id', 'co.code as course_code', 'co.name as course_name', 'cv.version as course_version', 'h.public_id as cohort_public_id', 'h.name as cohort_name', 'pl.public_id as location_public_id', 'pl.name as location_name']));
    }

    public function roster(int $actor, int $session, int $classId, array $input): array
    {
        return $this->rt->read('enrollment.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function () use ($classId, $input): array {
            $q = $this->rt->db->table('enrollments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.class_id', $classId)->whereNull('p.archived_at');
            if (isset($input['status'])) { $q->where('e.status', $input['status']); }
            if (!empty($input['search'])) { $needle = '%' . addcslashes(trim($input['search']), '%_\\') . '%'; $q->where('p.full_name', 'like', $needle); }
            return $this->page($q, $input, ['e.public_id as enrollment_public_id', 'e.status as enrollment_status', 'e.enrolled_at', 'p.public_id', 'p.full_name as display_name'], ['name'=>'p.full_name','status'=>'e.status','enrolled_at'=>'e.enrolled_at']);
        });
    }

    public function searchPeopleForEnrollment(int $actor, int $session, int $classId, array $input): array
    {
        return $this->rt->read('person.search', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function (AcademyTarget $target) use ($input): array {
            $term = trim((string) ($input['search'] ?? ''));
            if (mb_strlen($term) < 3) { throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'search']); }
            $needle = '%' . addcslashes($term, '%_\\') . '%';
            $q = $this->rt->db->table('people as p')->whereNull('p.archived_at')->where(function (Builder $scope) use ($target): void {
                $scope->whereExists(fn (Builder $x) => $x->selectRaw('1')->from('enrollments as e')->join('classes as c', 'c.id', '=', 'e.class_id')->whereColumn('e.person_id', 'p.id')->where('c.academic_unit_id', $target->academicUnitId));
            })->where(fn (Builder $x) => $x->where('p.full_name', 'like', $needle)->orWhere('p.public_id', $input['search']));
            return $this->page($q, $input, ['p.public_id', 'p.full_name as display_name'], ['name'=>'p.full_name']);
        });
    }

    public function enrollment(int $actor, int $session, int $classId, int $enrollmentId): array
    {
        return $this->rt->read('enrollment.view', $actor, $session, fn () => $this->rt->scope->forEnrollment($enrollmentId, null), function (AcademyTarget $target) use ($classId, $enrollmentId): array {
            if ($target->classId !== $classId) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            return (array) $this->rt->db->table('enrollments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $enrollmentId)
                ->first(['e.public_id', 'e.status', 'e.enrolled_at', 'e.lock_version', 'p.public_id as person_public_id', 'p.full_name as display_name']);
        });
    }

    public function sessions(int $actor, int $session, int $classId, array $input): array
    {
        return $this->rt->read('session.view', $actor, $session, fn () => $this->rt->scope->forClass($classId, null), function () use ($classId, $input): array {
            $q = $this->rt->db->table('class_sessions as s')->leftJoin('lessons as l', 'l.id', '=', 's.lesson_id')->where('s.class_id', $classId);
            if (isset($input['status'])) { $q->where('s.status', $input['status']); }
            if (isset($input['date_from'])) { $q->where('s.starts_at', '>=', $input['date_from']); }
            if (isset($input['date_to'])) { $q->where('s.starts_at', '<=', $input['date_to']); }
            return $this->page($q, $input, ['s.id', 's.starts_at', 's.ends_at', 's.status', 's.lock_version', 'l.name as lesson_name'], ['starts_at'=>'s.starts_at','status'=>'s.status']);
        });
    }

    public function session(int $actor, int $authSession, int $classId, int $sessionId): array
    {
        return $this->rt->read('session.view', $actor, $authSession, fn () => $this->rt->scope->forSession($sessionId, null), function (AcademyTarget $target) use ($classId, $sessionId): array {
            if ($target->classId !== $classId) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            return (array) $this->rt->db->table('class_sessions as s')->leftJoin('lessons as l', 'l.id', '=', 's.lesson_id')->where('s.id', $sessionId)
                ->first(['s.id', 's.starts_at', 's.ends_at', 's.status', 's.lock_version', 's.event_session_id', 'l.name as lesson_name']);
        });
    }

    public function progress(int $actor, int $session, int $enrollmentId, array $input): array
    {
        return $this->rt->read('progress.view', $actor, $session, fn () => $this->rt->scope->forEnrollment($enrollmentId, null), function () use ($enrollmentId, $input): array {
            [$page, $perPage] = AcademyInput::page((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 50));
            $lessons = $this->rt->db->table('progress as p')->join('lessons as l', 'l.id', '=', 'p.lesson_id')->where('p.enrollment_id', $enrollmentId)
                ->orderBy('l.sequence')->forPage($page, $perPage)->get(['l.name', 'p.completion_ratio', 'p.completed_at', 'p.status'])->map(fn ($r) => (array) $r)->all();
            $resources = $this->rt->db->table('resource_progress as rp')->join('resources as r', 'r.id', '=', 'rp.resource_id')->where('rp.enrollment_id', $enrollmentId)
                ->orderBy('rp.id')->forPage($page, $perPage)->get(['r.public_id', 'r.resource_kind', 'rp.watched_seconds', 'rp.verified_at', 'rp.status'])->map(fn ($r) => (array) $r)->all();
            return ['total' => max(count($lessons), count($resources)), 'page' => $page, 'per_page' => $perPage, 'items' => [['lessons' => $lessons, 'resources' => $resources]]];
        });
    }

    private function page(Builder $q, array $input, array $columns, array $sorts = []): array
    {
        [$page, $perPage] = AcademyInput::page((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 50));
        $total = (clone $q)->count();
        $default = preg_split('/\s+as\s+/i', $columns[0])[0];
        $order = isset($input['sort'], $sorts[$input['sort']]) ? $sorts[$input['sort']] : $default;
        $items = $q->orderBy($order, $input['direction'] ?? 'asc')->forPage($page, $perPage)->get($columns)->map(fn ($r) => (array) $r)->all();
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $items];
    }
}
