<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Membership;

use App\Domain\Membership\MembershipQueryService;
use App\Http\Controllers\Controller;
use App\Http\Membership\MembershipOutput;
use App\Http\Membership\MembershipServiceFactory;
use App\Http\Requests\Membership\MembershipListRequest;
use App\Http\Requests\Membership\MembershipPageRequest;
use App\Http\Requests\Membership\TransferListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MembershipController extends Controller
{
    public function __construct(private MembershipServiceFactory $factory)
    {
    }

    public function context(Request $r): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->svc()->context($u, $s)); }
    public function index(MembershipListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::page($r, $this->svc()->list($u, $s, $r->validated())); }
    public function show(Request $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->svc()->detail($u, $s, $membership)); }
    public function periods(MembershipPageRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::page($r, $this->svc()->periods($u, $s, $membership, $r->validated())); }
    public function transfers(MembershipPageRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::page($r, $this->svc()->membershipTransfers($u, $s, $membership, $r->validated())); }
    public function allTransfers(TransferListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::page($r, $this->svc()->transfers($u, $s, $r->validated())); }
    public function transfer(Request $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->svc()->transferDetail($u, $s, $transfer)); }

    private function svc(): MembershipQueryService { return $this->factory->make(MembershipQueryService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
