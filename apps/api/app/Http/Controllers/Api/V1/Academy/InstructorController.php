<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\InstructorAssignmentService;
use App\Http\Requests\Academy\{ContextRequest,InstructorAssignRequest,VersionedActionRequest};
use App\Http\Resources\Academy\{AcademyActionResource,AcademyCollectionResource};
use DateTimeImmutable;
final class InstructorController extends AcademyController
{
    public function store(InstructorAssignRequest $r,string $class){$v=$this->service(InstructorAssignmentService::class)->assign($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated('person'),isset($r->validated()['starts_at'])?new DateTimeImmutable($r->validated('starts_at')):null,isset($r->validated()['ends_at'])?new DateTimeImmutable($r->validated('ends_at')):null,$r->validated('reason'),$r->validated('source_document_id'),$r->validated('override_reason'),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
    public function index(ContextRequest $r,string $class){return new AcademyCollectionResource($this->service(InstructorAssignmentService::class)->activeAssignments($this->actor($r),$this->session($r),$this->id('classes',$class),$r->claimed()));}
    public function end(VersionedActionRequest $r,int $assignment){return new AcademyActionResource($this->service(InstructorAssignmentService::class)->end($this->actor($r),$this->session($r),$assignment,$r->validated('reason'),$r->validated('lock_version'),$r->validated('override_reason'),$r->claimed()));}
}
