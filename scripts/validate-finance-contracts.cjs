// P0.10-F1A Finance Core contract validator (ADR 0021 + D-04A).
//  - structural checks F01-F12 over the Finance sources, migrations, manifest and docs/contracts/finance_core_contracts.json;
//  - M1..M12 mutation probes: each mutates the real source (a domain file, the catalog installer or a migration), runs its
//    detector and EXPECTS it to fail, then restores the file byte-for-byte (SHA-256). Domain probes are detected by the
//    targeted PHPUnit tests of FinanceCoreFoundationTest against the isolated pool; schema probes (M10-M12) by the
//    catalog <-> migrations parity of validate-p010-finance-schema.cjs --static-only. A CONTROL run (no mutation) of both
//    detectors must pass first, so a probe's failure is caused by its mutation.
// Env for probes: WAVE5_DSN / WAVE5_USER / WAVE5_PASSWORD / WAVE5_ALLOW_SYNTHETIC=1 (isolated mepa_wave5_test_* pool).
// Flags: --structural-only, --only M3,M9, --output <file>
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')

const root = path.resolve(__dirname, '..')
const PHP = process.env.MEPA_PHP_BIN || 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const D = 'apps/api/app/Domain/Finance/'
const M = 'apps/api/database/migrations/'
const paths = {
  catalog: D + 'FinanceCatalog.php', ledger: D + 'LedgerPostingService.php', periods: D + 'FinancePeriods.php', queries: D + 'LedgerQueries.php', money: D + 'Money.php',
  audit: D + 'FinanceAudit.php', error: D + 'FinanceError.php', test: 'apps/api/tests/DatabaseV2/FinanceCoreFoundationTest.php', worker: 'scripts/p010-finance-worker.php',
  contracts: 'docs/contracts/finance_core_contracts.json', manifest: 'docs/database/physical/p010_finance_delta_manifest.json', pool: 'apps/api/tests/DatabaseV2/Support/migrate_pool_db.php',
  accounts: M + '2026_09_30_100006_p010_create_accounts.php', entries: M + '2026_09_30_100010_p010_create_journal_entries.php', lines: M + '2026_09_30_100011_p010_create_journal_lines.php',
  budgets: M + '2026_09_30_100020_p010_create_budgets.php', installer: M + '2026_09_30_100022_p010_install_finance_catalog.php',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')
const appDir = path.join(root, 'apps/api/app')
const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => e.isDirectory() ? walk(path.join(dir, e.name)) : e.name.endsWith('.php') ? [path.join(dir, e.name)] : [])

function checks(f) {
  const contract = JSON.parse(f.contracts)
  const manifest = JSON.parse(f.manifest)
  const allApp = walk(appDir).map((file) => [path.relative(root, file).replace(/\\/g, '/'), fs.readFileSync(file, 'utf8')])
  const domain = [f.catalog, f.ledger, f.periods, f.queries, f.money, f.audit].join('\n')
  const journalWriters = allApp.filter(([, text]) => /table\('journal_(entries|lines)'\)[^;]*->(insert|insertGetId|update|delete|upsert|insertOrIgnore)\(/.test(text)).map(([file]) => file)
  const ledgerDeletes = f.ledger.match(/table\('journal_lines'\)->where\('entry_id', \$entry->id\)->delete\(\)/g) || []
  const replaceBlock = f.ledger.slice(f.ledger.indexOf('public function replaceDraftLines'), f.ledger.indexOf('public function submit'))
  const entryUpdates = [...f.ledger.matchAll(/table\('journal_entries'\)->where\('id', \$entry->id\)(\s*->whereIn\('status', [^)]+\))?\s*->update\(/g)]
  const testNames = new Set([...f.test.matchAll(/public function (test_\w+)\(/g)].map((m) => m[1]))
  const declaredTests = [...Object.values(contract.accounting_contracts), ...Object.values(contract.schema_contracts), ...Object.values(contract.concurrency)].map((c) => c.test)
  const assertBlock = f.ledger.slice(f.ledger.indexOf('private function assertPostable'), f.ledger.indexOf('private function assertReversal'))
  return {
    F01_single_journal_writer: JSON.stringify(journalWriters) === JSON.stringify([paths.ledger]),
    F02_line_delete_only_while_draft: ledgerDeletes.length === 1 && replaceBlock.includes("if ($entry->status !== FinanceCatalog::DRAFT) {") && replaceBlock.indexOf('ENTRY_NOT_EDITABLE') < replaceBlock.indexOf('->delete()'),
    F03_header_updates_are_state_guarded: entryUpdates.length === 3 && (f.ledger.match(/->whereIn\('status', \[FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED\]\)\s*->update\(\['status' => FinanceCatalog::POSTED/g) || []).length === 1 && /->whereIn\('status', \$from\)->update\(\$values\)/.test(f.ledger),
    F04_posting_gate_complete: ['UNBALANCED', 'ENTRY_TOTAL_NOT_POSITIVE', 'TOO_FEW_LINES', 'CURRENCY_NOT_SUPPORTED', 'PERIOD_CLOSED', 'UNIT_MISMATCH', 'LEDGER_ACCOUNT_NOT_POSTABLE', 'FINANCIAL_ACCOUNT_CLOSED', 'TRANSFER_TOUCHES_RESULT', 'INTERUNIT_ONLY_BY_TRANSFER', 'CATEGORY_LEDGER_MISMATCH', 'ENTRY_DATE_IN_FUTURE', 'REVERSAL_NOT_INVERSE']
      .every((r) => assertBlock.includes(`'${r}'`) || f.ledger.includes(`'${r}'`)) && /SUM\(debit\) = SUM\(credit\)/.test(assertBlock),
    F05_no_float_money: !/\(float\)|floatval\(|round\(|number_format\(/.test(domain) && /string\|int \$value/.test(f.money) && /AMOUNT_SCALE/.test(f.money),
    F06_no_stored_balance: !/balance/i.test(f.accounts.replace(/^\/\/.*$/gm, '')) && /where\('e\.status', FinanceCatalog::POSTED\)/.test(f.queries),
    F07_catalog_role_free_nothing_statutory: !/table\('(roles|role_permissions|user_role_scopes|accounting_periods|accounts)'\)/.test(f.catalog) && !/\b(rate|percent|taxa)\b/i.test(f.catalog.replace(/^\s*\/\/.*$/gm, '').replace(/\/\*\*[\s\S]*?\*\//g, '')) && /FINANCE_CATALOG_CONFLICT/.test(f.catalog) && /FinanceCatalog::install/.test(f.installer),
    F08_d04a_vocabulary: /'INTERUNIT_CLEARING_OUT' => \[self::INTERUNIT_CONTROL, 'DEBIT'/.test(f.catalog) && /'INTERUNIT_CLEARING_IN' => \[self::INTERUNIT_CONTROL, 'CREDIT'/.test(f.catalog)
      && (f.catalog.match(/'INV_AST_\w+' => \['[^']+', 'INVESTMENT', 'FIXED_ASSETS'\]/g) || []).length === 7 && ['TRF_REMITTANCE', 'TRF_BUDGET_QUOTA', 'TRF_SPECIAL_CONTRIBUTION', 'TRF_SUPPORT', 'TRF_PROJECT', 'TRF_OTHER'].every((c) => f.catalog.includes(`'${c}' => [`)),
    F09_national_close_irreversible: !/reopenNational|function reopen\w*National/.test(f.periods) && /lockForUpdate/.test(f.periods),
    F10_contract_maps_existing_tests: Object.keys(contract.accounting_contracts).length === 16 && Object.keys(contract.schema_contracts).length === 7 && Object.keys(contract.mutation_probes).length === 12 && declaredTests.every((t) => testNames.has(t)),
    F11_manifest_pool_and_plan: /p010_finance_delta_manifest\.json/.test(f.pool) && manifest.migrations.length === 22 && manifest.materialized_tables_f1a.length === 21 && manifest.planned_table_count === 35 && manifest.migrations.every((m) => fs.existsSync(path.join(root, M, m))),
    F12_worker_isolated: /mepa_wave5_test_\[a-z0-9_\]\+/.test(f.worker) && /WAVE5_ALLOW_SYNTHETIC/.test(f.worker) && /READY/.test(f.worker),
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
  const source = originals[key].toString('utf8')
  const changed = fn(source)
  if (changed === source) throw new Error(`mutation of ${key} did not alter its target`)
  fs.writeFileSync(abs(key), changed)
}
function phpunit(filter) {
  const started = Date.now()
  const r = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', '--filter', filter, 'tests/DatabaseV2/FinanceCoreFoundationTest.php'], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 900000 })
  const summary = (r.stdout || '').split(/\r?\n/).filter((l) => /^(OK \(|Tests:|FAILURES|ERRORS)/.test(l)).pop() || ''
  return { detector: `phpunit --filter ${filter}`, exit_code: r.status, summary, seconds: Math.round((Date.now() - started) / 100) / 10 }
}
function parity() {
  const r = spawnSync(process.execPath, [path.join(root, 'scripts/validate-p010-finance-schema.cjs'), '--static-only'], { cwd: root, env: process.env, encoding: 'utf8' })
  let errors = []
  try { errors = JSON.parse(r.stdout).details.errors || [] } catch (e) { errors = [String(r.stdout).slice(0, 200)] }
  return { detector: 'validate-p010-finance-schema --static-only', exit_code: r.status, summary: errors.join('; ').slice(0, 300) }
}
const off = (s, from) => { if (!s.includes(from)) throw new Error('mutation anchor not found: ' + from.slice(0, 60)); return s.split(from).join('if (false) {') }

// [id, description, [[file, mutation], ...], detector]
const cases = [
  ['M1', 'balanced-journal guard removed', [['ledger', (s) => off(s, 'if ($debit !== $credit || (int) $sql->balanced !== 1) {')]], () => phpunit('test_j02')],
  ['M2', 'POSTED lines mutable', [['ledger', (s) => off(s, 'if ($entry->status !== FinanceCatalog::DRAFT) {')]], () => phpunit('test_j03')],
  ['M3', 'precision permissive (silent truncation)', [['money', (s) => s.replace("if (strlen($fraction) > self::SCALE) {\n                throw new FinanceError('AMOUNT_SCALE');\n            }", '$fraction = substr($fraction, 0, self::SCALE);')]], () => phpunit('test_j15')],
  ['M4', 'non-AOA currency accepted', [['ledger', (s) => off(off(s, 'if ($entry->currency_code !== FinanceCatalog::CURRENCY) {'), 'if ($line->fa_currency !== FinanceCatalog::CURRENCY) {')]], () => phpunit('test_j16')],
  ['M5', 'INTERUNIT_CONTROL installed as revenue', [['catalog', (s) => s.replace("'INTERUNIT_CLEARING_OUT' => [self::INTERUNIT_CONTROL, 'DEBIT'", "'INTERUNIT_CLEARING_OUT' => [self::INCOME, 'CREDIT'").replace("'INTERUNIT_CLEARING_IN' => [self::INTERUNIT_CONTROL, 'CREDIT'", "'INTERUNIT_CLEARING_IN' => [self::INCOME, 'CREDIT'")]], () => phpunit('test_j10')],
  ['M6', 'SEND touches revenue', [['catalog', (s) => s.replace("public const TRANSFER_KINDS = ['TRANSFER_SEND', ", 'public const TRANSFER_KINDS = [').replace("'TRANSFER_SEND' => [['INTERUNIT_CLEARING_OUT'], ['CASH', 'BANK'], 2],", "'TRANSFER_SEND' => [['INTERUNIT_CLEARING_OUT', 'EXPENSE'], ['CASH', 'BANK', 'INCOME'], 2],")]], () => phpunit('test_j10')],
  ['M7', 'RECEIVE touches expense', [['catalog', (s) => s.replace("public const TRANSFER_KINDS = ['TRANSFER_SEND', 'TRANSFER_RECEIVE', ", "public const TRANSFER_KINDS = ['TRANSFER_SEND', ").replace("'TRANSFER_RECEIVE' => [['CASH', 'BANK'], ['INTERUNIT_CLEARING_IN'], 2],", "'TRANSFER_RECEIVE' => [['CASH', 'BANK', 'EXPENSE'], ['INTERUNIT_CLEARING_IN', 'INCOME'], 2],")]], () => phpunit('test_j11')],
  ['M8', 'FIXED_ASSETS rubrics always expensed', [['catalog', (s) => s.split("'INVESTMENT', 'FIXED_ASSETS'],").join("'INVESTMENT', 'INVESTMENT_EXPENSE'],")]], () => phpunit('test_j08|test_j09')],
  ['M9', 'posting allowed in a closed unit period', [['ledger', (s) => off(s, 'if ($close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {').replace('|| $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id)', '|| false')]], () => phpunit('test_j14')],
  ['M10', 'stored balance column as source of truth', [['accounts', (s) => s.replace('  `closed_on` DATE NULL DEFAULT NULL,\n', '  `closed_on` DATE NULL DEFAULT NULL,\n  `balance` DECIMAL(19,4) NOT NULL DEFAULT 0,\n')]], () => parity()],
  ['M11', 'owner unit removed from journal_entries', [['entries', (s) => s.replace('  `unit_id` BIGINT UNSIGNED NOT NULL,\n', '').replace('  UNIQUE KEY `uq_journal_entries_id_unit_id` (`id`,`unit_id`),\n', '').replace('  KEY `ix_journal_entries_unit_id_period_id_status` (`unit_id`,`period_id`,`status`),\n', '').replace(/  CONSTRAINT `fk_journal_entries_unit_id`[^\n]*\n/, '')]], () => parity()],
  ['M12', 'second APPROVED budget allowed', [['budgets', (s) => s.replace('  UNIQUE KEY `uq_budgets_unit_id_period_id_fund_id_approved` (`unit_id`,`period_id`,`fund_id`,`approved_guard`),\n', '')]], () => parity()],
]

const probes = []
if (!structuralOnly) {
  const controlTests = phpunit('test_j0|test_j1')
  const controlParity = parity()
  probes.push({ id: 'CONTROL', status: controlTests.exit_code === 0 && controlParity.exit_code === 0 ? 'PASS' : 'FAIL', mode: 'NO_MUTATION_EXPECTED_PASS', tests: controlTests, parity: controlParity })
  for (const [id, description, mutations, detect] of cases) {
    if (only && !only.has(id)) continue
    try {
      for (const [key, fn] of mutations) mutate(key, fn)
      const run = detect()
      probes.push({ id, description, status: run.exit_code !== 0 ? 'PASS' : 'FAIL', mode: 'ON_DISK_MUTATION_EXPECTED_DETECTION', files: mutations.map(([key]) => paths[key]), detected: run.exit_code !== 0, ...run })
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
