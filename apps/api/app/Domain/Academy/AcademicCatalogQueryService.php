<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Database\Query\Builder;

final class AcademicCatalogQueryService
{
    public function __construct(private AcademyRuntime $rt) {}

    public function academicUnits(int $actor, int $session, array $query): array
    {
        $allowed = $this->rt->access->authorizedAcademicUnitIds('catalog.view', $actor, $session);
        $q = $this->rt->db->table('academic_units as au')->join('organizational_units as ou', 'ou.id', '=', 'au.unit_id')->whereIn('au.id', $allowed);
        $this->search($q, $query['search'] ?? null, ['au.code', 'au.name']);
        return $this->page($q, $query, ['au.id', 'au.code', 'au.name', 'au.status', 'ou.public_id as organizational_unit_public_id'], ['code'=>'au.code','name'=>'au.name','status'=>'au.status']);
    }

    public function academicUnit(int $actor, int $session, int $id): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forAcademicUnit($id, null), function (AcademyTarget $target): array {
            $row = $this->rt->db->table('academic_units as au')->join('organizational_units as ou', 'ou.id', '=', 'au.unit_id')->where('au.id', $target->academicUnitId)
                ->first(['au.id', 'au.code', 'au.name', 'au.status', 'ou.public_id as organizational_unit_public_id']);
            return (array) $row;
        });
    }

    public function programs(int $actor, int $session, array $query): array
    {
        $allowed = $this->rt->access->authorizedAcademicUnitIds('catalog.view', $actor, $session);
        $q = $this->rt->db->table('programs as p')->join('academic_units as au', 'au.id', '=', 'p.academic_unit_id')->whereIn('p.academic_unit_id', $allowed);
        $this->optionalId($q, 'p.academic_unit_id', $query['academic_unit_id'] ?? null);
        $this->optionalText($q, 'p.status', $query['status'] ?? null);
        $this->search($q, $query['search'] ?? null, ['p.code', 'p.name']);
        return $this->page($q, $query, ['p.public_id', 'p.code', 'p.name', 'p.status', 'au.code as academic_unit_code'], ['code'=>'p.code','name'=>'p.name','status'=>'p.status']);
    }

    public function program(int $actor, int $session, int $id): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forProgram($id, null), fn (AcademyTarget $t) => (array) $this->rt->db->table('programs as p')
            ->join('academic_units as au', 'au.id', '=', 'p.academic_unit_id')->where('p.id', $id)
            ->first(['p.public_id', 'p.code', 'p.name', 'p.status', 'p.lock_version', 'au.code as academic_unit_code', 'au.name as academic_unit_name']));
    }

    public function curricula(int $actor, int $session, array $query): array
    {
        $allowed = $this->rt->access->authorizedAcademicUnitIds('catalog.view', $actor, $session);
        $q = $this->rt->db->table('curricula as cu')->join('programs as p', 'p.id', '=', 'cu.program_id')->whereIn('p.academic_unit_id', $allowed);
        $this->optionalId($q, 'cu.program_id', $query['program_id'] ?? null);
        $this->optionalText($q, 'cu.status', $query['status'] ?? null);
        return $this->page($q, $query, ['cu.id', 'cu.version', 'cu.status', 'cu.published_at', 'cu.lock_version', 'p.public_id as program_public_id', 'p.code as program_code'], ['version'=>'cu.version','status'=>'cu.status']);
    }

    public function curriculum(int $actor, int $session, int $id): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCurriculum($id, null), function () use ($id): array {
            $row = (array) $this->rt->db->table('curricula as cu')->join('programs as p', 'p.id', '=', 'cu.program_id')->where('cu.id', $id)
                ->first(['cu.id', 'cu.version', 'cu.status', 'cu.published_at', 'cu.lock_version', 'p.public_id as program_public_id', 'p.code as program_code', 'p.name as program_name']);
            $row['course_count'] = $this->rt->db->table('curriculum_courses')->where('curriculum_id', $id)->count();
            return $row;
        });
    }

    public function curriculumCourses(int $actor, int $session, int $curriculumId, array $query): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCurriculum($curriculumId, null), function () use ($curriculumId, $query): array {
            $q = $this->rt->db->table('curriculum_courses as cc')->join('courses as c', 'c.id', '=', 'cc.course_id')->where('cc.curriculum_id', $curriculumId);
            $this->optionalText($q, 'c.status', $query['status'] ?? null);
            $this->search($q, $query['search'] ?? null, ['c.code', 'c.name']);
            return $this->page($q, $query, ['c.public_id', 'c.code', 'c.name', 'c.status', 'cc.sequence', 'cc.required'], ['sequence'=>'cc.sequence','code'=>'c.code','name'=>'c.name','status'=>'c.status']);
        });
    }

    public function courses(int $actor, int $session, array $query): array
    {
        $allowed = $this->rt->access->authorizedAcademicUnitIds('catalog.view', $actor, $session);
        $q = $this->rt->db->table('courses as c')->where(function (Builder $outer) use ($allowed): void {
            $outer->whereExists(fn (Builder $x) => $x->selectRaw('1')->from('curriculum_courses as cc')->join('curricula as cu', 'cu.id', '=', 'cc.curriculum_id')->join('programs as p', 'p.id', '=', 'cu.program_id')->whereColumn('cc.course_id', 'c.id')->whereIn('p.academic_unit_id', $allowed))
                ->orWhereExists(fn (Builder $x) => $x->selectRaw('1')->from('course_versions as cv')->join('classes as cl', 'cl.course_version_id', '=', 'cv.id')->whereColumn('cv.course_id', 'c.id')->whereIn('cl.academic_unit_id', $allowed));
        })->whereNotExists(fn (Builder $x) => $x->selectRaw('1')->from('curriculum_courses as cc')->join('curricula as cu', 'cu.id', '=', 'cc.curriculum_id')->join('programs as p', 'p.id', '=', 'cu.program_id')->whereColumn('cc.course_id', 'c.id')->whereNotIn('p.academic_unit_id', $allowed))
            ->whereNotExists(fn (Builder $x) => $x->selectRaw('1')->from('course_versions as cv')->join('classes as cl', 'cl.course_version_id', '=', 'cv.id')->whereColumn('cv.course_id', 'c.id')->whereNotIn('cl.academic_unit_id', $allowed));
        $this->optionalText($q, 'c.status', $query['status'] ?? null);
        $this->search($q, $query['search'] ?? null, ['c.code', 'c.name']);
        return $this->page($q, $query, ['c.public_id', 'c.code', 'c.name', 'c.status'], ['code'=>'c.code','name'=>'c.name','status'=>'c.status']);
    }

    public function course(int $actor, int $session, int $id): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCourse($id, null), fn () => (array) $this->rt->db->table('courses')->where('id', $id)->first(['public_id', 'code', 'name', 'status', 'lock_version']));
    }

    public function courseVersions(int $actor, int $session, int $courseId, array $query): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCourse($courseId, null), function () use ($courseId, $query): array {
            $q = $this->rt->db->table('course_versions')->where('course_id', $courseId);
            $this->optionalText($q, 'status', $query['status'] ?? null);
            return $this->page($q, $query, ['id', 'version', 'status', 'lock_version'], ['version'=>'version','status'=>'status']);
        });
    }

    public function courseVersion(int $actor, int $session, int $id): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCourseVersion($id, null), function () use ($id): array {
            $row = (array) $this->rt->db->table('course_versions as cv')->join('courses as c', 'c.id', '=', 'cv.course_id')->where('cv.id', $id)
                ->first(['cv.id', 'cv.version', 'cv.status', 'cv.lock_version', 'c.public_id as course_public_id', 'c.code as course_code', 'c.name as course_name']);
            $row['module_count'] = $this->rt->db->table('course_modules')->where('course_version_id', $id)->count();
            $row['lesson_count'] = $this->rt->db->table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.module_id')->where('m.course_version_id', $id)->count();
            $row['resource_count'] = $this->rt->db->table('lesson_resources as lr')->join('lessons as l', 'l.id', '=', 'lr.lesson_id')->join('course_modules as m', 'm.id', '=', 'l.module_id')->where('m.course_version_id', $id)->count();
            return $row;
        });
    }

    public function courseVersionModules(int $actor, int $session, int $courseVersionId, array $query): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCourseVersion($courseVersionId, null), function () use ($courseVersionId, $query): array {
            $q = $this->rt->db->table('course_modules')->where('course_version_id', $courseVersionId);
            return $this->page($q, $query, ['id', 'sequence', 'name'], ['sequence'=>'sequence','name'=>'name']);
        });
    }

    public function moduleLessons(int $actor, int $session, int $courseVersionId, int $moduleId, array $query): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCourseVersion($courseVersionId, null), function () use ($courseVersionId, $moduleId, $query): array {
            $module = $this->rt->db->table('course_modules')->where('id', $moduleId)->where('course_version_id', $courseVersionId)->first(['id']);
            if (!$module) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            $q = $this->rt->db->table('lessons')->where('module_id', $moduleId);
            return $this->page($q, $query, ['id', 'sequence', 'name', 'required'], ['sequence'=>'sequence','name'=>'name']);
        });
    }

    public function lessonResources(int $actor, int $session, int $courseVersionId, int $moduleId, int $lessonId, array $query): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCourseVersion($courseVersionId, null), function () use ($courseVersionId, $moduleId, $lessonId, $query): array {
            $lesson = $this->rt->db->table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.module_id')->where('l.id', $lessonId)
                ->where('l.module_id', $moduleId)->where('m.course_version_id', $courseVersionId)->first(['l.id']);
            if (!$lesson) { throw new AcademyError(AcademyReason::TARGET_NOT_FOUND); }
            $q = $this->rt->db->table('lesson_resources as lr')->join('resources as r', 'r.id', '=', 'lr.resource_id')->where('lr.lesson_id', $lessonId);
            $this->optionalText($q, 'r.status', $query['status'] ?? null);
            return $this->page($q, $query, ['r.public_id', 'lr.sequence', 'lr.required', 'r.resource_kind as kind', 'r.provider', 'r.external_url', 'r.duration_seconds', 'r.status'], ['sequence'=>'lr.sequence','kind'=>'r.resource_kind','status'=>'r.status']);
        });
    }

    public function cohorts(int $actor, int $session, array $query): array
    {
        $allowed = $this->rt->access->authorizedAcademicUnitIds('catalog.view', $actor, $session);
        $q = $this->rt->db->table('cohorts as h')->join('academic_units as au', 'au.id', '=', 'h.academic_unit_id')->whereIn('h.academic_unit_id', $allowed);
        $this->optionalId($q, 'h.academic_unit_id', $query['academic_unit_id'] ?? null);
        $this->optionalId($q, 'h.curriculum_id', $query['curriculum_id'] ?? null);
        $this->optionalText($q, 'h.status', $query['status'] ?? null);
        $this->search($q, $query['search'] ?? null, ['h.code', 'h.name']);
        return $this->page($q, $query, ['h.public_id', 'h.code', 'h.name', 'h.starts_at', 'h.ends_at', 'h.status', 'au.code as academic_unit_code'], ['code'=>'h.code','name'=>'h.name','status'=>'h.status','starts_at'=>'h.starts_at']);
    }

    public function cohort(int $actor, int $session, int $id): array
    {
        return $this->rt->read('catalog.view', $actor, $session, fn () => $this->rt->scope->forCohort($id, null), fn () => (array) $this->rt->db->table('cohorts as h')->join('academic_units as au', 'au.id', '=', 'h.academic_unit_id')->where('h.id', $id)
            ->first(['h.public_id', 'h.code', 'h.name', 'h.starts_at', 'h.ends_at', 'h.status', 'h.lock_version', 'h.curriculum_id', 'au.code as academic_unit_code']));
    }

    private function page(Builder $query, array $input, array $columns, array $sorts = []): array
    {
        [$page, $perPage] = AcademyInput::page((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 50));
        $total = (clone $query)->count();
        $default = preg_split('/\s+as\s+/i', $columns[0])[0];
        $order = isset($input['sort'], $sorts[$input['sort']]) ? $sorts[$input['sort']] : $default;
        $items = $query->orderBy($order, ($input['direction'] ?? 'asc'))->forPage($page, $perPage)->get($columns)->map(fn ($r) => (array) $r)->all();
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $items];
    }

    private function search(Builder $query, ?string $term, array $columns): void
    {
        if ($term !== null && trim($term) !== '') {
            $needle = '%' . addcslashes(trim($term), '%_\\') . '%';
            $query->where(fn (Builder $q) => collect($columns)->each(fn (string $c, int $i) => $i === 0 ? $q->where($c, 'like', $needle) : $q->orWhere($c, 'like', $needle)));
        }
    }

    private function optionalId(Builder $q, string $column, mixed $value): void { if ($value !== null) { $q->where($column, (int) $value); } }
    private function optionalText(Builder $q, string $column, mixed $value): void { if ($value !== null) { $q->where($column, (string) $value); } }
}
