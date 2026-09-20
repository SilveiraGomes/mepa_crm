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

    private function measure(callable $call): array
    {
        $db = $this->db(); $db->flushQueryLog(); $db->enableQueryLog(); $start = hrtime(true); $result = $call(); $ms = (hrtime(true)-$start)/1_000_000; $queries=count($db->getQueryLog()); $db->disableQueryLog();
        return ['rows'=>(int)($result['total']??count($result['items']??[])),'queries'=>$queries,'ms'=>round($ms,3)];
    }
}
