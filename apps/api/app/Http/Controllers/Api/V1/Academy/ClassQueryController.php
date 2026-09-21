<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Domain\Academy\ClassQueryService;
use App\Http\Requests\Academy\{AcademyQueryRequest, ContextRequest};
use App\Http\Resources\Academy\{AcademyReadCollectionResource, AcademyReadResource};

final class ClassQueryController extends AcademyController
{
    public function index(AcademyQueryRequest $r) { $q=$r->validated(); foreach(['academic_unit'=>'academic_unit_id','curriculum'=>'curriculum_id','course_version'=>'course_version_id'] as $from=>$to){if(isset($q[$from])){$q[$to]=$q[$from];}} if(isset($q['cohort'])){$q['cohort_id']=$this->id('cohorts',$q['cohort']);} return new AcademyReadCollectionResource($this->service(ClassQueryService::class)->classes($this->actor($r),$this->session($r),$q)); }
    public function show(ContextRequest $r,string $class){return new AcademyReadResource($this->service(ClassQueryService::class)->detail($this->actor($r),$this->session($r),$this->id('classes',$class)));}
    public function roster(AcademyQueryRequest $r,string $class){return new AcademyReadCollectionResource($this->service(ClassQueryService::class)->roster($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated()));}
    public function people(AcademyQueryRequest $r,string $class){return new AcademyReadCollectionResource($this->service(ClassQueryService::class)->searchPeopleForEnrollment($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated()));}
    public function instructorCandidates(AcademyQueryRequest $r,string $class){return new AcademyReadCollectionResource($this->service(ClassQueryService::class)->searchInstructorCandidates($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated()));}
    public function enrollment(ContextRequest $r,string $class,string $enrollment){return new AcademyReadResource($this->service(ClassQueryService::class)->enrollment($this->actor($r),$this->session($r),$this->id('classes',$class),$this->id('enrollments',$enrollment)));}
    public function sessions(AcademyQueryRequest $r,string $class){return new AcademyReadCollectionResource($this->service(ClassQueryService::class)->sessions($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated()));}
    public function showSession(ContextRequest $r,string $class,int $classSession){return new AcademyReadResource($this->service(ClassQueryService::class)->session($this->actor($r),$this->session($r),$this->id('classes',$class),$classSession));}
    public function progress(AcademyQueryRequest $r,string $enrollment){return new AcademyReadCollectionResource($this->service(ClassQueryService::class)->progress($this->actor($r),$this->session($r),$this->id('enrollments',$enrollment),$r->validated()));}
}
