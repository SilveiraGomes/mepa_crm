<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\AssessmentAttemptService;
use App\Http\Requests\Academy\{AttemptStoreRequest,TransitionRequest};
use App\Http\Resources\Academy\AcademyActionResource;
final class AttemptController extends AcademyController
{
    public function store(AttemptStoreRequest $r,string $assessment){$v=$this->service(AssessmentAttemptService::class)->start($this->actor($r),$this->session($r),$this->id('assessments',$assessment),$this->id('enrollments',$r->validated('enrollment')),null,$r->validated('answers_metadata'),$r->validated('override_reason'),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
    public function transition(TransitionRequest $r,int $attempt){return new AcademyActionResource($this->service(AssessmentAttemptService::class)->transition($this->actor($r),$this->session($r),$attempt,$r->validated('to_state'),$r->validated('reason'),$r->validated('lock_version'),$r->validated('override_reason'),$r->claimed()));}
}
