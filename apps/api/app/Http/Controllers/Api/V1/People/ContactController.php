<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\People\ContactService;
use App\Http\People\PeopleOutput;
use App\Http\Requests\People\ContactStoreRequest;
use App\Http\Requests\People\ContactUpdateRequest;
use App\Http\Requests\People\EndRequest;
use App\Http\Requests\People\PeopleRequest;
use Illuminate\Http\JsonResponse;

final class ContactController extends PeopleBaseController
{
    public function index(PeopleRequest $r, string $person): JsonResponse
    {
        $result = $this->service(ContactService::class)->list($this->actor($r), $this->session($r), $person);
        PeopleOutput::assertSafe($result['items']);
        return response()->json(['data' => $result['items'], 'meta' => ['hidden' => $result['hidden']]]);
    }

    public function store(ContactStoreRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(ContactService::class)->create($this->actor($r), $this->session($r), $person, $r->validated()), 201);
    }

    public function update(ContactUpdateRequest $r, string $person, string $contact): JsonResponse
    {
        return PeopleOutput::item($this->service(ContactService::class)->update($this->actor($r), $this->session($r), $person, $contact, $r->validated()));
    }

    public function end(EndRequest $r, string $person, string $contact): JsonResponse
    {
        $this->service(ContactService::class)->end($this->actor($r), $this->session($r), $person, $contact, $r->validated()['reason'] ?? null);
        return response()->json(null, 204);
    }
}
