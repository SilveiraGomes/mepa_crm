<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\FinanceHttpCase;

/** Focused F1D acceptance: reports are ledger-derived, historically scoped, concealed and internally eliminated. */
final class FinanceReportingF1DTest extends FinanceHttpCase
{
    public function test_r01_r05_r21_r24_own_dre_uses_only_posted_accrual_economic_lines(): void
    {
        $w = $this->reportingWorld();
        $actor = $this->treasurer($w['a1']);
        $this->contribute($actor, $w['cash_a1'], '1000.00', '2026-08-02');

        $report = $this->getReport($actor, 'OWN_DRE', $w['a1'], 'period_kind=YEAR&period=2026');
        $this->assertSame(['1000.00', '0.00', '1000.00', '0.00'], [$report['dre']['revenue'], $report['dre']['expenses'], $report['dre']['economic_result'], $report['dre']['internal_transfer_effect']]);
        $this->assertSame(['YEAR', '2026-01-01', now('Africa/Luanda')->format('Y-m-d')], [$report['period']['kind'], $report['period']['from'], $report['period']['to']]);
        $this->assertSame('REPEATABLE_READ', $report['snapshot']);
        $this->assertSame(64, strlen($report['parameters_hash']));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'finance.report.generated')->count());

        $catalog = $this->api($actor, 'GET', 'finance/reports')->assertOk()->json('data.reports');
        $this->assertCount(16, $catalog);
        $this->assertSame(16, count(array_unique(array_column($catalog, 'code'))));
    }

    public function test_r06_r15_consolidated_dre_and_doaf_eliminate_the_1000_600_400_chain(): void
    {
        $w = $this->reportingWorld();
        $leaf = $this->treasurer($w['a1']);
        $center = $this->treasurer($w['a']);
        $municipal = $this->treasurer($w['m'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $this->contribute($leaf, $w['cash_a1'], '1000.00', '2026-08-02');
        $first = $this->requestTransfer($leaf, $w['cash_a1'], $w['a'], '600.00');
        $this->sendTransfer($leaf, $first['public_id'], '2026-08-03');
        $this->receiveTransfer($center, $first['public_id'], $w['cash_a'], '2026-08-03');
        $second = $this->requestTransfer($center, $w['cash_a'], $w['m'], '400.00');
        $this->sendTransfer($center, $second['public_id'], '2026-08-04');
        $this->receiveTransfer($municipal, $second['public_id'], $w['cash_m'], '2026-08-04');

        $q = 'from=2026-08-01&to=2026-08-31&view=CONSOLIDATED';
        $dre = $this->getReport($municipal, 'CONSOLIDATED_DRE', $w['m'], $q);
        $this->assertSame(['1000.00', '0.00', '1000.00'], [$dre['dre']['revenue'], $dre['dre']['expenses'], $dre['dre']['economic_result']]);
        $doaf = $this->getReport($municipal, 'CONSOLIDATED_DOAF', $w['m'], $q)['doaf'];
        $this->assertSame(['1000.00', '0.00', '0.00', '1000.00', '1000.00'], [$doaf['external_funds_received'], $doaf['internal_funds_received'], $doaf['internal_funds_sent'], $doaf['total_origins'], $doaf['total_applications']]);
        $this->assertTrue($doaf['balanced']);
        $this->assertTrue($doaf['internal_transfers_eliminated']);
    }

    public function test_r25_historical_perimeter_is_resolved_at_report_end_not_from_current_parent(): void
    {
        $w = $this->reportingWorld();
        $oldParentActor = $this->treasurer($w['a'], ['FINANCE_CONSOLIDATED_VIEW'], true);
        $leaf = $this->treasurer($w['a1']);
        $this->contribute($leaf, $w['cash_a1'], '250.00', '2026-08-10');
        DB::table('unit_parent_periods')->where('unit_id', $w['a1']['id'])->update(['status' => 'ENDED', 'ends_at' => '2026-09-01 00:00:00.000000']);
        DB::table('unit_parent_periods')->insert(['unit_id' => $w['a1']['id'], 'parent_unit_id' => $w['b']['id'], 'status' => 'ACTIVE', 'starts_at' => '2026-09-01 00:00:00.000000', 'ends_at' => null, 'reason' => 'Teste histórico', 'source_document_id' => null, 'created_at' => '2026-09-01 00:00:00.000000', 'lock_version' => 0]);
        DB::table('organizational_units')->where('id', $w['a1']['id'])->update(['parent_id' => $w['b']['id']]);

        $old = $this->getReport($oldParentActor, 'CONSOLIDATED_DRE', $w['a'], 'from=2026-08-01&to=2026-08-31&view=CONSOLIDATED');
        $this->assertSame('250.00', $old['dre']['revenue']);
        $this->assertSame(3, $old['perimeter_units']);
    }

    public function test_r26_r27_consolidated_permission_and_scope_are_concealed(): void
    {
        $w = $this->reportingWorld();
        $ownOnly = $this->treasurer($w['m']);
        $this->api($ownOnly, 'GET', 'finance/reports/CONSOLIDATED_DRE?unit=' . $w['m']['public_id'] . '&period_kind=YEAR&period=2026')->assertStatus(403);
        $other = $this->treasurer($w['b1'], ['FINANCE_CONSOLIDATED_VIEW']);
        $this->assertConcealed($this->api($other, 'GET', 'finance/reports/CONSOLIDATED_DRE?unit=' . $w['m']['public_id'] . '&period_kind=YEAR&period=2026'));
        $this->assertConcealed($this->api($other, 'GET', 'finance/reports/CONSOLIDATED_DRE?unit=00000000000000000000000000&period_kind=YEAR&period=2026'));
    }

    private function getReport(array $actor, string $type, array $unit, string $query): array
    {
        return $this->api($actor, 'GET', 'finance/reports/' . $type . '?unit=' . $unit['public_id'] . '&' . $query)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function reportingWorld(): array
    {
        $w = $this->world();
        $parents = ['r' => 'g', 'p' => 'r', 'm' => 'p', 'a' => 'm', 'b' => 'm', 'a1' => 'a', 'a2' => 'a', 'b1' => 'b'];
        foreach ($parents as $child => $parent) {
            DB::table('unit_parent_periods')->insert(['unit_id' => $w[$child]['id'], 'parent_unit_id' => $w[$parent]['id'], 'status' => 'ACTIVE', 'starts_at' => '2025-01-01 00:00:00.000000', 'ends_at' => null,
                'reason' => 'F1D fixture', 'source_document_id' => null, 'created_at' => '2025-01-01 00:00:00.000000', 'lock_version' => 0]);
        }
        return $w;
    }
}
