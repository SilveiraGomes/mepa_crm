<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\ProgressService;
use App\Http\Requests\Academy\{LessonProgressRequest,ResourceProgressRequest};
use App\Http\Resources\Academy\AcademyActionResource;
final class ProgressController extends AcademyController
{
    public function lesson(LessonProgressRequest $r,string $enrollment,int $lesson){return new AcademyActionResource($this->service(ProgressService::class)->recordLesson($this->actor($r),$this->session($r),$this->id('enrollments',$enrollment),$lesson,$r->validated('ratio'),$r->validated('to_state'),$r->validated('override_reason'),$r->claimed()));}
    public function resource(ResourceProgressRequest $r,string $enrollment,string $resource){return new AcademyActionResource($this->service(ProgressService::class)->recordResource($this->actor($r),$this->session($r),$this->id('enrollments',$enrollment),$this->id('resources',$resource),(int)$r->validated('watched_seconds'),$r->validated('to_state'),$r->validated('override_reason'),$r->claimed()));}
}
