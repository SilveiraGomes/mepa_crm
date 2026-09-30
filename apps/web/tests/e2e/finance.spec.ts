import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.10-F1B Finance browser suite: transfer lists (sent / received / in transit), new transfer -> SEND -> RECEIVE
// (T27: the same flow on the mobile viewport), received detail + interunit reconciliation, fund custody position, and
// visual QA on 4 viewports. Fixture seeded by FinanceE2EFixtureTest through the API: one data set per viewport project.
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P010_FIXTURE_PATH ?? path.join(repo, '.tmp/p010-e2e-fixtures.json'), 'utf8'))
const evidence = path.join(repo, 'docs/reviews/evidence/P0.10-F1B')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/finance_e2e_support.php')
const supportEnv = { ...process.env, APP_ENV: 'e2e', MEPA_E2E_FINANCE_SUPPORT: '1' }
const resetLimiters = () => execFileSync(php, [support, 'reset', String(fx.user_id)], { cwd: apiRoot, env: supportEnv, stdio: 'pipe' })
const transferState = (publicId: string) => JSON.parse(execFileSync(php, [support, 'transfer', publicId], { cwd: apiRoot, env: supportEnv, encoding: 'utf8' }))
const kz = (amount: string) => {
  const [integer, fraction] = amount.split('.')
  return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f')},${fraction}\u00a0Kz`
}

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean }
const checks: LayoutCheck[] = []

async function login(page: Page) {
  await page.goto('/financas/transferencias')
  await page.getByLabel('Utilizador').fill(fx.login)
  await page.getByLabel('Palavra-passe').fill(fx.password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/entrar/)
  await expect(page.getByRole('button', { name: 'Terminar sessão' })).toBeVisible()
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

test('transfer lists: sent, received and in transit with origin, destination, purpose, value, dates and state', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  const collapsed = await menu.isVisible()
  if (collapsed) await menu.click()
  const nav = page.getByRole('navigation', { name: 'Finanças' })
  for (const label of ['Transferências enviadas', 'Transferências recebidas', 'Em trânsito', 'Posição de fundos', 'Nova transferência']) await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
  if (collapsed) await menu.click()
  await page.goto('/financas/transferencias')
  await expect(page.getByRole('heading', { level: 1, name: 'Transferências enviadas' })).toBeVisible()
  await expect(page.getByText('nunca o resultado económico')).toBeVisible()
  await layout(page, info, 'transfers-sent')
  await page.getByRole('navigation', { name: 'Secções de Finanças' }).getByRole('link', { name: 'Recebidas' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Transferências recebidas' })).toBeVisible()
  await expect(page.getByRole('link', { name: kz(set.received_amount) })).toBeVisible()
  await layout(page, info, 'transfers-received')
  await page.getByRole('navigation', { name: 'Secções de Finanças' }).getByRole('link', { name: 'Em trânsito' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Fundos em trânsito' })).toBeVisible()
  await expect(page.getByRole('link', { name: kz(set.transit_amount) })).toBeVisible()
  await expect(page.getByText('Não são receita nem despesa')).toBeVisible()
  await layout(page, info, 'in-transit')
})

test('received detail shows origin, destination, purpose, value, dates and reconciles the pairing', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  await page.goto(`/financas/transferencias/${set.received}`)
  await expect(page.getByRole('heading', { level: 1, name: `Transferência ${kz(set.received_amount)}` })).toBeVisible()
  for (const text of ['Origem', 'Destino', 'Finalidade', 'Data de envio', 'Data de recepção', 'Reconciliação', 'Efeito económico']) await expect(page.getByText(text, { exact: true })).toBeVisible()
  await expect(page.getByText(fx.a1.name).first()).toBeVisible()
  await expect(page.getByText('Remessa regular')).toBeVisible()
  await expect(page.getByText('Por reconciliar')).toBeVisible()
  await layout(page, info, 'received-detail')
  await page.getByRole('button', { name: 'Reconciliar' }).click()
  await layout(page, info, 'reconcile-dialog')
  await page.getByRole('dialog').getByRole('button', { name: 'Reconciliar' }).click()
  await expect(page.getByText('Reconciliada', { exact: true })).toBeVisible()
  expect(transferState(set.received)).toMatchObject({ status: 'RECEIVED', reconciled: true, result_lines: 0 })
})

test('T27 new transfer -> send -> receive on every viewport (mobile included), never touching the result', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  await page.goto('/financas/transferencias/nova')
  await expect(page.getByRole('heading', { level: 1, name: 'Nova transferência' })).toBeVisible()
  await page.getByLabel('Conta de origem').selectOption(fx.a1.account)
  await page.getByLabel('Unidade de destino').fill('Centro A E2E')
  await page.getByRole('list', { name: 'Unidades encontradas' }).getByRole('button', { name: 'Seleccionar' }).first().click()
  await expect(page.getByText('Destino:')).toBeVisible()
  await page.getByLabel('Valor (Kz)').fill(set.new_amount)
  await page.getByLabel('Finalidade').selectOption('TRF_PROJECT')
  await layout(page, info, 'new-transfer')
  await page.getByRole('button', { name: 'Preparar transferência' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Transferência ${kz(set.new_amount)}` })).toBeVisible()
  await expect(page.getByText('Rascunho', { exact: true })).toBeVisible()
  const publicId = page.url().split('/').pop() as string
  await page.getByRole('button', { name: 'Confirmar envio' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar envio' }).click()
  const main = page.locator('main')
  await expect(main.getByText('Enviada', { exact: true })).toBeVisible()
  await expect(main.getByText('Em trânsito', { exact: true }).first()).toBeVisible()
  await layout(page, info, 'sent-detail')
  expect(transferState(publicId)).toMatchObject({ status: 'SENT', postings: ['TRANSFER_SEND'], result_lines: 0 })
  await page.getByRole('button', { name: 'Confirmar recepção' }).click()
  await page.getByRole('dialog').getByLabel('Conta de destino').selectOption(fx.a.account)
  await layout(page, info, 'receive-dialog')
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar recepção' }).click()
  await expect(main.getByText('Recebida', { exact: true })).toBeVisible()
  await expect(page.getByText('Recepção (destino)')).toBeVisible()
  expect(transferState(publicId)).toMatchObject({ status: 'RECEIVED', postings: ['TRANSFER_SEND', 'TRANSFER_RECEIVE'], result_lines: 0 })
})

test('fund custody position shows where funds came from and went, apart from the economic result', async ({ page }, info) => {
  await login(page)
  await page.goto('/financas/posicao')
  await expect(page.getByRole('heading', { level: 1, name: 'Posição de fundos' })).toBeVisible()
  await page.getByLabel('Unidade').selectOption(fx.a.public_id)
  await page.getByLabel('De', { exact: true }).fill('2026-01-01')
  await expect(page.getByRole('heading', { name: 'Fundos internos recebidos · de onde vieram' })).toBeVisible()
  await expect(page.getByRole('cell', { name: fx.a1.name }).first()).toBeVisible()
  await expect(page.getByText('Resultado económico (separado da custódia)')).toBeVisible()
  await expect(page.getByText('= Saldo final sob gestão')).toBeVisible()
  await layout(page, info, 'custody')
})
