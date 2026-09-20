<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Domain\Academy\AssessmentQueryService;
use App\Http\Requests\Academy\{AcademyQueryRequest, ContextRequest};
use App\Http\Resources\Academy\{AcademyReadCollectionResource, AcademyReadResource};

final class AssessmentQueryController extends AcademyController
{
    public function index(AcademyQueryRequest $r,string $class){return new AcademyReadCollectionResource($this->service(AssessmentQueryService::class)->assessments($this->actor($r),$this->session($r),$this->id('classes',$class),$r->validated()));}
    public function show(ContextRequest $r,string $class,string $assessment){return new AcademyReadResource($this->service(AssessmentQueryService::class)->assessment($this->actor($r),$this->session($r),$this->id('classes',$class),$this->id('assessments',$assessment)));}
    public function attempts(AcademyQueryRequest $r,string $class,string $assessment){return new AcademyReadCollectionResource($this->service(AssessmentQueryService::class)->attempts($this->actor($r),$this->session($r),$this->id('classes',$class),$this->id('assessments',$assessment),$r->validated()));}
    public function attempt(ContextRequest $r,string $class,int $attempt){return new AcademyReadResource($this->service(AssessmentQueryService::class)->attempt($this->actor($r),$this->session($r),$this->id('classes',$class),$attempt));}
}
