<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Membership;

use App\Domain\Membership\AdmissionService;
use App\Domain\Membership\MembershipQueryService;
use App\Http\Controllers\Controller;
use App\Http\Membership\MembershipOutput;
use App\Http\Membership\MembershipServiceFactory;
use App\Http\Requests\Membership\ApproveRequest;
use App\Http\Requests\Membership\CandidacyRequest;
use App\Http\Requests\Membership\CollectiveApprovalRequest;
use App\Http\Requests\Membership\SubmitAdmissionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdmissionController extends Controller
{
    public function __construct(private MembershipServiceFactory $factory)
    {
    }

    public function submit(SubmitAdmissionRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $publicId = $this->svc()->submit($u, $s, $r->validated());
        return MembershipOutput::item($this->detail($u, $s, $publicId), 201);
    }

    public function validateCandidacy(CandidacyRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->validate($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }
    public function withdraw(CandidacyRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->withdraw($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }
    public function reject(CandidacyRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->reject($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }
    public function approve(ApproveRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->approve($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }

    public function collective(CollectiveApprovalRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $issued = $this->svc()->collectiveApprove($u, $s, $r->validated());
        return MembershipOutput::item(['approved' => $issued, 'count' => count($issued)]);
    }

    private function detail(int $u, int $s, string $publicId): array { return $this->factory->make(MembershipQueryService::class)->detail($u, $s, $publicId); }
    private function svc(): AdmissionService { return $this->factory->make(AdmissionService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
