<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\People\AddressService;
use App\Http\People\PeopleOutput;
use App\Http\Requests\People\AddressStoreRequest;
use App\Http\Requests\People\AddressUpdateRequest;
use App\Http\Requests\People\EndRequest;
use App\Http\Requests\People\HistoryRequest;
use Illuminate\Http\JsonResponse;

final class AddressController extends PeopleBaseController
{
    public function index(HistoryRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(AddressService::class)->list($this->actor($r), $this->session($r), $person, (bool) ($r->validated()['include_history'] ?? false)));
    }

    public function store(AddressStoreRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(AddressService::class)->create($this->actor($r), $this->session($r), $person, $r->validated()), 201);
    }

    public function update(AddressUpdateRequest $r, string $person, string $address): JsonResponse
    {
        return PeopleOutput::item($this->service(AddressService::class)->update($this->actor($r), $this->session($r), $person, $address, $r->validated()));
    }

    public function end(EndRequest $r, string $person, string $address): JsonResponse
    {
        $this->service(AddressService::class)->end($this->actor($r), $this->session($r), $person, $address, $r->validated()['reason'] ?? null);
        return response()->json(null, 204);
    }
}
