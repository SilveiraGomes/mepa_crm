<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Domain\Academy\DocumentQueryService;
use App\Http\Requests\Academy\{AcademyQueryRequest, ContextRequest};
use App\Http\Resources\Academy\{AcademyReadCollectionResource, AcademyReadResource};

final class DocumentQueryController extends AcademyController
{
    public function certificates(AcademyQueryRequest $r,string $class){return new AcademyReadCollectionResource($this->service(DocumentQueryService::class)->certificates($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated()));}
    public function certificate(ContextRequest $r,string $class,string $certificate){return new AcademyReadResource($this->service(DocumentQueryService::class)->certificate($this->actor($r),$this->session($r),$this->id('classes',$class),$this->id('certificates',$certificate)));}
    public function transcripts(AcademyQueryRequest $r,int $curriculum,string $person){return new AcademyReadCollectionResource($this->service(DocumentQueryService::class)->transcripts($this->actor($r),$this->session($r),$curriculum,$this->id('people',$person),$r->validated()));}
    public function transcript(ContextRequest $r,int $curriculum,string $person,string $transcript){return new AcademyReadResource($this->service(DocumentQueryService::class)->transcript($this->actor($r),$this->session($r),$curriculum,$this->id('people',$person),$this->id('transcripts',$transcript)));}
}
