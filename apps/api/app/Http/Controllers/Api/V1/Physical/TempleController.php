<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Physical;

use App\Domain\Physical\TempleService;
use App\Http\Controllers\Controller;
use App\Http\Physical\PhysicalOutput;
use App\Http\Physical\PhysicalServiceFactory;
use App\Http\Requests\Physical\LifecycleRequest;
use App\Http\Requests\Physical\PhysicalListRequest;
use App\Http\Requests\Physical\TempleCreateRequest;
use App\Http\Requests\Physical\TempleUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TempleController extends Controller
{
    public function __construct(private PhysicalServiceFactory $factory)
    {
    }

    public function index(PhysicalListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::page($r, $this->svc()->list($u, $s, $r->validated())); }
    public function store(TempleCreateRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->create($u, $s, $r->validated()), 201); }
    public function show(Request $r, string $temple): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->detail($u, $s, $temple)); }
    public function update(TempleUpdateRequest $r, string $temple): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->update($u, $s, $temple, $r->validated())); }
    public function activate(LifecycleRequest $r, string $temple): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->activate($u, $s, $temple, $r->validated())); }
    public function close(LifecycleRequest $r, string $temple): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->close($u, $s, $temple, $r->validated())); }

    private function svc(): TempleService { return $this->factory->make(TempleService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
