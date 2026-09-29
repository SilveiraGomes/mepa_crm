<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Physical;

use App\Domain\Physical\LinkService;
use App\Http\Controllers\Controller;
use App\Http\Physical\PhysicalOutput;
use App\Http\Physical\PhysicalServiceFactory;
use App\Http\Requests\Physical\LinkCreateRequest;
use App\Http\Requests\Physical\LinkEndRequest;
use App\Http\Requests\Physical\LinkPrimaryRequest;
use App\Http\Requests\Physical\LinkTransferRequest;
use App\Http\Requests\Physical\PhysicalListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Unit <-> Location links: explicit actions only (link, end-link, transfer, set-primary); a link is reached through
// its location and addressed by an opaque ref.
final class LinkController extends Controller
{
    public function __construct(private PhysicalServiceFactory $factory)
    {
    }

    public function index(PhysicalListRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::page($r, $this->svc()->listForLocation($u, $s, $location, $r->validated())); }
    public function forUnit(PhysicalListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::page($r, $this->svc()->listForUnit($u, $s, $r->validated())); }
    public function store(LinkCreateRequest $r, string $location): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->link($u, $s, $location, $r->validated()), 201); }
    public function end(LinkEndRequest $r, string $location, string $link): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->end($u, $s, $location, $link, $r->validated())); }
    public function transfer(LinkTransferRequest $r, string $location, string $link): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->transfer($u, $s, $location, $link, $r->validated()), 201); }
    public function setPrimary(LinkPrimaryRequest $r, string $location, string $link): JsonResponse { [$u, $s] = $this->ids($r); return PhysicalOutput::item($this->svc()->setPrimary($u, $s, $location, $link, $r->validated())); }

    private function svc(): LinkService { return $this->factory->make(LinkService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
