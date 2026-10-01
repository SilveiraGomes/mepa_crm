// P0.10-F1C Finance Core contract validator (ADR 0021 D01/D05-D07/D11-D14/D17/D20/D30 + D-04A + FIN-D10/FIN-D11):
// accrual subledgers, financial accounts, bank statements / reconciliation, budget, period closes.
//  - structural checks H01-H16 over routes, requests, services, handler, output, UI, e2e and the contract;
//  - M1..M16 negative probes (+ M17-M19 for FIN-D10 / FIN-D11): each mutates the real source (service, catalog, request, query, ledger or periods), runs
//    the targeted HTTP tests of FinanceCoreF1CTest against the isolated pool and EXPECTS them to fail, then restores the
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
  accrual: D + 'AccrualService.php', accounts: D + 'FinanceAccountService.php', bank: D + 'BankReconciliationService.php', budget: D + 'BudgetService.php',
  closes: D + 'PeriodCloseService.php', operation: D + 'FinanceOperation.php', queries: D + 'FinanceCoreQueryService.php', ledger: D + 'LedgerPostingService.php',
  periods: D + 'FinancePeriods.php', catalog: D + 'FinanceCatalog.php', runtime: D + 'FinanceRuntime.php', ledgerQueries: D + 'LedgerQueries.php',
  handler: 'apps/api/app/Exceptions/Handler.php', routes: 'apps/api/routes/api.php', output: 'apps/api/app/Http/Finance/FinanceOutput.php',
  controller: 'apps/api/app/Http/Controllers/Api/V1/Finance/FinanceCoreController.php', subledgerRequest: 'apps/api/app/Http/Requests/Finance/SubledgerCreateRequest.php',
  baseRequest: 'apps/api/app/Http/Requests/Finance/FinanceRequest.php', test: 'apps/api/tests/DatabaseV2/FinanceCoreF1CTest.php', contracts: 'docs/contracts/finance_core_contracts.json',
  pages: 'apps/web/src/pages/FinanceCorePages.tsx', shell: 'apps/web/src/layout/AppShell.tsx', e2e: 'apps/web/tests/e2e/finance-core.spec.ts',
  e2eConfig: 'apps/web/playwright.finance-core.config.ts', worker: 'scripts/p010-f1c-worker.php',
}
const abs = (key) => path.join(root, paths[key])
const read = () => Object.fromEntries(Object.keys(paths).map((key) => [key, fs.readFileSync(abs(key), 'utf8').replace(/\r\n/g, '\n')]))
const digest = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex')
const requestsDir = path.join(root, 'apps/api/app/Http/Requests/Finance')

function methodBody(text, name) {
  const start = text.indexOf(`function ${name}(`)
  if (start < 0) return ''
  const rest = text.slice(start + 10)
  const next = rest.search(/\n    (public|private|protected) (static )?function /)
  return text.slice(start, next < 0 ? undefined : start + 10 + next)
}

/** `first` occurs, and before every `later` occurrence, in the method body. */
const before = (body, first, later) => { const f = body.indexOf(first); const l = body.search(later); return f > 0 && (l < 0 || f < l) }

const F1C_ROUTES = ['GET accounts', 'POST accounts', 'GET accounts/{account}', 'GET accounts/{account}/history', 'POST accounts/{account}/close', 'GET receivables', 'POST receivables',
  'GET receivables/{receivable}', 'POST receivables/{receivable}/settlements', 'POST receivables/{receivable}/cancel', 'GET payables', 'POST payables', 'GET payables/{payable}',
  'POST payables/{payable}/settlements', 'POST payables/{payable}/cancel', 'GET settlements/{settlement}', 'POST settlements/{settlement}/cancel', 'GET bank-statements',
  'POST bank-statements', 'GET bank-statements/{statement}', 'GET reconciliations', 'POST reconciliations', 'GET reconciliations/{reconciliation}',
  'POST reconciliations/{reconciliation}/matches', 'POST reconciliations/{reconciliation}/unmatch', 'POST reconciliations/{reconciliation}/close', 'POST reconciliations/{reconciliation}/adjustments', 'GET budgets', 'POST budgets',
  'GET budgets/{budget}', 'GET budgets/{budget}/actual-vs-budget', 'POST budgets/{budget}/lines', 'POST budgets/{budget}/submit', 'POST budgets/{budget}/return',
  'POST budgets/{budget}/review', 'POST budgets/{budget}/approve', 'POST budgets/{budget}/cancel', 'POST budgets/{budget}/revise', 'GET periods', 'POST periods/{period}/close',
  'POST periods/{period}/reopen', 'POST periods/{period}/national-close']

function checks(f) {
  const contract = JSON.parse(f.contracts)
  const block = f.routes.slice(f.routes.indexOf("Route::prefix('finance')"), f.routes.indexOf("Route::prefix('physical')"))
  const lines = block.split('\n').filter((l) => /FinanceCoreController::class/.test(l))
  const routes = lines.map((l) => { const m = l.match(/Route::(get|post|patch|put|delete)\('([^']+)'/); return m ? `${m[1].toUpperCase()} ${m[2]}` : '' })
  const requests = fs.readdirSync(requestsDir).map((file) => fs.readFileSync(path.join(requestsDir, file), 'utf8'))
  const writes = [[f.accrual, ['recognize', 'settle', 'cancelSettlement', 'cancel']], [f.accounts, ['open', 'close']], [f.bank, ['importStatement', 'open', 'close', 'locked', 'adjust']],
    [f.budget, ['create', 'revise', 'transition']], [f.closes, ['closeUnit', 'reopenUnit', 'closeNational']]]
  const permissionFirst = writes.every(([text, methods]) => methods.every((m) => before(methodBody(text, m), '$guard->requires(', /byPublicId\(|lockRow\(|lockPostingPeriod\(|nationalRoot\(|->target\(/)))
  const scopeBeforeLocks = [[f.accrual, ['recognize', 'settle', 'cancelSettlement', 'cancel']], [f.accounts, ['open', 'close']], [f.bank, ['importStatement', 'open', 'target', 'adjust']], [f.budget, ['create', 'revise', 'transition']],
    [f.closes, ['closeUnit', 'reopenUnit', 'closeNational']]].every(([text, methods]) => methods.every((m) => before(methodBody(text, m), 'preauthorize(', /lockPostingPeriod\(|lockRow\(|->versions\(|sharedLock\(\)->first\(\)|new FinancePeriods/)))
  const settle = methodBody(f.accrual, 'settle')
  const shapes = f.catalog.match(/'SETTLEMENT' => \[\[([^\]]*)\], \[([^\]]*)\]/)
  const testNames = new Set([...f.test.matchAll(/public function (test_\w+)\(/g)].map((m) => m[1]))
  const f1c = contract.f1c_contracts || {}
  // F1D keeps the F1C pages but closes the final navigation wording/duplication debt.
  const navOrder = ['Contas', 'A receber', 'A pagar', 'Transferências', 'Extractos', 'Reconciliação', 'Orçamento', 'Fechos', 'Posição de fundos']
  return {
    H01_explicit_transition_routes_only: F1C_ROUTES.every((r) => routes.includes(r)) && routes.length === F1C_ROUTES.length && !/Route::(patch|put|delete)\(/.test(block)
      && lines.filter((l) => /Route::post/.test(l)).every((l) => /throttle:finance-write/.test(l)),
    H02_closed_field_lists_no_internal_ids: /array_diff\(array_keys\(\$this->all\(\)\), \$allowed\)/.test(f.baseRequest) && requests.every((t) => !/'\w+_id'\s*=>/.test(t)) && requests.filter((t) => /final class/.test(t)).every((t) => /extends FinanceRequest/.test(t)),
    H03_permission_before_target_f06: permissionFirst,
    H04_scope_before_locks_state_and_period: scopeBeforeLocks,
    H05_single_journal_writer: !/table\('journal_(entries|lines)'\)[^;]*->(insert|update|delete)\(/.test(f.accrual + f.accounts + f.bank + f.budget + f.closes + f.queries) && /postSubledgerEntry\(/.test(f.accrual) && /postSubledgerReversal\(/.test(f.accrual),
    H06_locking_reads_for_decisions: /a\.\{\$column\} = \? AND s\.status = 'POSTED' FOR SHARE/.test(f.accrual) && /FROM reconciliation_matches WHERE \{\$column\} = \? FOR SHARE/.test(f.bank) && /e\.status = 'POSTED' FOR SHARE/.test(f.operation)
      && /lockForUpdate\(\)->get\(\['id', 'status'\]\)/.test(f.ledger) && /orderBy\('u\.public_id'\)->sharedLock\(\)/.test(f.periods) && /lockForUpdate\(\)->get\(\);/.test(methodBody(f.budget, 'versions')),
    H07_canonical_balance_no_stored_balance: /'balance_source' => 'POSTED_JOURNAL_LINES'/.test(f.queries) && /financialAccountBalance\(/.test(f.queries) && !/'balance' =>[^,]*->value\('balance'\)/.test(f.queries) && /createDraft\([^;]*'OPENING_BALANCE'/s.test(methodBody(f.accounts, 'open')),
    H08_settlement_never_touches_the_result: /'account' => 'RECEIVABLES', 'credit' => \$money/.test(settle) && /'account' => 'PAYABLES', 'debit' => \$money/.test(settle) && !/INCOME|EXPENSE/.test(settle)
      && shapes !== null && !/INCOME|EXPENSE/.test(shapes[1] + shapes[2]),
    H09_statement_line_is_not_a_journal_line: !/ledger->|postSubledger|createDraft/.test(methodBody(f.bank, 'importStatement')) && /STATEMENT_UNBALANCED/.test(f.bank) && /STATEMENT_ALREADY_IMPORTED/.test(f.bank),
    H10_budget_segregation_one_approved_revision: /SEGREGATION_REQUIRED/.test(methodBody(f.budget, 'approve')) && /'SUPERSEDED'/.test(methodBody(f.budget, 'approve')) && /BUDGET_VERSION_OUTDATED/.test(f.budget) && /BUDGET_NOT_EDITABLE/.test(methodBody(f.budget, 'replaceLines')),
    H11_national_close_root_only_and_irreversible: /nationalRoot\(\$this->rt->db\)/.test(methodBody(f.closes, 'closeNational')) && /preauthorize\(\$actor, \[FinanceCatalog::PERMISSION_PERIOD_CLOSE\], \$root\)/.test(f.closes)
      && !/function \w*[Nn]ational\w*[Rr]eopen|function reopenNational/.test(f.periods) && /SEGREGATION_REQUIRED/.test(methodBody(f.periods, 'reopenUnit')),
    H12_concealed_404_and_mapped_codes: /in_array\(\$e->reason, \['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\], true\)/.test(f.handler) && ['CATEGORY_NOT_RECEIVABLE', 'PAYABLE_DOCUMENT_REQUIRED', 'STATEMENT_DOCUMENT_REQUIRED', 'STATEMENT_UNBALANCED', 'CRYPTO_UNAVAILABLE'].every((c) => f.handler.includes(`'${c}'`))
      && /FinanceOutput::assertSafe\(\$detail\)/.test(f.controller),
    H13_contract_maps_existing_tests: Object.keys(f1c.tests || {}).length === 44 && Object.keys(f1c.mutation_probes || {}).length === 16 && Object.values(f1c.tests || {}).every((t) => t.split('|').every((n) => testNames.has(n)))
      && Object.values(f1c.concurrency || {}).every((t) => testNames.has(t)),
    H14_ui_sections_money_as_strings_four_viewports: !/parseFloat|Number\(\s*\w+\.(amount|balance|outstanding)/.test(f.pages) && /formatKz/.test(f.pages) && navOrder.every((label) => f.shell.includes(`'${label}'`))
      && ['desktop-1440x900', 'laptop-1366x768', 'tablet-768x1024', 'mobile-390x844'].every((p) => f.e2eConfig.includes(p)) && /finance-core\.spec\.ts/.test(f.e2eConfig),
    H16_fin_d10_no_overdraft_fin_d11_closed_period_reconciliation: /\$this->assertNoOverdraft\(\$entryId, \$lines\);/.test(f.ledger) && /e\.id <> \? FOR SHARE/.test(methodBody(f.ledger, 'assertNoOverdraft'))
      && /INSUFFICIENT_FUNDS/.test(methodBody(f.ledger, 'assertNoOverdraft')) && !/lockPostingPeriod/.test(methodBody(f.bank, 'target') + methodBody(f.bank, 'open') + methodBody(f.bank, 'locked'))
      && /postingDate\(\$unit/.test(methodBody(f.bank, 'adjust')) && /lockPostingPeriod\(\$unit, \$postOn\)/.test(methodBody(f.bank, 'adjust')) && /ALREADY_ADJUSTED/.test(f.bank),
    H15_audit_actions: ['finance.receivable_recognized', 'finance.payable_recognized', 'finance.settlement_posted', 'finance.settlement_cancelled'].every((a) => f.accrual.includes(`'${a}'`) || f.accrual.includes(a.replace(/^finance\.(receivable|payable)/, "finance.' . self::KINDS[$kind]['audit'] . '")))
      && ['finance.account_opened', 'finance.account_closed'].every((a) => f.accounts.includes(`'${a}'`)) && ['finance.bank_statement_imported', 'finance.reconciliation_matched', 'finance.reconciliation_closed'].every((a) => f.bank.includes(`'${a}'`))
      && ['finance.budget_submitted', 'finance.budget_approved', 'finance.budget_revised'].every((a) => f.budget.includes(`'${a}'`)) && ['finance.period_unit_closed', 'finance.period_unit_reopened', 'finance.period_national_closed'].every((a) => f.periods.includes(`'${a}'`)),
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
  const r = spawnSync(PHP, ['vendor/phpunit/phpunit/phpunit', '--filter', filter, 'tests/DatabaseV2/FinanceCoreF1CTest.php'], { cwd: path.join(root, 'apps/api'), env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8', timeout: 1200000 })
  const summary = (r.stdout || '').split(/\r?\n/).filter((l) => /^(OK \(|Tests:|FAILURES|ERRORS)/.test(l)).pop() || ''
  return { detector: `phpunit --filter ${filter}`, exit_code: r.status, summary, seconds: Math.round((Date.now() - started) / 100) / 10 }
}

const OVER = "            if ($amount > $outstanding) {\n                throw new FinanceError('OVER_SETTLEMENT');\n            }\n"
const cases = [
  ['M1', 'receivable settlement recreates Revenue', [
    ['accrual', sub("[$treasury + ['debit' => $money], ['account' => 'RECEIVABLES', 'credit' => $money]]", "[$treasury + ['debit' => $money], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => $money]]")],
    ['catalog', sub("'SETTLEMENT' => [['CASH', 'BANK', 'PAYABLES'], ['CASH', 'BANK', 'RECEIVABLES'], null]", "'SETTLEMENT' => [['CASH', 'BANK', 'PAYABLES'], ['CASH', 'BANK', 'RECEIVABLES', 'INCOME'], null]")]], 'test_a02'],
  ['M2', 'payable settlement recreates Expense', [
    ['accrual', sub("[['account' => 'PAYABLES', 'debit' => $money], $treasury + ['credit' => $money]]", "[['account' => 'OPERATING_EXPENSE', 'category' => 'ADM_OTHER', 'debit' => $money], $treasury + ['credit' => $money]]")],
    ['catalog', sub("'SETTLEMENT' => [['CASH', 'BANK', 'PAYABLES'], ['CASH', 'BANK', 'RECEIVABLES'], null]", "'SETTLEMENT' => [['CASH', 'BANK', 'PAYABLES', 'EXPENSE'], ['CASH', 'BANK', 'RECEIVABLES'], null]")]], 'test_a07|test_a08'],
  ['M3', 'over-settlement accepted', [['accrual', sub(OVER, '')]], 'test_a04'],
  ['M4', 'fixed asset forced to Expense', [['accrual', sub("? [['ledger_account_id' => (int) $category->ledger_account_id, 'category' => (string) $category->code, 'debit' => $money], ['account' => 'PAYABLES', 'credit' => $money]]",
    "? [['account' => 'OPERATING_EXPENSE', 'category' => (string) $category->code, 'debit' => $money], ['account' => 'PAYABLES', 'credit' => $money]]")]], 'test_a06'],
  ['M5', 'mutable / non-canonical financial balance', [['queries', sub("'balance' => Money::format((new LedgerQueries($this->rt->db))->financialAccountBalance((int) $a->id)), 'lock_version' => (int) $a->lock_version,",
    "'balance' => Money::format(Money::fromDecimal((string) ($this->rt->db->table('journal_lines')->where('financial_account_id', $a->id)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS b')->value('b') ?? '0'))), 'lock_version' => (int) $a->lock_version,")]], 'test_u02'],
  ['M6', 'posting into a closed financial account', [['ledger', (s) => sub("            if ($account->status !== 'OPEN') {\n                throw new FinanceError('FINANCIAL_ACCOUNT_CLOSED');\n            }\n", '')(
    sub("if ($line->fa_status !== 'OPEN' || $line->fa_opened > $entry->entry_date) {", 'if ($line->fa_opened > $entry->entry_date) {')(s))]], 'test_u04'],
  ['M7', 'bank statement line turned into a journal entry automatically', [['bank', sub("            $this->complete($claim['id'], $publicId);\n            FinanceAudit::write($this->rt->db, $actor->user, 'finance.bank_statement_imported'",
    "            foreach ($normalized as $line) {\n                $this->ledger->createDraft($actor->user, 'BL-' . $publicId . '-' . $line['line_number'], ['unit_id' => $unit, 'entry_kind' => 'ADJUSTMENT', 'entry_date' => $line['occurred_on'], 'description' => $line['description'], 'reason' => 'Linha de extracto',\n                    'lines' => [['account' => 'BANK', 'financial_account_id' => (int) $account->id, ($line['amount'] > 0 ? 'debit' : 'credit') => Money::format(abs($line['amount']))], ['account' => 'OPENING_NET_ASSETS', ($line['amount'] > 0 ? 'credit' : 'debit') => Money::format(abs($line['amount']))]]]);\n            }\n            $this->complete($claim['id'], $publicId);\n            FinanceAudit::write($this->rt->db, $actor->user, 'finance.bank_statement_imported'")]], 'test_b03'],
  ['M8', 'double bank match (statement value reconciled twice)', [['bank', sub("            if ($this->matched('statement_line_id', (int) $statementLine->id) + $amount > abs($bankAmount)) {\n                throw new FinanceError('MATCH_EXCEEDS_STATEMENT_LINE');\n            }\n", '')]], 'test_b05'],
  ['M9', 'budget submitter can approve', [['budget', sub("            if ((int) $budget->submitted_by === $actor->user) {\n                // D13 / D07 exception: the approver is never the submitter (also a physical CHECK).\n                throw new FinanceError('SEGREGATION_REQUIRED');\n            }\n", '')]], 'test_g05'],
  ['M10', 'two approved budget versions (no supersede)', [['budget', sub("$this->rt->db->table('budgets')->where('id', $current->id)->where('status', 'APPROVED')->update(['status' => 'SUPERSEDED', 'lock_version' => $current->lock_version + 1]);", '// supersede removed')]], 'test_g07'],
  ['M11', 'approved budget overwritten', [['budget', sub("            if ($budget->status !== 'DRAFT') {\n                throw new FinanceError('BUDGET_NOT_EDITABLE');\n            }\n", '')]], 'test_g07'],
  ['M12', 'actual read from non-POSTED lines', [['queries', sub("->where('e.status', FinanceCatalog::POSTED)->where('l.unit_id', $unit)->where('l.fund_id', $fund)", "->where('l.unit_id', $unit)->where('l.fund_id', $fund)")]], 'test_g08'],
  ['M13', 'unit period close bypassed by postings', [['ledger', (s) => sub("        if ($close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {\n            throw new FinanceError('PERIOD_CLOSED');\n        }\n", '')(
    sub('if ($entry->period_status !== FinanceCatalog::PERIOD_OPEN || $this->unitClosed((int) $entry->period_id, (int) $entry->unit_id)) {', 'if ($entry->period_status !== FinanceCatalog::PERIOD_OPEN) {')(s))]], 'test_p01'],
  ['M14', 'reopen by the same closer', [['periods', sub("            if ((int) $close->closed_by === $actor) {\n                throw new FinanceError('SEGREGATION_REQUIRED');\n            }\n", '')]], 'test_p04'],
  ['M15', 'national close reopened (unit reopen after a national close)', [['periods', inMethod('reopenUnit', '$period = $this->lockMonth($code);', "$period = $this->db->table('accounting_periods')->where('code', $code)->lockForUpdate()->first();")]], 'test_p05'],
  ['M17', 'FIN-D10 overdraft check removed (CASH/BANK may go negative)', [['ledger', sub('        $this->assertNoOverdraft($entryId, $lines);\n', '')]], 'test_n01|test_n03'],
  ['M18', 'FIN-D11 closed period blocks reconciliation again', [['bank', sub("        $this->preauthorize($actor, [FinanceCatalog::PERMISSION_RECONCILE], $unit);\n        return [$this->lockRow('reconciliations', (int) $peek->id), $unit];",
    "        $this->preauthorize($actor, [FinanceCatalog::PERMISSION_RECONCILE], $unit);\n        $this->ledger->lockPostingPeriod($unit, (string) $this->rt->db->table('accounting_periods')->where('id', $peek->period_id)->value('starts_on'));\n        return [$this->lockRow('reconciliations', (int) $peek->id), $unit];")]], 'test_n05'],
  ['M19', 'FIN-D11 adjustment back-dated into the closed month', [['bank', sub('$postOn = $this->postingDate($unit, (string) $peekLine->occurred_on);', '$postOn = (string) $peekLine->occurred_on;')]], 'test_n07'],
  ['M16', 'numeric source_document id accepted', [['subledgerRequest', sub("'document' => ['sometimes', 'nullable', 'string', 'size:26'],", "'document' => ['sometimes', 'nullable', 'string', 'size:26'],\n            'document_id' => ['sometimes'],\n            'source_document_id' => ['sometimes'],")]], 'test_a05'],
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
  status: failures.length === 0 && restoredBytes && (structuralOnly || probes.every((probe) => probe.status === 'PASS')) ? 'PASS' : 'FAIL',
  checks: base, failures, mutation_probes: probes,
  probes_detected: probes.filter((p) => p.status === 'PASS' && p.id !== 'CONTROL').length, probes_run: probes.filter((p) => p.id !== 'CONTROL').length,
  source_hashes_before: hashesBefore, source_hashes_after: hashesAfter, source_restored_byte_for_byte: restoredBytes,
}
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(result.status === 'PASS' ? 0 : 1)
