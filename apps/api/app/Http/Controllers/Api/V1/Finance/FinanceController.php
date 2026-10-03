<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Finance\FinanceQueryService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceOutput;
use App\Http\Finance\FinanceServiceFactory;
use App\Http\Requests\Finance\ContributionListRequest;
use App\Http\Requests\Finance\EmptyFinanceRequest;
use App\Http\Requests\Finance\PeriodRequest;
use App\Http\Requests\Finance\TransferListRequest;
use App\Http\Requests\Finance\UnitSearchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceController extends Controller
{
    public function __construct(private FinanceServiceFactory $factory)
    {
    }

    public function context(EmptyFinanceRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->context($u, $s)); }
    public function units(UnitSearchRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item(['items' => $this->q()->units($u, $s, $r->validated()['search'])]); }
    public function transfers(TransferListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->transferList($u, $s, $r->validated())); }
    public function transfer(EmptyFinanceRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->transfer($u, $s, $transfer)); }
    public function custody(PeriodRequest $r, string $unit): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->custody($u, $s, $unit, $r->validated())); }
    public function subtree(PeriodRequest $r, string $unit): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->subtree($u, $s, $unit, $r->validated())); }
    public function contributions(ContributionListRequest $r): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::page($r, $this->q()->contributions($u, $s, $r->validated())); }
    public function contribution(EmptyFinanceRequest $r, string $contribution): JsonResponse { [$u, $s] = $this->ids($r); return FinanceOutput::item($this->q()->contribution($u, $s, $contribution)); }

    private function q(): FinanceQueryService { return $this->factory->make(FinanceQueryService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
