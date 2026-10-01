import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.10-F1C Finance Core browser suite on the four QA viewports: financial accounts (open BANK, derived balance, masked
// number), receivable recognised without cash then partly settled, capitalised payable with its invoice, bank statement
// import, bank reconciliation (match + close), budget review / approval by a different person + actual vs budget, unit
// month close. Fixture seeded through the API by FinanceE2EFixtureTest (one data set per viewport project).
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P010_FIXTURE_PATH ?? path.join(repo, '.tmp/p010-e2e-fixtures.json'), 'utf8'))
const evidence = path.join(repo, 'docs/reviews/evidence/P0.10-F1C')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/finance_e2e_support.php')
const supportEnv = { ...process.env, APP_ENV: 'e2e', MEPA_E2E_FINANCE_SUPPORT: '1' }
const probe = (...args: string[]) => JSON.parse(execFileSync(php, [support, ...args], { cwd: apiRoot, env: supportEnv, encoding: 'utf8' }))
const resetLimiters = () => execFileSync(php, [support, 'reset', String(fx.user_id)], { cwd: apiRoot, env: supportEnv, stdio: 'pipe' })
const kz = (amount: string) => {
  const [integer, fraction] = amount.split('.')
  return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f')},${fraction}\u00a0Kz`
}

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean }
const checks: LayoutCheck[] = []

async function login(page: Page, to = '/financas/contas') {
  await page.goto(to)
  await page.getByLabel('Utilizador').fill(fx.login)
  await page.getByLabel('Palavra-passe').fill(fx.password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
  await expect(page.getByRole('button', { name: 'Terminar sessão' })).toBeVisible()
  await page.goto(to)
  // Every context request of the shell (Finance included) answered before the journey starts.
  await page.waitForLoadState('networkidle')
}

/** 0 functional overflow, 0 clipped critical button, 0 modal outside the viewport; screenshot as evidence. */
async function layout(page: Page, info: TestInfo, screen: string) {
  const result = await page.evaluate(() => {
    const width = window.innerWidth
    const height = window.innerHeight
    const overflow = document.documentElement.scrollWidth > width + 1
    const clipped: string[] = []
    for (const button of Array.from(document.querySelectorAll<HTMLElement>('main .btn, dialog[open] .btn'))) {
      const box = button.getBoundingClientRect()
      if (box.width === 0 && box.height === 0) continue
      if (box.left < -1 || box.right > width + 1) clipped.push((button.textContent ?? '').trim())
    }
    const dialog = document.querySelector('dialog[open]')?.getBoundingClientRect()
    const dialogOutside = dialog ? dialog.left < -1 || dialog.right > width + 1 || dialog.top < -1 || dialog.bottom > height + 1 : false
    return { overflow, clipped, dialogOutside }
  })
  checks.push({ screen, overflow: result.overflow, clippedButtons: result.clipped, dialogOutside: result.dialogOutside })
  await page.screenshot({ path: path.join(evidence, `${info.project.name}-${screen}.png`), fullPage: !screen.includes('dialog') })
  expect(result.overflow, `${screen}: horizontal overflow`).toBe(false)
  expect(result.clipped, `${screen}: clipped buttons`).toEqual([])
  expect(result.dialogOutside, `${screen}: dialog outside viewport`).toBe(false)
}

// eslint-disable-next-line no-empty-pattern
test.afterAll(async ({}, info) => {
  fs.mkdirSync(evidence, { recursive: true })
  fs.writeFileSync(path.join(evidence, `visual-qa-${info.project.name}.json`), JSON.stringify({ project: info.project.name, checks }, null, 2) + '\n')
})

test('U01/U02 accounts: navigation, derived balance, open a BANK account with a masked number', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page)
  // The shell closes the menu on every route change: open it only once the page has rendered.
  await expect(page.getByRole('heading', { level: 1, name: 'Contas financeiras' })).toBeVisible()
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  const collapsed = await menu.isVisible()
  if (collapsed) await menu.click()
  const nav = page.getByRole('navigation', { name: 'Finanças' })
  for (const label of ['Contas', 'A receber', 'A pagar', 'Transferências enviadas', 'Extractos bancários', 'Reconciliação', 'Orçamento', 'Fechos', 'Posição de fundos']) await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
  if (collapsed) await menu.click()
  await expect(page.getByRole('heading', { level: 1, name: 'Contas financeiras' })).toBeVisible()
  await expect(page.getByRole('link', { name: set.bank_name })).toBeVisible()
  await layout(page, info, 'accounts')
  await page.goto(`/financas/contas/${set.bank}`)
  await expect(page.getByRole('heading', { level: 1, name: set.bank_name })).toBeVisible()
  await expect(page.locator('main').getByText(kz(set.opening)).first()).toBeVisible()
  await expect(page.getByText('Lançamentos publicados', { exact: true })).toBeVisible()
  await expect(page.getByText(/•••• \d{4}/)).toBeVisible()
  await layout(page, info, 'account-detail')
  await page.goto('/financas/contas/nova')
  await expect(page.getByRole('heading', { level: 1, name: 'Abrir conta financeira' })).toBeVisible()
  await page.getByLabel('Unidade').selectOption(fx.a1.public_id)
  await page.getByLabel('Código').fill(set.new_bank_code)
  await page.getByLabel('Nome').fill(`Banco UI ${set.new_bank_code}`)
  await page.getByLabel('Banco').fill('Banco Exemplo')
  await page.getByLabel('Número da conta').fill('0040 5555 6666 7777 1234')
  await layout(page, info, 'account-open')
  await page.getByRole('button', { name: 'Abrir conta' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Banco UI ${set.new_bank_code}` })).toBeVisible()
  await expect(page.getByText('•••• 1234')).toBeVisible()
  const opened = page.url().split('/').pop() as string
  expect(probe('account', opened, '00405555666677771234')).toMatchObject({ status: 'OPEN', kind: 'BANK', number_in_clear: false })
})

test('A01/A02/A03 receivable recognised without cash, then partly settled', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page, '/financas/a-receber/novo')
  await expect(page.getByRole('heading', { level: 1, name: 'Novo valor a receber' })).toBeVisible()
  await page.getByLabel('Unidade').selectOption(fx.a1.public_id)
  await page.getByLabel('Rubrica').selectOption('REV_OTHER')
  await page.locator('#party_name').fill('Cliente E2E')
  await page.getByLabel('Valor (Kz)').fill(set.receivable_amount)
  await page.getByLabel('Vencimento').fill('2026-12-31')
  await layout(page, info, 'receivable-new')
  await page.getByRole('button', { name: 'Reconhecer valor a receber' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Valor a receber ${kz(set.receivable_amount)}` })).toBeVisible()
  await expect(page.locator('main').getByText('Em aberto', { exact: true }).first()).toBeVisible()
  const publicId = page.url().split('/').pop() as string
  expect(probe('subledger', 'receivables', publicId)).toMatchObject({ status: 'RECOGNIZED', settlements: 0, economic_role: 'OPERATING_INCOME' })
  await page.getByRole('button', { name: 'Registar recebimento' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Conta').selectOption(fx.a1.account)
  await dialog.getByLabel('Valor (Kz)').fill('100.00')
  await layout(page, info, 'settle-dialog')
  await dialog.getByRole('button', { name: 'Registar recebimento' }).click()
  await expect(page.getByRole('cell', { name: kz('100.00') })).toBeVisible()
  expect(probe('subledger', 'receivables', publicId)).toMatchObject({ status: 'RECOGNIZED', settlements: 1, settlement_result_lines: 0 })
  await layout(page, info, 'receivable-detail')
})

test('A05/A06 payable: capitalised acquisition recognised with its invoice', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page, '/financas/a-pagar/novo')
  await expect(page.getByRole('heading', { level: 1, name: 'Novo valor a pagar' })).toBeVisible()
  await page.getByLabel('Unidade').selectOption(fx.a1.public_id)
  await page.getByLabel('Rubrica').selectOption('INV_AST_IT')
  await page.locator('#party_name').fill('Fornecedor E2E')
  await page.getByLabel('Valor (Kz)').fill(set.payable_amount)
  await page.getByLabel('Vencimento').fill('2026-12-31')
  await page.getByLabel('Documento de suporte (identificador)').fill(set.invoice)
  await page.getByRole('button', { name: 'Reconhecer valor a pagar' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Valor a pagar ${kz(set.payable_amount)}` })).toBeVisible()
  await expect(page.getByText('Activos (investimento capitalizado)')).toBeVisible()
  const publicId = page.url().split('/').pop() as string
  expect(probe('subledger', 'payables', publicId)).toMatchObject({ status: 'RECOGNIZED', economic_role: 'FIXED_ASSETS' })
  await layout(page, info, 'payable-detail')
})

test('B01/B02 bank statement imported as an external fact', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page, '/financas/extractos/novo')
  await expect(page.getByRole('heading', { level: 1, name: 'Importar extracto bancário' })).toBeVisible()
  await page.getByLabel('Conta bancária').selectOption(set.bank)
  await page.getByLabel('Ficheiro do extracto (identificador do documento)').fill(set.statement_document)
  await page.locator('#starts_on').fill('2026-09-01')
  await page.locator('#ends_on').fill('2026-09-30')
  await page.getByLabel('Saldo inicial (Kz)').fill('0.00')
  await page.getByLabel('Saldo final (Kz)').fill('50.00')
  await page.getByLabel('Data').fill('2026-09-05')
  await page.getByLabel('Valor (Kz)').fill('50.00')
  await page.getByLabel('Descrição').fill('Depósito E2E')
  await layout(page, info, 'statement-new')
  await page.getByRole('button', { name: 'Importar extracto' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Extracto ${set.bank_name}` })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Depósito E2E' })).toBeVisible()
  await expect(page.locator('main').getByText('Por reconciliar', { exact: true }).first()).toBeVisible()
  await layout(page, info, 'statement-detail')
})

test('B04 bank reconciliation: match the statement line with the opening entry and close the version', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page, `/financas/reconciliacao/${set.reconciliation}`)
  await expect(page.getByRole('heading', { level: 1, name: `Reconciliação ${set.bank_name} · 2026-09` })).toBeVisible()
  await layout(page, info, 'reconciliation')
  await page.getByRole('button', { name: 'Corresponder' }).first().click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Movimento contabilístico').selectOption({ index: 1 })
  await dialog.getByLabel('Valor a reconciliar (Kz)').fill(set.opening)
  await layout(page, info, 'match-dialog')
  await dialog.getByRole('button', { name: 'Corresponder' }).click()
  await expect(page.getByRole('heading', { name: 'Correspondências desta reconciliação' })).toBeVisible()
  await expect(page.locator('main').getByText('Reconciliada', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Fechar reconciliação' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Fechar reconciliação' }).click()
  await expect(page.locator('main').getByText('Fechada', { exact: true })).toBeVisible()
  expect(probe('reconciliation', set.reconciliation)).toMatchObject({ status: 'CLOSED', matches: 1 })
  await layout(page, info, 'reconciliation-closed')
})

test('G03/G04 budget reviewed and approved by a different person, then actual vs budget', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page, `/financas/orcamento/${set.budget}`)
  await expect(page.getByRole('heading', { level: 1, name: `Orçamento 2026 · ${set.budget_unit}` })).toBeVisible()
  await expect(page.locator('main').getByText('Submetido', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Rever', exact: true }).click()
  await layout(page, info, 'budget-review-dialog')
  await page.getByRole('dialog').getByRole('button', { name: 'Rever', exact: true }).click()
  await expect(page.locator('main').getByText('Revisto', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Aprovar', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Aprovar', exact: true }).click()
  // "Aprovado" is also a column header: wait for the action that only an APPROVED version offers.
  await expect(page.getByRole('button', { name: 'Criar revisão', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Orçamento vs real' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Energia Eléctrica' }).first()).toBeVisible()
  expect(probe('budget', set.budget)).toMatchObject({ status: 'APPROVED', segregated: true })
  await layout(page, info, 'budget-detail')
})

test('P01 unit month close from the closes screen', async ({ page }, info) => {
  const set = fx.f1c[info.project.name]
  await login(page, '/financas/fechos')
  await expect(page.getByRole('heading', { level: 1, name: 'Fechos' })).toBeVisible()
  await page.getByLabel('Unidade', { exact: true }).selectOption(fx.a1.public_id)
  const row = page.getByRole('row').filter({ hasText: set.month })
  await row.getByRole('button', { name: 'Fechar mês' }).click()
  await layout(page, info, 'period-close-dialog')
  await page.getByRole('dialog').getByRole('button', { name: 'Fechar mês' }).click()
  await expect(page.getByRole('row').filter({ hasText: set.month }).getByText('Fechado', { exact: true })).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: set.month }).getByRole('button', { name: 'Reabrir' })).toHaveCount(0)
  await layout(page, info, 'periods')
})
