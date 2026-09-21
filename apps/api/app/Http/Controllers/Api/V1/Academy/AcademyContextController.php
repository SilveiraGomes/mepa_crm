<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Domain\Academy\AcademyContextQueryService;
use App\Http\Requests\Academy\ContextRequest;
use App\Http\Resources\Academy\AcademyContextResource;

final class AcademyContextController extends AcademyController
{
    public function show(ContextRequest $request): AcademyContextResource
    {
        return new AcademyContextResource($this->service(AcademyContextQueryService::class)->context($this->actor($request), $this->session($request)));
    }
}
