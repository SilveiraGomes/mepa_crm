import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

// P0.8 Documents/Files browser suite (F22 + UI journeys + visual QA). Fixture seeded by FilesE2EFixtureTest.
const repo = path.resolve(process.cwd(), '../..')
const fx = JSON.parse(fs.readFileSync(process.env.P08_FIXTURE_PATH ?? path.join(repo, '.tmp/p08-e2e-fixtures.json'), 'utf8'))
const evidence = path.join(repo, 'docs/reviews/evidence/P0.8')
const apiRoot = path.join(repo, 'apps/api')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/people_e2e_support.php')
const resetLimiters = () => execFileSync(php, [support, 'reset', String(fx.user_id)], { cwd: apiRoot, env: { ...process.env, APP_ENV: 'e2e', MEPA_E2E_PEOPLE_SUPPORT: '1' }, stdio: 'pipe' })

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

interface LayoutCheck { screen: string; overflow: boolean; clippedButtons: string[]; dialogOutside: boolean }
const checks: LayoutCheck[] = []

function pdf(marker: string): Buffer {
  return Buffer.from(`%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n% ${marker}\n%%EOF\n`, 'latin1')
}

async function login(page: Page) {
  await page.goto('/ficheiros')
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

test('F22 upload -> detail -> download (exact bytes), list and navigation', async ({ page }, info) => {
  await login(page)
  await page.goto('/ficheiros')
  await expect(page.getByRole('heading', { level: 1, name: 'Ficheiros' })).toBeVisible()
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  const collapsed = await menu.isVisible()
  if (collapsed) await menu.click()
  const nav = page.getByRole('navigation', { name: 'Documentos e Ficheiros' })
  for (const label of ['Documentos', 'Ficheiros', 'Carregar ficheiro']) await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
  if (collapsed) await menu.click()
  await expect(page.getByRole('link', { name: fx.file_name })).toBeVisible()
  await layout(page, info, 'files-list')

  await page.goto('/ficheiros/carregar')
  await expect(page.getByRole('heading', { level: 1, name: 'Carregar ficheiro' })).toBeVisible()
  await expect(page.getByText('Não é uma análise antivírus.', { exact: false })).toBeVisible()
  const unit = page.getByLabel(/^Unidade proprietária/)
  await expect(unit).toBeEnabled()
  await unit.selectOption({ label: fx.unit_a_name })
  await expect(page.getByLabel(/^Classificação/)).toHaveValue('RESTRICTED')
  const name = `Oficio ${info.project.name}.pdf`
  const bytes = pdf(`F22-${info.project.name}-${Date.now()}`)
  await page.getByLabel(/^Ficheiro/).setInputFiles({ name, mimeType: 'application/pdf', buffer: bytes })
  await layout(page, info, 'upload')
  await page.getByRole('button', { name: 'Carregar', exact: true }).click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
  await expect(page.getByText('Disponível').first()).toBeVisible()
  await expect(page.getByText('Verificação estrutural')).toBeVisible()
  await expect(page.locator('main')).not.toContainText('files_private')
  await expect(page.locator('main')).not.toContainText(/v1\/\d{4}\//)
  await layout(page, info, 'file-detail')
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descarregar', exact: true }).click()])
  expect(download.suggestedFilename()).toBe(name)
  const saved = await download.path()
  expect(fs.readFileSync(saved).equals(bytes)).toBe(true)
})

test('documents and versions: current version, preserved history, new-version dialog', async ({ page }, info) => {
  await login(page)
  await page.goto('/documentos')
  await expect(page.getByRole('heading', { level: 1, name: 'Documentos' })).toBeVisible()
  await expect(page.getByRole('link', { name: fx.document_title })).toBeVisible()
  await page.getByRole('link', { name: fx.document_title }).click()
  await expect(page.getByRole('heading', { level: 1, name: fx.document_title })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Versões' })).toBeVisible()
  await expect(page.locator('.files-current')).toHaveCount(1)
  await expect(page.getByRole('button', { name: 'Descarregar v1' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Descarregar v2' })).toBeVisible()
  await layout(page, info, 'versions')
  await page.getByRole('button', { name: 'Nova versão' }).click()
  const dialog = page.locator('dialog[open]')
  await expect(dialog.getByRole('heading', { name: 'Nova versão' })).toBeVisible()
  await layout(page, info, 'new-version-dialog')
  await dialog.getByRole('button', { name: 'Fechar' }).click()
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descarregar v1' }).click()])
  expect(fs.readFileSync(await download.path()).toString('latin1')).toContain('E2E-V1')
})

test('highly sensitive download requires a reason in an in-page dialog; F-06 message', async ({ page }, info) => {
  await login(page)
  await page.goto(`/ficheiros/${fx.sensitive}`)
  await expect(page.getByRole('heading', { level: 1, name: fx.sensitive_name })).toBeVisible()
  await expect(page.getByText('Altamente sensível').first()).toBeVisible()
  await page.getByRole('button', { name: 'Descarregar', exact: true }).click()
  const dialog = page.locator('dialog[open]')
  await expect(dialog.getByRole('heading', { name: 'Descarregamento sensível' })).toBeVisible()
  await layout(page, info, 'sensitive-download-dialog')
  await dialog.getByLabel('Motivo').fill('Verificação de identidade E2E')
  const [download] = await Promise.all([page.waitForEvent('download'), dialog.getByRole('button', { name: 'Descarregar' }).click()])
  expect(fs.readFileSync(await download.path()).toString('latin1')).toContain('E2E-IDENTIDADE')
  await page.goto('/ficheiros/01ARZ3NDEKTSV4RRFFQ69G5FAV')
  await expect(page.getByText('Recurso não encontrado ou indisponível.')).toBeVisible()
})
