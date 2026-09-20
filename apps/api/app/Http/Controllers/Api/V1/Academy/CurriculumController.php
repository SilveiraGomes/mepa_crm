<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\CurriculumService;
use App\Http\Requests\Academy\{ContextRequest,CurriculumCourseRequest,VersionedActionRequest};
use App\Http\Resources\Academy\AcademyActionResource;
final class CurriculumController extends AcademyController
{
    public function store(ContextRequest $r,string $program){$v=$this->service(CurriculumService::class)->createVersion($this->actor($r),$this->session($r),$this->id('programs',$program),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
    public function publish(VersionedActionRequest $r,int $curriculum){$v=$this->service(CurriculumService::class)->publish($this->actor($r),$this->session($r),$curriculum,$r->validated('lock_version'),$r->claimed());return new AcademyActionResource($v);}
    public function addCourse(CurriculumCourseRequest $r,int $curriculum){$v=$this->service(CurriculumService::class)->addCourse($this->actor($r),$this->session($r),$curriculum,(int)$r->validated('course_id'),(int)$r->validated('sequence'),(bool)$r->validated('required'),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
}
