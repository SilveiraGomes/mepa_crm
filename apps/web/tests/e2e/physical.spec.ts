import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.7 Physical Locations browser suite (L14 + UI journeys + visual QA). Fixture seeded by PhysicalE2EFixtureTest.
// Each test resets the E2E rate limiters (global `api` limiter is per IP), as the People suite does.
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P07_FIXTURE_PATH ?? path.join(repo, '.tmp/p07-e2e-fixtures.json'), 'utf8'))
const evidence = path.join(repo, 'docs/reviews/evidence/P0.7')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/people_e2e_support.php')
const resetLimiters = () => execFileSync(php, [support, 'reset', String(fx.user_id)], { cwd: apiRoot, env: { ...process.env, APP_ENV: 'e2e', MEPA_E2E_PEOPLE_SUPPORT: '1' }, stdio: 'pipe' })

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean }
const checks: LayoutCheck[] = []

async function login(page: Page) {
  await page.goto('/locais')
  await page.getByLabel('Utilizador').fill(fx.login)
  await page.getByLabel('Palavra-passe').fill(fx.password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  // Signed in once the shell replaces the login page; tests navigate explicitly afterwards.
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

test('L14 mobile-first journey: list -> detail -> property -> temple', async ({ page }, info) => {
  await login(page)
  await page.goto('/locais')
  await expect(page.getByRole('heading', { level: 1, name: 'Locais' })).toBeVisible()
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  const collapsed = await menu.isVisible()
  if (collapsed) await menu.click()
  const nav = page.getByRole('navigation', { name: 'Locais e Património' })
  for (const label of ['Locais', 'Imóveis', 'Templos', 'Ligações institucionais']) await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
  if (collapsed) await menu.click()
  await expect(page.getByRole('link', { name: 'Sede Central MEPA' })).toBeVisible()
  await layout(page, info, 'locations-list')

  await page.getByRole('link', { name: 'Sede Central MEPA' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Sede Central MEPA' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Ligações institucionais' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Templo Principal' })).toBeVisible()
  await expect(page.getByText('Rua da Missão')).toHaveCount(0)
  await layout(page, info, 'location-detail')

  await page.getByRole('link', { name: fx.property_code }).click()
  await expect(page.getByRole('heading', { level: 1, name: `Imóvel ${fx.property_code}` })).toBeVisible()
  await expect(page.getByText('Proprietário externo registado')).toBeVisible()
  await expect(page.getByText('Proprietário Externo E2E')).toHaveCount(0)
  await layout(page, info, 'property-detail')
  await page.getByRole('button', { name: 'Mostrar nome (leitura registada)' }).click()
  await expect(page.getByText('Proprietário Externo E2E')).toBeVisible()

  await page.getByRole('link', { name: 'Sede Central MEPA' }).first().click()
  await expect(page.getByRole('heading', { level: 1, name: 'Sede Central MEPA' })).toBeVisible()
  await page.getByRole('link', { name: 'Templo Principal' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Templo Principal' })).toBeVisible()
  await expect(page.getByText('Vários templos no mesmo local não criam Congregações.')).toBeVisible()
  await layout(page, info, 'temple-detail')
})

test('links: transfer and set-primary dialogs stay inside the viewport', async ({ page }, info) => {
  await login(page)
  await page.goto(`/locais/${fx.location}`)
  await expect(page.getByRole('heading', { name: 'Ligações institucionais' })).toBeVisible()
  await page.getByRole('button', { name: 'Transferir' }).first().click()
  const transfer = page.locator('dialog[open]')
  await expect(transfer.getByRole('heading', { name: 'Transferir vínculo' })).toBeVisible()
  await expect(transfer.getByLabel(/^Unidade de destino/)).toBeVisible()
  await layout(page, info, 'transfer-dialog')
  await transfer.getByRole('button', { name: 'Fechar' }).click()
  // Centro A has two locations and exactly one primary: set-primary is offered on whichever is not primary now
  // (each viewport project swaps it, so the step does not depend on the order of the projects).
  let target = ''
  for (const candidate of [fx.second_location, fx.location]) {
    await page.goto(`/locais/${candidate}`)
    await expect(page.getByRole('heading', { name: 'Ligações institucionais' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Transferir' }).first()).toBeVisible()
    if (await page.getByRole('button', { name: 'Tornar principal' }).count() > 0) { target = candidate; break }
  }
  expect(target, 'one of the two locations of Centro A must be non-primary').not.toBe('')
  await page.getByRole('button', { name: 'Tornar principal' }).first().click()
  const primary = page.locator('dialog[open]')
  await expect(primary.getByRole('heading', { name: 'Tornar principal' })).toBeVisible()
  await layout(page, info, 'set-primary-dialog')
  await primary.getByRole('button', { name: 'Tornar principal' }).click()
  await expect(page.getByText('Local principal actualizado.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Tornar principal' })).toHaveCount(0)
})

test('create form, unit links and F-06 message', async ({ page }, info) => {
  await login(page)
  await page.goto('/locais/novo')
  await expect(page.getByRole('heading', { level: 1, name: 'Novo local' })).toBeVisible()
  await layout(page, info, 'create-location')
  await page.goto('/ligacoes')
  await page.getByLabel(/^Unidade/).selectOption({ label: 'Centro A' })
  await expect(page.getByRole('link', { name: 'Sede Central MEPA' })).toBeVisible()
  await layout(page, info, 'unit-links')
  await page.goto('/locais/01ARZ3NDEKTSV4RRFFQ69G5FAV')
  await expect(page.getByText('Recurso não encontrado ou indisponível.')).toBeVisible()
})

test('L01/L10 UI: create location with its first link, activate and publish', async ({ page }, info) => {
  await login(page)
  const name = `Local UI ${info.project.name}`
  await page.goto('/locais/novo')
  const unitSelect = page.getByLabel(/^Unidade responsável/)
  await expect(unitSelect).toBeEnabled()
  await unitSelect.selectOption({ label: 'Centro A' })
  await page.getByLabel('Forma de ocupação').selectOption('OWNED')
  await page.getByLabel('Nome do local').fill(name)
  await page.getByLabel(/^Endereço/).fill('Rua E2E da Missão 7')
  await page.getByLabel('Latitude').fill('-8.81')
  await page.getByLabel('Longitude').fill('13.24')
  await page.getByRole('button', { name: 'Criar local' }).click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
  await expect(page.getByText('Vinculado a Centro A')).toBeVisible()
  await page.getByRole('button', { name: 'Activar' }).first().click()
  await page.locator('dialog[open]').getByRole('button', { name: 'Activar' }).click()
  await expect(page.getByRole('button', { name: 'Publicar coordenadas' })).toBeVisible()
  await page.getByRole('button', { name: 'Publicar coordenadas' }).click()
  const publish = page.locator('dialog[open]')
  await publish.getByLabel('Motivo').fill('Aprovado para o mapa institucional')
  await layout(page, info, 'publish-dialog')
  await publish.getByRole('button', { name: 'Publicar' }).click()
  await expect(page.getByRole('heading', { name: 'Projecção pública aprovada' })).toBeVisible()
  await expect(page.getByText('Rua E2E da Missão')).toHaveCount(0)
  await layout(page, info, 'published-location')
})
