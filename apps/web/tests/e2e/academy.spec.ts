import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const evidence = path.resolve(here, '../../../../docs/reviews/evidence/P0.3.5-A4.2')
const fx = JSON.parse(fs.readFileSync(path.join(evidence, 'fixtures.json'), 'utf8'))

async function login(page: Page, actor: keyof typeof fx.accounts = 'manager') {
  await page.goto('/entrar')
  await page.getByLabel('Utilizador').fill(fx.accounts[actor].login)
  await page.getByLabel('Palavra-passe').fill(fx.password)
  await page.getByRole('button', { name: 'Entrar', exact: true }).click()
  await expect(page).toHaveURL(/\/academia$/)
  await expect(page.getByRole('heading', { level: 1, name: 'Academia', exact: true })).toBeVisible()
}

async function authenticate(page: Page, actor: keyof typeof fx.accounts = 'manager') {
  const account = fx.accounts[actor]
  await page.goto('/entrar')
  await page.evaluate(({ token, expiresAt, login, publicId }) => sessionStorage.setItem('mepa.session', JSON.stringify({
    token,
    expiresAt,
    user: { public_id: publicId, login, account_kind: 'HUMAN' },
  })), { token: account.browser_token, expiresAt: account.expires_at, login: account.login, publicId: account.user_public_id })
  await page.goto('/academia')
  await expect(page.getByRole('heading', { level: 1, name: 'Academia', exact: true })).toBeVisible()
}

async function logout(page: Page) {
  await page.getByRole('button', { name: 'Terminar sessão' }).click()
  await expect(page).toHaveURL(/\/entrar$/)
}

function observe(page: Page) {
  const errors: string[] = []
  const badResponses: string[] = []
  const httpFailures: { status: number; url: string }[] = []
  const requests: string[] = []
  page.on('console', (message) => { if (message.type() === 'error' || message.type() === 'warning') errors.push(`${message.type()}: ${message.text()}`) })
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`))
  page.on('request', (request) => requests.push(request.url()))
  page.on('response', (response) => {
    if (response.status() >= 400) httpFailures.push({ status: response.status(), url: response.url() })
    if (response.status() >= 500) badResponses.push(`${response.status()} ${response.url()}`)
  })
  return { errors, badResponses, httpFailures, requests }
}

async function assertClean(observation: ReturnType<typeof observe>, allowedStatuses: number[] = []) {
  const unexpectedHttp = observation.httpFailures.filter(({ status }) => !allowedStatuses.includes(status))
  const substantiveConsoleErrors = observation.errors.filter((message) => !message.includes('Failed to load resource'))
  expect(unexpectedHttp).toEqual([])
  expect(substantiveConsoleErrors).toEqual([])
  expect(observation.errors.length - substantiveConsoleErrors.length).toBeLessThanOrEqual(observation.httpFailures.length)
  expect(observation.badResponses).toEqual([])
  expect(observation.requests.filter((url) => /[?&](token|access_token)=/i.test(url))).toEqual([])
  expect(observation.requests.filter((url) => !url.startsWith('http://127.0.0.1:14173') && !url.startsWith('http://127.0.0.1:18080'))).toEqual([])
}

test.describe.serial('A4.2 real-browser critical flows', () => {
  test('E2E 1 enrollment persists in the roster', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page)
    await page.goto(`/academia/turmas/${fx.main.class}/matriculas`)
    await page.getByRole('button', { name: 'Matricular pessoa' }).click()
    await page.getByLabel('Pesquisar pessoa').fill('Candidato Externo')
    await expect(page.getByText('Candidato Externo E2E')).toBeVisible()
    await page.getByRole('button', { name: 'Seleccionar' }).click()
    await page.getByRole('button', { name: 'Matricular', exact: true }).click()
    await expect(page.getByText('Matrícula criada.')).toBeVisible()
    await page.reload()
    await expect(page.getByText('Candidato Externo E2E')).toBeVisible()
    await assertClean(observation)
  })

  test('E2E 2 attendance persists after browser reload', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page, 'instructor')
    await page.goto(`/academia/sessoes/${fx.main.session_id}/presencas`)
    const select = page.getByLabel('Presença de Aluno Externo E2E')
    await select.selectOption('S_ATD_PRESENT')
    await select.locator('xpath=ancestor::tr').getByRole('button', { name: 'Guardar', exact: true }).click()
    await expect(page.getByText('Presença guardada.')).toBeVisible()
    await page.reload()
    await expect(page.getByLabel('Presença de Aluno Externo E2E')).toHaveValue('S_ATD_PRESENT')
    await assertClean(observation)
  })

  test('E2E 3 grade record and revision produce real history', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page, 'instructor')
    await page.goto(`/academia/turmas/${fx.main.class}/tentativas/${fx.main.attempt_id}`)
    await page.getByRole('button', { name: 'Registar nota' }).click()
    await page.getByLabel('Nota').fill('78')
    await page.getByRole('dialog').getByRole('button', { name: 'Registar nota', exact: true }).click()
    await expect(page.getByText('Versão 1')).toBeVisible()
    await page.getByRole('button', { name: 'Rever nota' }).click()
    await page.getByLabel('Nota').fill('82')
    await page.getByLabel('Motivo').fill('Revisão E2E')
    await page.getByRole('button', { name: 'Registar revisão' }).click()
    await expect(page.getByText('Versão 2')).toBeVisible()
    await assertClean(observation)
  })

  test('E2E 4 certificate issue, detail and revoke', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page, 'certifier')
    await page.goto(`/academia/turmas/${fx.main.class}/certificados`)
    await page.getByRole('button', { name: 'Emitir certificado' }).click()
    await page.getByLabel('Matrícula').selectOption(fx.main.completed_enrollment)
    await expect(page.getByLabel('Ficheiro elegível')).toBeEnabled()
    await page.getByLabel('Ficheiro elegível').selectOption(fx.main.file)
    await page.getByRole('button', { name: 'Emitir', exact: true }).click()
    await expect(page.getByText('Certificado emitido.')).toBeVisible()
    await page.getByRole('link', { name: 'Abrir' }).click()
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('button', { name: 'Revogar' }).click()
    await expect(page.getByText('Certificado revogado.')).toBeVisible()
    await assertClean(observation)
  })

  test('E2E 5 wrong-scope deep link is concealed', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page, 'wrong_scope')
    await page.goto(`/academia/turmas/${fx.wrong_scope_target_class}`)
    await expect(page.getByText('Recurso não encontrado ou indisponível.').first()).toBeVisible()
    await assertClean(observation, [404])
  })

  test('E2E 6 mobile attendance remains usable and persists', async ({ page }) => {
    const observation = observe(page)
    await page.setViewportSize({ width: 390, height: 844 })
    await authenticate(page, 'instructor')
    await page.goto(`/academia/sessoes/${fx.main.session_id}/presencas`)
    await page.getByLabel('Presença de Aluno Externo E2E').selectOption('S_ATD_ABSENT')
    const mobileSelect = page.getByLabel('Presença de Aluno Externo E2E')
    await mobileSelect.locator('xpath=ancestor::tr').getByRole('button', { name: 'Guardar', exact: true }).click()
    await expect(page.getByText('Presença guardada.')).toBeVisible()
    await page.reload()
    await expect(page.getByLabel('Presença de Aluno Externo E2E')).toHaveValue('S_ATD_ABSENT')
    await assertClean(observation)
  })

  test('E2E 7 minor roster projection excludes sensitive data', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page)
    await page.goto(`/academia/turmas/${fx.main.class}/matriculas`)
    await expect(page.getByText('Menor Protegido E2E')).toBeVisible()
    const html = await page.locator('body').innerText()
    expect(html).not.toMatch(/guardian|responsável|consentimento|documento|contacto sensível/i)
    await assertClean(observation)
  })

  test('E2E 8 stale grade write is rejected without overwrite', async ({ browser }) => {
    const contextA = await browser.newContext(); const contextB = await browser.newContext()
    const a = await contextA.newPage(); const b = await contextB.newPage(); const oa = observe(a); const ob = observe(b)
    await authenticate(a, 'instructor'); await authenticate(b, 'instructor')
    const route = `/academia/turmas/${fx.main.class}/tentativas/${fx.main.attempt_id}`
    await a.goto(route); await b.goto(route)
    await a.getByRole('button', { name: 'Rever nota' }).click()
    await b.getByRole('button', { name: 'Rever nota' }).click()
    await b.getByLabel('Nota').fill('86'); await b.getByLabel('Motivo').fill('Contexto B'); await b.getByRole('button', { name: 'Registar revisão' }).click()
    await a.getByLabel('Nota').fill('84'); await a.getByLabel('Motivo').fill('Contexto A stale'); await a.getByRole('button', { name: 'Registar revisão' }).click()
    await expect(a.getByText(/alterado por outro utilizador/i)).toBeVisible()
    await assertClean(oa, [409]); await assertClean(ob); await contextA.close(); await contextB.close()
  })

  test('E2E 9 missing completion policy fails closed in the UI', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page, 'policy')
    await page.goto(`/academia/turmas/${fx.policy_missing.class}/matriculas/${fx.policy_missing.enrollment}`)
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('button', { name: 'Concluir' }).click()
    await expect(page.getByText(/política académica.*não foi configurada/i)).toBeVisible()
    await assertClean(observation, [422])
  })

  test('E2E 10 mixed-scope transcript returns one concealed failure', async ({ page }) => {
    const observation = observe(page)
    await authenticate(page)
    await page.goto('/academia/historicos')
    await page.getByLabel('Currículo').fill(String(fx.main.curriculum_id))
    await page.getByLabel('Pessoa').fill(fx.main.external_person)
    await page.getByRole('button', { name: 'Consultar' }).click()
    await page.getByRole('button', { name: 'Pré-visualizar' }).click()
    await expect(page.getByText('Recurso não encontrado ou indisponível.').first()).toBeVisible()
    await assertClean(observation, [404])
  })

  test('E2E-AUTH invalid login, logout/back, expiry and permission menu', async ({ page }) => {
    const observation = observe(page)
    await page.goto('/entrar')
    await page.getByLabel('Utilizador').fill('nobody')
    await page.getByLabel('Palavra-passe').fill('wrong')
    await page.getByRole('button', { name: 'Entrar', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('não estão correctos')
    await login(page, 'instructor')
    await expect(page.getByRole('link', { name: 'Históricos académicos' })).toHaveCount(0)
    await logout(page)
    await page.goBack()
    expect(page.url() === 'about:blank' || /\/entrar$/.test(page.url())).toBeTruthy()
    await page.goto('/academia')
    await expect(page).toHaveURL(/\/entrar$/)
    await page.evaluate(({ token, user }) => sessionStorage.setItem('mepa.session', JSON.stringify({ token, expiresAt: new Date(Date.now() + 3600000).toISOString(), user })), { token: fx.expired_token, user: { public_id: 'E2E', login: 'operador.e2e', account_kind: 'HUMAN' } })
    await page.goto('/academia/turmas')
    await expect(page).toHaveURL(/\/entrar$/)
    await assertClean(observation, [401])
  })
})

test.describe('visual, accessibility, keyboard and PWA evidence', () => {
  const viewports = { desktop: { width: 1440, height: 900 }, laptop: { width: 1366, height: 768 }, tablet: { width: 768, height: 1024 }, mobile: { width: 390, height: 844 } }
  for (const [name, viewport] of Object.entries(viewports)) {
    test(`visual QA ${name}`, async ({ page }) => {
      const observation = observe(page); await page.setViewportSize(viewport)
      await page.goto('/entrar'); await page.screenshot({ path: path.join(evidence, `${name}-login.png`), fullPage: true })
      await authenticate(page)
      const routes: Record<string, string> = {
        dashboard: '/academia', classes: '/academia/turmas', class: `/academia/turmas/${fx.main.class}`,
        roster: `/academia/turmas/${fx.main.class}/matriculas`, enrollment: `/academia/turmas/${fx.main.class}/matriculas/${fx.main.external_enrollment}`,
        attendance: `/academia/sessoes/${fx.main.session_id}/presencas`,
      }
      if (name === 'desktop') Object.assign(routes, { assessments: `/academia/turmas/${fx.main.class}/avaliacoes`, grades: `/academia/turmas/${fx.main.class}/tentativas/${fx.main.attempt_id}`, certificates: `/academia/turmas/${fx.main.class}/certificados`, transcripts: '/academia/historicos' })
      for (const [screen, route] of Object.entries(routes)) {
        await page.goto(route); await expect(page.locator('main')).toBeVisible()
        await expect(page.locator('.skeleton')).toHaveCount(0)
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy()
        await page.screenshot({ path: path.join(evidence, `${name}-${screen}.png`), fullPage: true })
      }
      await assertClean(observation)
    })
  }

  test('axe critical pages, keyboard focus and built PWA assets', async ({ page, request }) => {
    await authenticate(page)
    for (const route of [`/academia/turmas/${fx.main.class}`, `/academia/sessoes/${fx.main.session_id}/presencas`, `/academia/turmas/${fx.main.class}/tentativas/${fx.main.attempt_id}`, `/academia/turmas/${fx.main.class}/certificados`]) {
      await page.goto(route)
      await page.addScriptTag({ path: path.resolve(here, '../../node_modules/axe-core/axe.min.js') })
      const violations = await page.evaluate(async () => (await (window as any).axe.run(document, { resultTypes: ['violations'] })).violations.filter((v: any) => ['critical', 'serious'].includes(v.impact)))
      expect(violations).toEqual([])
    }
    await page.evaluate(() => sessionStorage.clear())
    await page.goto('/entrar')
    await expect(page.getByRole('heading', { level: 1, name: 'Aceder ao MEPA Gestão' })).toBeVisible()
    await page.keyboard.press('Tab')
    expect(await page.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY')
    expect((await request.get('/manifest.webmanifest')).ok()).toBeTruthy()
    expect((await request.get('/sw.js')).ok()).toBeTruthy()
  })
})
