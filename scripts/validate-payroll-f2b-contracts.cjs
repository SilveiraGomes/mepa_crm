// P0.10-F2B payroll engine / approval / Finance posting / payment contract validator (ADR 0021 D24-D30 + D-04A.13-15):
//  - structural checks P2B01-P2B20 over the payroll services, Finance boundary, routes, migrations, UI, tests and contract;
//  - M1..M20 negative probes: each mutates the real source (payroll engine, input hash, resolver, run service, production
//    gate, read model, and — for the Finance-side probes M14-M16 — the ledger gate), runs the targeted tests of
//    PayrollProcessingF2BTest against the isolated pool and EXPECTS them to fail, then restores every file byte-for-byte
//    (SHA-256). A CONTROL run (no mutation) of every detector must pass first.
// Env for probes: WAVE5_DSN / WAVE5_USER / WAVE5_PASSWORD / WAVE5_ALLOW_SYNTHETIC=1 (isolated mepa_wave5_test_* pool).
// Flags: --structural-only, --only M3,M9, --output <file>
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const PHP = process.env.MEPA_PHP_BIN || 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const D = 'apps/api/app/Domain/Payroll/'
const paths = {
  runs: D + 'PayrollRunService.php', query: D + 'PayrollRunQueryService.php', calculator: D + 'PayrollCalculator.php', hash: D + 'PayrollInputHash.php', resolver: D + 'PayrollRuleResolver.php',
  production: D + 'PayrollProduction.php', gate: D + 'PayrollFinanceGate.php', catalog: D + 'PayrollCatalog.php', audit: D + 'PayrollAudit.php', config: 'apps/api/config/payroll.php',
  ledger: 'apps/api/app/Domain/Finance/LedgerPostingService.php', factory: 'apps/api/app/Http/Payroll/PayrollServiceFactory.php', controller: 'apps/api/app/Http/Controllers/Api/V1/Payroll/PayrollRunController.php',
  routes: 'apps/api/routes/api.php', handler: 'apps/api/app/Exceptions/Handler.php', pages: 'apps/web/src/pages/PayrollRunPages.tsx', hrPages: 'apps/web/src/pages/HrPages.tsx', shell: 'apps/web/src/layout/AppShell.tsx',
  test: 'apps/api/tests/DatabaseV2/PayrollProcessingF2BTest.php', worker: 'scripts/p010-f2b-worker.php', manifest: 'docs/database/physical/p010_finance_delta_manifest.json',
  contracts: 'docs/contracts/payroll_contracts.json', e2e: 'apps/web/tests/e2e/payroll-f2b.spec.ts',
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
const before = (body, first, later) => { const f = body.indexOf(first); const l = body.indexOf(later); return f > 0 && l > 0 && f < l }
const FINANCE_DOMAIN = path.join(root, 'apps/api/app/Domain/Finance')

function checks(f) {
  const manifest = JSON.parse(f.manifest)
  const contract = JSON.parse(f.contracts)
  const migrations = fs.readdirSync(path.join(root, 'apps/api/database/migrations')).filter((m) => /^\d{4}_\d{2}_\d{2}_\d{6}_/.test(m))
  const testNames = [...f.test.matchAll(/public function (test_\w+)\(/g)].map((m) => m[1])
  const runRoutes = [...f.routes.matchAll(/Route::(\w+)\('(payroll\/runs[^']*)', \[PayrollRunController::class/g)].map((m) => `${m[1].toUpperCase()} ${m[2]}`)
  const financeSources = fs.readdirSync(FINANCE_DOMAIN).map((file) => fs.readFileSync(path.join(FINANCE_DOMAIN, file), 'utf8')).join('\n')
  const approve = methodBody(f.runs, 'approve')
  const pay = methodBody(f.runs, 'pay')
  const post = methodBody(f.runs, 'post')
  const accrual = methodBody(f.runs, 'accrualLines')
  return {
    P2B01_schema_unchanged_35: manifest.materialized_tables_f1a.length === 26 && manifest.materialized_tables_f2a.length === 9 && migrations.every((m) => m.slice(0, 17) <= '2026_10_02_200010'),
    P2B02_explicit_transitions_no_delete: ['create', 'calculate', 'approve', 'post', 'pay', 'reverse', 'cancel'].every((m) => methodBody(f.runs, m) !== '')
      && runRoutes.length === 12 && runRoutes.every((r) => /^(GET|POST) /.test(r)) && (f.runs.match(/->delete\(\)/g) || []).length === 1 && /->delete\(\)/.test(methodBody(f.runs, 'calculate'))
      && /if \(\$r->status === 'CALCULATED'\) \{/.test(methodBody(f.runs, 'calculate')),
    P2B03_production_gate_default_off: /env\('PAYROLL_PRODUCTION_ENABLED', false\)/.test(f.config) && /assertEnabled\('approve'\)/.test(approve) && /assertEnabled\(\$operation\)/.test(methodBody(f.runs, 'ledgerPreamble'))
      && !/assertEnabled/.test(methodBody(f.runs, 'calculate')) && /'operations' => \['calculate' => true/.test(f.production),
    P2B04_stale_detection_without_recalculation: /PAYROLL_INPUT_STALE/.test(approve) && /currentHash\(\$r, true\)/.test(approve) && !/PayrollCalculator|insertLines|payroll_run_lines'\)->(insert|update|delete)/.test(approve),
    P2B05_segregation: /PAYROLL_SEGREGATION_REQUIRED/.test(approve) && before(approve, 'calculated_by', 'PAYROLL_INPUT_STALE'),
    P2B06_ledger_only_through_posting_service: /postSubledgerEntry/.test(post) && /postSubledgerEntry/.test(pay) && !/table\('journal_(entries|lines)'\)->(insert|update|delete)/.test(f.runs),
    P2B07_aggregated_accrual: /groupBy\('t\.nature', 't\.liability_role', 'c\.code', 'c\.ledger_account_id'\)/.test(accrual) && !/employment|person|full_name/.test(accrual) && /'PAYROLL_NET_PAYABLE'/.test(accrual),
    P2B08_idempotent_postings: /recordPosting\(\(int\) \$r->id, self::STAGE_ACCRUAL/.test(post) && /recordPosting\(\(int\) \$r->id, self::STAGE_PAYMENT/.test(pay) && /sharedLock\(\)->first\(\)/.test(methodBody(f.runs, 'posting'))
      && /'replayed' => true/.test(post) && /'replayed' => true/.test(pay),
    P2B09_pay_uses_the_posted_net: /\$r->net_amount/.test(pay) && !/PayrollCalculator|payload\(|currentHash/.test(pay),
    P2B10_statutory_liabilities_untouched_by_pay: /'PAYROLL_NET_PAYABLE', 'debit' => \$net/.test(pay) && !/PAYROLL_WITHHOLDINGS|PAYROLL_EMPLOYER_CHARGES/.test(pay),
    P2B11_fin_d10_not_duplicated: !/INSUFFICIENT_FUNDS|lockedBalance|SUM\(l\.debit\)/.test(pay.replace(/\/\/.*$/gm, '')) && /assertNoOverdraft/.test(f.ledger),
    P2B12_pure_decimal_calculator: !/->table\(|DB::|\(float\)|floatval|round\(/.test(f.calculator) && /PAYROLL_PRORATION_POLICY_MISSING/.test(f.calculator) && /PAYROLL_NET_NEGATIVE/.test(f.calculator),
    P2B13_half_up_per_line: /PayrollMoney::applyRate|PayrollMoney::applyBrackets/.test(f.calculator) && /PayrollMoney::roundHalfUp/.test(f.calculator) && /bcadd\(\$gross, \$line\['amount'\]/.test(f.calculator),
    P2B14_privacy_and_finance_boundary: /HR_COMPENSATION_VIEW/.test(methodBody(f.query, 'employees')) && /payroll\.employee_detail_viewed/.test(methodBody(f.query, 'employees'))
      && !/payroll_run_lines|employment_compensations|employments/.test(financeSources) && /FINANCE_PAYROLL_SUMMARY_VIEW/.test(methodBody(f.query, 'summary')) && !/people|full_name/.test(methodBody(f.query, 'summary')),
    P2B15_audit_actions: ['payroll.created', 'payroll.calculated', 'payroll.approved', 'payroll.posted', 'payroll.paid', 'payroll.failed_transition', 'payroll.reversed', 'payroll.cancelled'].every((a) => f.runs.includes(`'${a}'`))
      && ['payroll.viewed', 'payroll.employee_detail_viewed', 'payroll.summary_viewed'].every((a) => f.query.includes(a)) && /MONEY_KEYS/.test(f.audit),
    P2B16_permission_before_target: ['calculate', 'approve', 'cancel'].every((m) => before(methodBody(f.runs, m), 'requires(PayrollCatalog::', 'peek(')) && before(methodBody(f.runs, 'ledgerPreamble'), 'requires($actor, FinanceCatalog::PERMISSION_POST)', 'peek(')
      && /FinanceAuthority\(new TerritorialAuthority\(\$this->db, \$users, \$grants, FinanceCatalog::DATA_TYPE\)\)/.test(f.factory) && /\$gate->recheck\(\)/.test(f.factory),
    P2B17_no_rates_seeded: !/table\('payroll_rules'\)->insert|table\('payroll_rule_brackets'\)->insert/.test(f.catalog + f.runs + f.calculator) && Object.values(contract.seeded_values).every((v) => v === 0),
    P2B18_ui_actions_from_server: /r\.actions\[k\]/.test(f.pages) && /'Folhas Salariais'/.test(f.shell) && !/disabled title="Disponível na fase F2B"/.test(f.hrPages) && /employee_detail_visible/.test(f.pages) && /4 viewports|layout\(/.test(f.e2e),
    P2B19_tests: ['test_p01_', 'test_p02_', 'test_p03_', 'test_p04_', 'test_p05_', 'test_p06_', 'test_p07_', 'test_p08_', 'test_p09_p12_', 'test_p13_', 'test_p14_', 'test_p15_', 'test_p16_', 'test_p17_', 'test_p18_', 'test_p19_', 'test_p20_',
      'test_p21_', 'test_p22_p23_', 'test_p24_', 'test_p25_', 'test_p26_', 'test_p27_p28_', 'test_p29_', 'test_p30_', 'test_p31_', 'test_p32_', 'test_p33_', 'test_p34_', 'test_p35_', 'test_p36_', 'test_p37_', 'test_p38_', 'test_p39_', 'test_p40_',
      'test_x01_', 'test_x02_', 'test_x03_', 'test_x04_', 'test_c5a_', 'test_c5b_', 'test_c6_', 'test_c11_', 'test_c_post_vs_close_post_first', 'test_c_post_vs_close_close_first', 'test_c_pay_vs_close_pay_first', 'test_c_pay_vs_close_close_first']
      .every((p) => testNames.some((n) => n.startsWith(p))) && /hold_until_commit/.test(f.worker) && /data_lock_waits/.test(f.test) && !/\bsleep\(/.test(f.test),
    P2B20_contract: contract.phase === 'P0.10-F2B' && contract.run_pipeline && contract.run_pipeline.statuses.join() === 'DRAFT,CALCULATED,APPROVED,POSTED,PAID,CANCELLED,REVERSED' && contract.finance_posting && contract.production_gate.default === false,
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
  const source = originals[key].toString('utf8').replace(/\r\n/g, '\n')
  const changed = fn(source)
  if (changed === source) throw new Error(`mutation of ${key} did not alter its target`)
  fs.writeFileSync(abs(key), changed)
}
const sub = (from, to) => (s) => { if (!s.includes(from)) throw new Error('mutation anchor not found: ' + from.slice(0, 90)); return s.split(from).join(to) }
function phpunit(filter) {
  const started = Date.now()
  const r = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', '--filter', filter, 'tests/DatabaseV2/PayrollProcessingF2BTest.php'], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 1800000 })
  const summary = (r.stdout || '').split(/\r?\n/).filter((l) => /^(OK \(|Tests:|FAILURES|ERRORS)/.test(l)).pop() || ''
  return { detector: `phpunit --filter ${filter}`, exit_code: r.status, summary, seconds: Math.round((Date.now() - started) / 100) / 10 }
}

const PERIOD_FILTER = "->where('c.starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('c.ends_on')->orWhere('c.ends_on', '>=', $from))"
const FLAT_LINE = "return [PayrollMoney::applyRate($base, (string) $rule['rate']), (string) $rule['rate']];"
const cases = [
  ['M1', 'current compensation used for an old payroll (effective-in-period filter replaced by the open line)', [['hash', sub(PERIOD_FILTER, "->whereNull('c.ends_on')")]], 'test_p03_'],
  ['M2', 'missing legal rule treated as zero', [['resolver', sub("            throw new FinanceError('PAYROLL_RULE_MISSING', [$component], ['component' => $component, 'from' => $from, 'to' => $to]);",
    "            return ['id' => 0, 'code' => 'NONE', 'version' => 0, 'component' => $component, 'method' => 'FLAT_RATE', 'rate' => '0.000000', 'starts_on' => $from, 'ends_on' => $to, 'status' => 'APPROVED', 'base_components' => [], 'brackets' => []];")]], 'test_p05_'],
  ['M3', 'unapproved (DRAFT) rule accepted', [['resolver', sub("->where('status', PayrollCatalog::RULE_APPROVED)", "->whereIn('status', [PayrollCatalog::RULE_APPROVED, PayrollCatalog::RULE_DRAFT])")]], 'test_p05_'],
  ['M4', 'component not rounded per line (rounding left to the totals)', [['calculator', sub(FLAT_LINE, "return [bcmul($base, (string) $rule['rate'], 4), (string) $rule['rate']];")]], 'test_p08_'],
  ['M5', 'input_hash omits the compensations', [['hash', sub('        return hash(\'sha256\', self::canonical($payload));', "        return hash('sha256', self::canonical(['compensations' => []] + $payload));")]], 'test_p15_|test_p16_'],
  ['M6', 'input_hash omits the rule brackets', [['hash', sub('        return hash(\'sha256\', self::canonical($payload));',
    "        return hash('sha256', self::canonical(['rules' => array_map(fn ($r) => array_diff_key($r, ['brackets' => 1]), $payload['rules'] ?? [])] + $payload));")]], 'test_p16_'],
  ['M7', 'stale run approved (hash comparison removed)', [['runs', sub("            if ($hash === null || !hash_equals(bin2hex((string) $r->input_hash), $hash)) {", '            if (false) {')]], 'test_p17_'],
  ['M8', 'calculator approves its own run', [['runs', sub('            if ((int) $r->calculated_by === $actor->user) {', '            if (false) {')]], 'test_p18_'],
  ['M9', 'production gate bypassed', [['production', sub('        if (!$this->enabled()) {', '        if (false) {')]], 'test_p19_'],
  ['M10', 'POST creates employee-detail Finance lines', [['runs', (s) => sub("->groupBy('t.nature', 't.liability_role', 'c.code', 'c.ledger_account_id')", "->groupBy('t.nature', 't.liability_role', 'c.code', 'c.ledger_account_id', 'l.employment_id')")(
    sub("selectRaw('t.nature, t.liability_role, c.code AS rubric, c.ledger_account_id, SUM(l.amount) AS total')", "selectRaw('t.nature, t.liability_role, c.code AS rubric, c.ledger_account_id, l.employment_id, SUM(l.amount) AS total')")(
      sub('                $key = $row->rubric;\n', "                $key = $row->rubric . '#' . $row->employment_id;\n")(s)))]], 'test_p22_p23_'],
  ['M11', 'POST duplicated (replay and state guard removed)', [['runs', (s) => sub("            if (($posting = $this->posting((int) $r->id, self::STAGE_ACCRUAL)) !== null) {\n                return", "            if (false) {\n                return")(
    sub("            if ($r->status !== 'APPROVED') {\n                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'APPROVED']);", "            if (false) {\n                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'APPROVED']);")(s))]], 'test_p25_'],
  ['M12', 'PAY duplicated (replay and state guard removed)', [['runs', (s) => sub("            if (($posting = $this->posting((int) $r->id, self::STAGE_PAYMENT)) !== null) {\n                return", "            if (false) {\n                return")(
    sub("            if ($r->status !== 'POSTED') {\n                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'POSTED']);\n            }\n            $accrual = $this->posting((int) $r->id, self::STAGE_ACCRUAL);",
      "            if (false) {\n                throw new FinanceError('TRANSITION_NOT_ALLOWED', [], ['from' => $r->status, 'expected' => 'POSTED']);\n            }\n            $accrual = $this->posting((int) $r->id, self::STAGE_ACCRUAL);")(s))]], 'test_p30_'],
  ['M13', 'PAY clears the statutory liabilities', [['runs', (s) => sub("['account' => 'PAYROLL_NET_PAYABLE', 'debit' => $net, 'description' => 'Salários líquidos a pagar'],",
    "['account' => 'PAYROLL_NET_PAYABLE', 'debit' => $net, 'description' => 'Salários líquidos a pagar'], ['account' => 'PAYROLL_WITHHOLDINGS', 'debit' => PayrollMoney::fromStorage((string) $r->deductions_amount)],")(
    sub("'financial_account_id' => (int) $account->id, 'credit' => $net,", "'financial_account_id' => (int) $account->id, 'credit' => bcadd($net, PayrollMoney::fromStorage((string) $r->deductions_amount), 2),")(s))]], 'test_p27_p28_'],
  ['M14', 'PAY bypasses FIN-D10 (no negative CASH/BANK)', [['ledger', sub('    private function assertNoOverdraft(int $entryId, array $lines): void\n    {\n',
    "    private function assertNoOverdraft(int $entryId, array $lines): void\n    {\n        if ($this->db->table('journal_entries')->where('id', $entryId)->value('entry_kind') === 'PAYROLL_PAYMENT') {\n            return;\n        }\n")]], 'test_p29_'],
  ['M15', 'POST into a CLOSED Finance period (unit close ignored)', [['ledger', (s) => sub('        if ($close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {', '        if (false && $close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {')(
    sub('$entry->period_status !== FinanceCatalog::PERIOD_OPEN || $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id)', '$entry->period_status !== FinanceCatalog::PERIOD_OPEN || (false && $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id))')(s))]], 'test_p31_'],
  ['M16', 'PAY into a CLOSED Finance period (unit close ignored)', [['ledger', (s) => sub('        if ($close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {', '        if (false && $close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {')(
    sub('$entry->period_status !== FinanceCatalog::PERIOD_OPEN || $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id)', '$entry->period_status !== FinanceCatalog::PERIOD_OPEN || (false && $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id))')(s))]], 'test_p32_'],
  ['M17', 'Finance journal unbalanced (employer charges never credited)', [['runs', sub('            if (in_array($row->nature, [PayrollCatalog::EMPLOYEE_DEDUCTION, PayrollCatalog::EMPLOYER_CHARGE], true)) {', '            if (in_array($row->nature, [PayrollCatalog::EMPLOYEE_DEDUCTION], true)) {')]], 'test_p22_p23_'],
  ['M18', 'salary detail exposed to the Finance side (payroll summary)', [['query', sub("'totals' => $this->totals($r), 'components' => $components];",
    "'totals' => $this->totals($r), 'components' => $components, 'people' => $this->rt->db->table('payroll_run_lines as l')->join('employments as e', 'e.id', '=', 'l.employment_id')->join('people as p', 'p.id', '=', 'e.person_id')->where('l.run_id', $r->id)->distinct()->pluck('p.full_name')->all()];")]], 'test_p36_'],
  ['M19', 'individual salary read without audit', [['query', sub("            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.employee_detail_viewed', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id,\n                ['run' => (string) $r->public_id, 'employments' => count($employees)], null, $actor->session);\n", '')]], 'test_p34_'],
  ['M20', 'official rate hardcoded as a fallback', [['calculator', sub(FLAT_LINE, "return [PayrollMoney::applyRate($base, (string) ($rule['rate'] ?? '0.03')), (string) $rule['rate']];")]], 'test_p39_'],
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
  phase: 'P0.10-F2B',
  status: failures.length === 0 && restoredBytes && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base, failures, mutation_probes: probes,
  probes_detected: probes.filter((p) => p.status === 'PASS' && p.id !== 'CONTROL').length, probes_run: probes.filter((p) => p.id !== 'CONTROL').length,
  source_hashes_before: hashesBefore, source_hashes_after: hashesAfter, source_restored_byte_for_byte: restoredBytes,
}
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(result.status === 'PASS' ? 0 : 1)
