<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\People\ExportService;
use App\Http\Requests\People\ExportRequest;
use Illuminate\Http\JsonResponse;

final class ExportController extends PeopleBaseController
{
    // The CSV travels inside the JSON body (no caching, bearer-only); the PWA saves it locally.
    public function store(ExportRequest $r): JsonResponse
    {
        $result = $this->service(ExportService::class)->export($this->actor($r), $this->session($r), $r->validated());
        return response()->json(['data' => $result], 201, ['Cache-Control' => 'no-store']);
    }
}
