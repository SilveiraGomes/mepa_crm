<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\People\RelationshipService;
use App\Http\People\PeopleOutput;
use App\Http\Requests\People\EndRequest;
use App\Http\Requests\People\HistoryRequest;
use App\Http\Requests\People\RelationshipStoreRequest;
use Illuminate\Http\JsonResponse;

final class RelationshipController extends PeopleBaseController
{
    public function index(HistoryRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(RelationshipService::class)->list($this->actor($r), $this->session($r), $person, (bool) ($r->validated()['include_history'] ?? false)));
    }

    public function store(RelationshipStoreRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(RelationshipService::class)->create($this->actor($r), $this->session($r), $person, $r->validated()), 201);
    }

    public function end(EndRequest $r, string $person, string $relationship): JsonResponse
    {
        $this->service(RelationshipService::class)->end($this->actor($r), $this->session($r), $person, $relationship, $r->validated()['reason'] ?? null);
        return response()->json(null, 204);
    }
}
