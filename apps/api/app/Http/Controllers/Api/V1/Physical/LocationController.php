<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Physical;

use App\Domain\Physical\LocationService;
use App\Http\Controllers\Controller;
use App\Http\Physical\PhysicalOutput;
use App\Http\Physical\PhysicalServiceFactory;
use App\Http\Requests\Physical\AddressUpdateRequest;
use App\Http\Requests\Physical\LifecycleRequest;
use App\Http\Requests\Physical\LocationCreateRequest;
use App\Http\Requests\Physical\LocationUpdateRequest;
use App\Http\Requests\Physical\PhysicalListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// P0.7 Physical Locations (ADR 0018). Every target is a public_id; the actor comes from the authenticated session.
final class LocationController extends Controller
{
    public function __construct(private PhysicalServiceFactory $factory)
    {
    }

    public function context(Request $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->context($u, $s)); }
    public function index(PhysicalListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::page($r, $this->svc()->list($u, $s, $r->validated())); }
    public function store(LocationCreateRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->create($u, $s, $r->validated()), 201); }
    public function show(Request $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->detail($u, $s, $location)); }
    public function update(LocationUpdateRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->update($u, $s, $location, $r->validated())); }
    public function address(Request $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->address($u, $s, $location)); }
    public function updateAddress(AddressUpdateRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->updateAddress($u, $s, $location, $r->validated())); }
    public function activate(LifecycleRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->activate($u, $s, $location, $r->validated())); }
    public function close(LifecycleRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->close($u, $s, $location, $r->validated())); }
    public function publish(LifecycleRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->publish($u, $s, $location, $r->validated())); }
    public function unpublish(LifecycleRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->unpublish($u, $s, $location, $r->validated())); }

    private function svc(): LocationService { return $this->factory->make(LocationService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
