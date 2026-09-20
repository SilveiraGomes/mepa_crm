<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\GradeService;
use App\Http\Requests\Academy\{ContextRequest,GradeRevisionRequest,GradeStoreRequest,VersionedActionRequest};
use App\Http\Resources\Academy\{AcademyActionResource,AcademyCollectionResource};
final class GradeController extends AcademyController
{
    public function store(GradeStoreRequest $r,int $attempt){$v=$this->service(GradeService::class)->record($this->actor($r),$this->session($r),$attempt,$r->validated('score'),$r->validated('reason'),$r->validated('override_reason'),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
    public function revise(GradeRevisionRequest $r,int $attempt){$v=$this->service(GradeService::class)->revise($this->actor($r),$this->session($r),$attempt,(int)$r->validated('expected_version'),$r->validated('score'),$r->validated('reason'),$r->validated('override_reason'),$r->claimed());return (new AcademyActionResource($v))->response()->setStatusCode(201);}
    public function finalize(VersionedActionRequest $r,int $attempt){return new AcademyActionResource($this->service(GradeService::class)->finalize($this->actor($r),$this->session($r),$attempt,(int)$r->validated('lock_version'),$r->validated('reason'),$r->validated('override_reason'),$r->claimed()));}
    public function index(ContextRequest $r,int $attempt){return new AcademyCollectionResource($this->service(GradeService::class)->history($this->actor($r),$this->session($r),$attempt,$r->claimed()));}
}
