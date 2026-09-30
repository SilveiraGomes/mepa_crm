// P0.8 Documents/Files contract validator (ADR 0019).
//  - structural checks over the Files sources, routes, handler, requests, config, contract and UI;
//  - M1..M14 negative probes: each mutates the real source, runs the targeted PHPUnit tests of FilesVerticalTest and
//    EXPECTS a detection (a failing test, or — for configuration-level mutations — a failing structural check as well),
//    then restores every file byte-for-byte (SHA-256). A control run (no mutation) must pass first.
// Env for probes: WAVE5_DSN / WAVE5_USER / WAVE5_PASSWORD / WAVE5_ALLOW_SYNTHETIC=1 (isolated pool).
// Flags: --structural-only, --only M3,M9, --output <file>
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const PHP = process.env.MEPA_PHP_BIN || 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const D = 'apps/api/app/Domain/Files/'
const paths = {
  classification: D + 'FileClassification.php', records: D + 'FileRecords.php', consumers: D + 'FilesConsumers.php', pipeline: D + 'FileUploadPipeline.php',
  content: D + 'FileContentService.php', service: D + 'FileService.php', documents: D + 'DocumentService.php', runtime: D + 'FilesRuntime.php',
  mepaf: D + 'Mepaf1.php', storage: D + 'FileStorage.php', ring: D + 'FilesKeyRing.php', inspector: D + 'FileInspector.php', audit: D + 'FilesAudit.php',
  authority: D + 'FilesAuthority.php', catalog: D + 'FilesCatalog.php', maintenance: D + 'FilesMaintenance.php',
  factory: 'apps/api/app/Http/Files/FilesServiceFactory.php', output: 'apps/api/app/Http/Files/FilesOutput.php', handler: 'apps/api/app/Exceptions/Handler.php',
  routes: 'apps/api/routes/api.php', filesystems: 'apps/api/config/filesystems.php', config: 'apps/api/config/files.php',
  instructorRequest: 'apps/api/app/Http/Requests/Academy/InstructorAssignRequest.php', academyGuard: 'apps/api/app/Domain/Academy/AcademyResourceGuard.php',
  contracts: 'docs/contracts/documents_files_contracts.json', manifest: 'docs/database/physical/p08_files_delta_manifest.json',
  app: 'apps/web/src/App.tsx', shell: 'apps/web/src/layout/AppShell.tsx', pages: 'apps/web/src/pages/FilesPages.tsx', docPages: 'apps/web/src/pages/DocumentsPages.tsx', client: 'apps/web/src/lib/files/client.ts',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')
const requestsDir = path.join(root, 'apps/api/app/Http/Requests/Files')

function checks(f) {
  const domain = Object.entries(f).filter(([k]) => paths[k].startsWith(D)).map(([, v]) => v).join('\n')
  const contract = JSON.parse(f.contracts)
  const requests = fs.readdirSync(requestsDir).map((file) => fs.readFileSync(path.join(requestsDir, file), 'utf8')).join('\n')
  const routeBlock = f.routes.slice(f.routes.indexOf("Route::prefix('files')"), f.routes.indexOf('// P0.7 Physical Locations'))
  const routes = []
  for (const [prefix, block] of [['files', routeBlock.slice(0, routeBlock.indexOf("Route::prefix('documents')"))], ['documents', routeBlock.slice(routeBlock.indexOf("Route::prefix('documents')"))]]) {
    for (const [, method, uri] of block.matchAll(/Route::(get|post|patch|put|delete)\('([^']+)'/g)) routes.push(`${method.toUpperCase()} /api/v1/${prefix}/${uri === '/' ? '' : uri}`)
  }
  const filesHandler = f.handler.slice(f.handler.indexOf('function (FilesError'), f.handler.indexOf('P0.7 Physical Locations (ADR-0018)'))
  const disk = (f.filesystems.match(/'files_private' => \[[\s\S]*?\],/) || [''])[0]
  return {
    S01_closed_ordered_classification: /ORDER = \[self::INTERNAL => 1, self::RESTRICTED => 2, self::CONFIDENTIAL => 3, self::HIGHLY_SENSITIVE => 4\]/.test(f.classification) && /DEFAULT_UPLOAD = self::RESTRICTED/.test(f.classification),
    S02_unknown_classification_fails_closed: /if \(!self::isKnown\(\$input\)\) \{\s*throw new FilesError\(FilesReason::CLASSIFICATION_INVALID/.test(f.classification) && /return self::isKnown\(\$classification\) && self::ORDER\[\$classification\] <= \$clearance;/.test(f.classification),
    S03_private_disk_no_url: /'driver' => 'local'/.test(disk) && !/'url'/.test(disk) && /'visibility' => 'private'/.test(disk) && /public_path\(\)/.test(f.factory),
    S04_random_storage_key: /bin2hex\(random_bytes\(16\)\)/.test(f.storage) && /'v1\/' \. substr\(\$utcNow, 0, 4\)/.test(f.storage),
    S05_output_guard: /'storage_key'/.test(f.output) && /'checksum'/.test(f.output) && /'disk'/.test(f.output) && /internal_field_in_response/.test(f.output) && !/storage_key|checksum/.test((f.records.match(/public function projectFile[\s\S]*?\n    }\n/) || [''])[0]),
    S06_mepaf1_secretstream: /MAGIC = 'MEPAF1'/.test(f.mepaf) && /CHUNK = 65536/.test(f.mepaf) && /crypto_secretstream_xchacha20poly1305_init_push/.test(f.mepaf) && /TAG_FINAL/.test(f.mepaf) && /crypto_aead_xchacha20poly1305_ietf_encrypt\(\$dek/.test(f.mepaf) && /sodium_memzero\(\$dek\)/.test(f.mepaf),
    S07_no_plaintext_fallback: /Mepaf1::encrypt\(\$in, \$out, \$publicId, \$ring\)/.test(f.pipeline) && !/stream_copy_to_stream/.test(f.pipeline),
    S08_integrity_before_stream: /\$this->authenticate\(\$row\);/.test(f.content) && /INTEGRITY_FAILURE, 'checksum'/.test(f.content),
    S09_final_gate_verifies_object: /if \(\$rejection === null && !\$this->verifyStored\(\$row\)\)/.test(f.pipeline),
    S10_quota_locked: /\$this->rt->lockUnits\(\[\$ownerUnit\]\);/.test(f.pipeline) && /\$this->rt->nationalQuotaLock\(\);/.test(f.pipeline) && (f.runtime.match(/FOR SHARE", /g) || []).length + (f.runtime.match(/FOR SHARE"\)/g) || []).length >= 2,
    S11_f06_concealment: /\['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\][\s\S]*?files_http_concealed[\s\S]*?RESOURCE_NOT_FOUND[\s\S]*?404/.test(filesHandler),
    S12_permission_before_target: /requireAnywhere\(\$actor, FilesCatalog::FILES_DOWNLOAD\);\s*\$id = \$this->records->fileId/.test(f.content) && (f.service.match(/\$guard->requires\(/g) || []).length >= 6,
    S13_consumer_fail_closed: /consumer_without_adapter/.test(f.consumers) && /information_schema\.KEY_COLUMN_USAGE/.test(f.consumers),
    S14_no_purge_operation: !/Route::delete/.test(routeBlock) && !/purge/i.test(routeBlock) && (domain.match(/\['status' => FilesCatalog::PURGED, 'purged_at'/g) || []).length === 1 && /if \(\(string\) \$row->status !== FilesCatalog::QUARANTINED\) \{\s*return \$row;/.test(f.pipeline),
    S15_quarantine_not_downloadable: /\$available = \$status === FilesCatalog::AVAILABLE && \$file->deleted_at === null && \$file->purged_at === null;/.test(f.records) && /assertVisible\(\$actor, \$row, FilesCatalog::FILES_DOWNLOAD, true, true\)/.test(f.content),
    S16_version_monotonic_locked: /\$next = \$current === null \? 1 : \(int\) \$current->version \+ 1;/.test(f.documents) && /orderByDesc\('dv\.version'\)->lockForUpdate\(\)/.test(f.documents) && !/table\('document_versions'\)->where\([^)]*\)->update/.test(f.documents),
    S17_dedup_same_unit_authorized: /where\('owner_unit_id', \$row->owner_unit_id\)->where\('checksum', \$row->checksum\)/.test(f.service) && /DUPLICATE_CONTENT_IN_UNIT/.test(f.service),
    S18_audit_forbidden_keys: /'original_name'/.test(f.audit) && /'storage_key'/.test(f.audit) && /'checksum'/.test(f.audit) && /'kek'/.test(f.audit),
    S19_routes_match_contract_bidirectionally: JSON.stringify([...routes].sort()) === JSON.stringify([...contract.endpoints].sort()),
    S20_requests_never_accept_internal_ids: !/'(file_id|document_id|owner_unit_id|storage_key|disk|checksum|mime_type|source_document_id)'\s*=>/.test(requests),
    S21_p08_d_f01_public_id_only: /'source_document' => \['sometimes','nullable','string','max:64'\]/.test(f.instructorRequest) && !/'source_document_id'/.test(f.instructorRequest) && /function legalDocument\(AcademyTarget \$target, string \$publicId\)/.test(f.academyGuard),
    S22_catalog_nine_plus_eight_no_roles: Object.keys(JSON.parse(JSON.stringify(contract.catalog.permissions))).length === 9 && contract.catalog.legal_document_types.length === 8 && !/table\('roles'\)|role_permissions/.test(f.catalog) && JSON.parse(f.manifest).schema_changes.length === 0 && JSON.parse(f.manifest).controlled_data.length === 17,
    S23_keyring_outside_code: /FileStorage::isInside\(\$real, \$forbiddenRoots\)/.test(f.ring) && /keyring_not_configured/.test(f.ring),
    S24_ui_section_and_routes: /Documentos e Ficheiros/.test(f.shell) && contract.ui.routes.every((route) => f.app.includes(`path="${route}"`)) && !/storage_key|checksum|files_private/.test(f.pages + f.docPages) && /cache: 'no-store'/.test(f.client) && /X-Access-Reason/.test(f.client),
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
  const result = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', 'tests/DatabaseV2/FilesVerticalTest.php', '--filter', filter], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 900000 })
  const out = `${result.stdout || ''}${result.stderr || ''}`
  return { filter, exit_code: result.status, seconds: Math.round((Date.now() - started) / 100) / 10, summary: (out.match(/^(OK \(.*\)|Tests: .*)$/m) || [''])[0], failure_head: (out.match(/^1\) [\s\S]{0,300}/m) || [''])[0].split('\n').slice(0, 3).join(' | ') }
}

function mutate(key, fn) {
  const source = originals[key].toString('utf8')
  const changed = fn(source)
  if (changed === source) throw new Error(`mutation of ${key} did not alter its target`)
  fs.writeFileSync(abs(key), changed)
}
const swap = (from, to) => (source) => {
  if (source.split(from).length !== 2) throw new Error(`target not found exactly once: ${from.slice(0, 80)}`)
  return source.replace(from, to)
}

// [id, description, [[file, mutation], ...], phpunit filter]
const cases = [
  ['M1', 'unknown classification treated permissively (mapped to the default)', [['classification', swap("        if (!self::isKnown($input)) {\n            throw new FilesError(FilesReason::CLASSIFICATION_INVALID, ['field' => 'classification']);\n        }\n        return $input;", "        return self::isKnown($input) ? $input : self::DEFAULT_UPLOAD;")]], 'test_f08_'],
  ['M2', 'storage_key exposed in the file projection (and removed from the output guard)', [['records', swap("'inspection' => FileInspector::INSPECTION_STRUCTURAL,", "'inspection' => FileInspector::INSPECTION_STRUCTURAL, 'storage_key' => (string) $row->storage_key,")], ['output', swap("'id', 'disk', 'storage_key', 'checksum'", "'id', 'disk', 'checksum'")]], 'test_f01_'],
  ['M3', 'direct public URL on the private disk', [['filesystems', swap("            'visibility' => 'private',\n            'throw' => true,", "            'visibility' => 'public',\n            'url' => env('APP_URL') . '/files',\n            'throw' => true,")]], 'test_context_'],
  ['M4', 'scope bypass: owner unit coverage no longer checked', [['records', swap("if (!isset($this->rt->authority->covered($actor, $permission, $lock)[$unit])) {\n            throw $conceal('scope');", "if (false) {\n            throw $conceal('scope');")]], 'test_f07_'],
  ['M5', 'classification bypass: clearance no longer checked', [['records', swap("if (!FileClassification::allows($this->rt->authority->clearance($actor, $unit, $lock), $file->classification)) {\n            throw $conceal('clearance');", "if (false) {\n            throw $conceal('clearance');")]], 'test_f08_'],
  ['M6', 'QUARANTINED downloadable', [['records', swap("$available = $status === FilesCatalog::AVAILABLE && $file->deleted_at === null", "$available = in_array($status, [FilesCatalog::AVAILABLE, FilesCatalog::QUARANTINED], true) && $file->deleted_at === null")]], 'test_f19_storage'],
  ['M7', 'plaintext fallback: content stored unencrypted', [['pipeline', swap("$sealed = Mepaf1::encrypt($in, $out, $publicId, $ring);", "stream_copy_to_stream($in, $out); $sealed = ['sha256' => hash('sha256', $content, true), 'bytes' => strlen($content), 'key_version' => $ring->activeVersion()];")]], 'test_f01_'],
  ['M8', 'integrity check removed before streaming', [['content', swap('                $this->authenticate($row);\n', '')]], 'test_f13_'],
  ['M9', 'source_document PK accepted by the Academy request', [['instructorRequest', swap("'source_document' => ['sometimes','nullable','string','max:64']", "'source_document' => ['sometimes','nullable','string','max:64'], 'source_document_id' => ['sometimes','nullable','integer','min:1']")]], 'test_f18_'],
  ['M10', 'consumer authority bypass: consumers without an adapter allowed', [['consumers', swap("                throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'consumer_without_adapter', 'consumer' => $ref]);", '                continue;')]], 'test_f21_'],
  ['M11', 'version overwrite: the current version row is repointed instead of a new version', [['documents', swap("            $this->insertVersion($actor, $documentId, (string) $document->public_id, $unit, $file, $next, $current, $in['issued_on'] ?? null, null, isset($in['file_public_id']));", "            $this->rt->db->table('document_versions')->where('id', $current->id)->update(['file_id' => (int) $file->id]);")]], 'test_f15_'],
  ['M12', 'quota lock removed (no unit lock, no national lock, snapshot reads)', [['pipeline', (s) => swap('                $this->rt->nationalQuotaLock();\n', '')(swap('                $this->rt->lockUnits([$ownerUnit]);\n', '')(s))], ['runtime', (s) => s.split('FOR SHARE", [$unit]').join('", [$unit]').split('FOR SHARE")').join('")')]], 'test_f20_'],
  ['M13', 'AVAILABLE despite a missing object (final gate no longer verifies the stored object)', [['pipeline', swap('if ($rejection === null && !$this->verifyStored($row)) {', 'if (false) {')]], 'test_f19_storage'],
  ['M14', 'cross-unit dedup leak: warning on any checksum match, without re-authorizing the existing file', [['service', (s) => swap("            try {\n                $this->records->assertVisible($actor, $candidate, FilesCatalog::FILES_VIEW);\n                return ['DUPLICATE_CONTENT_IN_UNIT'];\n            } catch (FilesError) {\n                continue;\n            }", "            return ['DUPLICATE_CONTENT_IN_UNIT'];")(swap("$candidates = $this->rt->db->table('files')->where('owner_unit_id', $row->owner_unit_id)->where('checksum', $row->checksum)", "$candidates = $this->rt->db->table('files')->where('checksum', $row->checksum)")(s))]], 'test_f16_'],
]

if (!structuralOnly) {
  const control = phpunit('test_f01_|test_f07_|test_f08_|test_f13_|test_f15_|test_f16_|test_f18_|test_f19_storage|test_f20_|test_f21_|test_context_')
  probes.push({ id: 'CONTROL', status: control.exit_code === 0 ? 'PASS' : 'FAIL', mode: 'NO_MUTATION_EXPECTED_PASS', ...control })
  for (const [id, description, mutations, filter] of cases) {
    if (only && !only.has(id)) continue
    try {
      for (const [key, fn] of mutations) mutate(key, fn)
      const structural = checks(read())
      const brokenChecks = Object.entries(structural).filter(([, ok]) => !ok).map(([name]) => name)
      const run = phpunit(filter)
      const detectedByTests = run.exit_code !== 0
      probes.push({ id, description, status: detectedByTests ? 'PASS' : 'FAIL', mode: 'ON_DISK_MUTATION_EXPECTED_TEST_FAILURE', files: [...new Set(mutations.map(([key]) => paths[key]))], detected: detectedByTests, detected_by_tests: detectedByTests, detected_by_structural_checks: brokenChecks, ...run })
    } catch (error) {
      probes.push({ id, description, status: 'FAIL', error: String(error.message || error) })
    } finally {
      for (const [key] of mutations) fs.writeFileSync(abs(key), originals[key])
    }
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
