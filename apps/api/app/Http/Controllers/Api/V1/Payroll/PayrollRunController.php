<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Domain\Payroll\PayrollRunQueryService;
use App\Domain\Payroll\PayrollRunService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceOutput;
use App\Http\Payroll\PayrollServiceFactory;
use App\Http\Requests\Payroll\PayrollRunApproveRequest;
use App\Http\Requests\Payroll\PayrollRunCalculateRequest;
use App\Http\Requests\Payroll\PayrollRunCancelRequest;
use App\Http\Requests\Payroll\PayrollRunCreateRequest;
use App\Http\Requests\Payroll\PayrollRunPayRequest;
use App\Http\Requests\Payroll\PayrollRunPostRequest;
use App\Http\Requests\Payroll\PayrollRunQueryRequest;
use App\Http\Requests\Payroll\PayrollRunReverseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * P0.10-F2B payroll run API (ADR 0021 D26-D29 + D-04A.14/15). One route per explicit transition (no generic status
 * write, no delete); run public ids only; never cached; responses never carry internal ids (FinanceOutput::assertSafe).
 * Approve / post / pay / reverse are refused with 409 PAYROLL_PRODUCTION_DISABLED while payroll.production_enabled is false.
 */
final class PayrollRunController extends Controller
{
    public function __construct(private PayrollServiceFactory $factory)
    {
    }

    public function index(PayrollRunQueryRequest $request): JsonResponse
    {
        $page = $this->query()->runs(...[...$this->ids($request), $request->validated()]);
        FinanceOutput::assertSafe($page);
        return response()->json($page, 200, ['Cache-Control' => 'no-store, private']);
    }

    public function store(PayrollRunCreateRequest $request): JsonResponse
    {
        $result = $this->runs()->create(...[...$this->ids($request), (string) $request->header('Idempotency-Key', ''), $request->validated()]);
        return FinanceOutput::item($result, $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->query()->run(...[...$this->ids($request), $run]));
    }

    public function employees(Request $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->query()->employees(...[...$this->ids($request), $run]));
    }

    public function summary(Request $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->query()->summary(...[...$this->ids($request), $run]));
    }

    public function summaryCsv(Request $request, string $run): Response
    {
        $summary = $this->query()->summary(...[...$this->ids($request), $run, true]);
        return response(PayrollRunQueryService::csv($summary), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="folha-' . $summary['period'] . '-' . $summary['run'] . '.csv"']);
    }

    public function calculate(PayrollRunCalculateRequest $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->runs()->calculate(...[...$this->ids($request), $run, $request->validated()]));
    }

    public function approve(PayrollRunApproveRequest $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->runs()->approve(...[...$this->ids($request), $run, $request->validated()]));
    }

    public function post(PayrollRunPostRequest $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->runs()->post(...[...$this->ids($request), $run, $request->validated()]));
    }

    public function pay(PayrollRunPayRequest $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->runs()->pay(...[...$this->ids($request), $run, $request->validated()]));
    }

    public function reverse(PayrollRunReverseRequest $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->runs()->reverse(...[...$this->ids($request), $run, $request->validated()]));
    }

    public function cancel(PayrollRunCancelRequest $request, string $run): JsonResponse
    {
        return FinanceOutput::item($this->runs()->cancel(...[...$this->ids($request), $run, $request->validated()]));
    }

    private function runs(): PayrollRunService
    {
        return $this->factory->make(PayrollRunService::class);
    }

    private function query(): PayrollRunQueryService
    {
        return $this->factory->make(PayrollRunQueryService::class);
    }

    /** @return array{0: int, 1: int} */
    private function ids(Request $request): array
    {
        return [(int) $request->user()->getAuthIdentifier(), (int) $request->attributes->get('auth_session_id')];
    }
}
