import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.9 Membership browser suite (M01-M03 via the UI, M10 completion, M23 mobile detail/history, M24 collective admission,
// visual QA). Fixture seeded by MembershipE2EFixtureTest: one independent data set per viewport project.
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P09_FIXTURE_PATH ?? path.join(repo, '.tmp/p09-e2e-fixtures.json'), 'utf8'))
const evidence = path.join(repo, 'docs/reviews/evidence/P0.9')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/membership_e2e_support.php')
const supportEnv = { ...process.env, APP_ENV: 'e2e', MEPA_E2E_MEMBERSHIP_SUPPORT: '1' }
const resetLimiters = () => execFileSync(php, [support, 'reset', String(fx.user_id)], { cwd: apiRoot, env: supportEnv, stdio: 'pipe' })
const member = (publicId: string) => JSON.parse(execFileSync(php, [support, 'member', publicId], { cwd: apiRoot, env: supportEnv, encoding: 'utf8' }))

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean }
const checks: LayoutCheck[] = []

async function login(page: Page) {
  await page.goto('/membros')
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

test('members list, navigation and scoped search by official number', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  await page.goto('/membros')
  await expect(page.getByRole('heading', { level: 1, name: 'Membros' })).toBeVisible()
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  const collapsed = await menu.isVisible()
  if (collapsed) await menu.click()
  const nav = page.getByRole('navigation', { name: 'Membros' })
  for (const label of ['Membros', 'Admissões', 'Transferências']) await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
  if (collapsed) await menu.click()
  await layout(page, info, 'members-list')
  await page.getByLabel('Nome, número oficial ou identificador anterior').fill(set.member.number)
  await page.getByRole('button', { name: 'Pesquisar' }).click()
  await expect(page.getByRole('link', { name: set.member.name })).toBeVisible()
  await expect(page.getByText(set.member.number)).toBeVisible()
  await expect(page.getByText('1 registos')).toBeVisible()
  await page.getByLabel('Nome, número oficial ou identificador anterior').fill(set.member.legacy.toLowerCase().replace(/-/g, ' '))
  await page.getByRole('button', { name: 'Pesquisar' }).click()
  await expect(page.getByRole('link', { name: set.member.name })).toBeVisible()
})

test('M01-M03 admission in the browser: submit, validate, approve issues the number', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  await page.goto('/membros/admissoes')
  await expect(page.getByRole('heading', { level: 1, name: 'Admissões' })).toBeVisible()
  await page.getByLabel('Pessoa existente').fill(set.submit_person)
  const found = page.getByRole('list', { name: 'Pessoas encontradas' })
  await found.getByRole('listitem').filter({ hasText: set.submit_person }).getByRole('button', { name: 'Seleccionar' }).click()
  await page.getByLabel(/^Congregação de admissão/).selectOption({ label: fx.a1.name })
  await layout(page, info, 'admission')
  await page.getByRole('button', { name: 'Submeter candidatura' }).click()
  await expect(page.getByText('Candidatura submetida.', { exact: false })).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: set.submit_person })).toBeVisible()

  const row = page.getByRole('row').filter({ hasText: set.candidate.name })
  await row.getByRole('button', { name: 'Validar' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Validar' }).click()
  await expect(page.getByText('Candidatura actualizada.')).toBeVisible()
  await expect(row.getByRole('button', { name: 'Aprovar', exact: true })).toBeVisible()
  expect(member(set.candidate.public_id).member_number, 'no number before approval').toBeNull()
  await row.getByRole('button', { name: 'Aprovar', exact: true }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await layout(page, info, 'approve-dialog')
  await page.getByRole('dialog').getByRole('button', { name: 'Aprovar e emitir número' }).click()
  await expect(page.getByText(/aprovado com o número MEPA\d{10}/)).toBeVisible()
  const issued = member(set.candidate.public_id)
  expect(issued.member_number).toMatch(/^MEPA\d{2}(0[1-9]|1[0-2])\d{6}$/)
  await page.goto(`/membros/${set.candidate.public_id}`)
  await expect(page.getByText(issued.member_number)).toBeVisible()
  await expect(page.getByText('Membro activo').first()).toBeVisible()
})

test('M24 collective admission follows the list order and is all-or-nothing', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  const [daniela, eduardo, filipa] = set.collective_names as string[]
  await login(page)
  await page.goto('/membros/admissoes/colectiva')
  await expect(page.getByRole('heading', { level: 1, name: 'Aprovação colectiva' })).toBeVisible()
  for (const name of [filipa, daniela, eduardo]) await page.getByRole('checkbox', { name: new RegExp(name) }).check()
  await page.getByRole('button', { name: `Subir ${eduardo}` }).click()
  const order = page.locator('.collective-order__name')
  await expect(order.nth(0)).toContainText(filipa)
  await expect(order.nth(1)).toContainText(eduardo)
  await expect(order.nth(2)).toContainText(daniela)
  await layout(page, info, 'collective-admission')
  await page.getByRole('button', { name: 'Aprovar 3 candidatura(s)' }).click()
  await expect(page.getByRole('heading', { name: 'Números emitidos' })).toBeVisible()
  const numbers = await page.locator('.member-number').allTextContents()
  expect(numbers).toHaveLength(3)
  const sequences = numbers.map((value) => Number(value.slice(-6)))
  expect(sequences[1]).toBe(sequences[0] + 1)
  expect(sequences[2]).toBe(sequences[1] + 1)
  const byName: Record<string, string> = { [daniela]: set.collective[0], [eduardo]: set.collective[1], [filipa]: set.collective[2] }
  expect(member(byName[filipa]).member_number).toBe(numbers[0])
  expect(member(byName[eduardo]).member_number).toBe(numbers[1])
  expect(member(byName[daniela]).member_number).toBe(numbers[2])
  await layout(page, info, 'collective-result')
})

test('M23 member detail, legacy identifiers, milestones and history (mobile-first)', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  await page.goto(`/membros/${set.member.public_id}`)
  await expect(page.getByRole('heading', { level: 1, name: set.member.name })).toBeVisible()
  const summary = page.locator('.member-summary')
  await expect(summary.getByText(set.member.number)).toBeVisible()
  await expect(summary.getByText(fx.a1.name)).toBeVisible()
  await expect(summary.getByText('Membro activo')).toBeVisible()
  await expect(page.getByText(set.member.legacy)).toBeVisible()
  await expect(page.getByText('Conversão')).toBeVisible()
  await expect(page.getByText('04/2012', { exact: false })).toBeVisible()
  await expect(page.getByText('18/08/2013', { exact: false })).toBeVisible()
  await layout(page, info, 'member-detail')
  await page.getByRole('link', { name: 'Histórico' }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Histórico · ${set.member.name}` })).toBeVisible()
  await expect(page.locator('.member-timeline__item--open')).toContainText('Membro activo')
  await expect(page.locator('.member-timeline__item')).toHaveCount(3)
  await layout(page, info, 'history')
  // A transferred member: history shows the destination as current and the origin before it.
  await page.goto(`/membros/${set.moved.public_id}/historico`)
  await expect(page.locator('.member-timeline__item--open')).toContainText(fx.a2.name)
  await expect(page.locator('.member-timeline__item').nth(1)).toContainText(fx.a1.name)
  // A deceased member: badge, preserved number, only closure offered.
  await page.goto(`/membros/${set.deceased.public_id}`)
  await expect(page.getByText('Falecido(a)').first()).toBeVisible()
  await expect(page.getByRole('button', { name: 'Inactivar' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Terminar', exact: true })).toBeVisible()
})

test('M10 transfer completion in the browser moves the Congregation and keeps the number', async ({ page }, info) => {
  const set = fx.sets[info.project.name]
  await login(page)
  await page.goto(`/membros/${set.pending.public_id}`)
  await expect(page.getByText('Transferência em curso')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Inactivar' })).toHaveCount(0)
  await page.goto(`/membros/${set.pending.public_id}/transferencias`)
  await expect(page.getByRole('button', { name: 'Efectivar' })).toBeVisible()
  await layout(page, info, 'transfer')
  await page.getByRole('button', { name: 'Efectivar' }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await layout(page, info, 'transfer-dialog')
  await page.getByRole('dialog').getByRole('button', { name: 'Efectivar' }).click()
  await expect(page.getByText('Transferência actualizada.')).toBeVisible()
  await expect(page.getByText('Efectivada', { exact: true })).toBeVisible()
  const after = member(set.pending.public_id)
  expect(after.member_number).toBe(set.pending.number)
  expect(after.open_periods).toBe(1)
  expect(after.open_congregation).toBe(fx.b1.public_id)
  await page.goto(`/membros/${set.pending.public_id}`)
  await expect(page.locator('.member-summary').getByText(fx.b1.name)).toBeVisible()
  await expect(page.locator('.member-summary').getByText(set.pending.number)).toBeVisible()
  await page.goto('/membros/transferencias')
  await page.getByLabel('Situação').selectOption('all')
  await expect(page.getByText(set.pending.name)).toBeVisible()
  await layout(page, info, 'transfers')
})
