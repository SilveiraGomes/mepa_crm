<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Domain\Payroll\CompensationService;
use App\Domain\Payroll\EmploymentService;
use App\Domain\Payroll\PayrollQueryService;
use App\Domain\Payroll\PayrollReadinessService;
use App\Domain\Payroll\PayrollRuleService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceOutput;
use App\Http\Payroll\PayrollServiceFactory;
use App\Http\Requests\Payroll\CompensationChangeRequest;
use App\Http\Requests\Payroll\CompensationStopRequest;
use App\Http\Requests\Payroll\EmploymentEndRequest;
use App\Http\Requests\Payroll\EmploymentStoreRequest;
use App\Http\Requests\Payroll\HrQueryRequest;
use App\Http\Requests\Payroll\RuleApproveRequest;
use App\Http\Requests\Payroll\RuleDraftRequest;
use App\Http\Requests\Payroll\RuleRetireRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0.10-F2A RH / payroll foundation API (ADR 0021 D23-D28 + D-04A.14/15). Employment, compensation, components, statutory
 * rules, readiness and the production status. There is deliberately NO route to calculate, approve, post or pay a payroll
 * run in F2A. Responses never carry internal ids (FinanceOutput::assertSafe) and are never cached.
 */
final class HrController extends Controller
{
    public function __construct(private PayrollServiceFactory $factory)
    {
    }

    public function context(Request $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->context(...$this->ids($request)));
    }

    public function status(Request $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->status(...$this->ids($request)));
    }

    public function employments(HrQueryRequest $request): JsonResponse
    {
        $page = $this->factory->make(PayrollQueryService::class)->employments(...[...$this->ids($request), $request->validated()]);
        FinanceOutput::assertSafe($page);
        return response()->json($page, 200, ['Cache-Control' => 'no-store, private']);
    }

    public function storeEmployment(EmploymentStoreRequest $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(EmploymentService::class)->create(...[...$this->ids($request), $request->validated()]), 201);
    }

    public function employment(Request $request, string $employment): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->employment(...[...$this->ids($request), $employment]));
    }

    public function endEmployment(EmploymentEndRequest $request, string $employment): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(EmploymentService::class)->end(...[...$this->ids($request), $employment, $request->validated()]));
    }

    public function compensation(HrQueryRequest $request, string $employment): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->compensation(...[...$this->ids($request), $employment, $request->validated()]));
    }

    public function changeCompensation(CompensationChangeRequest $request, string $employment): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(CompensationService::class)->change(...[...$this->ids($request), $employment, $request->validated()]), 201);
    }

    public function stopCompensation(CompensationStopRequest $request, string $employment): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(CompensationService::class)->stop(...[...$this->ids($request), $employment, $request->validated()]));
    }

    public function compensationOverview(HrQueryRequest $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->compensationOverview(...[...$this->ids($request), $request->validated()]));
    }

    public function components(Request $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->components(...$this->ids($request)));
    }

    public function rules(HrQueryRequest $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->rules(...[...$this->ids($request), $request->validated()]));
    }

    public function rule(Request $request, string $code, string $version): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollQueryService::class)->rule(...[...$this->ids($request), $code, $this->version($version)]));
    }

    public function draftRule(RuleDraftRequest $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollRuleService::class)->draft(...[...$this->ids($request), $request->validated()]), 201);
    }

    public function approveRule(RuleApproveRequest $request, string $code, string $version): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollRuleService::class)->approve(...[...$this->ids($request), $code, $this->version($version), $request->validated()]));
    }

    public function retireRule(RuleRetireRequest $request, string $code, string $version): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollRuleService::class)->retire(...[...$this->ids($request), $code, $this->version($version), $request->validated()]));
    }

    public function readiness(HrQueryRequest $request): JsonResponse
    {
        return FinanceOutput::item($this->factory->make(PayrollReadinessService::class)->readiness(...[...$this->ids($request), $request->validated()]));
    }

    /** @return array{0: int, 1: int} */
    private function ids(Request $request): array
    {
        return [(int) $request->user()->getAuthIdentifier(), (int) $request->attributes->get('auth_session_id')];
    }

    private function version(string $version): int
    {
        return preg_match('/^[1-9]\d{0,5}$/D', $version) === 1 ? (int) $version : 0;
    }
}
