<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\TranscriptService;
use App\Http\Requests\Academy\{ContextRequest,TranscriptIssueRequest};
use App\Http\Resources\Academy\TranscriptResource;
final class TranscriptController extends AcademyController
{
    public function preview(ContextRequest $r,int $curriculum,string $person){return new TranscriptResource($this->service(TranscriptService::class)->compile($this->actor($r),$this->session($r),$person,$curriculum,$r->claimed()));}
    public function store(TranscriptIssueRequest $r,int $curriculum,string $person){$v=$this->service(TranscriptService::class)->issue($this->actor($r),$this->session($r),$person,$curriculum,(int)$r->validated('file_id'),$r->validated('override_reason'),$r->claimed());return (new TranscriptResource($v))->response()->setStatusCode(201);}
}
