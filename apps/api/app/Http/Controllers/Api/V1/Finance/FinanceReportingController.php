<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Finance\FinanceReportingService;
use App\Http\Controllers\Controller;
use App\Http\Finance\FinanceOutput;
use App\Http\Finance\FinanceServiceFactory;
use App\Http\Requests\Finance\EmptyFinanceRequest;
use App\Http\Requests\Finance\ReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FinanceReportingController extends Controller
{
    public function __construct(private FinanceServiceFactory $factory)
    {
    }

    public function index(EmptyFinanceRequest $request): JsonResponse
    {
        [$user, $session] = $this->ids($request);
        return FinanceOutput::item($this->service()->catalog($user, $session));
    }

    public function show(ReportRequest $request, string $report): JsonResponse
    {
        [$user, $session] = $this->ids($request);
        return FinanceOutput::item($this->service()->report($user, $session, $report, $request->validated()));
    }

    public function dashboard(ReportRequest $request): JsonResponse
    {
        [$user, $session] = $this->ids($request);
        return FinanceOutput::item($this->service()->dashboard($user, $session, $request->validated()));
    }

    public function export(ReportRequest $request, string $report): Response
    {
        [$user, $session] = $this->ids($request);
        $data = $this->service()->report($user, $session, $report, $request->validated(), 'finance.export');
        FinanceOutput::assertSafe($data);
        $rows = [['Campo', 'Valor']];
        $this->flatten('', $data, $rows);
        $stream = fopen('php://temp', 'r+');
        foreach ($rows as $row) { fputcsv($stream, $row, ';'); }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $name = strtolower(preg_replace('/[^A-Z0-9_-]+/i', '-', $report)) . '-' . $data['period']['from'] . '-' . $data['period']['to'] . '.csv';
        return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'no-store, private']);
    }

    private function flatten(string $prefix, mixed $value, array &$rows): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) { $this->flatten($prefix === '' ? (string) $key : $prefix . '.' . $key, $child, $rows); }
            return;
        }
        $rows[] = [$prefix, is_bool($value) ? ($value ? 'sim' : 'não') : ($value === null ? '' : (string) $value)];
    }

    private function service(): FinanceReportingService { return $this->factory->make(FinanceReportingService::class); }
    private function ids(Request $request): array { return [(int) $request->user()->getAuthIdentifier(), (int) $request->attributes->get('auth_session_id')]; }
}
