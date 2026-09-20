<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\{AcademicCatalogQueryService, AcademyReason, AssessmentQueryService, ClassQueryService, DocumentQueryService, EnrollmentService};
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

final class AcademyReadModelTest extends PooledWaveFiveCase
{
    public function test_scoped_catalog_detail_roster_person_search_and_nested_integrity(): void
    {
        $a = $this->world(); $b = $this->world();
        $actor = $this->actor($a['unit'], ['ACADEMY_VIEW', 'ACADEMY_GRADES_VIEW', 'ACADEMY_ENROLL']);
        $studentA = $this->enrollment($a['class']);
        $studentB = $this->enrollment($b['class']);
        $catalog = new AcademicCatalogQueryService($this->rt());
        $classes = new ClassQueryService($this->rt());

        $programs = $catalog->programs($actor['user'], $actor['session'], []);
        self::assertSame(1, $programs['total']);
        self::assertSame($this->db()->table('programs')->where('id', $a['program'])->value('public_id'), $programs['items'][0]['public_id']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $catalog->program($actor['user'], $actor['session'], $b['program']));
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $catalog->program($actor['user'], $actor['session'], PHP_INT_MAX));

        $roster = $classes->roster($actor['user'], $actor['session'], $a['class'], []);
        self::assertSame(1, $roster['total']);
        self::assertArrayHasKey('public_id', $roster['items'][0]);
        self::assertArrayHasKey('display_name', $roster['items'][0]);
        foreach (['id','person_id','birth_date','phones','guardian'] as $forbidden) self::assertArrayNotHasKey($forbidden, $roster['items'][0]);

        $publicB = (string) $this->db()->table('people')->where('id', $studentB['person'])->value('public_id');
        $search = $classes->searchPeopleForEnrollment($actor['user'], $actor['session'], $a['class'], ['search' => $publicB]);
        self::assertSame(0, $search['total'], 'A Person connected only to unit B must not become an enumeration oracle in unit A');
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $classes->enrollment($actor['user'], $actor['session'], $a['class'], $studentB['id']));
        self::assertNotSame($studentA['id'], $studentB['id']);
    }

    public function test_query_counts_remain_bounded_as_result_sets_grow(): void
    {
        $w = $this->world();
        $actor = $this->actor($w['unit'], ['ACADEMY_VIEW', 'ACADEMY_GRADES_VIEW']);
        $catalog = new AcademicCatalogQueryService($this->rt());
        $classes = new ClassQueryService($this->rt());
        $assessments = new AssessmentQueryService($this->rt());
        $documents = new DocumentQueryService($this->rt());
        $enrollments = new EnrollmentService($this->rt());
        $student = $this->enrollment($w['class']);
        $assessment = $this->assessment($w['version']);
        $file = $this->file($w['unit']);
        $this->row('certificates', ['enrollment_id' => $student['id'], 'version' => 1, 'file_id' => $file, 'approved_by' => $actor['user']]);

        $calls = [
            'academic_units' => fn () => $catalog->academicUnits($actor['user'], $actor['session'], ['per_page'=>100]),
            'programs' => fn () => $catalog->programs($actor['user'], $actor['session'], ['per_page'=>100]),
            'courses' => fn () => $catalog->courses($actor['user'], $actor['session'], ['per_page'=>100]),
            'classes' => fn () => $classes->classes($actor['user'], $actor['session'], ['per_page'=>100]),
            'roster' => fn () => $classes->roster($actor['user'], $actor['session'], $w['class'], ['per_page'=>100]),
            'enrollments' => fn () => $enrollments->listForClass($actor['user'], $actor['session'], $w['class'], null, 1, 100),
            'assessments' => fn () => $assessments->assessments($actor['user'], $actor['session'], $w['class'], ['per_page'=>100]),
            'certificates' => fn () => $documents->certificates($actor['user'], $actor['session'], $w['class'], ['per_page'=>100]),
        ];
        $small = []; foreach ($calls as $name => $call) $small[$name] = $this->measure($call);

        for ($i=2; $i<=20; $i++) {
            $program = $this->row('programs', ['academic_unit_id'=>$w['academicUnit'], 'code'=>'P'.$i]);
            $course = $this->row('courses', ['code'=>'C'.$i]);
            $this->row('curriculum_courses', ['curriculum_id'=>$w['curriculum'], 'course_id'=>$course, 'sequence'=>$i]);
            $version = $this->row('course_versions', ['course_id'=>$course, 'version'=>1]);
            $this->row('classes', ['academic_unit_id'=>$w['academicUnit'], 'course_version_id'=>$version, 'cohort_id'=>$w['cohort'], 'code'=>'CL'.$i, 'status'=>'S_CLS_OPEN']);
            $enrollment = $this->enrollment($w['class']);
            $this->assessment($w['version'], ['name'=>'Assessment '.$i]);
            $this->row('certificates', ['enrollment_id'=>$enrollment['id'], 'version'=>1, 'file_id'=>$file, 'approved_by'=>$actor['user']]);
            unset($program, $assessment);
        }
        $large = []; foreach ($calls as $name => $call) $large[$name] = $this->measure($call);
        foreach ($calls as $name => $_) {
            self::assertLessThanOrEqual($small[$name]['queries'] + 2, $large[$name]['queries'], "$name query count grew with row count");
            self::assertLessThan(2000.0, $large[$name]['ms'], "$name exceeded the local smoke budget");
        }
        fwrite(STDOUT, "\nA31_READ_BASELINE " . json_encode(['small'=>$small,'large'=>$large], JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function test_a32_details_are_constant_and_nested_collections_are_fully_paginated(): void
    {
        $a = $this->world(); $b = $this->world();
        $actor = $this->actor($a['unit'], ['ACADEMY_VIEW']);
        $service = new AcademicCatalogQueryService($this->rt());
        $smallCurriculum = $service->curriculum($actor['user'], $actor['session'], $a['curriculum']);
        $smallVersion = $service->courseVersion($actor['user'], $actor['session'], $a['version']);
        $smallSizes = ['curriculum_detail'=>strlen(json_encode($smallCurriculum)), 'course_version_detail'=>strlen(json_encode($smallVersion))];

        $small = [
            'curriculum_detail' => $this->measure(fn () => $service->curriculum($actor['user'], $actor['session'], $a['curriculum'])),
            'curriculum_courses' => $this->measure(fn () => $service->curriculumCourses($actor['user'], $actor['session'], $a['curriculum'], [])),
            'course_version_detail' => $this->measure(fn () => $service->courseVersion($actor['user'], $actor['session'], $a['version'])),
            'modules' => $this->measure(fn () => $service->courseVersionModules($actor['user'], $actor['session'], $a['version'], [])),
        ];

        $initialResource = $this->row('resources');
        $this->row('lesson_resources', ['lesson_id' => $a['lesson'], 'resource_id' => $initialResource, 'sequence' => 1]);

        for ($i = 2; $i <= 125; $i++) {
            $course = $this->row('courses', ['code' => 'A32-C-' . $i]);
            $this->row('curriculum_courses', ['curriculum_id' => $a['curriculum'], 'course_id' => $course, 'sequence' => $i]);
            $this->row('course_modules', ['course_version_id' => $a['version'], 'sequence' => $i, 'name' => 'Module ' . $i]);
            $this->row('lessons', ['module_id' => $a['module'], 'sequence' => $i, 'name' => 'Lesson ' . $i]);
            $resource = $this->row('resources');
            $this->row('lesson_resources', ['lesson_id' => $a['lesson'], 'resource_id' => $resource, 'sequence' => $i]);
            if ($i === 20) {
                $twenty = [
                    'curriculum_detail' => $this->measure(fn () => $service->curriculum($actor['user'], $actor['session'], $a['curriculum'])),
                    'curriculum_courses' => $this->measure(fn () => $service->curriculumCourses($actor['user'], $actor['session'], $a['curriculum'], [])),
                    'course_version_detail' => $this->measure(fn () => $service->courseVersion($actor['user'], $actor['session'], $a['version'])),
                    'modules' => $this->measure(fn () => $service->courseVersionModules($actor['user'], $actor['session'], $a['version'], [])),
                ];
            }
        }

        $curriculum = $service->curriculum($actor['user'], $actor['session'], $a['curriculum']);
        $version = $service->courseVersion($actor['user'], $actor['session'], $a['version']);
        self::assertSame(125, $curriculum['course_count']);
        self::assertArrayNotHasKey('courses', $curriculum);
        self::assertSame(125, $version['module_count']);
        self::assertSame(125, $version['lesson_count']);
        self::assertSame(125, $version['resource_count']);
        foreach (['modules', 'lessons', 'resources'] as $key) self::assertArrayNotHasKey($key, $version);

        foreach ([
            fn (array $q) => $service->curriculumCourses($actor['user'], $actor['session'], $a['curriculum'], $q),
            fn (array $q) => $service->courseVersionModules($actor['user'], $actor['session'], $a['version'], $q),
            fn (array $q) => $service->moduleLessons($actor['user'], $actor['session'], $a['version'], $a['module'], $q),
            fn (array $q) => $service->lessonResources($actor['user'], $actor['session'], $a['version'], $a['module'], $a['lesson'], $q),
        ] as $collection) {
            $p1 = $collection([]); $p2 = $collection(['page'=>2]); $p3 = $collection(['page'=>3]);
            self::assertCount(50, $p1['items']); self::assertCount(50, $p2['items']); self::assertCount(25, $p3['items']);
            self::assertSame(125, $p1['total']);
            $hundred = $collection(['per_page'=>100]); $remainder = $collection(['page'=>2, 'per_page'=>100]);
            self::assertCount(100, $hundred['items']); self::assertCount(25, $remainder['items']);
            self::assertCount(125, array_unique(array_map('serialize', array_merge($hundred['items'], $remainder['items']))));
        }

        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $service->curriculumCourses($actor['user'], $actor['session'], $b['curriculum'], []));
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $service->curriculumCourses($actor['user'], $actor['session'], PHP_INT_MAX, []));
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $service->moduleLessons($actor['user'], $actor['session'], $a['version'], $b['module'], []));
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $service->lessonResources($actor['user'], $actor['session'], $a['version'], $a['module'], $b['lesson'], []));

        $at125 = [
            'curriculum_detail' => $this->measure(fn () => $service->curriculum($actor['user'], $actor['session'], $a['curriculum'])),
            'curriculum_courses' => $this->measure(fn () => $service->curriculumCourses($actor['user'], $actor['session'], $a['curriculum'], ['per_page'=>100])),
            'course_version_detail' => $this->measure(fn () => $service->courseVersion($actor['user'], $actor['session'], $a['version'])),
            'modules' => $this->measure(fn () => $service->courseVersionModules($actor['user'], $actor['session'], $a['version'], ['per_page'=>100])),
        ];
        foreach ([$twenty, $at125] as $dataset) {
            foreach ($dataset as $name => $measurement) self::assertLessThanOrEqual($small[$name]['queries'] + 1, $measurement['queries'], "$name query count grew with child count");
        }
        $sizes125 = ['curriculum_detail'=>strlen(json_encode($curriculum)), 'course_version_detail'=>strlen(json_encode($version))];

        for ($i = 126; $i <= 500; $i++) {
            $course = $this->row('courses', ['code' => 'A32-C-' . $i]);
            $this->row('curriculum_courses', ['curriculum_id' => $a['curriculum'], 'course_id' => $course, 'sequence' => $i]);
            $this->row('course_modules', ['course_version_id' => $a['version'], 'sequence' => $i, 'name' => 'Module ' . $i]);
        }
        $curriculum500 = $service->curriculum($actor['user'], $actor['session'], $a['curriculum']);
        $version500 = $service->courseVersion($actor['user'], $actor['session'], $a['version']);
        self::assertSame(500, $curriculum500['course_count']);
        self::assertSame(500, $version500['module_count']);
        self::assertCount(100, $service->curriculumCourses($actor['user'], $actor['session'], $a['curriculum'], ['per_page'=>100])['items']);
        self::assertCount(100, $service->courseVersionModules($actor['user'], $actor['session'], $a['version'], ['per_page'=>100])['items']);
        $at500 = [
            'curriculum_detail' => $this->measure(fn () => $service->curriculum($actor['user'], $actor['session'], $a['curriculum'])),
            'curriculum_courses' => $this->measure(fn () => $service->curriculumCourses($actor['user'], $actor['session'], $a['curriculum'], ['per_page'=>100])),
            'course_version_detail' => $this->measure(fn () => $service->courseVersion($actor['user'], $actor['session'], $a['version'])),
            'modules' => $this->measure(fn () => $service->courseVersionModules($actor['user'], $actor['session'], $a['version'], ['per_page'=>100])),
        ];
        foreach ($at500 as $name => $measurement) self::assertLessThanOrEqual($small[$name]['queries'] + 1, $measurement['queries'], "$name query count grew at 500 children");
        $sizes500 = ['curriculum_detail'=>strlen(json_encode($curriculum500)), 'course_version_detail'=>strlen(json_encode($version500))];
        self::assertLessThanOrEqual($smallSizes['curriculum_detail'] + 16, $sizes500['curriculum_detail']);
        self::assertLessThanOrEqual($smallSizes['course_version_detail'] + 16, $sizes500['course_version_detail']);
        fwrite(STDOUT, "\nA32_BOUNDED_BASELINE " . json_encode(['children_1'=>$small,'children_20'=>$twenty,'children_125'=>$at125,'children_500'=>$at500,'detail_payload_bytes'=>['children_1'=>$smallSizes,'children_125'=>$sizes125,'children_500'=>$sizes500]], JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function measure(callable $call): array
    {
        $db = $this->db(); $db->flushQueryLog(); $db->enableQueryLog(); $start = hrtime(true); $result = $call(); $ms = (hrtime(true)-$start)/1_000_000; $queries=count($db->getQueryLog()); $db->disableQueryLog();
        return [
            'rows'=>(int)($result['total']??count($result['items']??[])),
            'response_items'=>count($result['items']??[]),
            'queries'=>$queries,
            'ms'=>round($ms,3),
        ];
    }
}
