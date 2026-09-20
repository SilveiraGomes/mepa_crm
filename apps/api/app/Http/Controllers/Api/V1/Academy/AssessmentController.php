<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\AssessmentService;
use App\Http\Requests\Academy\{AssessmentStoreRequest,AssessmentUpdateRequest};
use App\Http\Resources\Academy\AcademyActionResource;
final class AssessmentController extends AcademyController
{
    public function store(AssessmentStoreRequest $r,int $courseVersion){$v=$r->validated();$out=$this->service(AssessmentService::class)->create($this->actor($r),$this->session($r),$courseVersion,$v['lesson_id']??null,$v['name'],$v['max_score'],$v['pass_score']??null,$v['max_attempts'],$v['weight'],$r->claimed());return (new AcademyActionResource($out))->response()->setStatusCode(201);}
    public function update(AssessmentUpdateRequest $r,string $assessment){$v=$r->validated();$changes=array_intersect_key($v,array_flip(['name','max_score','pass_score','max_attempts','weight']));return new AcademyActionResource($this->service(AssessmentService::class)->update($this->actor($r),$this->session($r),$this->id('assessments',$assessment),$changes,(int)$v['lock_version'],$r->claimed()));}
}
