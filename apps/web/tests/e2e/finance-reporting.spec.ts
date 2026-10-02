import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.10-F1D-E1 Finance reporting browser suite on the four QA viewports (R28-R31, R35, R36 + visual QA): dashboard in the
// own and consolidated views, own / consolidated DRE and DOAF, internal funds / interunit position, budget versus actual,
// open payables, cash/bank balances, month / quarter / semester / year in the filters, contributions (in kind without
// monetary impact) and a reader without FINANCE_CONSOLIDATED_VIEW. Every figure on screen is compared with the JSON the
// page received from the API AND with a hand-computed value (fixture FinanceE2EFixtureTest::reportingWorld); the CSV
// export is downloaded and compared field by field with the API report, and its audit row is read from the pool.
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P010_FIXTURE_PATH ?? path.join(repo, '.tmp/p010-e2e-fixtures.json'), 'utf8'))
const f1d = fx.f1d
const units: Record<string, { public_id: string; name: string }> = f1d.units
const evidence = process.env.P010_F1D_EVIDENCE_DIR ?? path.join(repo, 'docs/reviews/evidence/P0.10-F1D')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/finance_e2e_support.php')
const supportEnv = { ...process.env, APP_ENV: 'e2e', MEPA_E2E_FINANCE_SUPPORT: '1' }
const probe = (...args: string[]) => JSON.parse(execFileSync(php, [support, ...args], { cwd: apiRoot, env: supportEnv, encoding: 'utf8' }))
const resetLimiters = () => execFileSync(php, [support, 'reset', String(f1d.readers.cons.user_id), String(f1d.readers.own.user_id)], { cwd: apiRoot, env: supportEnv, stdio: 'pipe' })
const kz = (amount: string) => {
  const negative = amount.startsWith('-')
  const [integer, fraction] = (negative ? amount.slice(1) : amount).split('.')
  return `${negative ? '\u2212' : ''}${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f')},${fraction.slice(0, 2)}\u00a0Kz`
}

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean; hiddenFilters: string[]; clippedTotals: string[]; unusableTables: number }
const checks: LayoutCheck[] = []

async function login(page: Page, reader: 'cons' | 'own', to = '/financas') {
  await page.goto(to)
  await page.getByLabel('Utilizador').fill(f1d.readers[reader].login)
  await page.getByLabel('Palavra-passe').fill(f1d.readers[reader].password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
  await expect(page.getByRole('button', { name: 'Terminar sessão' })).toBeVisible()
  await page.goto(to)
  await page.waitForLoadState('networkidle')
}

/** 0 overflow, 0 clipped button, 0 dialog outside, 0 filter out of reach, 0 clipped total, 0 table without its own scroll. */
async function layout(page: Page, info: TestInfo, screen: string) {
  await page.waitForLoadState('networkidle')
  const result = await page.evaluate(() => {
    const width = window.innerWidth
    const height = window.innerHeight
    const outside = (box: DOMRect) => box.left < -1 || box.right > width + 1
    const overflow = document.documentElement.scrollWidth > width + 1
    const clipped: string[] = []
    for (const button of Array.from(document.querySelectorAll<HTMLElement>('main .btn, dialog[open] .btn'))) {
      const box = button.getBoundingClientRect()
      if (box.width === 0 && box.height === 0) continue
      if (outside(box)) clipped.push((button.textContent ?? '').trim())
    }
    const hiddenFilters: string[] = []
    for (const control of Array.from(document.querySelectorAll<HTMLElement>('main select, main input'))) {
      const box = control.getBoundingClientRect()
      if (box.width < 40 || box.height === 0 || outside(box)) hiddenFilters.push(control.id || control.getAttribute('name') || '?')
    }
    const clippedTotals: string[] = []
    for (const total of Array.from(document.querySelectorAll<HTMLElement>('main .finance-kpi strong, main .finance-custody dd, main .finance-amount'))) {
      const box = total.getBoundingClientRect()
      if (box.width === 0 && box.height === 0) continue
      const wrap = total.closest('.table-wrap') as HTMLElement | null
      if (wrap) continue
      if (outside(box) || total.scrollWidth > total.clientWidth + 1) clippedTotals.push((total.textContent ?? '').trim())
    }
    let unusableTables = 0
    for (const wrap of Array.from(document.querySelectorAll<HTMLElement>('main .table-wrap'))) {
      const scrolls = ['auto', 'scroll'].includes(getComputedStyle(wrap).overflowX)
      if (outside(wrap.getBoundingClientRect()) || (wrap.scrollWidth > wrap.clientWidth + 1 && !scrolls)) unusableTables++
    }
    const dialog = document.querySelector('dialog[open]')?.getBoundingClientRect()
    const dialogOutside = dialog ? dialog.left < -1 || dialog.right > width + 1 || dialog.top < -1 || dialog.bottom > height + 1 : false
    return { overflow, clipped, dialogOutside, hiddenFilters, clippedTotals, unusableTables }
  })
  checks.push({ screen, overflow: result.overflow, clippedButtons: result.clipped, dialogOutside: result.dialogOutside, hiddenFilters: result.hiddenFilters, clippedTotals: result.clippedTotals, unusableTables: result.unusableTables })
  await page.screenshot({ path: path.join(evidence, `${info.project.name}-${screen}.png`), fullPage: true })
  expect(result.overflow, `${screen}: horizontal overflow`).toBe(false)
  expect(result.clipped, `${screen}: clipped buttons`).toEqual([])
  expect(result.dialogOutside, `${screen}: dialog outside viewport`).toBe(false)
  expect(result.hiddenFilters, `${screen}: filters out of reach`).toEqual([])
  expect(result.clippedTotals, `${screen}: clipped totals`).toEqual([])
  expect(result.unusableTables, `${screen}: tables without their own scroll`).toBe(0)
}

// eslint-disable-next-line no-empty-pattern
test.afterAll(async ({}, info) => {
  fs.mkdirSync(evidence, { recursive: true })
  fs.writeFileSync(path.join(evidence, `visual-qa-${info.project.name}.json`), JSON.stringify({ project: info.project.name, checks }, null, 2) + '\n')
})

type Filters = { code?: string; unit: string; view?: 'OWN' | 'CONSOLIDATED'; kind?: 'MONTH' | 'QUARTER' | 'SEMESTER' | 'YEAR'; period: string }

/** Applies the filters and returns the JSON body the PAGE received for the final combination (the API figures). */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function apply(page: Page, f: Filters): Promise<any> {
  const kind = f.kind ?? 'MONTH'
  const view = f.code?.startsWith('CONSOLIDATED_') ? 'CONSOLIDATED' : f.code?.startsWith('OWN_') ? 'OWN' : (f.view ?? 'OWN')
  const pathEnd = f.code ? `/finance/reports/${f.code}` : '/finance/dashboard'
  // Registered first: the final combination may be requested by any of the steps below (e.g. YEAR defaults to this year).
  const response = page.waitForResponse((r) => {
    const url = new URL(r.url())
    return r.request().method() === 'GET' && url.pathname.endsWith(pathEnd) && url.searchParams.get('unit') === units[f.unit].public_id && url.searchParams.get('view') === view
      && url.searchParams.get('period_kind') === kind && url.searchParams.get('period') === f.period
  })
  if (f.code) await page.locator('#report-code').selectOption(f.code)
  await page.locator('#report-unit').selectOption({ label: units[f.unit].name })
  if (f.view && await page.locator('#report-view').isEnabled()) await page.locator('#report-view').selectOption(f.view)
  await page.locator('#report-kind').selectOption(kind)
  await page.locator('#report-period').fill(f.period)
  const r = await response
  expect(r.status()).toBe(200)
  const data = (await r.json()).data
  await page.waitForLoadState('networkidle')
  return data
}

const kpi = (page: Page, label: string) => page.locator('main .finance-kpi').filter({ has: page.locator('span').getByText(label, { exact: true }) }).locator('strong')
const custody = (page: Page, label: string) => page.locator('main .finance-custody div').filter({ has: page.locator('dt').getByText(label, { exact: true }) }).locator('dd')

async function expectKpis(page: Page, pairs: [string, string][]) {
  for (const [label, amount] of pairs) await expect(kpi(page, label), label).toHaveText(kz(amount))
}

async function expectHeader(page: Page, unit: string, view: 'PRÓPRIO' | 'CONSOLIDADO') {
  const header = page.locator('main .finance-official-header')
  await expect(header).toContainText('MEPA')
  await expect(header).toContainText(`${units[unit].name} · ${view}`)
  await expect(header).toContainText('Gerado em')
}

function flatten(value: unknown, prefix = '', out: Record<string, string> = {}): Record<string, string> {
  if (value !== null && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) flatten(child, prefix === '' ? key : `${prefix}.${key}`, out)
    return out
  }
  out[prefix] = typeof value === 'boolean' ? (value ? 'sim' : 'não') : value === null || value === undefined ? '' : String(value)
  return out
}

function parseCsv(text: string): Record<string, string> {
  expect(text.charCodeAt(0), 'UTF-8 BOM').toBe(0xfeff)
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let quoted = false
  for (let i = 1; i < text.length; i++) {
    const c = text[i]
    if (quoted) {
      if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++ } else quoted = false } else field += c
    } else if (c === '"') quoted = true
    else if (c === ';') { row.push(field); field = '' }
    else if (c === '\n') { row.push(field); rows.push(row); row = []; field = '' }
    else if (c !== '\r') field += c
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row) }
  expect(rows.shift()).toEqual(['Campo', 'Valor'])
  return Object.fromEntries(rows.map((r) => [r[0], r[1] ?? '']))
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function exportAndCompare(page: Page, info: TestInfo, api: any, name: string) {
  const reader = String(f1d.readers.cons.user_id)
  const before = probe('audit', reader, 'finance.export').count
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportar CSV' }).click()])
  const file = path.join(evidence, `${info.project.name}-${name}.csv`)
  await download.saveAs(file)
  const csv = parseCsv(fs.readFileSync(file, 'utf8'))
  const expected = flatten(api)
  expect(csv.generated_at, 'export generation timestamp').toMatch(/^\d{4}-\d{2}-\d{2}/)
  delete expected.generated_at
  delete csv.generated_at
  expect(csv, `${name}: export = API, field by field`).toEqual(expected)
  for (const field of ['unit.name', 'unit.public_id', 'view', 'period.kind', 'period.label', 'period.from', 'period.to', 'perimeter_units', 'parameters_hash', 'source', 'snapshot']) expect(csv[field], field).toBeTruthy()
  const audit = probe('audit', reader, 'finance.export')
  expect(audit.count, 'the export is audited').toBe(before + 1)
  expect([audit.last.report_type, audit.last.view, audit.last.period_from, audit.last.period_to, audit.last.parameters_hash]).toEqual([api.report_type, api.view, api.period.from, api.period.to, api.parameters_hash])
  return csv
}

test('R28/R35 dashboard: consolidated and own views are explicit, KPIs equal the API and the hand-computed figures', async ({ page }, info) => {
  await login(page, 'cons')
  await expect(page.getByRole('heading', { level: 1, name: 'Visão Geral Finance' })).toBeVisible()
  const cons = await apply(page, { unit: 'm', view: 'CONSOLIDATED', period: '2026-08' })
  expect([cons.view, cons.perimeter_units]).toEqual(['CONSOLIDATED', 6])
  // Hand-computed (fixture): revenue 100000 + 30000 + 8000; expenses 20000 + 10000 + 5000; transit 7000; closing 3000 + 138000 - 25000.
  expect([cons.dashboard.revenue, cons.dashboard.expenses, cons.dashboard.economic_result, cons.dashboard.internal_received, cons.dashboard.internal_sent, cons.dashboard.in_transit,
    cons.dashboard.cash_bank_position, cons.dashboard.receivables, cons.dashboard.payables]).toEqual(['138000.00', '35000.00', '103000.00', '0.00', '0.00', '7000.00', '116000.00', '0.00', '10000.00'])
  await expectKpis(page, [['Receitas', cons.dashboard.revenue], ['Gastos', cons.dashboard.expenses], ['Resultado económico', cons.dashboard.economic_result], ['Fundos internos recebidos', cons.dashboard.internal_received],
    ['Fundos internos enviados', cons.dashboard.internal_sent], ['Em trânsito', cons.dashboard.in_transit], ['Caixa/Banco', cons.dashboard.cash_bank_position], ['A receber', cons.dashboard.receivables],
    ['A pagar', cons.dashboard.payables], ['Execução orçamental', cons.dashboard.budget_execution.actual]])
  await expectHeader(page, 'm', 'CONSOLIDADO')
  await expect(page.locator('#report-view')).toHaveValue('CONSOLIDATED')
  const drill = page.locator('main section').filter({ has: page.getByRole('heading', { name: 'Unidades do perímetro' }) })
  await expect(drill.locator('tbody tr')).toHaveCount(6)
  await expect(drill.locator('tbody tr').filter({ hasText: units.a1.name })).toContainText(kz('80000.00'))
  await layout(page, info, 'dashboard-consolidated')

  const own = await apply(page, { unit: 'm', view: 'OWN', period: '2026-08' })
  expect([own.view, own.perimeter_units, own.dashboard.revenue, own.dashboard.internal_received, own.dashboard.cash_bank_position]).toEqual(['OWN', 1, '30000.00', '40000.00', '70000.00'])
  await expectKpis(page, [['Receitas', own.dashboard.revenue], ['Fundos internos recebidos', own.dashboard.internal_received], ['Caixa/Banco', own.dashboard.cash_bank_position], ['A pagar', '5000.00']])
  await expectHeader(page, 'm', 'PRÓPRIO')
  await expect(page.getByRole('heading', { name: 'Unidades do perímetro' })).toHaveCount(0)
  await layout(page, info, 'dashboard-own')
})

test('R09-R11/R30/R36 DRE and DOAF, own and consolidated: UI = API = CSV export, audited', async ({ page }, info) => {
  await login(page, 'cons', '/financas/relatorios')
  await expect(page.getByRole('heading', { level: 1, name: 'Relatórios Finance' })).toBeVisible()
  const ownDre = await apply(page, { code: 'OWN_DRE', unit: 'm', period: '2026-08' })
  expect([ownDre.view, ownDre.dre.revenue, ownDre.dre.expenses, ownDre.dre.economic_result]).toEqual(['OWN', '30000.00', '5000.00', '25000.00'])
  await expectKpis(page, [['Receitas', ownDre.dre.revenue], ['Gastos', ownDre.dre.expenses], ['Resultado económico', ownDre.dre.economic_result]])
  await expectHeader(page, 'm', 'PRÓPRIO')
  await expect(page.locator('#report-view')).toBeDisabled()
  await layout(page, info, 'dre-own')

  const consDre = await apply(page, { code: 'CONSOLIDATED_DRE', unit: 'm', period: '2026-08' })
  expect([consDre.view, consDre.dre.revenue, consDre.dre.expenses, consDre.dre.economic_result, consDre.dre.internal_transfer_effect]).toEqual(['CONSOLIDATED', '138000.00', '35000.00', '103000.00', '0.00'])
  await expectKpis(page, [['Receitas', consDre.dre.revenue], ['Gastos', consDre.dre.expenses], ['Resultado económico', consDre.dre.economic_result]])
  await expectHeader(page, 'm', 'CONSOLIDADO')
  await expect(page.locator('#report-view')).toHaveValue('CONSOLIDATED')
  await layout(page, info, 'dre-consolidated')
  const csv = await exportAndCompare(page, info, consDre, 'dre-consolidated')
  expect([csv['dre.revenue'], csv.view, csv['unit.name'], csv['period.from'], csv['period.to']]).toEqual(['138000.00', 'CONSOLIDATED', units.m.name, '2026-08-01', '2026-08-31'])

  const ownDoaf = await apply(page, { code: 'OWN_DOAF', unit: 'm', period: '2026-08' })
  expect([ownDoaf.doaf.opening_balance, ownDoaf.doaf.external_funds_received, ownDoaf.doaf.internal_funds_received, ownDoaf.doaf.closing_balance, ownDoaf.doaf.balanced]).toEqual(['0.00', '30000.00', '40000.00', '70000.00', true])
  for (const [label, key] of [['Saldo inicial', 'opening_balance'], ['Recebimentos externos', 'external_funds_received'], ['Fundos internos recebidos', 'internal_funds_received'], ['Saldo final', 'closing_balance']]) await expect(custody(page, label)).toHaveText(kz(ownDoaf.doaf[key]))
  await expectHeader(page, 'm', 'PRÓPRIO')
  await layout(page, info, 'doaf-own')

  const consDoaf = await apply(page, { code: 'CONSOLIDATED_DOAF', unit: 'm', period: '2026-08' })
  expect([consDoaf.doaf.opening_balance, consDoaf.doaf.external_funds_received, consDoaf.doaf.internal_funds_received, consDoaf.doaf.external_applications, consDoaf.doaf.internal_funds_sent,
    consDoaf.doaf.funds_in_transit_under_custody, consDoaf.doaf.closing_balance, consDoaf.doaf.balanced]).toEqual(['3000.00', '138000.00', '0.00', '25000.00', '0.00', '7000.00', '116000.00', true])
  for (const [label, key] of [['Saldo inicial', 'opening_balance'], ['Recebimentos externos', 'external_funds_received'], ['Aplicações externas', 'external_applications'], ['Fundos em trânsito', 'funds_in_transit_under_custody'],
    ['Saldo final', 'closing_balance'], ['Total de origens', 'total_origins'], ['Total de aplicações', 'total_applications']]) await expect(custody(page, label)).toHaveText(kz(consDoaf.doaf[key]))
  await expect(page.getByText('Origens e aplicações equilibradas.')).toBeVisible()
  await layout(page, info, 'doaf-consolidated')
  await exportAndCompare(page, info, consDoaf, 'doaf-consolidated')
})

test('R06/R07/R14/R15/R17/R20 control reports render their rows and totals', async ({ page }, info) => {
  await login(page, 'cons', '/financas/relatorios')
  const received = await apply(page, { code: 'INTERNAL_FUNDS_RECEIVED', unit: 'm', period: '2026-08' })
  expect(received.transfers.map((t: { origin: { name: string }; amount: string }) => [t.origin.name, t.amount])).toEqual([[units.a.name, '40000.00']])
  await expect(page.locator('main tbody tr').filter({ hasText: units.a.name })).toContainText(kz('40000.00'))
  await expectKpis(page, [['Total', '40000.00']])
  await layout(page, info, 'internal-funds-received')

  const sent = await apply(page, { code: 'INTERNAL_FUNDS_SENT', unit: 'a2', period: '2026-08' })
  expect(sent.transfers.map((t: { destination: { name: string }; amount: string; status: string }) => [t.destination.name, t.amount, t.status])).toEqual([[units.a.name, '7000.00', 'SENT']])
  await expectKpis(page, [['Total', '7000.00']])
  await layout(page, info, 'internal-funds-sent')

  const position = await apply(page, { code: 'INTERUNIT_POSITION', unit: 'm', view: 'CONSOLIDATED', period: '2026-08' })
  expect([position.view, position.position.in_transit, position.position.sent, position.position.received]).toEqual(['CONSOLIDATED', '7000.00', '107000.00', '100000.00'])
  await expectKpis(page, [['Enviado', position.position.sent], ['Recebido', position.position.received], ['Em trânsito', position.position.in_transit]])
  await expectHeader(page, 'm', 'CONSOLIDADO')
  await layout(page, info, 'interunit-position')

  const budget = await apply(page, { code: 'BUDGET_VS_ACTUAL', unit: 'a1', view: 'OWN', kind: 'YEAR', period: '2026' })
  // approved 90000 + 25000; actual = tithes 1000 + 2000 + 100000 + electricity 20000.
  expect([budget.budget_vs_actual.approved_budget, budget.budget_vs_actual.actual, budget.budget_vs_actual.variance]).toEqual(['115000.00', '123000.00', '8000.00'])
  await expectKpis(page, [['Orçamento aprovado', '115000.00'], ['Realizado', '123000.00'], ['Desvio', '8000.00']])
  await layout(page, info, 'budget-vs-actual')

  const payables = await apply(page, { code: 'PAYABLES', unit: 'm', view: 'CONSOLIDATED', period: '2026-08' })
  expect(payables.payables.map((p: { unit: { name: string }; outstanding: string }) => [p.unit.name, p.outstanding]).sort()).toEqual([[units.a1.name, '5000.00'], [units.m.name, '5000.00']].sort())
  await expect(page.locator('main tbody tr')).toHaveCount(2)
  await expectKpis(page, [['Total em aberto', '10000.00']])
  await layout(page, info, 'payables-open')

  const balances = await apply(page, { code: 'CASH_BANK_BALANCES', unit: 'a1', view: 'OWN', period: '2026-08' })
  expect(balances.treasury.map((t: { opening: string; inflows: string; outflows: string; closing: string }) => [t.opening, t.inflows, t.outflows, t.closing])).toEqual([['3000.00', '100000.00', '75000.00', '28000.00']])
  await expect(page.locator('main tbody tr').first()).toContainText(kz('28000.00'))
  await expectKpis(page, [['Saldo final', '28000.00']])
  await layout(page, info, 'cash-bank-balances')
})

test('R21-R24 month / quarter / semester / year over a data set spanning months', async ({ page }, info) => {
  await login(page, 'cons', '/financas/relatorios')
  for (const [kind, period, revenue, from, to] of [['MONTH', '2026-08', '100000.00', '2026-08-01', '2026-08-31'], ['QUARTER', '2026-Q3', '102000.00', '2026-07-01', '2026-09-30'],
    ['SEMESTER', '2026-H1', '1000.00', '2026-01-01', '2026-06-30'], ['YEAR', '2026', '103000.00', '2026-01-01', null]] as const) {
    if (kind === 'MONTH') await page.locator('#report-kind').selectOption('MONTH')
    const r = await apply(page, { code: 'OWN_DRE', unit: 'a1', kind, period })
    expect([r.period.kind, r.period.from, r.dre.revenue], `${kind} ${period}`).toEqual([kind, from, revenue])
    if (to) expect(r.period.to).toBe(to)
    await expect(kpi(page, 'Receitas')).toHaveText(kz(revenue))
    if (kind === 'QUARTER') await layout(page, info, 'dre-quarter')
  }
})

test('R31 contributions: external origins only; in kind without approved valuation has no monetary impact', async ({ page }, info) => {
  await login(page, 'cons', '/financas/contribuicoes')
  await expect(page.getByRole('heading', { level: 1, name: 'Contribuições' })).toBeVisible()
  await page.locator('#contribution-unit').selectOption({ label: units.a1.name })
  const rows = page.locator('main tbody tr')
  await expect(rows).toHaveCount(4)
  await expect(rows.filter({ hasText: 'Em espécie' })).toContainText('Não valorizada')
  await expect(rows.filter({ hasText: kz('100000.00') })).toHaveCount(1)
  await layout(page, info, 'contributions')
  await page.locator('#contribution-unit').selectOption({ label: units.a.name })
  await expect(page.locator('main tbody tr')).toHaveCount(0)
  await page.goto(`/financas/contribuicoes/${f1d.gift}`)
  await expect(page.getByText('Sem impacto monetário')).toBeVisible()
  await expect(page.getByText('Sem valorização aprovada')).toBeVisible()
  await layout(page, info, 'contribution-in-kind')
})

test('R26 a reader without FINANCE_CONSOLIDATED_VIEW never sees or uses the consolidated view', async ({ page }, info) => {
  await login(page, 'own')
  await expect(page.getByRole('heading', { level: 1, name: 'Visão Geral Finance' })).toBeVisible()
  await expect(page.locator('#report-view option')).toHaveText(['PRÓPRIO'])
  const own = await apply(page, { unit: 'm', period: '2026-08' })
  expect([own.view, own.dashboard.revenue]).toEqual(['OWN', '30000.00'])
  await layout(page, info, 'own-reader-dashboard')
  await page.goto('/financas/relatorios')
  await page.waitForLoadState('networkidle')
  const labels = await page.locator('#report-code option').allTextContents()
  expect(labels).not.toContain('DRE consolidada')
  expect(labels).not.toContain('DOAF consolidado')
  expect(labels).toContain('DRE própria')
  await expect(page.locator('#report-view option')).toHaveText(['PRÓPRIO'])
  await layout(page, info, 'own-reader-reports')
})
