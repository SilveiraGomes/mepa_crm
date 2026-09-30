<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Membership;

use App\Domain\Membership\LifecycleService;
use App\Domain\Membership\MembershipQueryService;
use App\Http\Controllers\Controller;
use App\Http\Membership\MembershipOutput;
use App\Http\Membership\MembershipServiceFactory;
use App\Http\Requests\Membership\MemberLifecycleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberLifecycleController extends Controller
{
    public function __construct(private MembershipServiceFactory $factory)
    {
    }

    public function inactivate(MemberLifecycleRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->inactivate($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }
    public function reactivate(MemberLifecycleRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->reactivate($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }
    public function end(MemberLifecycleRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->end($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }
    public function readmit(MemberLifecycleRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->readmit($u, $s, $membership, $r->validated()); return MembershipOutput::item($this->detail($u, $s, $membership)); }

    private function detail(int $u, int $s, string $publicId): array { return $this->factory->make(MembershipQueryService::class)->detail($u, $s, $publicId); }
    private function svc(): LifecycleService { return $this->factory->make(LifecycleService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
