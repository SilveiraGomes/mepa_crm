import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.10-F2A RH / Folha Salarial browser suite on the 4 viewports (1440x900, 1366x768, 768x1024, 390x844): employee list,
// employee detail, employment history, compensation, components, rules, readiness; a reader without HR_COMPENSATION_VIEW
// never sees a salary; the compensation change dialog works on every viewport (mobile included). Fixture seeded through the
// HR API by FinanceE2EFixtureTest::payrollWorld (the rule there is SYNTHETIC test data, never an official value).
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P010_FIXTURE_PATH ?? path.join(repo, '.tmp/p010-e2e-fixtures.json'), 'utf8'))
const f2a = fx.f2a
const evidence = process.env.P010_F2A_EVIDENCE_DIR ?? path.join(repo, 'docs/reviews/evidence/P0.10-F2A')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/finance_e2e_support.php')
const supportEnv = { ...process.env, APP_ENV: 'e2e', MEPA_E2E_FINANCE_SUPPORT: '1' }
const probe = (...args: string[]) => JSON.parse(execFileSync(php, [support, ...args], { cwd: apiRoot, env: supportEnv, encoding: 'utf8' }))
const resetLimiters = () => execFileSync(php, [support, 'reset', String(f2a.readers.hr.user_id), String(f2a.readers.viewer.user_id)], { cwd: apiRoot, env: supportEnv, stdio: 'pipe' })
const kz = (amount: string) => {
  const [integer, fraction] = amount.split('.')
  return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f')},${fraction.slice(0, 2)}\u00a0Kz`
}

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean; hiddenControls: string[]; unusableTables: number }
const checks: LayoutCheck[] = []

async function login(page: Page, reader: 'hr' | 'viewer', to: string) {
  await page.goto(to)
  await page.getByLabel('Utilizador').fill(f2a.readers[reader].login)
  await page.getByLabel('Palavra-passe').fill(f2a.readers[reader].password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
  await expect(page.getByRole('button', { name: 'Terminar sessão' })).toBeVisible()
  await page.goto(to)
  await page.waitForLoadState('networkidle')
}

/** 0 overflow, 0 clipped button, 0 dialog outside the viewport, 0 control out of reach, 0 table without its own scroll. */
async function layout(page: Page, info: TestInfo, screen: string) {
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
  checks.push({ screen, overflow: result.overflow, clippedButtons: result.clipped, dialogOutside: result.dialogOutside, hiddenControls: result.hiddenControls, unusableTables: result.unusableTables })
  await page.screenshot({ path: path.join(evidence, `${info.project.name}-${screen}.png`), fullPage: !screen.includes('dialog') })
  expect(result.overflow, `${screen}: horizontal overflow`).toBe(false)
  expect(result.clipped, `${screen}: clipped buttons`).toEqual([])
  expect(result.dialogOutside, `${screen}: dialog outside viewport`).toBe(false)
  expect(result.hiddenControls, `${screen}: controls out of reach`).toEqual([])
  expect(result.unusableTables, `${screen}: tables without their own scroll`).toBe(0)
}

// eslint-disable-next-line no-empty-pattern
test.afterAll(async ({}, info) => {
  fs.mkdirSync(evidence, { recursive: true })
  fs.writeFileSync(path.join(evidence, `visual-qa-${info.project.name}.json`), JSON.stringify({ project: info.project.name, checks }, null, 2) + '\n')
})

async function navLinks(page: Page): Promise<string[]> {
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  const collapsed = await menu.isVisible()
  if (collapsed) await menu.click()
  const nav = page.getByRole('navigation', { name: 'RH / Folha Salarial' })
  const labels = (await nav.count()) === 0 ? [] : await nav.getByRole('link').allTextContents()
  if (collapsed) await menu.click()
  return labels
}

test('F2A employees: list, detail, employment history and compensation (HR reader, audited salary reads)', async ({ page }, info) => {
  await login(page, 'hr', '/rh/funcionarios')
  await expect(page.getByRole('heading', { level: 1, name: 'Funcionários' })).toBeVisible()
  // P0.10-F2B added Folhas Salariais (the HR reader holds PAYROLL_MANAGE).
  expect(await navLinks(page)).toEqual(['Funcionários', 'Vínculos', 'Remuneração', 'Componentes', 'Regras', 'Prontidão da Folha', 'Folhas Salariais'])
  await expect(page.locator('main tbody tr').filter({ hasText: f2a.ana.name })).toContainText('Secretária administrativa')
  await layout(page, info, 'employee-list')

  const before = probe('audit', String(f2a.readers.hr.user_id), 'hr.compensation_viewed').count
  await page.locator('main').getByRole('link', { name: f2a.ana.name }).click()
  await expect(page.getByRole('heading', { level: 1, name: f2a.ana.name })).toBeVisible()
  const current = page.locator('main section').filter({ has: page.getByRole('heading', { name: 'Remuneração vigente' }) })
  await expect(current.locator('tbody tr').filter({ hasText: 'Salário base' })).toContainText(kz('165000.00'))
  await expect(current.locator('tbody tr').filter({ hasText: 'INSS (trabalhador)' })).toContainText('Por regra legal')
  const history = page.locator('main section').filter({ has: page.getByRole('heading', { name: 'Histórico de remuneração' }) })
  await expect(history.locator('tbody tr').filter({ hasText: kz('150000.00') })).toHaveCount(1)
  await layout(page, info, 'employee-detail')
  expect(probe('audit', String(f2a.readers.hr.user_id), 'hr.compensation_viewed').count).toBeGreaterThan(before)

  await page.goto(`/rh/funcionarios/${f2a.bruno.employment}`)
  const employments = page.locator('main section').filter({ has: page.getByRole('heading', { name: 'Histórico de vínculos' }) })
  await expect(employments.locator('tbody tr')).toHaveCount(2)
  await expect(employments.locator('tbody tr').first()).toContainText(f2a.units.a.name)
  await expect(employments.locator('tbody tr').first()).toContainText('Encerrado')
  await layout(page, info, 'employment-history')

  await page.goto('/rh/vinculos')
  await page.locator('#hr-status').selectOption('ENDED')
  await expect(page.locator('main tbody tr').filter({ hasText: f2a.bruno.name })).toContainText('Encerrado')
  await layout(page, info, 'employments')

  await page.goto('/rh/remuneracao')
  await page.locator('#comp-unit').selectOption({ label: f2a.units.a1.name })
  await expect(page.locator('main tbody tr').filter({ hasText: f2a.ana.name })).toContainText(kz('165000.00'))
  await layout(page, info, 'compensation')
})

test('F2A components, rules (pending configuration, provenance) and readiness (configuration vs production)', async ({ page }, info) => {
  await login(page, 'hr', '/rh/componentes')
  await expect(page.locator('main tbody tr')).toHaveCount(13)
  await expect(page.locator('main')).not.toContainText('%')
  await layout(page, info, 'components')

  await page.goto('/rh/regras')
  const coverage = page.locator('main section').filter({ has: page.getByRole('heading', { name: /^Cobertura em/ }) })
  await expect(coverage.locator('tbody tr').filter({ hasText: 'Retenção de IRT' })).toContainText('Configuração pendente')
  await expect(coverage.locator('tbody tr').filter({ hasText: 'INSS (trabalhador)' })).toContainText('SINTETICO_E2E_INSS v1')
  const versions = page.locator('main section').filter({ has: page.getByRole('heading', { name: 'Versões de regras' }) })
  await expect(versions.locator('tbody tr').filter({ hasText: 'SINTETICO_E2E_INSS v1' })).toContainText('Aprovada')
  await expect(versions.locator('tbody tr').filter({ hasText: 'SINTETICO_E2E_IRT v1' })).toContainText('Rascunho')
  await layout(page, info, 'rules')
  await versions.getByRole('link', { name: 'SINTETICO_E2E_INSS v1' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'SINTETICO_E2E_INSS v1' })).toBeVisible()
  await expect(page.locator('main')).toContainText('Aprovada por')
  await layout(page, info, 'rule-detail')

  await page.goto('/rh/prontidao')
  await page.locator('#ready-unit').selectOption({ label: f2a.units.a1.name })
  await page.locator('#ready-period').fill('2026-09')
  await expect(page.getByTestId('configuration-readiness')).toContainText('PRONTA')
  await expect(page.getByTestId('configuration-readiness')).not.toContainText('NÃO PRONTA')
  await expect(page.getByTestId('production-status')).toContainText('DESACTIVADA')
  // P0.10-F2B: the run actions live in Folhas Salariais (server-gated per run); readiness only links there.
  for (const action of ['Processar', 'Aprovar', 'Contabilizar', 'Pagar']) await expect(page.getByRole('button', { name: new RegExp(action) })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Ver folhas salariais' })).toBeVisible()
  await layout(page, info, 'readiness')
  await page.locator('#ready-unit').selectOption({ label: f2a.units.m.name })
  await expect(page.getByTestId('configuration-readiness')).toContainText('NÃO PRONTA')
  await expect(page.locator('main')).toContainText('Sem vínculos activos no mês')
  await expect(page.getByTestId('production-status')).toContainText('DESACTIVADA')
})

test('F2A a reader without HR_COMPENSATION_VIEW never sees a salary', async ({ page }, info) => {
  await login(page, 'viewer', '/rh/funcionarios')
  expect(await navLinks(page)).toEqual(['Funcionários', 'Vínculos', 'Componentes'])
  await page.goto(`/rh/funcionarios/${f2a.ana.employment}`)
  await expect(page.getByRole('heading', { level: 1, name: f2a.ana.name })).toBeVisible()
  await expect(page.getByText('Remuneração reservada')).toBeVisible()
  const text = (await page.locator('main').innerText()).replace(/\s/g, '')
  expect(text).not.toMatch(/165\D?000|150\D?000|Kz/)
  await expect(page.getByRole('button', { name: 'Alterar remuneração' })).toHaveCount(0)
  await layout(page, info, 'viewer-detail')
  await page.goto('/rh/remuneracao')
  await expect(page.getByText('Sem acesso')).toBeVisible()
})

test('F2A the compensation change works on every viewport (new line; the old one is closed, not edited)', async ({ page }, info) => {
  const set = f2a.projects[info.project.name]
  await login(page, 'hr', `/rh/funcionarios/${set.employment}`)
  await expect(page.getByRole('heading', { level: 1, name: set.name })).toBeVisible()
  await page.getByRole('button', { name: 'Alterar remuneração' }).click()
  const dialog = page.locator('dialog[open]').filter({ has: page.getByRole('heading', { name: 'Alterar remuneração' }) })
  await expect(dialog).toBeVisible()
  await dialog.locator('#component').selectOption('TRANSPORT_MEAL_ALLOWANCE')
  await dialog.locator('#amount').fill(set.allowance)
  await dialog.locator('#starts_on').fill('2026-09-01')
  await dialog.locator('#reason').fill('Subsídio de transporte E2E')
  await layout(page, info, 'compensation-dialog')
  await dialog.getByRole('button', { name: 'Registar' }).click()
  await expect(page.getByText('Nova linha de remuneração registada')).toBeVisible()
  await expect(dialog).toBeHidden()
  const history = page.locator('main section').filter({ has: page.getByRole('heading', { name: 'Histórico de remuneração' }) })
  await expect(history.locator('tbody tr').filter({ hasText: 'Subsídio de transporte' })).toContainText(kz(set.allowance))
  await layout(page, info, 'compensation-after-change')
})
