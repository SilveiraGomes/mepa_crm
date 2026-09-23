<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\People\HouseholdService;
use App\Http\People\PeopleOutput;
use App\Http\Requests\People\EndRequest;
use App\Http\Requests\People\HouseholdListRequest;
use App\Http\Requests\People\HouseholdMemberRequest;
use App\Http\Requests\People\HouseholdStoreRequest;
use App\Http\Requests\People\HouseholdUpdateRequest;
use App\Http\Requests\People\LifecycleRequest;
use App\Http\Requests\People\PeopleRequest;
use Illuminate\Http\JsonResponse;

final class HouseholdController extends PeopleBaseController
{
    public function index(HouseholdListRequest $r): JsonResponse
    {
        return PeopleOutput::page($r, $this->service(HouseholdService::class)->list($this->actor($r), $this->session($r), $r->validated()));
    }

    public function show(PeopleRequest $r, string $household): JsonResponse
    {
        return PeopleOutput::item($this->service(HouseholdService::class)->detail($this->actor($r), $this->session($r), $household));
    }

    public function forPerson(PeopleRequest $r, string $person): JsonResponse
    {
        return PeopleOutput::item($this->service(HouseholdService::class)->forPerson($this->actor($r), $this->session($r), $person));
    }

    public function store(HouseholdStoreRequest $r): JsonResponse
    {
        return PeopleOutput::item($this->service(HouseholdService::class)->create($this->actor($r), $this->session($r), $r->validated()), 201);
    }

    public function update(HouseholdUpdateRequest $r, string $household): JsonResponse
    {
        return PeopleOutput::item($this->service(HouseholdService::class)->update($this->actor($r), $this->session($r), $household, $r->validated()));
    }

    public function inactivate(LifecycleRequest $r, string $household): JsonResponse { return $this->transition($r, $household, 'inactivate'); }
    public function reactivate(LifecycleRequest $r, string $household): JsonResponse { return $this->transition($r, $household, 'reactivate'); }
    public function archive(LifecycleRequest $r, string $household): JsonResponse { return $this->transition($r, $household, 'archive'); }
    public function restore(LifecycleRequest $r, string $household): JsonResponse { return $this->transition($r, $household, 'restore'); }

    public function addMember(HouseholdMemberRequest $r, string $household): JsonResponse
    {
        return PeopleOutput::item($this->service(HouseholdService::class)->addMember($this->actor($r), $this->session($r), $household, $r->validated()), 201);
    }

    public function endMember(EndRequest $r, string $household, string $member): JsonResponse
    {
        $this->service(HouseholdService::class)->endMember($this->actor($r), $this->session($r), $household, $member, $r->validated()['reason'] ?? null);
        return response()->json(null, 204);
    }

    private function transition(LifecycleRequest $r, string $household, string $action): JsonResponse
    {
        $v = $r->validated();
        return PeopleOutput::item($this->service(HouseholdService::class)->transition($this->actor($r), $this->session($r), $household, $action, $v['reason'] ?? null, $v['lock_version']));
    }
}
