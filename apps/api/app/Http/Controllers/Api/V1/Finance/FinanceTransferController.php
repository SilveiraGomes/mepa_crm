<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Finance\FinanceQueryService;
use App\Domain\Finance\InternalTransferService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceOutput;
use App\Http\Finance\FinanceServiceFactory;
use App\Http\Requests\Finance\TransferCreateRequest;
use App\Http\Requests\Finance\TransferReasonRequest;
use App\Http\Requests\Finance\TransferReceiveRequest;
use App\Http\Requests\Finance\TransferReconcileRequest;
use App\Http\Requests\Finance\TransferSendRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Explicit stage endpoints only (no generic status PATCH). A replayed stage answers 200 with the current detail and
// meta.replayed = true: never a second accounting effect.
final class FinanceTransferController extends Controller
{
    public function __construct(private FinanceServiceFactory $factory)
    {
    }

    public function store(TransferCreateRequest $r): JsonResponse
    {
        [$u, $s] = $this->ids($r);
        $result = $this->svc()->request($u, $s, (string) $r->header('Idempotency-Key', ''), $r->validated());
        return $this->respond($u, $s, $result['public_id'], $result['replayed'], $result['replayed'] ? 200 : 201);
    }

    public function send(TransferSendRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return $this->respond($u, $s, $transfer, $this->svc()->send($u, $s, $transfer, $r->validated())['replayed']); }
    public function receive(TransferReceiveRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return $this->respond($u, $s, $transfer, $this->svc()->receive($u, $s, $transfer, $r->validated())['replayed']); }
    public function cancel(TransferReasonRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return $this->respond($u, $s, $transfer, $this->svc()->cancel($u, $s, $transfer, $r->validated())['replayed']); }
    public function reverseSend(TransferReasonRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return $this->respond($u, $s, $transfer, $this->svc()->reverseSend($u, $s, $transfer, $r->validated())['replayed']); }
    public function reconcile(TransferReconcileRequest $r, string $transfer): JsonResponse { [$u, $s] = $this->ids($r); return $this->respond($u, $s, $transfer, $this->svc()->reconcile($u, $s, $transfer, $r->validated())['replayed']); }

    private function respond(int $u, int $s, string $publicId, bool $replayed, int $status = 200): JsonResponse
    {
        $detail = $this->factory->make(FinanceQueryService::class)->transfer($u, $s, $publicId);
        FinanceOutput::assertSafe($detail);
        return response()->json(['data' => $detail, 'meta' => ['replayed' => $replayed]], $status, ['Cache-Control' => 'no-store, private']);
    }

    private function svc(): InternalTransferService { return $this->factory->make(InternalTransferService::class); }
    private function ids(Request $r): array { return [(int) $r->user()->getAuthIdentifier(), (int) $r->attributes->get('auth_session_id')]; }
}
