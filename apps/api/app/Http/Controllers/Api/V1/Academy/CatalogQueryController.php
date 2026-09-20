<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Domain\Academy\AcademicCatalogQueryService;
use App\Http\Requests\Academy\{AcademyQueryRequest, ContextRequest};
use App\Http\Resources\Academy\{AcademyReadCollectionResource, AcademyReadResource};

final class CatalogQueryController extends AcademyController
{
    public function academicUnits(AcademyQueryRequest $r) { return new AcademyReadCollectionResource($this->service(AcademicCatalogQueryService::class)->academicUnits($this->actor($r), $this->session($r), $r->validated())); }
    public function academicUnit(ContextRequest $r, int $academicUnit) { return new AcademyReadResource($this->service(AcademicCatalogQueryService::class)->academicUnit($this->actor($r), $this->session($r), $academicUnit)); }
    public function programs(AcademyQueryRequest $r) { $q=$r->validated(); if(isset($q['academic_unit'])){$q['academic_unit_id']=$q['academic_unit'];} return new AcademyReadCollectionResource($this->service(AcademicCatalogQueryService::class)->programs($this->actor($r), $this->session($r), $q)); }
    public function program(ContextRequest $r, string $program) { return new AcademyReadResource($this->service(AcademicCatalogQueryService::class)->program($this->actor($r), $this->session($r), $this->id('programs',$program))); }
    public function curricula(AcademyQueryRequest $r) { $q=$r->validated(); if(isset($q['program'])){$q['program_id']=$this->id('programs',$q['program']);} return new AcademyReadCollectionResource($this->service(AcademicCatalogQueryService::class)->curricula($this->actor($r), $this->session($r), $q)); }
    public function curriculum(ContextRequest $r, int $curriculum) { return new AcademyReadResource($this->service(AcademicCatalogQueryService::class)->curriculum($this->actor($r), $this->session($r), $curriculum)); }
    public function courses(AcademyQueryRequest $r) { return new AcademyReadCollectionResource($this->service(AcademicCatalogQueryService::class)->courses($this->actor($r), $this->session($r), $r->validated())); }
    public function course(ContextRequest $r, string $course) { return new AcademyReadResource($this->service(AcademicCatalogQueryService::class)->course($this->actor($r), $this->session($r), $this->id('courses',$course))); }
    public function courseVersions(AcademyQueryRequest $r, string $course) { return new AcademyReadCollectionResource($this->service(AcademicCatalogQueryService::class)->courseVersions($this->actor($r), $this->session($r), $this->id('courses',$course), $r->validated())); }
    public function courseVersion(ContextRequest $r, int $courseVersion) { return new AcademyReadResource($this->service(AcademicCatalogQueryService::class)->courseVersion($this->actor($r), $this->session($r), $courseVersion)); }
    public function cohorts(AcademyQueryRequest $r) { $q=$r->validated(); if(isset($q['academic_unit'])){$q['academic_unit_id']=$q['academic_unit'];} if(isset($q['curriculum'])){$q['curriculum_id']=$q['curriculum'];} return new AcademyReadCollectionResource($this->service(AcademicCatalogQueryService::class)->cohorts($this->actor($r), $this->session($r), $q)); }
    public function cohort(ContextRequest $r, string $cohort) { return new AcademyReadResource($this->service(AcademicCatalogQueryService::class)->cohort($this->actor($r), $this->session($r), $this->id('cohorts',$cohort))); }
}
