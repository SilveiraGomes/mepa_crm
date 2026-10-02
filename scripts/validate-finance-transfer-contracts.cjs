// P0.10-F1B interunit transfers / custody / contributions contract validator (ADR 0021 + D-04A).
//  - structural checks G01-G13 over routes, requests, services, handler, output, UI and the contract;
//  - M1..M14 negative probes: each mutates the real source (service, catalog, request, handler or runtime), runs the
//    targeted HTTP tests of FinanceTransfersTest against the isolated pool and EXPECTS them to fail, then restores the
//    file byte-for-byte (SHA-256). A CONTROL run (no mutation) of every detector must pass first.
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
  transfers: D + 'InternalTransferService.php', contributions: D + 'ContributionService.php', queries: D + 'FinanceQueryService.php', runtime: D + 'FinanceRuntime.php',
  authority: D + 'FinanceAuthority.php', guard: D + 'FinanceGuard.php', audit: D + 'FinanceAudit.php', catalog: D + 'FinanceCatalog.php', ledger: D + 'LedgerPostingService.php',
  handler: 'apps/api/app/Exceptions/Handler.php', routes: 'apps/api/routes/api.php', output: 'apps/api/app/Http/Finance/FinanceOutput.php',
  createRequest: 'apps/api/app/Http/Requests/Finance/TransferCreateRequest.php', baseRequest: 'apps/api/app/Http/Requests/Finance/FinanceRequest.php',
  transferController: 'apps/api/app/Http/Controllers/Api/V1/Finance/FinanceTransferController.php', controller: 'apps/api/app/Http/Controllers/Api/V1/Finance/FinanceController.php',
  config: 'apps/api/config/finance.php', test: 'apps/api/tests/DatabaseV2/FinanceTransfersTest.php', contracts: 'docs/contracts/finance_core_contracts.json',
  pages: 'apps/web/src/pages/FinancePages.tsx', format: 'apps/web/src/lib/finance/format.ts', worker: 'scripts/p010-transfer-worker.php',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')
const requestsDir = path.join(root, 'apps/api/app/Http/Requests/Finance')

function methodBody(text, name) {
  const start = text.indexOf(`public function ${name}(`)
  if (start < 0) return ''
  const next = text.indexOf('\n    public function ', start + 10)
  const priv = text.indexOf('\n    private function ', start + 10)
  const ends = [next, priv].filter((i) => i > 0)
  return text.slice(start, ends.length ? Math.min(...ends) : undefined)
}

function checks(f) {
  const contract = JSON.parse(f.contracts)
  // The Finance route group ends at the next prefix group (P0.10-F2A added the 'hr' group right after it).
  const financeStart = f.routes.indexOf("Route::prefix('finance')")
  const nextGroup = ["Route::prefix('hr')", "Route::prefix('physical')"].map((g) => f.routes.indexOf(g, financeStart)).filter((i) => i > financeStart)
  const block = f.routes.slice(financeStart, Math.min(...nextGroup))
  // F1B routes are the transfer / custody / contribution endpoints (F1C adds its own, validated by the F1C validator).
  const routes = [...block.matchAll(/Route::(get|post|patch|put|delete)\('([^']+)'/g)].map(([, m, u]) => `${m.toUpperCase()} ${u}`).filter((r) => /^(GET|POST|PATCH|PUT|DELETE) (context|units|transfers|contributions)/.test(r))
  const requests = fs.readdirSync(requestsDir).map((file) => fs.readFileSync(path.join(requestsDir, file), 'utf8'))
  const stages = ['request', 'send', 'receive', 'cancel', 'reverseSend', 'reconcile']
  const firstStatementRequires = stages.every((m) => /->write\(\$user, \$session, function \([^)]*\)[^{]*\{\s*\n\s*\$guard->requires\(/.test(methodBody(f.transfers, m)))
    && ['valuate', 'approveValuation'].every((m) => /\{\s*\n\s*\$guard->requires\(/.test(methodBody(f.contributions, m)))
  const preauthorizedBeforeLocks = ['send', 'receive', 'reverseSend', 'cancel'].every((m) => { const b = methodBody(f.transfers, m); const p = b.indexOf('preauthorize('); const l = b.search(/lockPostingPeriod\(|lockTransfer\(/); return p > 0 && l > p })
  const receive = methodBody(f.transfers, 'receive')
  const testNames = new Set([...f.test.matchAll(/public function (test_\w+)\(/g)].map((m) => m[1]))
  const f1b = contract.f1b_contracts || {}
  return {
    G01_explicit_stage_routes_only: routes.length === 17 && !routes.some((r) => /^(PATCH|PUT|DELETE)/.test(r)) && ['POST transfers/{transfer}/send', 'POST transfers/{transfer}/receive', 'POST transfers/{transfer}/reverse-send', 'POST transfers/{transfer}/reconcile', 'POST transfers/{transfer}/cancel'].every((r) => routes.includes(r)) && /throttle:finance-write/.test(block),
    G02_closed_field_lists_no_internal_ids: /array_diff\(array_keys\(\$this->all\(\)\), \$allowed\)/.test(f.baseRequest) && requests.every((t) => !/'\w+_id'\s*=>/.test(t)) && requests.filter((t) => /final class/.test(t)).every((t) => /extends FinanceRequest/.test(t)),
    G03_permission_before_target_f06: firstStatementRequires,
    G04_scope_before_locks_and_state: preauthorizedBeforeLocks && /OUT_OF_SCOPE/.test(methodBody(f.transfers, 'reconcile')),
    G05_single_journal_writer: !/table\('journal_(entries|lines)'\)[^;]*->(insert|update|delete)/.test(f.transfers + f.contributions + f.queries) && /postSubledgerEntry\(/.test(f.transfers) && /postSubledgerEntry\(/.test(f.contributions),
    G06_interunit_clearing_never_result: /'account' => 'INTERUNIT_CLEARING_OUT'/.test(methodBody(f.transfers, 'send')) && /'account' => 'INTERUNIT_CLEARING_IN'/.test(receive) && !/OPERATING_INCOME|OPERATING_EXPENSE|INVESTMENT_EXPENSE|OPENING_NET_ASSETS/.test(f.transfers),
    G07_receive_amount_is_the_send_amount: /AMOUNT_MISMATCH/.test(receive) && /Money::fromDecimal\(\(string\) \$transfer->amount\)/.test(receive),
    G08_locking_reads_for_decisions: /posting_stage', \$stage\)->sharedLock\(\)/.test(f.transfers) && /FOR SHARE/.test(f.transfers) && /pairing\(\$this->rt->db, \$transfer, true\)/.test(f.transfers),
    G09_bounded_collections_safe_output: /'max' => 100/.test(f.config) && /\$value < 1 \|\| \$value > \$max/.test(f.runtime) && /assertSafe/.test(f.output) && /FinanceOutput::(page|item)|assertSafe/.test(f.controller + f.transferController),
    G10_concealed_404_single_shape: /in_array\(\$e->reason, \['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\], true\)\) \{\s*\n[^\n]*\n\s*return response\(\)->json\(\['error' => \['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found\.'\]\], 404\);/.test(f.handler.slice(f.handler.indexOf('FinanceError $e'))),
    G11_ui_never_calls_transfers_revenue_no_float: !/parseFloat|Number\(\s*(row|t|c)\.\w*amount/.test(f.pages) && /formatKz/.test(f.pages) && !/'Receita interna'|'Despesa interna'/.test(f.pages) && !/parseFloat|Number\(/.test(f.format),
    G12_contract_maps_existing_tests: Object.keys(f1b.tests || {}).length === 28 && Object.keys(f1b.mutation_probes || {}).length === 14 && Object.values(f1b.tests || {}).every((t) => t === 'PLAYWRIGHT' || t.split('|').every((n) => testNames.has(n))),
    G13_audit_actions: ['finance.transfer_requested', 'finance.transfer_sent', 'finance.transfer_received', 'finance.transfer_cancelled', 'finance.transfer_reconciled'].every((a) => f.transfers.includes(`'${a}'`)) && /'finance.contribution_recorded'/.test(f.contributions) && /'finance.contributor_detail_viewed'/.test(f.queries) && /\$actor->session/.test(f.transfers),
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
const sub = (from, to) => (s) => { if (!s.includes(from)) throw new Error('mutation anchor not found: ' + from.slice(0, 70)); return s.split(from).join(to) }
function phpunit(filter) {
  const started = Date.now()
  const r = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', '--filter', filter, 'tests/DatabaseV2/FinanceTransfersTest.php'], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 900000 })
  const summary = (r.stdout || '').split(/\r?\n/).filter((l) => /^(OK \(|Tests:|FAILURES|ERRORS)/.test(l)).pop() || ''
  return { detector: `phpunit --filter ${filter}`, exit_code: r.status, summary, seconds: Math.round((Date.now() - started) / 100) / 10 }
}

const cases = [
  ['M1', 'SEND classified as revenue', [['transfers', sub("['account' => 'INTERUNIT_CLEARING_OUT', 'counterparty_unit_id' => (int) $transfer->destination_unit_id, 'category' => (string) $purpose->code, 'debit' => Money::format($amount)]", "['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'debit' => Money::format($amount)]")]], 'test_t03'],
  ['M2', 'RECEIVE classified as revenue', [['transfers', sub("['account' => 'INTERUNIT_CLEARING_IN', 'counterparty_unit_id' => (int) $transfer->origin_unit_id, 'category' => (string) $purpose->code, 'credit' => Money::format($amount)]", "['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => Money::format($amount)]")]], 'test_t06'],
  ['M3', 'SEND clearing account classified as EQUITY instead of INTERUNIT_CONTROL', [['catalog', sub("'INTERUNIT_CLEARING_OUT' => [self::INTERUNIT_CONTROL, 'DEBIT'", "'INTERUNIT_CLEARING_OUT' => [self::EQUITY, 'CREDIT'")]], 'test_t03'],
  ['M4', 'RECEIVE amount may differ from SEND', [['transfers', sub("throw new FinanceError('AMOUNT_MISMATCH');", '// amount check removed')]], 'test_t06'],
  ['M5', 'duplicate SEND not prevented', [['transfers', (s) => sub("            if (in_array($transfer->status, ['SENT', 'RECEIVED'], true) && $this->posting((int) $transfer->id, 'SEND') !== null) {\n                return ['replayed' => true];\n            }\n            $this->assertState($transfer, 'DRAFT', $in);", "            if (false) {\n            }")(s)]], 'test_t12'],
  ['M6', 'duplicate RECEIVE not prevented', [['transfers', (s) => sub("            if ($transfer->status === 'RECEIVED' && $this->posting((int) $transfer->id, 'RECEIVE') !== null) {\n                return ['replayed' => true, 'posted_on' => null];\n            }\n            $this->assertState($transfer, 'SENT', $in);", "            if (false) {\n            }")(s)]], 'test_t12'],
  ['M7', 'SEND authorized on the wrong side (destination)', [['transfers', (s) => { const b = methodBody(s, 'send'); return s.replace(b, b.replace("$this->preauthorize($actor, [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], (int) $peek->origin_unit_id);", '').replace("(int) $transfer->origin_unit_id);\n            if (in_array", "(int) $transfer->destination_unit_id);\n            if (in_array")) }]], 'test_t14'],
  ['M8', 'RECEIVE authorized on the wrong side (origin)', [['transfers', (s) => { const b = methodBody(s, 'receive'); return s.replace(b, b.replace("$this->preauthorize($actor, [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST], $destinationUnit);", '').replace("(int) $transfer->destination_unit_id);\n            if ($transfer->status === 'RECEIVED'", "(int) $transfer->origin_unit_id);\n            if ($transfer->status === 'RECEIVED'")) }]], 'test_t14'],
  ['M9', 'F-06 oracle: out-of-scope distinguishable from nonexistent', [['handler', (s) => { const i = s.indexOf('FinanceError $e'); return s.slice(0, i) + s.slice(i).replace("in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)", "$e->reason === 'TARGET_NOT_FOUND'") }]], 'test_t14'],
  ['M10', 'reconciliation without RECEIVE', [['transfers', (s) => { const b = methodBody(s, 'reconcile'); return s.replace(b, b.replace("$this->assertState($transfer, 'RECEIVED', $in);", '').replace('$mismatches = self::pairing($this->rt->db, $transfer, true);', '$mismatches = [];')) }]], 'test_t22'],
  ['M11', 'REVERSE_SEND allowed after RECEIVE', [['transfers', (s) => { const b = methodBody(s, 'reverseSend'); return s.replace(b, b.replace("throw new FinanceError('ALREADY_RECEIVED');", '// guard removed').replace("$this->assertState($transfer, 'SENT', $in);", '')) }]], 'test_t25'],
  ['M12', 'a transfer mutates the original external revenue', [['transfers', sub("            $this->recordPosting((int) $transfer->id, 'SEND', (int) $entry['id']);", "            $this->recordPosting((int) $transfer->id, 'SEND', (int) $entry['id']);\n            $this->rt->db->table('contributions')->where('receiving_unit_id', $transfer->origin_unit_id)->update(['status' => 'CANCELLED']);")]], 'test_t19'],
  ['M13', 'numeric source_document id accepted', [['createRequest', sub("'document' => ['sometimes', 'nullable', 'string', 'size:26']", "'document' => ['sometimes', 'nullable', 'string', 'size:26'], 'document_id' => ['sometimes'], 'source_document_id' => ['sometimes']")]], 'test_t16'],
  ['M14', 'unbounded transfer list', [['runtime', sub('if ($value < 1 || $value > $max) {', 'if ($value < 1) {')]], 'test_t28'],
]

const probes = []
if (!structuralOnly) {
  const control = phpunit(cases.filter(([id]) => !only || only.has(id)).map(([, , , f]) => f).filter((v, i, a) => a.indexOf(v) === i).join('|'))
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
  status: failures.length === 0 && restoredBytes && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base, failures, mutation_probes: probes,
  probes_detected: probes.filter((p) => p.status === 'PASS' && p.id !== 'CONTROL').length, probes_run: probes.filter((p) => p.id !== 'CONTROL').length,
  source_hashes_before: hashesBefore, source_hashes_after: hashesAfter, source_restored_byte_for_byte: restoredBytes,
}
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(result.status === 'PASS' ? 0 : 1)
