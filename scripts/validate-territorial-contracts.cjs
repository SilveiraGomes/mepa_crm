const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const paths = {
  service: 'apps/api/app/Domain/Territorial/TerritorialService.php',
  authority: 'apps/api/app/Domain/Territorial/TerritorialAuthority.php',
  output: 'apps/api/app/Http/Territorial/TerritorialOutput.php',
  handler: 'apps/api/app/Exceptions/Handler.php',
  routes: 'apps/api/routes/api.php',
  rules: 'docs/database/unit_parent_rules.json',
  contracts: 'docs/contracts/territorial_contracts.json',
  ui: 'apps/web/src/pages/TerritorialPages.tsx',
}
const absolute = (key) => path.join(root, paths[key])
const readFiles = () => Object.fromEntries(Object.entries(paths).map(([key, value]) => [key, fs.readFileSync(path.join(root, value), 'utf8')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')

function checks(files) {
  const rules = JSON.parse(files.rules)
  const contract = JSON.parse(files.contracts)
  return {
    M1_cycle_validation: /assertNoCycle/.test(files.service) && /CYCLE_DETECTED/.test(files.service),
    M2_parent_type_validation: /assertParentPair/.test(files.service) && /unit_parent_rules/.test(files.service),
    M3_general_center_optional: rules.general_center_optional === true && rules.allowed_parent_child_pairs.some((pair) => pair[0] === 'MUNICIPAL_DIRECTION' && pair[1] === 'CENTER') && rules.allowed_parent_child_pairs.some((pair) => pair[0] === 'MUNICIPAL_DIRECTION' && pair[1] === 'GENERAL_CENTER'),
    M4_scope_bypass: /authorize\(\$actor,TerritorialCatalog::MOVE,\(int\)\$unit->id,true\)/.test(files.service) && /authorize\(\$actor,TerritorialCatalog::MOVE,\(int\)\$parent->id,true\)/.test(files.service) && /coveredUnits/.test(files.authority),
    M5_f06: /TerritorialError[\s\S]*?\['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\][\s\S]*?territorial_http_concealed[\s\S]*?RESOURCE_NOT_FOUND[\s\S]*?404/.test(files.handler),
    M6_internal_id: /parent_id/.test(files.output) && /internal_field_in_response/.test(files.output),
    M7_bounded_tree: contract.reads.full_tree_endpoint === false && !/Route::get\(['"]tree/.test(files.routes) && /min\(50/.test(files.service) && /depth<64/.test(files.service),
    M8_commit_recheck: (files.service.match(/assertNoCycle/g) || []).length >= 3 && (files.service.match(/assertParentPair/g) || []).length >= 3 && /NATIONAL_TREE/.test(files.service),
    ui_lazy: /open&&<TreeChildren/.test(files.ui) && /function TreeChildren/.test(files.ui),
  }
}

const structuralOnly = process.argv.includes('--structural-only')
const originals = Object.fromEntries(Object.entries(paths).map(([key, value]) => [key, fs.readFileSync(path.join(root, value))]))
const hashesBefore = Object.fromEntries(Object.entries(originals).map(([key, value]) => [key, digest(value)]))
const base = checks(readFiles())
const probes = []

function runPhpunit(filter) {
  const result = spawnSync('C:\\wamp64\\bin\\php\\php8.1.33\\php.exe', [
    'apps/api/vendor/bin/phpunit', '--configuration', 'apps/api/phpunit.xml',
    'apps/api/tests/DatabaseV2/TerritorialVerticalTest.php',
  ], { cwd: root, env: process.env, encoding: 'utf8', timeout: 180000 })
  return { target_test: filter, exit_code: result.status, detected: result.status !== 0, timed_out: Boolean(result.error && result.error.code === 'ETIMEDOUT'), output_tail: `${result.stdout || ''}${result.stderr || ''}`.slice(-800) }
}

function writeMutation(key, mutate) {
  const source = originals[key].toString('utf8')
  const changed = mutate(source)
  if (changed === source) throw new Error(`mutation ${key} did not alter its target`)
  fs.writeFileSync(absolute(key), changed)
}

if (!structuralOnly) {
  const cases = [
    ['M1_cycle_validation', 'service', (source) => source.replaceAll('$this->assertNoCycle((int)$unit->id,(int)$parent->id);', ''), 'test_t05_and_t07_cycles_are_denied'],
    ['M2_parent_type_validation', 'service', (source) => source.replaceAll('$this->assertParentPair((int)$parent->unit_type_id,(int)$unit->unit_type_id);', '').replace('$this->assertParentPair((int)$parent->unit_type_id,(int)$type->id);', ''), 'test_t04_invalid_parent_is_denied'],
    ['M3_general_center_optional', 'rules', (source) => source.replace(/\s*\["MUNICIPAL_DIRECTION", "CENTER"\],?/, ''), 'test_t01_t03_valid_paths_with_and_without_general_center'],
    ['M4_scope_bypass', 'authority', (source) => source.replace(/public function authorize\(TerritorialActor \$actor, string \$permission, int \$unit, bool \$lock = false\): void\s*\{[\s\S]*?\n    \}/, 'public function authorize(TerritorialActor $actor, string $permission, int $unit, bool $lock = false): void { return;\n    }'), 'test_scope_matrix_conceals_ancestors_siblings_other_regions_parents_and_move_targets'],
    ['M5_f06', 'handler', (source) => { const start = source.indexOf('function (TerritorialError'); const index = source.indexOf("['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']", start); return source.slice(0, index) + "['TARGET_NOT_FOUND']" + source.slice(index + "['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']".length) }, 'test_scope_matrix_conceals_ancestors_siblings_other_regions_parents_and_move_targets'],
    ['M6_internal_id', 'service', (source) => source.replace("private function project(object $r): array { return [", "private function project(object $r): array { return ['id'=>(int)$r->id,"), 'test_public_payloads_and_audit_are_safe'],
    ['M7_bounded_tree', 'service', (source) => source.replace("public function roots(int $user,int $session,array $filters=[]): array\n    {", "public function roots(int $user,int $session,array $filters=[]): array\n    {").replace("$per=min(50,max(1,(int)($filters['per_page']??25)));", "$per=min(5000,max(1,(int)($filters['per_page']??25)));"), 'test_tree_reads_are_bounded'],
  ]
  for (const [id, key, mutate, filter] of cases) {
    try {
      writeMutation(key, mutate)
      const run = runPhpunit(filter)
      probes.push({ id, status: run.detected ? 'PASS' : 'FAIL', mode: 'ON_DISK_MUTATION_EXPECTED_TEST_FAILURE', ...run })
    } finally {
      fs.writeFileSync(absolute(key), originals[key])
    }
  }
  try {
    writeMutation('service', (source) => { const start = source.indexOf('// Commit-time recheck'); const end = source.indexOf('$now=', start); return source.slice(0, start) + source.slice(end) })
    const run = spawnSync(process.execPath, [__filename, '--structural-only'], { cwd: root, env: process.env, encoding: 'utf8', timeout: 30000 })
    probes.push({ id: 'M8_commit_recheck', status: run.status !== 0 ? 'PASS' : 'FAIL', mode: 'ON_DISK_MUTATION_EXPECTED_VALIDATOR_FAILURE', exit_code: run.status, detected: run.status !== 0, timed_out: false })
  } finally {
    fs.writeFileSync(absolute('service'), originals.service)
  }
}

const hashesAfter = Object.fromEntries(Object.entries(paths).map(([key, value]) => [key, digest(fs.readFileSync(path.join(root, value)))]))
const restored = Object.keys(paths).every((key) => hashesBefore[key] === hashesAfter[key])
const failures = Object.entries(base).filter(([, ok]) => !ok).map(([name]) => name)
const result = {
  status: failures.length === 0 && restored && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base,
  failures,
  mutation_probes: probes,
  source_hashes_before: hashesBefore,
  source_hashes_after: hashesAfter,
  source_restored_byte_for_byte: restored,
}
const json = JSON.stringify(result, null, 2)
const outputIndex = process.argv.indexOf('--output')
if (outputIndex >= 0 && process.argv[outputIndex + 1]) fs.writeFileSync(path.resolve(root, process.argv[outputIndex + 1]), json + '\n')
console.log(json)
process.exit(result.status === 'PASS' ? 0 : 1)
