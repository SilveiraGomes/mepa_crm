<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Physical;

use App\Domain\Physical\PropertyService;
use App\Http\Controllers\Controller;
use App\Http\Physical\PhysicalOutput;
use App\Http\Physical\PhysicalServiceFactory;
use App\Http\Requests\Physical\LifecycleRequest;
use App\Http\Requests\Physical\OwnershipStatusRequest;
use App\Http\Requests\Physical\PhysicalListRequest;
use App\Http\Requests\Physical\PropertyCreateRequest;
use App\Http\Requests\Physical\PropertyUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PropertyController extends Controller
{
    public function __construct(private PhysicalServiceFactory $factory)
    {
    }

    public function index(PhysicalListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::page($r, $this->svc()->list($u, $s, $r->validated())); }
    public function store(PropertyCreateRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->create($u, $s, $r->validated()), 201); }
    public function show(Request $r, string $property): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->detail($u, $s, $property)); }
    public function update(PropertyUpdateRequest $r, string $property): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->update($u, $s, $property, $r->validated())); }
    public function ownershipStatus(OwnershipStatusRequest $r, string $property): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->ownershipStatus($u, $s, $property, $r->validated())); }
    public function activate(LifecycleRequest $r, string $property): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->activate($u, $s, $property, $r->validated())); }
    public function close(LifecycleRequest $r, string $property): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->close($u, $s, $property, $r->validated())); }
    public function externalOwner(Request $r, string $property): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->externalOwner($u, $s, $property)); }

    private function svc(): PropertyService { return $this->factory->make(PropertyService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
