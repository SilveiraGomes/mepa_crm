// P0.7 Physical Locations contract validator (ADR 0018).
//  - structural checks over the Physical sources, routes, handler, requests, contract and UI;
//  - M1..M12 negative probes: each mutates the real source (or, for M1b, the pool schema), runs the targeted
//    PHPUnit tests of PhysicalVerticalTest and EXPECTS them to fail, then restores the file byte-for-byte (SHA-256).
//    A control run (no mutation) must pass first, so a probe's failure is caused by its mutation.
// Env for probes: WAVE5_DSN / WAVE5_USER / WAVE5_PASSWORD / WAVE5_ALLOW_SYNTHETIC=1 (isolated pool), P07_DSN for M1b.
// Flags: --structural-only, --only M3,M9, --output <file>
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync, execFileSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const PHP = process.env.MEPA_PHP_BIN || 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const MYSQL = process.env.MYSQL_BIN || 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe'
const D = 'apps/api/app/Domain/Physical/'
const paths = {
  authority: D + 'PhysicalAuthority.php', runtime: D + 'PhysicalRuntime.php', invariants: D + 'PhysicalInvariants.php', records: D + 'PhysicalRecords.php',
  location: D + 'LocationService.php', link: D + 'LinkService.php', property: D + 'PropertyService.php', temple: D + 'TempleService.php',
  catalog: D + 'PhysicalCatalog.php', guard: D + 'PhysicalGuard.php', territorialAuthority: 'apps/api/app/Domain/Territorial/TerritorialAuthority.php',
  factory: 'apps/api/app/Http/Physical/PhysicalServiceFactory.php', output: 'apps/api/app/Http/Physical/PhysicalOutput.php',
  handler: 'apps/api/app/Exceptions/Handler.php', routes: 'apps/api/routes/api.php', linkRequest: 'apps/api/app/Http/Requests/Physical/LinkCreateRequest.php',
  config: 'apps/api/config/physical.php', contracts: 'docs/contracts/physical_contracts.json', manifest: 'docs/database/physical/p07_physical_delta_manifest.json',
  templesMigration: 'apps/api/database/migrations/2026_09_29_000001_p07_create_temples.php', app: 'apps/web/src/App.tsx', shell: 'apps/web/src/layout/AppShell.tsx',
  pages: 'apps/web/src/pages/PhysicalPages.tsx', patrimony: 'apps/web/src/pages/PatrimonyPages.tsx',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')
const requestsDir = path.join(root, 'apps/api/app/Http/Requests/Physical')

function checks(f) {
  const domain = [f.authority, f.runtime, f.invariants, f.records, f.location, f.link, f.property, f.temple, f.guard].join('\n')
  const contract = JSON.parse(f.contracts)
  const requests = fs.readdirSync(requestsDir).map((file) => fs.readFileSync(path.join(requestsDir, file), 'utf8')).join('\n')
  const routeBlock = f.routes.slice(f.routes.indexOf("Route::prefix('physical')"), f.routes.indexOf("Route::prefix('people')"))
  const routes = [...routeBlock.matchAll(/Route::(get|post|patch|put|delete)\('([^']+)'/g)].map(([, method, uri]) => `${method.toUpperCase()} /api/v1/physical/${uri}`).sort()
  const declared = [...contract.endpoints].sort()
  const projection = (f.records.match(/public static function publicProjection[\s\S]*?\n    }\n/) || [''])[0]
  const projectionKeys = [...projection.matchAll(/'([a-z_]+)' =>/g)].map((m) => m[1])
  const physicalHandler = f.handler.slice(f.handler.indexOf('function (PhysicalError'), f.handler.indexOf('function (PeopleError'))
  return {
    S01_no_owner_unit_column: !/owner_unit_id/.test(domain + f.templesMigration) && contract.identity_separation.forbidden_columns.includes('physical_locations.owner_unit_id'),
    S02_authority_only_through_active_vigente_links: /unit_location_links WHERE location_id = \? AND status = 'ACTIVE' AND starts_at <= \? AND \(ends_at IS NULL OR ends_at > \?\)/.test(f.authority) && /isset\(\$covered\[\(int\) \$link->unit_id\]\)/.test(f.authority),
    S03_single_scope_engine_reused: /TerritorialAuthority/.test(f.factory) && /PhysicalCatalog::DATA_TYPE/.test(f.factory) && /private string \$dataType/.test(f.territorialAuthority) && !/WITH RECURSIVE/.test(domain),
    S04_f06_concealment: /\['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\][\s\S]*?physical_http_concealed[\s\S]*?RESOURCE_NOT_FOUND[\s\S]*?404/.test(physicalHandler),
    S05_permission_checked_before_target: /public function requires\(/.test(f.guard) && (f.location.match(/\$guard->requires\(/g) || []).length >= 8 && (f.link.match(/\$guard->requires\(/g) || []).length >= 5,
    S06_source_document_never_accepted: !/'source_document_id'\s*=>/.test(requests) && /'source_document_id' => null/.test(f.link),
    S07_primary_demotion_locked: /function demotePrimary[\s\S]*?lockForUpdate\(\)->pluck/.test(f.link) && /D10\.3_single_primary/.test(f.invariants),
    S08_last_active_link: /LAST_ACTIVE_LINK_REQUIRED/.test(f.link) && /D04_operational_link/.test(f.invariants),
    S09_publish_permission: /requires\(PhysicalCatalog::LOCATION_PUBLISH\)/.test(f.location) && /lockForManage\(\$guard, \$actor, \$id, PhysicalCatalog::LOCATION_PUBLISH\)/.test(f.location),
    S10_public_projection_minimal: JSON.stringify(projectionKeys) === JSON.stringify(['public_id', 'name', 'latitude', 'longitude']) && JSON.stringify(contract.publication.public_projection_fields) === JSON.stringify(projectionKeys),
    S11_crypto_fail_closed_own_aad: /mepa\.physical\.address\.line1\.v1\|/.test(f.location) && /catch \(PeopleError \$e\) \{\s*throw new PhysicalError\(PhysicalReason::CRYPTO_UNAVAILABLE/.test(f.runtime) && !/blindIndex/.test(f.location + f.runtime),
    S12_temple_is_not_a_unit: !/table\('organizational_units'\)|organizational_units \(|unit_parent_periods/.test(f.temple) && !/`(parent_id|unit_id)`/.test(f.templesMigration.slice(f.templesMigration.indexOf('CREATE TABLE'))),
    S13_commit_time_recheck: /\$this->commitRecheck\(\$user, \$session, \$guard\);/.test(f.runtime) && /recheck\(\$actor, \$decision\)/.test(f.runtime) && /PhysicalInvariants::assert/.test(f.runtime) && / FOR SHARE/.test(f.invariants),
    S14_bounded_collections: /'max' => 100/.test(f.config) && /'default' => 50/.test(f.config) && /max'\] \?\? 100\)/.test(f.runtime) && contract.pagination.max === 100,
    S15_lock_order: /treeShared\(\);[\s\S]*?actor\(/.test(f.runtime) && /function lockUnits[\s\S]*?sort\(\$ids\)/.test(f.runtime),
    S16_output_guard: /internal_field_in_response/.test(f.output) && /'source_document_id'/.test(f.output),
    S17_routes_match_contract_bidirectionally: JSON.stringify(routes) === JSON.stringify(declared),
    S18_no_generic_status_or_delete_route: !/Route::delete/.test(routeBlock) && !/status'\)/.test(routeBlock),
    S19_manifest_single_schema_change: JSON.parse(f.manifest).schema_changes.length === 1 && JSON.parse(f.manifest).controlled_data.length === 14,
    S20_ui_section_and_routes: /Locais e Património/.test(f.shell) && contract.ui.routes.every((route) => f.app.includes(`path="${route}"`)) && !/\bperson_id\b/.test(f.pages + f.patrimony),
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
  const result = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', 'tests/DatabaseV2/PhysicalVerticalTest.php', '--filter', filter], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 600000 })
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
  const source = originals[key].toString('utf8')
  const changed = fn(source)
  if (changed === source) throw new Error(`mutation of ${key} did not alter its target`)
  fs.writeFileSync(abs(key), changed)
}
const swap = (from, to) => (source) => {
  if (source.split(from).length !== 2) throw new Error(`target not found exactly once: ${from.slice(0, 70)}`)
  return source.replace(from, to)
}

// [id, description, [[file, mutation], ...], phpunit filter]
const cases = [
  ['M1a', 'owner_unit_id artificial (code writes an owner unit on the location)', [['location', swap("'geocode_accuracy' => null,", "'geocode_accuracy' => null, 'owner_unit_id' => $unit,")]], 'test_l01_'],
  ['M2', 'scope bypass: any active link authorizes regardless of the actor scope', [['authority', swap('if (isset($covered[(int) $link->unit_id])) {', 'if (true) {')]], 'test_l05_'],
  ['M3', 'F-06 broken: OUT_OF_SCOPE no longer concealed', [['handler', (s) => { const start = s.indexOf('function (PhysicalError'); const i = s.indexOf("['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']", start); return s.slice(0, i) + "['TARGET_NOT_FOUND']" + s.slice(i + "['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']".length) }]], 'test_l05_'],
  ['M4', 'source_document_id accepted by the link request', [['linkRequest', swap("return ['unit_public_id'", "return ['source_document_id' => ['sometimes', 'nullable', 'integer'], 'unit_public_id'")]], 'test_source_document_id_'],
  ['M5', 'second active primary: demotion and the D10.3 invariant removed', [['link', swap('$demoted = $this->demotePrimary((int) $current->unit_id, (int) $current->id);', '$demoted = [];')], ['invariants', swap("throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['invariant' => 'D10.3_single_primary']);", '// mutated')]], 'test_l07_'],
  ['M6', 'last active link removable without transfer/close (service rule and D04 invariant removed)', [['link', swap('throw new PhysicalError(PhysicalReason::LAST_ACTIVE_LINK_REQUIRED);', '// mutated')], ['invariants', swap("throw new PhysicalError(PhysicalReason::LAST_ACTIVE_LINK_REQUIRED, ['invariant' => 'D04_operational_link']);", '// mutated')]], 'test_l08_'],
  ['M7', 'publish without PHYSICAL_LOCATION_PUBLISH', [['location', (s) => swap('lockForManage($guard, $actor, $id, PhysicalCatalog::LOCATION_PUBLISH)', 'lockForManage($guard, $actor, $id, PhysicalCatalog::LOCATION_VIEW)')(swap('$guard->requires(PhysicalCatalog::LOCATION_PUBLISH);', '$guard->requires(PhysicalCatalog::LOCATION_VIEW);')(s))]], 'test_l10_'],
  ['M8', 'public projection leaks address/owner/status fields', [['records', swap("'longitude' => (float) $row->longitude,", "'longitude' => (float) $row->longitude, 'status' => (string) $row->status, 'country_code' => $row->country_code ?? null,")]], 'test_l10_'],
  ['M9', 'plaintext fallback of the address line', [['runtime', swap("return $this->crypto()->encrypt($plaintext, $aad);\n        } catch (PeopleError $e) {\n            throw new PhysicalError(PhysicalReason::CRYPTO_UNAVAILABLE, ['reason' => (string) ($e->context['reason'] ?? 'crypto')]);", "return $this->crypto()->encrypt($plaintext, $aad);\n        } catch (PeopleError $e) {\n            return [$plaintext, 1];")]], 'test_l13_'],
  ['M10', 'Temple treated as an Organizational Unit (creates a Congregation)', [['temple', swap('$guard->touch(null, $location);', "$guard->touch(null, $location);\n            $this->rt->db->insert(\"INSERT INTO organizational_units (public_id, parent_id, unit_type_id, municipality_id, code, name, status, opened_on, closed_on, created_at, lock_version) SELECT ?, id, (SELECT id FROM organizational_unit_types WHERE code = 'CONGREGATION'), municipality_id, ?, ?, 'DRAFT', NULL, NULL, UTC_TIMESTAMP(6), 0 FROM organizational_units WHERE id = ?\", [(string) Str::ulid(), 'TEMPLE-' . $id, $name, $decision->unit]);")]], 'test_l04_'],
  ['M11', 'commit-time recheck removed', [['runtime', swap('$this->commitRecheck($user, $session, $guard);', '// mutated')]], 'test_commit_time_recheck_'],
  ['M12', 'collections unbounded', [['runtime', swap("$max = (int) ($this->settings['pagination']['max'] ?? 100);", '$max = PHP_INT_MAX;')]], 'test_collections_'],
]

if (!structuralOnly) {
  const control = phpunit('test_l01_|test_l04_|test_l05_|test_l07_|test_l08_|test_l10_|test_l13_|test_source_document_id_|test_commit_time_recheck_|test_collections_|test_catalog_is_idempotent_')
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
  if (!only || only.has('M1b')) {
    // Schema-level variant: an artificial owner_unit_id column in the pool must be caught by the tests AND by the
    // bidirectional schema-parity validator; the column is then dropped and parity re-verified.
    let run = null; let parity = null; let restored = null
    try {
      mysqlExec('ALTER TABLE physical_locations ADD COLUMN owner_unit_id BIGINT UNSIGNED NULL')
      run = phpunit('test_catalog_is_idempotent_')
      parity = spawnSync(process.execPath, [path.join(root, 'scripts/validate-p07-physical-schema.cjs')], { cwd: root, env: process.env, encoding: 'utf8' }).status
    } finally {
      mysqlExec("SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'physical_locations' AND COLUMN_NAME = 'owner_unit_id'); SET @s := IF(@c > 0, 'ALTER TABLE physical_locations DROP COLUMN owner_unit_id', 'SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;")
      restored = spawnSync(process.execPath, [path.join(root, 'scripts/validate-p07-physical-schema.cjs')], { cwd: root, env: process.env, encoding: 'utf8' }).status
    }
    probes.push({ id: 'M1b', description: 'owner_unit_id artificial column in the schema', status: run.exit_code !== 0 && parity !== 0 && restored === 0 ? 'PASS' : 'FAIL', mode: 'DB_MUTATION_EXPECTED_TEST_AND_PARITY_FAILURE', detected_by_tests: run.exit_code !== 0, detected_by_schema_parity: parity !== 0, schema_restored_parity_pass: restored === 0, ...run })
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
process.exit(result.status === 'PASS' ? 0 : 1)
