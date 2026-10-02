// P0.10-F2A RH / compensation foundation contract validator (ADR 0021 D22-D28, D31-D33 + D-04A.14/15):
//  - structural checks P2A01-P2A16 over migrations, catalog, services, routes, requests, handler, UI, tests and the contract;
//  - M1..M16 negative probes: each mutates the real source (payroll services, resolver, catalog, configuration, People
//    projection), runs the targeted tests of PayrollFoundationF2ATest against the isolated pool and EXPECTS them to fail,
//    then restores the file byte-for-byte (SHA-256). A CONTROL run (no mutation) of every detector must pass first.
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
  employment: D + 'EmploymentService.php', compensation: D + 'CompensationService.php', rules: D + 'PayrollRuleService.php', resolver: D + 'PayrollRuleResolver.php',
  readiness: D + 'PayrollReadinessService.php', query: D + 'PayrollQueryService.php', money: D + 'PayrollMoney.php', catalog: D + 'PayrollCatalog.php', base: D + 'PayrollService.php',
  hash: D + 'PayrollInputHash.php', production: D + 'PayrollProduction.php', audit: D + 'PayrollAudit.php', config: 'apps/api/config/payroll.php', people: 'apps/api/app/Domain/People/PersonRecords.php',
  routes: 'apps/api/routes/api.php', handler: 'apps/api/app/Exceptions/Handler.php', factory: 'apps/api/app/Http/Payroll/PayrollServiceFactory.php', controller: 'apps/api/app/Http/Controllers/Api/V1/Payroll/HrController.php',
  pages: 'apps/web/src/pages/HrPages.tsx', shell: 'apps/web/src/layout/AppShell.tsx', test: 'apps/api/tests/DatabaseV2/PayrollFoundationF2ATest.php', worker: 'scripts/p010-f2a-worker.php',
  manifest: 'docs/database/physical/p010_finance_delta_manifest.json', contracts: 'docs/contracts/payroll_contracts.json', e2e: 'apps/web/tests/e2e/payroll-f2a.spec.ts',
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
const TABLES = ['employments', 'compensation_component_types', 'employment_compensations', 'payroll_rules', 'payroll_rule_base_components', 'payroll_rule_brackets', 'payroll_runs', 'payroll_run_lines', 'payroll_postings']

function checks(f) {
  const manifest = JSON.parse(f.manifest)
  const contract = JSON.parse(f.contracts)
  const migrations = manifest.migrations.filter((m) => m.startsWith('2026_10_02_2000')).map((m) => fs.readFileSync(path.join(root, 'apps/api/database/migrations', m), 'utf8'))
  const testNames = new Set([...f.test.matchAll(/public function (test_\w+)\(/g)].map((m) => m[1]))
  const hrRoutes = [...f.routes.matchAll(/Route::(get|post)\('([^']+)', \[HrController::class/g)].map((m) => `${m[1].toUpperCase()} ${m[2]}`)
  return {
    P2A01_35_tables: JSON.stringify(manifest.materialized_tables_f2a) === JSON.stringify(TABLES) && manifest.materialized_tables_f1a.length === 26 && manifest.planned_table_count === 35 && migrations.length === 10,
    P2A02_money_decimal_no_float: migrations.every((m) => !/FLOAT|DOUBLE|REAL/.test(m)) && migrations.join('').match(/DECIMAL\(19,4\)/g).length === 11 && !/\(float\)|floatval|round\(/.test(f.money),
    P2A03_person_root_no_copy: /canSeePerson/.test(methodBody(f.employment, 'create')) && !/table\('people'\)->(insert|update)/.test(f.employment) && !/table\('(person_employment|employment_types)'\)/.test(f.employment + f.compensation + f.query + f.readiness),
    P2A04_no_appointment_link: !/ministerial_assignments|organizational_posts|department_posts|memberships/.test(f.employment + f.compensation + f.query) && migrations.every((m) => !/REFERENCES `(ministerial_assignments|organizational_posts|department_posts|memberships|person_employment|employment_types)`/.test(m)),
    P2A05_owner_unit_and_context: /employing_unit_id/.test(f.employment) && /PEOPLE_CONTEXT/.test(f.employment) && !/department_id/.test(f.employment),
    P2A06_append_only_compensation: /COMPENSATION_RETROACTIVE/.test(f.compensation) && /COMPENSATION_OVERLAP/.test(f.compensation) && /dayBefore\(\$startsOn\)/.test(f.compensation) && !/->delete\(/.test(f.compensation + f.employment),
    P2A07_no_seeded_rates: !/table\('payroll_rules'\)|table\('payroll_rule_brackets'\)/.test(f.catalog) && Object.values(contract.seeded_values).every((v) => v === 0) && !/\d\.\d{2,}|\d+%/.test(f.catalog.split('COMPONENTS = [')[1].split('];')[0]),
    P2A08_rule_document_and_segregation: /RULE_DOCUMENT_REQUIRED/.test(f.rules) && /SEGREGATION_REQUIRED/.test(f.rules) && /RULE_OVERLAP/.test(f.rules) && /documentAuthority/.test(methodBody(f.rules, 'approve')),
    P2A09_fail_closed_resolver: /PAYROLL_RULE_MISSING/.test(f.resolver) && /PAYROLL_RULE_AMBIGUOUS/.test(f.resolver) && /RULE_APPROVED/.test(methodBody(f.resolver, 'forPeriod')) && !/return 0|'0\.000000'/.test(methodBody(f.resolver, 'forPeriod')),
    P2A10_production_gate_default_off: /env\('PAYROLL_PRODUCTION_ENABLED', false\)/.test(f.config) && /PAYROLL_PRODUCTION_DISABLED/.test(f.production) && /=== true/.test(f.production),
    P2A11_readiness_separates: /'configuration' =>/.test(f.readiness) && /'production' =>/.test(f.readiness) && ['NO_ACTIVE_EMPLOYMENT', 'MISSING_COMPENSATION', 'INVALID_EFFECTIVITY', 'MISSING_RULE', 'AMBIGUOUS_RULE', 'MISSING_DOCUMENT'].every((c) => f.readiness.includes(c)),
    P2A12_salary_privacy_and_audit: (f.query.match(/hr\.compensation_viewed/g) || []).length >= 3 && /MONEY_KEYS/.test(f.audit) && !/employment_compensations|compensation/.test(f.people),
    P2A13_permission_before_target: before(methodBody(f.employment, 'create'), 'requires(PayrollCatalog::HR_EMPLOYMENT_MANAGE)', 'unitByPublicId') && before(methodBody(f.query, 'compensation'), 'requiresAny', 'employmentByPublicId')
      && /PUBLIC_ID_PATTERN/.test(f.base) && /api\/v1\/hr/.test(f.handler) && /TerritorialAuthority\(\$db, \$users, \$grants, PayrollCatalog::DATA_TYPE\)/.test(f.factory),
    // Phase-aware (P0.10-F2B): the F2A HrController keeps its 17 routes and no run operation; the run pipeline is the
    // separate F2B PayrollRunController + PayrollRunPages (or, before F2B, the disabled F2B placeholders).
    P2A14_no_run_operation_in_f2a: hrRoutes.length === 17 && hrRoutes.every((r) => !/run|calculate|\/post$|\/pay$/.test(r)) && !/payroll-runs/.test(f.pages)
      && (/disabled title="Disponível na fase F2B"/.test(f.pages) || (fs.existsSync(path.join(root, 'apps/api/app/Http/Controllers/Api/V1/Payroll/PayrollRunController.php')) && fs.existsSync(path.join(root, 'apps/web/src/pages/PayrollRunPages.tsx')))),
    P2A15_input_hash_and_rounding_contract: /ksort\(\$value, SORT_STRING\)/.test(f.hash) && /float_in_payroll_input/.test(f.hash) && /HALF_UP/.test(f.catalog) && /bcadd|bcsub/.test(methodBody(f.money, 'roundHalfUp'))
      && contract.input_hash.covers.length >= 10 && contract.rounding.mode === 'HALF_UP',
    P2A16_tests_and_ui: ['test_h01_', 'test_h02_', 'test_h03_', 'test_h04_', 'test_h05_', 'test_h06_h07_', 'test_h08_', 'test_h09_h10_', 'test_h11_', 'test_h12_', 'test_h13_', 'test_h14_', 'test_h15_', 'test_h16_', 'test_h17_', 'test_h18_',
      'test_h19_', 'test_h20_', 'test_h21_', 'test_h22_', 'test_h23_', 'test_h24_', 'test_hc1_', 'test_hc2_', 'test_s01_s02_', 'test_s03_', 'test_s04_s05_s07_', 'test_s06_', 'test_s08_s09_'].every((p) => [...testNames].some((n) => n.startsWith(p)))
      && /RH \/ Folha Salarial/.test(f.shell) && ['Funcionários', 'Vínculos', 'Remuneração', 'Componentes', 'Regras', 'Prontidão da Folha'].every((l) => f.shell.includes(`'${l}'`)) && /hold_until_commit/.test(f.worker) && /4 viewports|layout\(/.test(f.e2e),
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
const inMethod = (name, from, to) => (s) => { const body = methodBody(s, name); if (!body.includes(from)) throw new Error(`mutation anchor not found in ${name}: ` + from.slice(0, 90)); return s.replace(body, body.split(from).join(to)) }
function phpunit(filter) {
  const started = Date.now()
  const r = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', '--filter', filter, 'tests/DatabaseV2/PayrollFoundationF2ATest.php'], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 1200000 })
  const summary = (r.stdout || '').split(/\r?\n/).filter((l) => /^(OK \(|Tests:|FAILURES|ERRORS)/.test(l)).pop() || ''
  return { detector: `phpunit --filter ${filter}`, exit_code: r.status, summary, seconds: Math.round((Date.now() - started) / 100) / 10 }
}

const cases = [
  ['M1', 'employee duplicated as a parallel Person', [['employment', sub("'person_id' => $person->id, 'employing_unit_id' => $unit->id, 'relationship_kind' => $kind,",
    "'person_id' => $this->rt->db->table('people')->insertGetId(collect((array) $this->rt->db->table('people')->where('id', $person->id)->first())->except(['id'])->merge(['public_id' => (string) Str::ulid()])->all()), 'employing_unit_id' => $unit->id, 'relationship_kind' => $kind,")]], 'test_h01_'],
  ['M2', 'person_employment treated as the MEPA employment', [['employment', sub("            $this->rt->db->table('person_unit_contexts')->insert(['person_id' => $person->id,",
    "            $this->rt->db->table('person_employment')->where('person_id', $person->id)->update(['profession' => $jobTitle, 'lock_version' => $this->rt->db->raw('lock_version + 1')]);\n            $this->rt->db->table('person_unit_contexts')->insert(['person_id' => $person->id,")]], 'test_h02_'],
  ['M3', 'ecclesiastical appointment treated as the employment (job title taken from the post)', [['employment', sub("'job_title' => $jobTitle, 'starts_on' => $startsOn,", "'job_title' => 'Cargo eclesiástico', 'starts_on' => $startsOn,")]], 'test_h03_'],
  ['M4', 'salary history overwritten (closed line takes the new amount)', [['compensation', sub("->update(['ends_on' => self::dayBefore($startsOn), 'closed_by' => $actor->user,", "->update(['ends_on' => self::dayBefore($startsOn), 'amount' => $amount, 'closed_by' => $actor->user,")]], 'test_h06_h07_'],
  ['M5', 'overlapping / retroactive compensation accepted', [['compensation', sub("                if ((string) $line->starts_on === $startsOn) {\n                    throw new FinanceError('COMPENSATION_OVERLAP', [(string) $component->code . '@' . $startsOn]);\n                }\n", '')]], 'test_h06_h07_'],
  ['M6', 'FLOAT money', [['money', inMethod('parse', "return bcadd($m[1] . '.' . ($fraction === '' ? '0' : $fraction), '0', PayrollCatalog::MONEY_SCALE);", "return number_format((float) $value, 2, '.', '');")]], 'test_h22_'],
  ['M7', 'legal percentage hardcoded in the catalog', [['catalog', sub("'INSS_EMPLOYEE' => ['INSS (trabalhador)',", "'INSS_EMPLOYEE' => ['INSS (trabalhador) 3%',")]], 'test_h11_'],
  ['M8', 'missing rule treated as zero', [['resolver', sub("            throw new FinanceError('PAYROLL_RULE_MISSING', [$component], ['component' => $component, 'from' => $from, 'to' => $to]);",
    "            return ['id' => 0, 'code' => 'NONE', 'version' => 0, 'component' => $component, 'method' => 'FLAT_RATE', 'rate' => '0.000000', 'starts_on' => $from, 'ends_on' => $to, 'status' => 'APPROVED', 'base_components' => [], 'brackets' => []];")]], 'test_h14_'],
  ['M9', 'unapproved (DRAFT) rule used', [['resolver', inMethod('forPeriod', "->where('status', PayrollCatalog::RULE_APPROVED)", "->whereIn('status', [PayrollCatalog::RULE_APPROVED, PayrollCatalog::RULE_DRAFT])")]], 'test_h13_'],
  ['M10', 'salary exposed through the generic People API', [['people', sub("            'display_name' => (string) $row->full_name,",
    "            'display_name' => (string) $row->full_name,\n            'base_salary' => (string) (\\Illuminate\\Support\\Facades\\DB::table('employment_compensations as c')->join('employments as e', 'e.id', '=', 'c.employment_id')->join('people as p', 'p.id', '=', 'e.person_id')->where('p.public_id', $row->public_id)->orderByDesc('c.id')->value('c.amount') ?? ''),")]], 'test_h16_'],
  ['M11', 'salary read without audit', [['query', inMethod('compensation', "            PayrollAudit::write($this->rt->db, $actor->user, 'hr.compensation_viewed', 'employments', (int) $e->id, (int) $e->employing_unit_id,\n                ['employment' => (string) $e->public_id, 'view' => 'compensation', 'as_of' => $asOf], null, $actor->session);\n", '')]], 'test_h16_'],
  ['M12', 'wrong HR scope exposed (employment detail without unit coverage)', [['query', inMethod('employment', "            $guard->unit(PayrollCatalog::HR_EMPLOYMENT_VIEW, (int) $e->employing_unit_id);\n", '')]], 'test_h17_'],
  ['M13', 'internal primary key accepted', [['base', inMethod('employmentByPublicId', "$q = is_string($publicId) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('employments')->where('public_id', $publicId) : null;",
    "$q = is_string($publicId) && ctype_digit($publicId) ? $this->rt->db->table('employments')->where('id', (int) $publicId) : (is_string($publicId) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('employments')->where('public_id', $publicId) : null);")]], 'test_h18_'],
  ['M14', 'payroll production enabled by default', [['config', sub("env('PAYROLL_PRODUCTION_ENABLED', false)", "env('PAYROLL_PRODUCTION_ENABLED', true)")]], 'test_h19_'],
  ['M15', 'supporting document bypass (approver Files authority neither checked nor rechecked at commit)', [['rules', sub("                $this->rt->documentAuthority($actor, $document, true);\n                $guard->document($document);\n", '')]], 'test_h23_'],
  ['M16', 'readiness ignores a missing rule', [['readiness', sub("                    $ruleComponents[(int) $l->component_id] = (string) $l->code;", "                    // rule-based component ignored")]], 'test_h14_|test_h20_'],
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
  phase: 'P0.10-F2A',
  status: failures.length === 0 && restoredBytes && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base, failures, mutation_probes: probes,
  probes_detected: probes.filter((p) => p.status === 'PASS' && p.id !== 'CONTROL').length, probes_run: probes.filter((p) => p.id !== 'CONTROL').length,
  source_hashes_before: hashesBefore, source_hashes_after: hashesAfter, source_restored_byte_for_byte: restoredBytes,
}
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(result.status === 'PASS' ? 0 : 1)
