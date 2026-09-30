<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Membership;

use App\Domain\Membership\MembershipQueryService;
use App\Domain\Membership\MembershipTransferService;
use App\Http\Controllers\Controller;
use App\Http\Membership\MembershipOutput;
use App\Http\Membership\MembershipServiceFactory;
use App\Http\Requests\Membership\TransferCreateRequest;
use App\Http\Requests\Membership\TransferStageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MembershipTransferController extends Controller
{
    public function __construct(private MembershipServiceFactory $factory)
    {
    }

    public function store(TransferCreateRequest $r, string $membership): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $publicId = $this->svc()->request($u, $s, $membership, $r->validated());
        return MembershipOutput::item($this->detail($u, $s, $publicId), 201);
    }

    public function validateOrigin(TransferStageRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->validateOrigin($u, $s, $transfer, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $transfer)); }
    public function accept(TransferStageRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->accept($u, $s, $transfer, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $transfer)); }
    public function reject(TransferStageRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->reject($u, $s, $transfer, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $transfer)); }
    public function complete(TransferStageRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->complete($u, $s, $transfer, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $transfer)); }
    public function cancel(TransferStageRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->cancel($u, $s, $transfer, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $transfer)); }

    private function detail(int $u, int $s, string $publicId): array { return $this->factory->make(MembershipQueryService::class)->transferDetail($u, $s, $publicId); }
    private function svc(): MembershipTransferService { return $this->factory->make(MembershipTransferService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
