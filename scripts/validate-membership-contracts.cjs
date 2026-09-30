// P0.9 Membership contract validator (ADR 0020).
//  - structural checks S01-S20 over the Membership sources, routes, handler, requests, catalog, contract and UI;
//  - N1..N15 negative probes: each mutates the real source (N4b also the pool schema), runs the targeted PHPUnit tests of
//    MembershipVerticalTest and EXPECTS them to fail, then restores the file byte-for-byte (SHA-256). A control run
//    (no mutation) must pass first, so a probe's failure is caused by its mutation.
// Env for probes: WAVE5_DSN / WAVE5_USER / WAVE5_PASSWORD / WAVE5_ALLOW_SYNTHETIC=1 (isolated pool), P09_DSN for N4b.
// Flags: --structural-only, --only N3,N9, --output <file>
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync, execFileSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const PHP = process.env.MEPA_PHP_BIN || 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const MYSQL = process.env.MYSQL_BIN || 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe'
const D = 'apps/api/app/Domain/Membership/'
const paths = {
  catalog: D + 'MembershipCatalog.php', authority: D + 'MembershipAuthority.php', runtime: D + 'MembershipRuntime.php', invariants: D + 'MembershipInvariants.php',
  records: D + 'MembershipRecords.php', guard: D + 'MembershipGuard.php', audit: D + 'MembershipAudit.php', admission: D + 'AdmissionService.php',
  lifecycle: D + 'LifecycleService.php', transfers: D + 'MembershipTransferService.php', transferCore: D + 'TransferService.php', generator: D + 'MemberNumberGenerator.php',
  legacy: D + 'LegacyIdentifierService.php', milestones: D + 'MilestoneService.php', query: D + 'MembershipQueryService.php',
  factory: 'apps/api/app/Http/Membership/MembershipServiceFactory.php', output: 'apps/api/app/Http/Membership/MembershipOutput.php',
  handler: 'apps/api/app/Exceptions/Handler.php', routes: 'apps/api/routes/api.php', provider: 'apps/api/app/Providers/RouteServiceProvider.php',
  approveRequest: 'apps/api/app/Http/Requests/Membership/ApproveRequest.php', config: 'apps/api/config/membership.php',
  contracts: 'docs/contracts/membership_contracts.json', manifest: 'docs/database/physical/p09_membership_delta_manifest.json',
  guardMigration: 'apps/api/database/migrations/2026_09_30_000002_p09_add_membership_periods_open_guard.php', catalogMigration: 'apps/api/database/migrations/2026_09_30_000003_p09_install_membership_catalog.php',
  app: 'apps/web/src/App.tsx', shell: 'apps/web/src/layout/AppShell.tsx', pages: 'apps/web/src/pages/MembershipPages.tsx', endpoints: 'apps/web/src/lib/membership/endpoints.ts',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')
const requestsDir = path.join(root, 'apps/api/app/Http/Requests/Membership')
const appDir = path.join(root, 'apps/api/app')

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => e.isDirectory() ? walk(path.join(dir, e.name)) : e.name.endsWith('.php') ? [path.join(dir, e.name)] : [])
}

function checks(f) {
  const services = [f.admission, f.lifecycle, f.transfers, f.legacy, f.milestones]
  const domain = [f.catalog, f.authority, f.runtime, f.invariants, f.records, f.guard, f.audit, f.query, f.transferCore, ...services].join('\n')
  const contract = JSON.parse(f.contracts)
  const requests = fs.readdirSync(requestsDir).map((file) => fs.readFileSync(path.join(requestsDir, file), 'utf8')).join('\n')
  const start = f.routes.indexOf("Route::prefix('memberships')")
  const routeBlock = f.routes.slice(start, f.routes.indexOf("Route::prefix('physical')"))
  const routes = [...routeBlock.matchAll(/Route::(get|post|patch|put|delete)\('([^']+)'/g)].map(([, method, uri]) => `${method.toUpperCase()} /api/v1/memberships/${uri === '/' ? '' : uri}`).sort()
  const declared = [...contract.endpoints].sort()
  const handler = f.handler.slice(f.handler.indexOf('function (MembershipError'), f.handler.indexOf('function (PhysicalError'))
  const allApp = walk(appDir).map((file) => [path.relative(root, file).replace(/\\/g, '/'), fs.readFileSync(file, 'utf8')])
  const generatorCallers = allApp.filter(([file, text]) => /->generateFor\(/.test(text) && !file.endsWith('MemberNumberGenerator.php')).map(([file]) => file)
  // The generator is the single issuer (INSERT only); nothing anywhere updates or deletes an official number.
  const numberWriters = allApp.filter(([file, text]) => /table\('member_numbers'\)[^;]*->(update|delete)\(/.test(text) || (/table\('member_numbers'\)[^;]*->insert/.test(text) && !file.endsWith('MemberNumberGenerator.php'))).map(([file]) => file)
  // Every business write closure starts with the permission check (F-06 ordering), before any target is resolved.
  const writesOutsideServices = [...services].every((s) => (s.match(/->write\(\$user, \$session, function/g) || []).length === (s.match(/->write\(\$user, \$session, function[^{]*\{\s*\n\s*\$guard->requires\(/g) || []).length)
  const personWrites = /table\('(people|person_unit_contexts)'\)[^;]*->(insert|update|delete)/.test(domain)
  const literalBeforeParam = routeBlock.indexOf("'transfers'") < routeBlock.indexOf("'{membership}'") && routeBlock.indexOf("'admissions'") < routeBlock.indexOf("'{membership}'")
  return {
    S01_single_ddl_open_period_guard: /MEMBERSHIP_OPEN_PERIOD_GUARD_INVALID_DATA/.test(f.guardMigration) && /havingRaw\('COUNT\(\*\) > 1'\)/.test(f.guardMigration) && (f.guardMigration.match(/ALTER TABLE/g) || []).length === 2 && /GENERATED ALWAYS AS \(IF\(`ends_at` IS NULL, 1, NULL\)\) STORED/.test(f.guardMigration) && JSON.parse(f.manifest).schema_changes.length === 1,
    S02_catalog_complete_role_free_counter_insert_only: Object.keys({ SUBMITTED: 1, VALIDATED: 1, REJECTED: 1, WITHDRAWN: 1, ACTIVE: 1, INACTIVE: 1, ENDED: 1 }).every((c) => f.catalog.includes(`'${c}'`)) && /insertOrIgnore\(\['code' => self::COUNTER, 'last_value' => 0/.test(f.catalog) && !/table\('member_number_sequences'\)[^;]*->update\(/.test(f.catalog) && !/table\('roles'\)|role_permissions/.test(f.catalog) && JSON.parse(f.manifest).controlled_data.length === 17 && /MembershipCatalog::install/.test(f.catalogMigration),
    S03_one_generation_path: JSON.stringify(generatorCallers) === JSON.stringify(['apps/api/app/Domain/Membership/AdmissionService.php']) && (f.admission.match(/->generateFor\(/g) || []).length === 1 && /return \$this->approveList\(\$user, \$session, \[\$publicId\]/.test(f.admission),
    S04_number_immutable_in_code: JSON.stringify(numberWriters) === JSON.stringify([]) && /I6_number_immutable/.test(f.invariants),
    S05_luanda_aa_mm_utc_storage: /NUMBER_TIMEZONE = 'Africa\/Luanda'/.test(f.generator) && /\$local = \$issuedAt->setTimezone/.test(f.generator) && /setTimezone\(new DateTimeZone\('UTC'\)\)->format/.test(f.generator),
    S06_p09_d_f01_atomic_effectuation: /function effectuate\(int \$transferId\): array/.test(f.transferCore) && /'ends_at' => \$at/.test(f.transferCore) && /'congregation_id' => \(int\) \$transfer->destination_unit_id/.test(f.transferCore) && /D06_origin_is_open_period/.test(f.transferCore) && !/member_numbers/.test(f.transferCore.slice(f.transferCore.indexOf('function effectuate'), f.transferCore.indexOf('function cancel'))),
    S07_person_root_never_written: !personWrites && !/unit_id' => .*people/.test(domain),
    S08_source_document_public_id_only: !/'source_document_id'\s*=>\s*\[/.test(requests) && /'source_document' => \['sometimes', 'nullable', 'string'/.test(requests) && /FilesCatalog::PUBLIC_ID_PATTERN/.test(f.runtime) && /documentAuthority\(\$this->files, \$actor, \$document, \$lock\)/.test(f.runtime),
    S09_output_guard: /internal_field_in_response/.test(f.output) && /'sequence_value'/.test(f.output),
    S10_f06_concealment: /\['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\][\s\S]*?membership_http_concealed[\s\S]*?RESOURCE_NOT_FOUND[\s\S]*?404/.test(handler),
    S11_permission_before_target: /public function requires\(/.test(f.guard) && writesOutsideServices,
    S12_single_scope_engine: /new TerritorialAuthority\(\$db, \$users, \$grants, MembershipCatalog::DATA_TYPE\)/.test(f.factory) && !/WITH RECURSIVE/.test(domain),
    S13_commit_time_recheck: /\$this->commitRecheck\(\$user, \$session, \$guard\);/.test(f.runtime) && /MembershipInvariants::assert/.test(f.runtime) && /documentAuthority\(\$actor, \$document, true\)/.test(f.runtime) && (f.invariants.match(/FOR SHARE/g) || []).length >= 6,
    S14_lock_order: /function lockByMembership[\s\S]*?lockPerson[\s\S]*?lockMembership/.test(f.records) && /sort\(\$personIds\)[\s\S]*?sort\(\$sorted\)/.test(f.admission) && /Person -> membership -> transfer -> periods/.test(f.transferCore),
    S15_collective_all_or_nothing_list_order: /MAX_COLLECTIVE = 200/.test(f.admission) && /COLLECTIVE_REJECTED/.test(f.admission) && /Numbers are assigned in the LIST order/.test(f.admission) && /\$correlation = \(string\) Str::ulid\(\)/.test(f.admission),
    S16_audit_source_and_forbidden_keys: /AUDIT_SOURCE = 'P09_MEMBERSHIP'/.test(f.catalog) && /'full_name'/.test(f.audit) && /'title'/.test(f.audit),
    S17_routes_match_contract_bidirectionally: JSON.stringify(routes) === JSON.stringify(declared) && literalBeforeParam,
    S18_no_delete_route_no_hard_delete: !/Route::delete/.test(routeBlock) && !/->delete\(\)/.test(domain),
    S19_rate_limiters: ['membership', 'membership-write', 'membership-search'].every((n) => f.provider.includes(`RateLimiter::for('${n}'`)) && /'max' => 100/.test(f.config),
    S20_ui_routes_public_ids_only: contract.ui.routes.every((route) => f.app.includes(`path="${route}"`)) && /Membros/.test(f.shell) && !/\b(person_id|membership_id|unit_id)\b/.test(f.pages + f.endpoints),
  }
}

const args = process.argv.slice(2)
const structuralOnly = args.includes('--structural-only')
const onlyIndex = args.indexOf('--only')
const only = onlyIndex >= 0 ? new Set(args[onlyIndex + 1].split(',')) : null
const originals = Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key))]))
const hashesBefore = Object.fromEntries(Object.entries(originals).map(([key, value]) => [key, digest(value)]))
const base = checks(read())
const probes = []

function phpunit(filter) {
  const started = Date.now()
  const result = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', 'tests/DatabaseV2/MembershipVerticalTest.php', '--filter', filter], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 900000 })
  const out = `${result.stdout || ''}${result.stderr || ''}`
  return { filter, exit_code: result.status, seconds: Math.round((Date.now() - started) / 100) / 10, summary: (out.match(/^(OK \(.*\)|Tests: .*)$/m) || [''])[0], failure_head: (out.match(/^1\) [\s\S]{0,400}/m) || [''])[0].split('\n').slice(0, 4).join(' | ') }
}

function mysqlExec(sql) {
  const parts = Object.fromEntries((process.env.WAVE5_DSN || '').replace(/^mysql:/, '').split(';').filter(Boolean).map((item) => item.split('=')))
  if (!/^mepa_wave5_test_[a-z0-9_]+$/.test(parts.dbname || '')) throw new Error('WAVE5_DSN must target an isolated Wave 5 pool')
  const cli = ['--user=' + (process.env.WAVE5_USER || 'root'), '--host=' + (parts.host || '127.0.0.1'), '--port=' + (parts.port || 3306), '--database=' + parts.dbname, '--batch', '--skip-column-names', '--execute=' + sql]
  if (process.env.WAVE5_PASSWORD) cli.splice(1, 0, '--password=' + process.env.WAVE5_PASSWORD)
  return execFileSync(MYSQL, cli, { encoding: 'utf8' })
}

function mutate(key, fn) {
  const source = fs.readFileSync(abs(key), 'utf8')
  const changed = fn(source)
  if (changed === source) throw new Error(`mutation of ${key} did not alter its target`)
  fs.writeFileSync(abs(key), changed)
}
const swap = (from, to) => (source) => {
  if (source.split(from).length !== 2) throw new Error(`target not found exactly once: ${from.slice(0, 70)}`)
  return source.replace(from, to)
}
const between = (from, until, to) => (source) => {
  const a = source.indexOf(from)
  const b = source.indexOf(until, a)
  if (a < 0 || b < 0 || source.indexOf(from, a + 1) >= 0) throw new Error(`range not found exactly once: ${from.slice(0, 60)}`)
  return source.slice(0, a) + to + source.slice(b + until.length)
}

// [id, description, [[file, mutation], ...], phpunit filter]
const cases = [
  ['N1', 'number issued before approval (on validation)', [['admission', swap("            $this->records->transition($membership, $open, $to, (int) $open->congregation_id, $reason, $document?->id === null ? null : (int) $document->id);\n", "            $this->records->transition($membership, $open, $to, (int) $open->congregation_id, $reason, $document?->id === null ? null : (int) $document->id);\n            $this->rt->db->table('memberships')->where('id', $id)->update(['approved_at' => $this->rt->ts(), 'approved_by' => $actor->user]);\n            (new MemberNumberGenerator($this->rt->db))->generateFor($id, new DateTimeImmutable('now'));\n")]], 'test_m02_'],
  ['N2', 'national counter reset by the catalog install', [['catalog', swap("            if (!$db->table('member_number_sequences')->where('code', self::COUNTER)->exists()) {", "            $db->table('member_number_sequences')->where('code', self::COUNTER)->update(['last_value' => 0]);\n            if (!$db->table('member_number_sequences')->where('code', self::COUNTER)->exists()) {")]], 'test_s02_|test_m07_'],
  ['N3', 'duplicate official number (counter not advanced)', [['generator', swap('$next = (int) $sequenceRow->last_value + 1;', '$next = max(1, (int) $sequenceRow->last_value);')]], 'test_m07_'],
  ['N4', 'second open period (the transition no longer closes the open one)', [['records', between("        $closed = $this->rt->db->table('membership_periods')->where('id', $open->id)", "throw new MembershipError(MembershipReason::STALE_WRITE, ['entity' => 'membership_periods']);\n        }\n", '')]], 'test_m02_'],
  ['N5', 'transfer changes the member number', [['transferCore', swap('$this->workflow((int) $transfer->workflow_instance_id, MembershipCatalog::T_COMPLETED, $at);', "$this->workflow((int) $transfer->workflow_instance_id, MembershipCatalog::T_COMPLETED, $at);\n            $this->db->table('member_numbers')->where('membership_id', $membershipId)->update(['number' => $this->db->raw(\"CONCAT(SUBSTRING(number, 1, 8), '999999')\")]);")]], 'test_m10_'],
  ['N6', 'P09-D-F01 regression: effectuation closes the transfer without moving the periods', [['transferCore', between('$at = $this->after((string) $open->starts_at);', "'source_document_id' => $transfer->source_document_id, 'created_at' => $at, 'lock_version' => 0,\n            ]);", '$at = $this->now(); $destinationPeriod = 0;')]], 'test_m10_'],
  ['N7', 'source_document PK (source_document_id) accepted by the approval request', [['approveRequest', swap("return ['lock_version'", "return ['source_document_id' => ['sometimes', 'nullable', 'integer'], 'lock_version'")]], 'test_m17_'],
  ['N8', 'scope bypass: any covered grant authorizes any Congregation', [['authority', swap("        if (!isset($covered[$unit])) {\n            throw new MembershipError(MembershipReason::OUT_OF_SCOPE, ['permission' => $permission]);", "        if (false) {\n            throw new MembershipError(MembershipReason::OUT_OF_SCOPE, ['permission' => $permission]);")]], 'test_m18_'],
  ['N9', 'F-06 oracle: OUT_OF_SCOPE no longer concealed', [['handler', (s) => { const a = s.indexOf('function (MembershipError'); const i = s.indexOf("['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']", a); return s.slice(0, i) + "['TARGET_NOT_FOUND']" + s.slice(i + "['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']".length) }]], 'test_m18_'],
  ['N10', 'legacy collision ignored (no CONFLICT flag)', [['legacy', swap('$status = $others === [] ? MembershipCatalog::L_ACTIVE : MembershipCatalog::L_CONFLICT;', '$status = MembershipCatalog::L_ACTIVE; $others = [];')]], 'test_m14_'],
  ['N11', 'milestone duplicate accepted', [['milestones', swap('throw new MembershipError(MembershipReason::MILESTONE_EXISTS);', '// mutated')]], 'test_m15_'],
  ['N12', 'collective partial commit (failing items skipped, valid ones approved)', [['admission', swap("            $this->rejectIfErrors($errors, $collective);\n", "            foreach ($errors as $error) { unset($ids[$error['index']]); }\n")]], 'test_m06_'],
  ['N13', 'commit-time recheck removed', [['runtime', swap('$this->commitRecheck($user, $session, $guard);', '// mutated')]], 'test_commit_time_recheck_|test_m06b_'],
  ['N14', 'deceased destructive handling (ending a deceased member deletes the milestones)', [['lifecycle', swap('$this->records->assertPersonOperational($person, $closure);', "$this->records->assertPersonOperational($person, $closure);\n            if ($person->status_code === 'DECEASED') { $this->rt->db->table('ecclesiastical_milestones')->where('person_id', $person->id)->delete(); }")]], 'test_m20_'],
  ['N15', 'deceased guard removed (non-closure operations accepted)', [['records', swap("if (!$closureCompatible && $person->status_code === 'DECEASED') {", 'if (false) {')]], 'test_m20_'],
]

if (!structuralOnly) {
  const control = phpunit('test_s02_|test_m02_|test_m06_|test_m06b_|test_m07_|test_m10_|test_m14_|test_m15_|test_m17_|test_m18_|test_m20_|test_commit_time_recheck_')
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
  if (!only || only.has('N4b')) {
    // Schema-level variant: without the physical guard, the code mutation of N4 must still be caught by the commit-time
    // invariant (I1) AND the missing guard by the schema-parity validator; the guard is then re-installed and parity
    // re-verified.
    let run = null; let parity = null; let restored = null
    try {
      mysqlExec('ALTER TABLE membership_periods DROP KEY uq_membership_periods_membership_open')
      mutate('records', cases.find(([id]) => id === 'N4')[2][0][1])
      run = phpunit('test_m02_')
      parity = spawnSync(process.execPath, [path.join(root, 'scripts/validate-p09-membership-schema.cjs'), '--schema-only'], { cwd: root, env: process.env, encoding: 'utf8' }).status
    } finally {
      fs.writeFileSync(abs('records'), originals.records)
      mysqlExec("SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'membership_periods' AND INDEX_NAME = 'uq_membership_periods_membership_open'); SET @s := IF(@c = 0, 'ALTER TABLE membership_periods ADD UNIQUE KEY uq_membership_periods_membership_open (membership_id, open_flag)', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;")
      restored = spawnSync(process.execPath, [path.join(root, 'scripts/validate-p09-membership-schema.cjs'), '--schema-only'], { cwd: root, env: process.env, encoding: 'utf8' }).status
    }
    probes.push({ id: 'N4b', description: 'physical open-period guard dropped + second open period in code', status: run.exit_code !== 0 && parity !== 0 && restored === 0 ? 'PASS' : 'FAIL', mode: 'DB_AND_CODE_MUTATION_EXPECTED_INVARIANT_AND_PARITY_FAILURE', detected_by_tests: run.exit_code !== 0, detected_by_schema_parity: parity !== 0, schema_restored_parity_pass: restored === 0, ...run })
  }
}

const hashesAfter = Object.fromEntries(Object.keys(paths).map((key) => [key, digest(fs.readFileSync(abs(key)))]))
const restoredBytes = Object.keys(paths).every((key) => hashesBefore[key] === hashesAfter[key])
const failures = Object.entries(base).filter(([, ok]) => !ok).map(([name]) => name)
const result = {
  status: failures.length === 0 && restoredBytes && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base, failures, mutation_probes: probes,
  probes_passed: probes.filter((p) => p.status === 'PASS' && p.id !== 'CONTROL').length,
  source_hashes_before: hashesBefore, source_hashes_after: hashesAfter, source_restored_byte_for_byte: restoredBytes,
}
const json = JSON.stringify(result, null, 2)
const outputIndex = args.indexOf('--output')
if (outputIndex >= 0 && args[outputIndex + 1]) fs.writeFileSync(path.resolve(root, args[outputIndex + 1]), json + '\n')
console.log(json)
