<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Domain\Academy\EligibleFileQueryService;
use App\Http\Requests\Academy\AcademyQueryRequest;
use App\Http\Resources\Academy\AcademyReadCollectionResource;

final class EligibleFileController extends AcademyController
{
    public function certificate(AcademyQueryRequest $request, string $enrollment): AcademyReadCollectionResource
    {
        return new AcademyReadCollectionResource($this->service(EligibleFileQueryService::class)->forCertificate($this->actor($request), $this->session($request), $this->id('enrollments', $enrollment), $request->validated()));
    }

    public function transcript(AcademyQueryRequest $request, int $curriculum, string $person): AcademyReadCollectionResource
    {
        return new AcademyReadCollectionResource($this->service(EligibleFileQueryService::class)->forTranscript($this->actor($request), $this->session($request), $person, $curriculum, $request->validated()));
    }
}
