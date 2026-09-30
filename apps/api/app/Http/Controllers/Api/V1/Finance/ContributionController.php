<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Finance\ContributionService;
use App\Domain\Finance\FinanceQueryService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceServiceFactory;
use App\Http\Requests\Finance\ContributionCreateRequest;
use App\Http\Requests\Finance\ContributionValuationRequest;
use App\Http\Requests\Finance\EmptyFinanceRequest;
use App\Http\Finance\FinanceOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContributionController extends Controller
{
    public function __construct(private FinanceServiceFactory $factory)
    {
    }

    public function store(ContributionCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $result = $this->svc()->record($u, $s, (string) $r->header('Idempotency-Key', ''), $r->validated());
        $detail = $this->factory->make(FinanceQueryService::class)->contribution($u, $s, $result['public_id']);
        FinanceOutput::assertSafe($detail);
        return response()->json(['data' => $detail, 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201, ['Cache-Control' => 'no-store, private']);
    }

    public function valuate(ContributionValuationRequest $r, string $contribution): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->valuate($u, $s, $contribution, $r->validated()); return $this->detail($u, $s, $contribution); }
    public function approve(EmptyFinanceRequest $r, string $contribution): JsonResponse { [$u, $s] = $this->ids($r); $this->svc()->approveValuation($u, $s, $contribution); return $this->detail($u, $s, $contribution); }

    private function detail(int $u, int $s, string $publicId): JsonResponse { return FinanceOutput::item($this->factory->make(FinanceQueryService::class)->contribution($u, $s, $publicId)); }
    private function svc(): ContributionService { return $this->factory->make(ContributionService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
