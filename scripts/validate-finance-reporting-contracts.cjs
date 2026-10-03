// P0.10-F1D Finance reporting contract validator (ADR 0021 D15/D16/D17/D20/D21/D30 + D-04A):
//  - structural checks F1D01-F1D15 over the reporting service, perimeter resolver, runtime, routes, controller, UI and tests;
//  - M1..M18 negative probes (P0.10-F1D-E1): each mutates the real source (reporting service, perimeter resolver or
//    reporting controller), runs the targeted HTTP evidence tests of FinanceReportingEvidenceTest against the isolated
//    pool and EXPECTS them to fail, then restores the file byte-for-byte (SHA-256). A CONTROL run (no mutation) of every
//    detector must pass first.
// Env for probes: WAVE5_DSN / WAVE5_USER / WAVE5_PASSWORD / WAVE5_ALLOW_SYNTHETIC=1 (isolated mepa_wave5_test_* pool).
// Flags: --structural-only, --only M3,M9, --output <file>
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const PHP = process.env.MEPA_PHP_BIN || 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const D = 'apps/api/app/Domain/Finance/'
const paths = {
  service: D + 'FinanceReportingService.php', perimeter: D + 'ReportPerimeterResolver.php', runtime: D + 'FinanceRuntime.php', routes: 'apps/api/routes/api.php',
  controller: 'apps/api/app/Http/Controllers/Api/V1/Finance/FinanceReportingController.php', page: 'apps/web/src/pages/FinanceReportingPages.tsx', shell: 'apps/web/src/layout/AppShell.tsx',
  test: 'apps/api/tests/DatabaseV2/FinanceReportingF1DTest.php', evidence: 'apps/api/tests/DatabaseV2/FinanceReportingEvidenceTest.php', worker: 'scripts/p010-f1d-report-worker.php',
  e2e: 'apps/web/tests/e2e/finance-reporting.spec.ts', contracts: 'docs/contracts/finance_core_contracts.json',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8').replace(/\r\n/g, '\n')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')

function methodBody(text, name) {
  const start = text.indexOf(`function ${name}(`)
  if (start < 0) return ''
  const rest = text.slice(start + 10)
  const next = rest.search(/\n    (public|private|protected) (static )?function /)
  return text.slice(start, next < 0 ? undefined : start + 10 + next)
}

const EVIDENCE_TESTS = ['test_r01_', 'test_r02_', 'test_r03_', 'test_r04_', 'test_r05_', 'test_r06_', 'test_r07_', 'test_r08_', 'test_r09_', 'test_r10_', 'test_r11_', 'test_r12_', 'test_r13_', 'test_r14_',
  'test_r15_', 'test_r16_', 'test_r17_', 'test_r18_', 'test_r19_', 'test_r20_', 'test_r21_r24_', 'test_r25_', 'test_r26_', 'test_r27_', 'test_r28_', 'test_r29_', 'test_r30_', 'test_r31_', 'test_r32_', 'test_r33_',
  'test_r34_c8_', 'test_accounting_cross_check_']

function checks(f) {
  const contract = JSON.parse(f.contracts).f1d_contracts
  const report = methodBody(f.service, 'report')
  return {
    F1D01_sixteen_reports: contract.reports_v1.length === 16 && contract.reports_v1.every((code) => f.service.includes(`'${code}'`)),
    F1D02_historical_perimeter: /unit_parent_periods/.test(f.perimeter) && /starts_at <= \?/.test(f.perimeter) && /ends_at IS NULL OR p\.ends_at > \?/.test(f.perimeter) && !/organizational_units o ON o\.parent_id/.test(f.perimeter),
    F1D03_consistent_snapshot_c8: /SET TRANSACTION ISOLATION LEVEL REPEATABLE READ/.test(f.runtime) && /->snapshot\(/.test(f.service),
    F1D04_posted_ledger_only: /e\.status.*FinanceCatalog::POSTED/.test(f.service) && !/materialized|stored_balance|dashboard_cache/i.test(f.service),
    F1D05_own_consolidated_permissions: /PERMISSION_REPORT/.test(f.service) && /PERMISSION_CONSOLIDATED_VIEW/.test(f.service) && /PERMISSION_VIEW/.test(f.service),
    F1D06_dre_doaf_separate: /ACCRUAL_POSTED_LEDGER/.test(f.service) && /FUND_CUSTODY_POSTED_LEDGER/.test(f.service),
    F1D07_internal_transfer_elimination: /boundaryTransfer/.test(f.service) && /internal_transfers_eliminated/.test(f.service) && /INTERUNIT_CONTROL/.test(f.service),
    F1D08_periods: ['MONTH', 'QUARTER', 'SEMESTER', 'YEAR'].every((p) => f.service.includes(`'${p}'`)),
    F1D09_bounded_routes_and_public_ids: ['dashboard', 'reports', 'reports/{report}', 'reports/{report}/export'].every((r) => f.routes.includes(`'${r}'`)) && /FinanceOutput::assertSafe/.test(f.controller) && /limit\(100\)/.test(f.service),
    F1D10_export_same_model_and_audit: /service\(\)->report\(/.test(f.controller) && /finance\.export/.test(f.controller) && /parameters_hash/.test(f.service),
    F1D11_final_ui_and_contributions: ['Visão Geral', 'Contribuições', 'Relatórios'].every((label) => f.shell.includes(`'${label}'`)) && /PRÓPRIO/.test(f.page) && /CONSOLIDADO/.test(f.page) && /Sem impacto monetário/.test(f.page),
    F1D12_acceptance_tests: ['test_r01_r05_r21_r24', 'test_r06_r15', 'test_r25', 'test_r26_r27'].every((name) => f.test.includes(name)),
    // E1: F-06 permission before target (F1D-P01) and open items as of the report end (F1D-P02).
    F1D13_permission_before_target: report.indexOf('requires(FinanceCatalog::PERMISSION_REPORT)') > 0 && report.indexOf('requires(FinanceCatalog::PERMISSION_REPORT)') < report.indexOf('$this->unit('),
    F1D14_open_items_as_of_end: /recognition_entry_id/.test(methodBody(f.service, 'openItems')) && /entry_date <= \?/.test(methodBody(f.service, 'settledAsOf')) && !/due_on', '<='/.test(f.service),
    // E1: the report type names its view (F1D-P04); consolidated types hidden without the permission (F1D-P05); every
    // report type has a rendered body with visible totals (F1D-P06).
    F1D16_view_named_by_type_and_rendered_bodies: f.service.includes("str_starts_with($type, 'OWN_') ? 'OWN'") && /lockedView/.test(f.page) && /consolidated \|\| !value\.startsWith\('CONSOLIDATED_'\)/.test(f.page)
      && ['r.summary', 'r.transfers', 'r.reconciliation', 'r.position', 'r.movements', 'r.treasury', 'r.budget_vs_actual', 'r.receivables ?? r.payables', 'r.dashboard', 'r.dre', 'r.doaf'].every((body) => f.page.includes(body)),
    F1D15_evidence_and_c8_worker: EVIDENCE_TESTS.every((name) => f.evidence.includes(`public function ${name}`)) && /pause_after_ledger_read/.test(f.worker) && /hold_until_commit/.test(f.worker)
      && /mepa_wave5_test_/.test(f.worker) && /FinanceE2EFixtureTest::reportingWorld/.test(f.e2e) && /exportAndCompare/.test(f.e2e),
  }
}

const args = process.argv.slice(2)
const outputIndex = args.indexOf('--output')
const output = outputIndex >= 0 ? args[outputIndex + 1] : null
const onlyIndex = args.indexOf('--only')
const only = onlyIndex >= 0 ? new Set(args[onlyIndex + 1].split(',')) : null
const structuralOnly = args.includes('--structural-only')
const originals = Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key))]))
const hashesBefore = Object.fromEntries(Object.entries(originals).map(([key, buffer]) => [key, digest(buffer)]))
const base = checks(read())
const failures = Object.entries(base).filter(([, ok]) => !ok).map(([name]) => name)

function mutate(key, fn) {
  // Anchors are written with \n: normalise CRLF working copies first (the byte-for-byte restore uses the originals).
  const source = originals[key].toString('utf8').replace(/\r\n/g, '\n')
  const changed = fn(source)
  if (changed === source) throw new Error(`mutation of ${key} did not alter its target`)
  fs.writeFileSync(abs(key), changed)
}
const sub = (from, to) => (s) => { if (!s.includes(from)) throw new Error('mutation anchor not found: ' + from.slice(0, 80)); return s.split(from).join(to) }
const inMethod = (name, from, to) => (s) => { const body = methodBody(s, name); if (!body.includes(from)) throw new Error(`mutation anchor not found in ${name}: ` + from.slice(0, 80)); return s.replace(body, body.split(from).join(to)) }
function phpunit(filter) {
  const started = Date.now()
  const r = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', '--filter', filter, 'tests/DatabaseV2/FinanceReportingEvidenceTest.php'], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 1200000 })
  const summary = (r.stdout || '').split(/\r?\n/).filter((l) => /^(OK \(|Tests:|FAILURES|ERRORS)/.test(l)).pop() || ''
  return { detector: `phpunit --filter ${filter}`, exit_code: r.status, summary, seconds: Math.round((Date.now() - started) / 100) / 10 }
}

const ZERO = '$revenue = $expense = $investments = $returns = 0;'
const RESULT_KINDS = "->whereIn('a.account_kind', [FinanceCatalog::INCOME, FinanceCatalog::EXPENSE])"
const cases = [
  ['M1', 'perimeter resolved from the CURRENT parent_id instead of unit_parent_periods', [['perimeter', sub('  FROM subtree s JOIN parentage p ON p.parent_unit_id = s.id\n  JOIN organizational_units o ON o.id = p.unit_id\n',
    '  FROM subtree s JOIN organizational_units o ON o.parent_id = s.id\n')]], 'test_r25_'],
  ['M2', 'internal RECEIVE counted as revenue in the DRE', [['service', inMethod('dre', ZERO, "$revenue = $this->sum($this->posted($units, $from, $to)->where('e.entry_kind', 'TRANSFER_RECEIVE'), 'l.debit'); $expense = $investments = $returns = 0;")]], 'test_r09_'],
  ['M3', 'internal SEND counted as expense in the DRE', [['service', inMethod('dre', ZERO, "$expense = $this->sum($this->posted($units, $from, $to)->where('e.entry_kind', 'TRANSFER_SEND'), 'l.credit'); $revenue = $investments = $returns = 0;")]], 'test_r09_'],
  ['M4', 'consolidated DRE adds the interunit clearing (internal receipts) to revenue', [['service', sub("'OWN_DRE', 'CONSOLIDATED_DRE' => ['dre' => $this->dre($units, $from, $to)],",
    "'OWN_DRE' => ['dre' => $this->dre($units, $from, $to)],\n            'CONSOLIDATED_DRE' => ['dre' => ['revenue' => Money::format(Money::fromDecimal($this->dre($units, $from, $to)['revenue']) + $this->sum($this->posted($units, $from, $to)->where('e.entry_kind', 'TRANSFER_RECEIVE'), 'l.debit'))] + $this->dre($units, $from, $to)],")]], 'test_r10_'],
  ['M5', 'consolidated DOAF without elimination of internal transfers', [['service', inMethod('doaf', "$received = $this->boundaryTransfer($units, $from, $to, 'RECEIVE', false);\n            $sent = $this->boundaryTransfer($units, $from, $to, 'SEND', true) - $this->boundaryTransfer($units, $from, $to, 'REVERSE_SEND', true);",
    "$received = $this->sum($base()->where('e.entry_kind', 'TRANSFER_RECEIVE'), 'l.debit');\n            $sent = $this->sum($base()->where('e.entry_kind', 'TRANSFER_SEND'), 'l.credit');")]], 'test_r11_'],
  ['M6', 'accrual recognitions excluded from the DRE (cash basis)', [['service', inMethod('dre', RESULT_KINDS, RESULT_KINDS + "->whereNotIn('e.entry_kind', ['RECEIVABLE_RECOGNITION', 'PAYABLE_RECOGNITION'])")]], 'test_r02_|test_r03_'],
  ['M7', 'settlements added to the DRE (double counting)', [['service', inMethod('dre', ZERO, "$revenue = $this->sum($this->posted($units, $from, $to)->where('e.entry_kind', 'SETTLEMENT'), 'l.debit'); $expense = $investments = $returns = 0;")]], 'test_r04_'],
  ['M8', 'FIXED_ASSETS acquisition treated as expense', [['service', inMethod('dre', RESULT_KINDS, "->where(fn ($x) => $x->whereIn('a.account_kind', [FinanceCatalog::INCOME, FinanceCatalog::EXPENSE])->orWhere('a.system_role', 'FIXED_ASSETS'))")]], 'test_r05_'],
  ['M9', 'POSTED filter removed (DRAFT lines reported)', [['service', inMethod('posted', "->where('e.status', FinanceCatalog::POSTED)", '')]], 'test_r01_'],
  ['M10', 'SUPERSEDED budget versions included', [['service', inMethod('budget', "->where('b.status', 'APPROVED')", "->whereIn('b.status', ['APPROVED', 'SUPERSEDED'])")]], 'test_r18_'],
  ['M11', 'consolidated subtree coverage guard removed', [['service', sub("                if (array_diff($current, array_keys($covered)) !== []) {\n                    throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);\n                }\n", '')]], 'test_r26_'],
  ['M12', 'F-06: report permission no longer decided before the target', [['service', sub('            $guard->requires(FinanceCatalog::PERMISSION_REPORT);\n            // F1D-P04', '            // F1D-P04')]], 'test_r27_'],
  ['M13', 'report body kept in a process-wide cache (stale figures after a new posting)', [['service', (s) => sub("    private const TRANSFER_KINDS = ['TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND'];\n",
    "    private const TRANSFER_KINDS = ['TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND'];\n    private static array $bodyCache = [];\n")(sub('            $body = $this->body($type, $units, $unit, $from, $to, $view, $actor, $in);',
    "            $body = self::$bodyCache[$type . '|' . $view . '|' . $unit . '|' . $from . '|' . $to] ??= $this->body($type, $units, $unit, $from, $to, $view, $actor, $in);")(s))]], 'test_r16_'],
  ['M14', 'report outside the REPEATABLE READ snapshot transaction', [['service', sub('return $this->rt->snapshot($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($type, $in, $auditAction): array {',
    'return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($type, $in, $auditAction): array {')]], 'test_r34_c8_'],
  ['M15', 'export alters a value', [['controller', sub("($value === null ? '' : (string) $value)", "($value === null ? '' : str_replace('.00', '', (string) $value))")]], 'test_r30_'],
  ['M16', 'IN_KIND_ASSETS treated as treasury in the DOAF', [['service', sub("->whereIn('a.system_role', FinanceCatalog::TREASURY_ROLES)", "->whereIn('a.system_role', [...FinanceCatalog::TREASURY_ROLES, 'IN_KIND_ASSETS'])")]], 'test_r32_'],
  ['M17', 'funds in transit added to revenue', [['service', inMethod('summary', "'revenue' => $dre['revenue'],", "'revenue' => Money::format(Money::fromDecimal($dre['revenue']) + Money::fromDecimal($doaf['funds_in_transit_under_custody'])),")]], 'test_r14_'],
  ['M18', 'payroll field leaks into the Finance dashboard', [['service', inMethod('summary', "'budget_execution' => $budget['totals']]", "'budget_execution' => $budget['totals'], 'payroll_cost' => '0.00']")]], 'test_r29_'],
]

const probes = []
if (!structuralOnly) {
  const control = phpunit(cases.filter(([id]) => !only || only.has(id)).flatMap(([, , , f]) => f.split('|')).filter((v, i, a) => a.indexOf(v) === i).join('|'))
  probes.push({ id: 'CONTROL', status: control.exit_code === 0 ? 'PASS' : 'FAIL', mode: 'NO_MUTATION_EXPECTED_PASS', ...control })
  for (const [id, description, mutations, filter] of cases) {
    if (only && !only.has(id)) continue
    try {
      for (const [key, fn] of mutations) mutate(key, fn)
      const run = phpunit(filter)
      probes.push({ id, description, status: run.exit_code !== 0 ? 'PASS' : 'FAIL', mode: 'ON_DISK_MUTATION_EXPECTED_TEST_FAILURE', files: mutations.map(([key]) => paths[key]), detected: run.exit_code !== 0, ...run })
    } catch (error) {
      probes.push({ id, description, status: 'FAIL', error: String(error.message || error) })
    } finally {
      for (const [key] of mutations) fs.writeFileSync(abs(key), originals[key])
    }
  }
}
const hashesAfter = Object.fromEntries(Object.keys(paths).map((key) => [key, digest(fs.readFileSync(abs(key)))]))
const restoredBytes = Object.keys(paths).every((key) => hashesBefore[key] === hashesAfter[key])
const result = {
  phase: 'P0.10-F1D',
  status: failures.length === 0 && restoredBytes && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base, failures, mutation_probes: probes,
  probes_detected: probes.filter((p) => p.status === 'PASS' && p.id !== 'CONTROL').length, probes_run: probes.filter((p) => p.id !== 'CONTROL').length,
  source_hashes_before: hashesBefore, source_hashes_after: hashesAfter, source_restored_byte_for_byte: restoredBytes,
}
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(result.status === 'PASS' ? 0 : 1)
