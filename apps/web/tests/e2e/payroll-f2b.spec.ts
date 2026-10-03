import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.10-F2B Folhas Salariais browser suite on the 4 viewports (1440x900, 1366x768, 768x1024, 390x844).
//   @disabled  (API server with payroll.production_enabled = false, the shipped default): run list, new run, calculation,
//              calculated detail, per-person breakdown (HR_COMPENSATION_VIEW, audited), approver without salary view, no
//              approve / post / pay action offered.
//   @enabled   (a SECOND API server started by the runner with the flag on for this test process only): approval by a
//              different person, Finance posting, payment of the net; the action set always matches the run status.
// Fixture seeded through the API by FinanceE2EFixtureTest::payrollRunWorld (synthetic salaries and rule; test data only).
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P010_FIXTURE_PATH ?? path.join(repo, '.tmp/p010-e2e-fixtures.json'), 'utf8'))
const f2b = fx.f2b
const evidence = process.env.P010_F2B_EVIDENCE_DIR ?? path.join(repo, 'docs/reviews/evidence/P0.10-F2B')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/finance_e2e_support.php')
const supportEnv = { ...process.env, APP_ENV: 'e2e', MEPA_E2E_FINANCE_SUPPORT: '1' }
const probe = (...args: string[]) => JSON.parse(execFileSync(php, [support, ...args], { cwd: apiRoot, env: supportEnv, encoding: 'utf8' }))
const users = ['calc', 'approver', 'poster'] as const
type Reader = typeof users[number]
const resetLimiters = () => execFileSync(php, [support, 'reset', ...users.map((u) => String(f2b.readers[u].user_id))], { cwd: apiRoot, env: supportEnv, stdio: 'pipe' })
const kz = (amount: string) => {
  const [integer, fraction] = amount.split('.')
  return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f')},${fraction.slice(0, 2)}\u00a0Kz`
}

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean; hiddenControls: string[]; unusableTables: number; salaryVisible?: boolean }
const checks: LayoutCheck[] = []

async function login(page: Page, reader: Reader, to: string) {
  await page.goto(to)
  await page.getByLabel('Utilizador').fill(f2b.readers[reader].login)
  await page.getByLabel('Palavra-passe').fill(f2b.readers[reader].password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
  await expect(page.getByRole('button', { name: 'Terminar sessão' })).toBeVisible()
  await page.goto(to)
  await page.waitForLoadState('networkidle')
}

async function as(browser: Browser, info: TestInfo, reader: Reader, to: string): Promise<Page> {
  const context = await browser.newContext({ ...info.project.use, baseURL: 'http://127.0.0.1:14173' })
  const page = await context.newPage()
  await login(page, reader, to)
  return page
}

/** 0 overflow, 0 clipped button, 0 dialog outside the viewport, 0 control out of reach, 0 table without its own scroll. */
async function layout(page: Page, info: TestInfo, screen: string, extra: Partial<LayoutCheck> = {}) {
  await page.waitForLoadState('networkidle')
  const result = await page.evaluate(() => {
    const width = window.innerWidth
    const height = window.innerHeight
    const outside = (box: DOMRect) => box.left < -1 || box.right > width + 1
    const overflow = document.documentElement.scrollWidth > width + 1
    const scope = document.querySelector('dialog[open]') ? 'dialog[open]' : 'main'
    const clipped: string[] = []
    for (const button of Array.from(document.querySelectorAll<HTMLElement>(`${scope} .btn`))) {
      const box = button.getBoundingClientRect()
      if (box.width === 0 && box.height === 0) continue
      if (outside(box)) clipped.push((button.textContent ?? '').trim())
    }
    const hiddenControls: string[] = []
    for (const control of Array.from(document.querySelectorAll<HTMLElement>(`${scope} select, ${scope} input, ${scope} textarea`))) {
      const box = control.getBoundingClientRect()
      if (box.width === 0 && box.height === 0) continue
      if (box.width < 40 || outside(box)) hiddenControls.push(control.id || control.getAttribute('name') || '?')
    }
    let unusableTables = 0
    for (const wrap of Array.from(document.querySelectorAll<HTMLElement>('main .table-wrap'))) {
      const scrolls = ['auto', 'scroll'].includes(getComputedStyle(wrap).overflowX)
      if (outside(wrap.getBoundingClientRect()) || (wrap.scrollWidth > wrap.clientWidth + 1 && !scrolls)) unusableTables++
    }
    const dialog = document.querySelector('dialog[open]')?.getBoundingClientRect()
    const dialogOutside = dialog ? dialog.left < -1 || dialog.right > width + 1 || dialog.top < -1 || dialog.bottom > height + 1 : false
    return { overflow, clipped, dialogOutside, hiddenControls, unusableTables }
  })
  checks.push({ screen, overflow: result.overflow, clippedButtons: result.clipped, dialogOutside: result.dialogOutside, hiddenControls: result.hiddenControls, unusableTables: result.unusableTables, ...extra })
  fs.mkdirSync(evidence, { recursive: true })
  await page.screenshot({ path: path.join(evidence, `${info.project.name}-${screen}.png`), fullPage: !screen.includes('dialog') })
  expect(result.overflow, `${screen}: horizontal overflow`).toBe(false)
  expect(result.clipped, `${screen}: clipped buttons`).toEqual([])
  expect(result.dialogOutside, `${screen}: dialog outside viewport`).toBe(false)
  expect(result.hiddenControls, `${screen}: controls out of reach`).toEqual([])
  expect(result.unusableTables, `${screen}: tables without their own scroll`).toBe(0)
}

/** The run's action buttons: exactly the ones the status allows (never an action incoherent with the status). */
async function actions(page: Page): Promise<string[]> {
  return (await page.getByTestId('run-actions').getByRole('button').allTextContents()).map((t) => t.trim()).sort()
}

const salaryPattern = /120\D?000|95\D?000|119\D?800|94\D?050/

// eslint-disable-next-line no-empty-pattern
test.afterAll(async ({}, info) => {
  fs.mkdirSync(evidence, { recursive: true })
  const file = path.join(evidence, `visual-qa-${info.project.name}.json`)
  const prior = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')).checks as LayoutCheck[] : []
  fs.writeFileSync(file, JSON.stringify({ project: info.project.name, checks: [...prior, ...checks] }, null, 2) + '\n')
})

test('@disabled F2B run list, calculated detail and the audited per-person breakdown (calculator)', async ({ page }, info) => {
  const set = f2b.projects[info.project.name]
  await login(page, 'calc', '/rh/folhas')
  await expect(page.getByRole('heading', { level: 1, name: 'Folhas Salariais' })).toBeVisible()
  await expect(page.getByTestId('production-disabled')).toBeVisible()
  await page.locator('#run-period').fill(set.month)
  const row = page.locator('main tbody tr').filter({ hasText: set.month })
  await expect(row).toContainText('Calculada')
  await expect(row).toContainText(kz(f2b.totals.net))
  await layout(page, info, 'run-list')
  await row.getByRole('link', { name: set.month }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Folha ${set.month} · ${f2b.unit.name}` })).toBeVisible()
  await expect(page.getByTestId('payroll-totals')).toContainText(kz(f2b.totals.gross))
  await expect(page.getByTestId('payroll-totals')).toContainText(kz(f2b.totals.deductions))
  await expect(page.getByTestId('run-input')).toContainText('Inputs actuais')
  expect(await actions(page)).toEqual(['Cancelar folha', 'Recalcular'])
  await expect(page.getByTestId('production-disabled')).toBeVisible()
  await layout(page, info, 'run-detail-calculated')
  const before = probe('audit', String(f2b.readers.calc.user_id), 'payroll.employee_detail_viewed').count
  await page.getByRole('button', { name: 'Mostrar detalhe por trabalhador' }).click()
  const breakdown = page.getByTestId('run-breakdown')
  await expect(breakdown).toContainText(f2b.people[0].name)
  await expect(breakdown).toContainText(kz('120000.00'))
  await expect(breakdown).toContainText('Regra legal SINTETICO_E2E_INSS v1')
  await layout(page, info, 'run-breakdown')
  expect(probe('audit', String(f2b.readers.calc.user_id), 'payroll.employee_detail_viewed').count).toBe(before + 1)
})

test('@disabled F2B new run and calculation while production is disabled', async ({ page }, info) => {
  const set = f2b.projects[info.project.name]
  await login(page, 'calc', '/rh/folhas/nova')
  await expect(page.getByRole('heading', { level: 1, name: 'Nova folha salarial' })).toBeVisible()
  await page.locator('#unit').selectOption({ label: f2b.unit.name })
  await page.locator('#period').fill(set.new_month)
  await layout(page, info, 'run-new')
  await page.getByRole('button', { name: 'Criar folha' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Folha ${set.new_month} · ${f2b.unit.name}` })).toBeVisible()
  await expect(page.locator('main')).toContainText('Rascunho')
  expect(await actions(page)).toEqual(['Calcular', 'Cancelar folha'])
  await page.getByRole('button', { name: 'Calcular' }).click()
  const dialog = page.locator('dialog[open]').filter({ has: page.getByRole('heading', { name: 'Calcular' }) })
  await expect(dialog).toBeVisible()
  await layout(page, info, 'run-calculate-dialog')
  await dialog.getByRole('button', { name: 'Confirmar' }).click()
  await expect(page.getByText('Folha calculada a partir dos dados vigentes no mês de serviço.', { exact: true }).first()).toBeVisible()
  await expect(page.getByTestId('run-header')).toContainText('Calculada')
  await expect(page.getByTestId('payroll-totals')).toContainText(kz(f2b.totals.net))
  await layout(page, info, 'run-calculated-new')
})

test('@disabled F2B an approver without HR_COMPENSATION_VIEW sees totals only and no approval while production is disabled', async ({ page }, info) => {
  const set = f2b.projects[info.project.name]
  await login(page, 'approver', `/rh/folhas/${set.run}`)
  await expect(page.getByRole('heading', { level: 1, name: `Folha ${set.month} · ${f2b.unit.name}` })).toBeVisible()
  await expect(page.getByTestId('run-breakdown')).toContainText('Reservado')
  await expect(page.getByRole('button', { name: 'Mostrar detalhe por trabalhador' })).toHaveCount(0)
  expect(await actions(page)).toEqual([])
  await expect(page.getByTestId('run-blocked')).toContainText('Produção salarial desactivada')
  const text = await page.locator('main').innerText()
  expect(text).not.toMatch(salaryPattern)
  expect(text).not.toContain(f2b.people[0].name)
  await layout(page, info, 'run-approver-disabled', { salaryVisible: salaryPattern.test(text) })
})

test('@enabled F2B approve (approver) -> post -> pay (poster); actions always match the status', async ({ browser }, info) => {
  const set = f2b.projects[info.project.name]
  const approver = await as(browser, info, 'approver', `/rh/folhas/${set.run}`)
  expect(await actions(approver)).toEqual(['Aprovar'])
  await approver.getByRole('button', { name: 'Aprovar' }).click()
  const approveDialog = approver.locator('dialog[open]').filter({ has: approver.getByRole('heading', { name: 'Aprovar' }) })
  await layout(approver, info, 'run-approve-dialog')
  await approveDialog.getByRole('button', { name: 'Confirmar' }).click()
  await expect(approver.getByText('Folha aprovada. As linhas ficam imutáveis.', { exact: true }).first()).toBeVisible()
  await expect(approver.getByTestId('run-header')).toContainText('Aprovada')
  expect(await actions(approver)).toEqual([])
  await expect(approver.getByTestId('run-input')).toContainText('Congelados')
  expect(await approver.locator('main').innerText()).not.toMatch(salaryPattern)
  await layout(approver, info, 'run-approved')
  await approver.context().close()

  const poster = await as(browser, info, 'poster', `/rh/folhas/${set.run}`)
  expect(await actions(poster)).toEqual(['Contabilizar'])
  await poster.getByRole('button', { name: 'Contabilizar' }).click()
  const postDialog = poster.locator('dialog[open]').filter({ has: poster.getByRole('heading', { name: 'Contabilizar' }) })
  await layout(poster, info, 'run-post-dialog')
  await postDialog.getByRole('button', { name: 'Confirmar' }).click()
  await expect(poster.getByText('Folha contabilizada no Finance (lançamento agregado).', { exact: true }).first()).toBeVisible()
  await expect(poster.getByTestId('run-header')).toContainText('Contabilizada')
  await expect(poster.getByTestId('run-finance')).toContainText('PAYROLL_ACCRUAL')
  await expect(poster.getByTestId('run-finance')).toContainText('Líquido por pagar')
  expect(await actions(poster)).toEqual(['Anular processamento', 'Pagar líquido'])
  await layout(poster, info, 'run-posted')
  await poster.getByRole('button', { name: 'Pagar líquido' }).click()
  const payDialog = poster.locator('dialog[open]').filter({ has: poster.getByRole('heading', { name: 'Pagar líquido' }) })
  await expect(payDialog).toContainText(kz(f2b.totals.net))
  await payDialog.locator('#pay_account').selectOption(f2b.account)
  await layout(poster, info, 'run-pay-dialog')
  await payDialog.getByRole('button', { name: 'Confirmar' }).click()
  await expect(poster.getByText('Líquido pago. Retenções e encargos continuam a pagar até à sua liquidação própria.', { exact: true }).first()).toBeVisible()
  await expect(poster.getByTestId('run-header')).toContainText('Paga')
  await expect(poster.getByTestId('run-finance')).toContainText('A entregar (INSS / IRT / encargos)')
  expect(await actions(poster)).toEqual([])
  expect(await poster.locator('main').innerText()).not.toMatch(salaryPattern)
  await layout(poster, info, 'run-paid')
  await poster.context().close()
})
