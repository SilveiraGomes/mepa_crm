<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Membership;

use App\Domain\Membership\LegacyIdentifierService;
use App\Domain\Membership\MilestoneService;
use App\Http\Controllers\Controller;
use App\Http\Membership\MembershipOutput;
use App\Http\Membership\MembershipServiceFactory;
use App\Http\Requests\Membership\LegacyRegisterRequest;
use App\Http\Requests\Membership\LegacyRevokeRequest;
use App\Http\Requests\Membership\MilestoneCorrectRequest;
use App\Http\Requests\Membership\MilestoneRecordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Legacy identifiers and ecclesiastical milestones, both reached only through their membership public_id and a
// natural key (source_system + normalized value; milestone type): no internal id is ever addressable.
final class MembershipRecordController extends Controller
{
    public function __construct(private MembershipServiceFactory $factory)
    {
    }

    public function legacy(Request $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::page($r, $this->legacySvc()->list($u, $s, $membership)); }
    public function registerLegacy(LegacyRegisterRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->legacySvc()->register($u, $s, $membership, $r->validated()), 201); }
    public function revokeLegacy(LegacyRevokeRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->legacySvc()->revoke($u, $s, $membership, $r->validated())); }
    public function milestones(Request $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::page($r, $this->milestoneSvc()->list($u, $s, $membership)); }
    public function recordMilestone(MilestoneRecordRequest $r, string $membership): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->milestoneSvc()->record($u, $s, $membership, $r->validated()), 201); }
    public function correctMilestone(MilestoneCorrectRequest $r, string $membership, string $type): JsonResponse { [$u, $s] = $this->ids($r); return MembershipOutput::item($this->milestoneSvc()->correct($u, $s, $membership, $type, $r->validated())); }

    private function legacySvc(): LegacyIdentifierService { return $this->factory->make(LegacyIdentifierService::class); }
    private function milestoneSvc(): MilestoneService { return $this->factory->make(MilestoneService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
