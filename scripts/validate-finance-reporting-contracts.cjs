const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8').replace(/\r\n/g, '\n')
const service = read('apps/api/app/Domain/Finance/FinanceReportingService.php')
const perimeter = read('apps/api/app/Domain/Finance/ReportPerimeterResolver.php')
const runtime = read('apps/api/app/Domain/Finance/FinanceRuntime.php')
const routes = read('apps/api/routes/api.php')
const controller = read('apps/api/app/Http/Controllers/Api/V1/Finance/FinanceReportingController.php')
const page = read('apps/web/src/pages/FinanceReportingPages.tsx')
const shell = read('apps/web/src/layout/AppShell.tsx')
const test = read('apps/api/tests/DatabaseV2/FinanceReportingF1DTest.php')
const contract = JSON.parse(read('docs/contracts/finance_core_contracts.json')).f1d_contracts

const checks = {
  F1D01_sixteen_reports: contract.reports_v1.length === 16 && contract.reports_v1.every((code) => service.includes(`'${code}'`)),
  F1D02_historical_perimeter: /unit_parent_periods/.test(perimeter) && /starts_at <= \?/.test(perimeter) && /ends_at IS NULL OR p\.ends_at > \?/.test(perimeter) && !/organizational_units ou JOIN s ON ou\.parent_id/.test(perimeter),
  F1D03_consistent_snapshot_c8: /SET TRANSACTION ISOLATION LEVEL REPEATABLE READ/.test(runtime) && /->snapshot\(/.test(service),
  F1D04_posted_ledger_only: /e\.status.*FinanceCatalog::POSTED/.test(service) && !/materialized|stored_balance|dashboard_cache/i.test(service),
  F1D05_own_consolidated_permissions: /PERMISSION_REPORT/.test(service) && /PERMISSION_CONSOLIDATED_VIEW/.test(service) && /PERMISSION_VIEW/.test(service),
  F1D06_dre_doaf_separate: /ACCRUAL_POSTED_LEDGER/.test(service) && /FUND_CUSTODY_POSTED_LEDGER/.test(service),
  F1D07_internal_transfer_elimination: /boundaryTransfer/.test(service) && /internal_transfers_eliminated/.test(service) && /INTERUNIT_CONTROL/.test(service),
  F1D08_periods: ['MONTH', 'QUARTER', 'SEMESTER', 'YEAR'].every((p) => service.includes(`'${p}'`)),
  F1D09_bounded_routes_and_public_ids: ['dashboard', 'reports', 'reports/{report}', 'reports/{report}/export'].every((r) => routes.includes(`'${r}'`)) && /FinanceOutput::assertSafe/.test(controller) && /limit\(100\)/.test(service),
  F1D10_export_same_model_and_audit: /service\(\)->report\(/.test(controller) && /finance\.export/.test(controller) && /parameters_hash/.test(service),
  F1D11_final_ui_and_contributions: ['Visão Geral', 'Contribuições', 'Relatórios'].every((label) => shell.includes(`'${label}'`)) && /PRÓPRIO/.test(page) && /CONSOLIDADO/.test(page) && /Sem impacto monetário/.test(page),
  F1D12_acceptance_tests: ['test_r01_r05_r21_r24', 'test_r06_r15', 'test_r25', 'test_r26_r27'].every((name) => test.includes(name)),
}
const failures = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name)
const result = { phase: 'P0.10-F1D', status: failures.length ? 'FAIL' : 'PASS', checks, failures }
const args = process.argv.slice(2); const oi = args.indexOf('--output')
if (oi >= 0 && args[oi + 1]) fs.writeFileSync(path.resolve(root, args[oi + 1]), JSON.stringify(result, null, 2) + '\n')
console.log(JSON.stringify(result, null, 2))
process.exitCode = failures.length ? 1 : 0
