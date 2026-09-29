<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\People\PersonService;
use App\Http\People\PeopleOutput;
use App\Http\Requests\People\LifecycleRequest;
use App\Http\Requests\People\PeopleListRequest;
use App\Http\Requests\People\PeopleRequest;
use App\Http\Requests\People\PersonStoreRequest;
use App\Http\Requests\People\PersonUpdateRequest;
use App\Http\Requests\People\SelectorRequest;
use Illuminate\Http\JsonResponse;

final class PersonController extends PeopleBaseController
{
    public function context(PeopleRequest $r): JsonResponse
    {
        return PeopleOutput::item($this->service(PersonService::class)->context($this->actor($r), $this->session($r)));
    }

    public function catalogs(PeopleRequest $r): JsonResponse
    {
        return PeopleOutput::item($this->service(PersonService::class)->catalogs($this->actor($r), $this->session($r)));
    }

    public function index(PeopleListRequest $r): JsonResponse
    {
        return PeopleOutput::page($r, $this->service(PersonService::class)->list($this->actor($r), $this->session($r), $r->validated()));
    }

    public function selector(SelectorRequest $r): JsonResponse
    {
        $v = $r->validated();
        return PeopleOutput::item($this->service(PersonService::class)->selector($this->actor($r), $this->session($r), $v['purpose'], $v['search']));
    }

    public function show(PeopleRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(PersonService::class)->detail($this->actor($r), $this->session($r), $person));
    }

    public function store(PersonStoreRequest $r): JsonResponse
    {
        return PeopleOutput::item($this->service(PersonService::class)->create($this->actor($r), $this->session($r), $r->validated()), 201);
    }

    public function update(PersonUpdateRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(PersonService::class)->update($this->actor($r), $this->session($r), $person, $r->validated()));
    }

    public function inactivate(LifecycleRequest $r, string $person): JsonResponse
    {
        return $this->transition($r, $person, 'inactivate');
    }

    public function reactivate(LifecycleRequest $r, string $person): JsonResponse
    {
        return $this->transition($r, $person, 'reactivate');
    }

    public function markDeceased(LifecycleRequest $r, string $person): JsonResponse
    {
        return $this->transition($r, $person, 'mark-deceased');
    }

    private function transition(LifecycleRequest $r, string $person, string $action): JsonResponse
    {
        $v = $r->validated();
        return PeopleOutput::item($this->service(PersonService::class)->transition($this->actor($r), $this->session($r), $person, $action, $v['reason'] ?? null, $v['lock_version']));
    }
}
