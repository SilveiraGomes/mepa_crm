<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\ClassSessionService;
use App\Http\Requests\Academy\{ContextRequest,SessionStoreRequest,TransitionRequest};
use App\Http\Resources\Academy\AcademyActionResource;
use DateTimeImmutable;
final class SessionController extends AcademyController
{
    public function store(SessionStoreRequest $r,string $class){$v=$this->service(ClassSessionService::class)->schedule($this->actor($r),$this->session($r),$this->id('classes',$class),new DateTimeImmutable($r->validated('starts_at')),new DateTimeImmutable($r->validated('ends_at')),$r->validated('lesson_id'),$r->validated('event_session_id'),$r->validated('override_reason'),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
    public function transition(TransitionRequest $r,int $session){return new AcademyActionResource($this->service(ClassSessionService::class)->transition($this->actor($r),$this->session($r),$session,$r->validated('to_state'),$r->validated('reason'),$r->validated('lock_version'),$r->validated('override_reason'),$r->claimed()));}
    public function location(ContextRequest $r,int $session){return new AcademyActionResource($this->service(ClassSessionService::class)->effectiveLocation($this->actor($r),$this->session($r),$session,$r->claimed()));}
}
