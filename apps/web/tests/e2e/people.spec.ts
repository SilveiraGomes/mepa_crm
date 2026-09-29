import { expect, test, type Page } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// P0.5-I People / Families real-browser suite (Chromium, production build, real Laravel API on an
// isolated Test Infrastructure V2 pool). Fixture: tests/DatabaseV2/PeopleE2EFixtureTest.php.
const here = path.dirname(fileURLToPath(import.meta.url))
const evidence = path.resolve(here, '../../../../docs/reviews/evidence/P0.5-I')
const apiRoot = path.resolve(here, '../../../api')
const support = path.join(apiRoot, 'tests/DatabaseV2/Support/people_e2e_support.php')
const php = process.env.MEPA_PHP_BIN ?? 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe'
const API = 'http://127.0.0.1:18080/api/v1'
const fx = JSON.parse(fs.readFileSync(path.join(evidence, 'fixtures.json'), 'utf8'))
const ULID = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/
type Actor = 'manager' | 'viewer' | 'wrong_scope'
type AxeViolation = { impact: string | null; id: string }
type AxeRuntime = { run(document: Document, options: { resultTypes: string[] }): Promise<{ violations: AxeViolation[] }> }

function supportCall(args: string[]): string {
  return execFileSync(php, [support, ...args], { cwd: apiRoot, env: { ...process.env, APP_ENV: 'e2e', MEPA_E2E_PEOPLE_SUPPORT: '1' }, stdio: 'pipe' }).toString()
}
const resetLimiters = () => supportCall(['reset', ...fx.user_ids.map(String)])

test.beforeEach(() => { resetLimiters() })
test.afterEach(() => { resetLimiters() })

async function authenticate(page: Page, actor: Actor = 'manager', landing = '/pessoas') {
  const account = fx.accounts[actor]
  await page.goto('/entrar')
  await page.evaluate(({ token, expiresAt, login, publicId }) => sessionStorage.setItem('mepa.session', JSON.stringify({ token, expiresAt, user: { public_id: publicId, login, account_kind: 'HUMAN' } })),
    { token: account.browser_token, expiresAt: account.expires_at, login: account.login, publicId: account.user_public_id })
  await page.goto(landing)
}

const bearer = (actor: Actor) => ({ Authorization: `Bearer ${fx.accounts[actor].browser_token}`, Accept: 'application/json' })

function observe(page: Page) {
  const errors: string[] = []
  const httpFailures: { status: number; url: string }[] = []
  const requests: string[] = []
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errors.push(`${m.type()}: ${m.text()}`) })
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
  page.on('request', (r) => requests.push(r.url()))
  page.on('response', (r) => { if (r.status() >= 400) httpFailures.push({ status: r.status(), url: r.url() }) })
  return { errors, httpFailures, requests }
}

async function assertClean(o: ReturnType<typeof observe>, allowed: number[] = []) {
  const unexpected = o.httpFailures.filter((f) => !allowed.includes(f.status))
  expect(unexpected).toEqual([])
  expect(o.httpFailures.filter((f) => f.status >= 500)).toEqual([])
  expect(o.errors.filter((m) => !m.includes('Failed to load resource'))).toEqual([])
  expect(o.requests.filter((u) => /[?&](token|access_token)=/i.test(u))).toEqual([])
  expect(o.requests.filter((u) => !u.startsWith('http://127.0.0.1:14173') && !u.startsWith('http://127.0.0.1:18080'))).toEqual([])
}

function assertPublicOnly(payload: unknown) {
  const walk = (value: unknown): void => {
    if (Array.isArray(value)) { value.forEach(walk); return }
    if (value && typeof value === 'object') {
      for (const [key, inner] of Object.entries(value)) {
        expect(key === 'id' || (key.endsWith('_id') && key !== 'public_id') || key === 'member_number' || key === 'key_version', `internal field ${key}`).toBeFalsy()
        walk(inner)
      }
    }
  }
  walk(payload)
}

async function noHorizontalOverflow(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy()
}

let createdPerson = ''

test.describe.serial('P0.5-I People / Families critical flows', () => {
  test('P01 create non-member Person then open detail', async ({ page }) => {
    const o = observe(page)
    await authenticate(page, 'manager', '/pessoas/nova')
    const name = `Não Membro E2E ${Date.now()}`
    await page.getByLabel('Nome completo').fill(name)
    await page.getByLabel('Precisão').selectOption('MONTH')
    await expect(page.getByLabel('Data de nascimento')).toHaveCount(0)
    await page.getByLabel('Mês').selectOption('3')
    await page.getByLabel('Ano').fill('1990')
    await page.getByRole('button', { name: 'Criar pessoa' }).click()
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
    await expect(page.getByText('Março de 1990')).toBeVisible()
    createdPerson = page.url().split('/').pop() ?? ''
    expect(createdPerson).toMatch(ULID)
    await assertClean(o)
  })

  test('P02 contact and address persist after reload', async ({ page }) => {
    const o = observe(page)
    await authenticate(page, 'manager', `/pessoas/${fx.people.base}/contactos`)
    await page.getByRole('button', { name: 'Adicionar contacto' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Tipo de contacto').selectOption('PHONE')
    await dialog.getByLabel('Número de telefone').fill('+244 923 000 111')
    await dialog.getByLabel('Contacto principal deste tipo').check()
    await dialog.getByRole('button', { name: 'Adicionar', exact: true }).click()
    await expect(page.getByText('+244 923 000 111')).toBeVisible()
    await page.reload()
    await expect(page.getByText('+244 923 000 111')).toBeVisible()

    await page.goto(`/pessoas/${fx.people.base}/enderecos`)
    await page.getByRole('button', { name: 'Adicionar endereço' }).click()
    const addr = page.getByRole('dialog')
    await addr.getByLabel('Morada').fill('Rua E2E da Missão, 10')
    await addr.getByLabel('Localidade ou bairro').fill('Bairro E2E')
    await addr.getByLabel('Província').selectOption(fx.territory.province)
    await addr.getByLabel('Município').selectOption(fx.territory.municipality)
    await addr.getByRole('button', { name: 'Adicionar', exact: true }).click()
    await expect(page.getByText('Rua E2E da Missão, 10')).toBeVisible()
    await page.reload()
    await expect(page.getByText('Rua E2E da Missão, 10')).toBeVisible()
    await expect(page.getByText(/Município E2E/)).toBeVisible()
    await assertClean(o)
  })

  test('P03 create household and add two People', async ({ page }) => {
    const o = observe(page)
    await authenticate(page, 'manager', '/familias/nova')
    await page.getByLabel('Nome da família').fill('Família E2E Criada')
    await page.getByLabel('Pessoa de referência').fill('Elsa')
    await page.getByRole('button', { name: 'Seleccionar' }).first().click()
    await page.getByRole('button', { name: 'Criar família' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Família E2E Criada' })).toBeVisible()
    for (const [search, role] of [['Filipe', 'MEMBER'], ['Graça', 'DEPENDENT']] as const) {
      await page.getByRole('button', { name: 'Adicionar membro' }).click()
      const dialog = page.getByRole('dialog')
      await dialog.getByLabel('Pesquisar pessoa').fill(search)
      await dialog.getByRole('button', { name: 'Seleccionar' }).first().click()
      await dialog.getByLabel('Papel no agregado').selectOption(role)
      await dialog.getByRole('button', { name: 'Adicionar membro', exact: true }).click()
      await expect(page.getByRole('dialog')).toHaveCount(0)
    }
    await page.reload()
    for (const name of ['Família E2E Elsa', 'Família E2E Filipe', 'Família E2E Graça']) await expect(page.getByRole('link', { name })).toBeVisible()
    await assertClean(o)
  })

  test('P04 PARENT produces CHILD and SPOUSE is symmetric', async ({ page }) => {
    const o = observe(page)
    await authenticate(page, 'manager', `/pessoas/${fx.people.parent}/relacoes`)
    await page.getByRole('button', { name: 'Registar relação' }).click()
    let dialog = page.getByRole('dialog')
    await dialog.getByLabel('Progenitor E2E Carlos é').selectOption('PARENT')
    await dialog.getByLabel('de', { exact: true }).fill('Daniel')
    await dialog.getByRole('button', { name: 'Seleccionar' }).first().click()
    await dialog.getByRole('button', { name: 'Registar relação', exact: true }).click()
    await expect(page.getByText('Pai ou mãe')).toBeVisible()
    await page.goto(`/pessoas/${fx.people.child}/relacoes`)
    await expect(page.getByText('Filho ou filha')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Progenitor E2E Carlos' })).toBeVisible()

    await page.goto(`/pessoas/${fx.people.spouse_a}/relacoes`)
    await page.getByRole('button', { name: 'Registar relação' }).click()
    dialog = page.getByRole('dialog')
    await dialog.getByLabel('Cônjuge E2E Ana é').selectOption('SPOUSE')
    await dialog.getByLabel('de', { exact: true }).fill('Bento')
    await dialog.getByRole('button', { name: 'Seleccionar' }).first().click()
    await dialog.getByRole('button', { name: 'Registar relação', exact: true }).click()
    await expect(page.getByRole('link', { name: 'Cônjuge E2E Bento' })).toBeVisible()
    await page.goto(`/pessoas/${fx.people.spouse_b}/relacoes`)
    await expect(page.getByText('Cônjuge', { exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Cônjuge E2E Ana' })).toBeVisible()
    await page.getByRole('button', { name: 'Registar relação' }).click()
    dialog = page.getByRole('dialog')
    await dialog.getByLabel('Cônjuge E2E Bento é').selectOption('SPOUSE')
    await dialog.getByLabel('de', { exact: true }).fill('Ana')
    await dialog.getByRole('button', { name: 'Seleccionar' }).first().click()
    await dialog.getByRole('button', { name: 'Registar relação', exact: true }).click()
    await expect(dialog.getByText('Esta relação já está registada.')).toBeVisible()
    await assertClean(o, [409])
  })

  test('P05 contextual search returns public identifiers only', async ({ page }) => {
    const o = observe(page)
    await authenticate(page)
    const response = page.waitForResponse((r) => r.url().startsWith(`${API}/people?`) && r.url().includes('search=Pesquisa'))
    await page.getByLabel('Pesquisar pessoa').fill('Pesquisa E2E')
    const json = await (await response).json()
    await expect(page.getByRole('link', { name: 'Pesquisa E2E Base' })).toBeVisible()
    await expect(page.getByText('Pesquisa E2E Outra Unidade')).toHaveCount(0)
    assertPublicOnly(json)
    expect(json.data.map((p: { public_id: string }) => p.public_id)).toContain(fx.people.base)
    expect(JSON.stringify(json)).not.toContain(fx.people.foreign)
    for (const item of json.data) expect(item.public_id).toMatch(ULID)
    await assertClean(o)
  })

  test('P06 wrong-scope Person is concealed like a nonexistent one', async ({ page }) => {
    const o = observe(page)
    await authenticate(page, 'wrong_scope', `/pessoas/${fx.people.base}`)
    await expect(page.getByText('Recurso não encontrado ou indisponível.').first()).toBeVisible()
    await expect(page.getByText('Pesquisa E2E Base')).toHaveCount(0)
    const outOfScope = await page.request.get(`${API}/people/${fx.people.base}`, { headers: bearer('wrong_scope') })
    const missing = await page.request.get(`${API}/people/01ZZZZZZZZZZZZZZZZZZZZZZZZ`, { headers: bearer('wrong_scope') })
    expect([outOfScope.status(), missing.status()]).toEqual([404, 404])
    expect(await outOfScope.text()).toBe(await missing.text())
    await assertClean(o, [404])
  })

  test('P07 minor projection is minimal and sensitive areas are closed', async ({ page }) => {
    const o = observe(page)
    const detail = page.waitForResponse((r) => r.url() === `${API}/people/${fx.people.minor}`)
    await authenticate(page, 'manager', `/pessoas/${fx.people.minor}`)
    const json = await (await detail).json()
    await expect(page.getByText('Projecção protegida')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Contactos' })).toHaveCount(0)
    await expect(page.getByText('Oculto', { exact: true })).toBeVisible()
    expect(json.data.projection).toBe('MINIMAL')
    expect(json.data.protected_minor).toBe(true)
    expect(Object.keys(json.data)).not.toContain('birth')
    assertPublicOnly(json)
    const text = await page.locator('main').innerText()
    expect(text).not.toMatch(/responsável legal|consentimento|custódia|emergência|número de membro/i)
    await page.goto(`/pessoas/${fx.people.minor}/contactos`)
    await expect(page.getByText(/Dados sensíveis ocultos/)).toBeVisible()
    const contacts = await page.request.get(`${API}/people/${fx.people.minor}/contacts`, { headers: bearer('manager') })
    expect(contacts.status()).toBe(403)
    expect((await contacts.json()).error.code).toBe('MINOR_PROTECTED')
    await assertClean(o)
  })

  test('P08 stale write is rejected without overwrite', async ({ browser }) => {
    const ca = await browser.newContext(); const cb = await browser.newContext()
    const a = await ca.newPage(); const b = await cb.newPage(); const oa = observe(a); const ob = observe(b)
    await authenticate(a, 'manager', `/pessoas/${fx.people.stale}/editar`)
    await authenticate(b, 'manager', `/pessoas/${fx.people.stale}/editar`)
    await expect(a.getByLabel('Nome completo')).toHaveValue('Concorrência E2E Hugo')
    await expect(b.getByLabel('Nome completo')).toHaveValue('Concorrência E2E Hugo')
    await b.getByLabel('Nome completo').fill('Concorrência E2E Hugo B')
    await b.getByRole('button', { name: 'Guardar alterações' }).click()
    await expect(b.getByRole('heading', { level: 1, name: 'Concorrência E2E Hugo B' })).toBeVisible()
    await a.getByLabel('Nome completo').fill('Concorrência E2E Hugo A obsoleto')
    await a.getByRole('button', { name: 'Guardar alterações' }).click()
    await expect(a.getByText(/alterado por outro utilizador/i)).toBeVisible()
    const check = await a.request.get(`${API}/people/${fx.people.stale}`, { headers: bearer('manager') })
    expect((await check.json()).data.display_name).toBe('Concorrência E2E Hugo B')
    await assertClean(oa, [409]); await assertClean(ob); await ca.close(); await cb.close()
  })

  test('P09 mobile 390x844 list, search, detail and edit', async ({ page }) => {
    const o = observe(page)
    await page.setViewportSize({ width: 390, height: 844 })
    await authenticate(page)
    await expect(page.getByRole('heading', { level: 1, name: 'Pessoas' })).toBeVisible()
    await noHorizontalOverflow(page)
    await page.getByLabel('Pesquisar pessoa').fill('Telemóvel')
    await page.getByRole('link', { name: 'Telemóvel E2E Inês' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Telemóvel E2E Inês' })).toBeVisible()
    await noHorizontalOverflow(page)
    await page.getByRole('link', { name: 'Editar' }).click()
    await page.getByLabel('Nome completo').fill('Telemóvel E2E Inês Editada')
    const save = page.getByRole('button', { name: 'Guardar alterações' })
    await save.scrollIntoViewIfNeeded()
    const box = await save.boundingBox()
    expect(box && box.x >= 0 && box.x + box.width <= 390).toBeTruthy()
    await noHorizontalOverflow(page)
    await save.click()
    await expect(page.getByRole('heading', { level: 1, name: 'Telemóvel E2E Inês Editada' })).toBeVisible()
    await assertClean(o)
  })

  test('P10 non-member keeps no membership and no member number', async ({ page }) => {
    const o = observe(page)
    expect(createdPerson).toMatch(ULID)
    const detail = page.waitForResponse((r) => r.url() === `${API}/people/${createdPerson}`)
    await authenticate(page, 'manager', `/pessoas/${createdPerson}`)
    const json = await (await detail).json()
    expect(JSON.stringify(json)).not.toMatch(/member_number|membership/i)
    await expect(page.locator('main')).not.toContainText(/número de membro/i)
    const probe = JSON.parse(supportCall(['membership', createdPerson]))
    expect(probe).toEqual({ found: true, memberships: 0, member_numbers: 0, onboarding_contexts: 1 })
    await assertClean(o)
  })

  test('P11 Class B hidden without PEOPLE_SENSITIVE_VIEW', async ({ page }) => {
    const o = observe(page)
    const detail = page.waitForResponse((r) => r.url() === `${API}/people/${fx.people.sensitive}`)
    await authenticate(page, 'viewer', `/pessoas/${fx.people.sensitive}`)
    const json = await (await detail).json()
    await expect(page.getByRole('heading', { level: 1, name: 'Classe B E2E' })).toBeVisible()
    await expect(page.getByText('Oculto', { exact: true })).toBeVisible()
    await expect(page.getByText(/Dados sensíveis ocultos:/)).toBeVisible()
    await expect(page.getByRole('link', { name: 'Contactos' })).toHaveCount(0)
    await expect(page.getByText('1979')).toHaveCount(0)
    expect(Object.keys(json.data)).not.toContain('birth')
    await page.goto(`/pessoas/${fx.people.sensitive}/contactos`)
    await expect(page.getByText(/Dados sensíveis ocultos/)).toBeVisible()
    const contacts = await page.request.get(`${API}/people/${fx.people.sensitive}/contacts`, { headers: bearer('viewer') })
    expect(contacts.status()).toBe(403)
    expect((await contacts.json()).error.code).toBe('SENSITIVE_DATA_RESTRICTED')
    await assertClean(o)
  })

  test('P12 Class C export without PEOPLE_EXPORT_CLASS_C is denied', async ({ page }) => {
    const o = observe(page)
    await authenticate(page, 'manager', '/pessoas/exportar')
    const options = await page.getByLabel('Classe de dados').locator('option').evaluateAll((els) => els.map((e) => (e as HTMLOptionElement).value))
    expect(options).not.toContain('CLASS_C')
    const denied = await page.request.post(`${API}/people/exports`, { headers: { ...bearer('manager'), 'Content-Type': 'application/json' }, data: { class: 'CLASS_C', reason: 'Tentativa E2E sem permissão' } })
    expect(denied.status()).toBe(403)
    const body = await denied.json()
    expect(body.error.code).toBe('FORBIDDEN')
    expect(JSON.stringify(body)).not.toContain('csv')
    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Exportar CSV' }).click()
    expect((await download).suggestedFilename()).toMatch(/^pessoas-standard-.*\.csv$/)
    await expect(page.getByText(/Exportação concluída/)).toBeVisible()
    await assertClean(o)
  })
})

test.describe('P0.5-I visual QA and accessibility', () => {
  const viewports = { desktop: { width: 1440, height: 900 }, laptop: { width: 1366, height: 768 }, tablet: { width: 768, height: 1024 }, mobile: { width: 390, height: 844 } }
  const results: Record<string, Record<string, { overflow: boolean; critical_visible: boolean; modal_inside?: boolean }>> = {}
  for (const [name, viewport] of Object.entries(viewports)) {
    test(`visual QA ${name}`, async ({ page }) => {
      const o = observe(page)
      await page.setViewportSize(viewport)
      await authenticate(page)
      const screens: [string, string, string][] = [
        ['people-list', '/pessoas', 'Nova Pessoa'],
        ['person-detail', `/pessoas/${fx.people.base}`, 'Editar'],
        ['person-form', '/pessoas/nova', 'Criar pessoa'],
        ['household-detail', `/familias/${fx.household}`, 'Adicionar membro'],
      ]
      results[name] = {}
      for (const [screen, route, critical] of screens) {
        await page.goto(route)
        await expect(page.locator('main')).toBeVisible()
        await expect(page.locator('.skeleton')).toHaveCount(0)
        const button = page.locator('main').getByRole(screen === 'people-list' || screen === 'person-detail' ? 'link' : 'button', { name: critical, exact: true })
        await button.scrollIntoViewIfNeeded()
        const box = await button.boundingBox()
        const criticalVisible = Boolean(box && box.x >= 0 && box.x + box.width <= viewport.width + 0.5 && box.width > 0)
        const overflow = !(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth))
        results[name][screen] = { overflow, critical_visible: criticalVisible }
        expect(overflow).toBeFalsy()
        expect(criticalVisible).toBeTruthy()
        await page.screenshot({ path: path.join(evidence, `${name}-${screen}.png`), fullPage: true })
        if (screen === 'household-detail') {
          await button.click()
          const dialog = page.getByRole('dialog')
          await expect(dialog).toBeVisible()
          const d = await dialog.boundingBox()
          const inside = Boolean(d && d.x >= 0 && d.y >= 0 && d.x + d.width <= viewport.width + 0.5 && d.y + d.height <= viewport.height + 0.5)
          results[name][screen].modal_inside = inside
          expect(inside).toBeTruthy()
          await page.screenshot({ path: path.join(evidence, `${name}-household-modal.png`) })
          await dialog.getByRole('button', { name: 'Fechar' }).click()
        }
      }
      fs.writeFileSync(path.join(evidence, `visual-qa-${name}.json`), JSON.stringify(results[name], null, 2) + '\n')
      await assertClean(o)
    })
  }

  test('axe: no critical or serious violations on People screens', async ({ page }) => {
    await authenticate(page)
    for (const route of ['/pessoas', `/pessoas/${fx.people.base}`, '/pessoas/nova', `/pessoas/${fx.people.base}/contactos`, `/familias/${fx.household}`, '/familias']) {
      await page.goto(route)
      await expect(page.locator('.skeleton')).toHaveCount(0)
      await page.addScriptTag({ path: path.resolve(here, '../../node_modules/axe-core/axe.min.js') })
      const violations = await page.evaluate(async () => {
        const axe = (window as unknown as { axe: AxeRuntime }).axe
        const result = await axe.run(document, { resultTypes: ['violations'] })
        return result.violations.filter((v) => ['critical', 'serious'].includes(v.impact ?? '')).map((v) => v.id)
      })
      expect(violations, route).toEqual([])
    }
  })
})
