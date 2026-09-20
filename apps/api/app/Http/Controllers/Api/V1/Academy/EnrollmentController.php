<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\{CompletionService,EnrollmentService};
use App\Http\Requests\Academy\{AcademyListRequest,EnrollmentStoreRequest,TransitionRequest,VersionedActionRequest};
use App\Http\Resources\Academy\{AcademyActionResource,AcademyCollectionResource,EnrollmentResource};
final class EnrollmentController extends AcademyController
{
    public function store(EnrollmentStoreRequest $r,string $class){$v=$this->service(EnrollmentService::class)->enroll($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated('person'),$r->claimed());return (new EnrollmentResource($v))->response()->setStatusCode(201);}
    public function index(AcademyListRequest $r,string $class){$v=$this->service(EnrollmentService::class)->listForClass($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated('status'),(int)($r->validated('page')??1),(int)($r->validated('per_page')??50),$r->claimed());return new AcademyCollectionResource($v);}
    public function transition(TransitionRequest $r,string $enrollment){$v=$this->service(EnrollmentService::class)->transition($this->actor($r),$this->session($r),$this->id('enrollments',$enrollment),$r->validated('to_state'),$r->validated('reason'),$r->validated('lock_version'),$r->claimed());return new AcademyActionResource($v);}
    public function complete(VersionedActionRequest $r,string $enrollment){$v=$this->service(CompletionService::class)->complete($this->actor($r),$this->session($r),$this->id('enrollments',$enrollment),$r->validated('reason'),$r->validated('lock_version'),$r->validated('override_reason'),$r->claimed());return new AcademyActionResource($v);}
}
