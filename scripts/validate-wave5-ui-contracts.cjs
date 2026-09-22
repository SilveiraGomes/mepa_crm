'use strict'

const fs = require('node:fs')
const path = require('node:path')

const root = path.resolve(__dirname, '..')
const uiPath = path.join(root, 'docs/ui/wave5_academy_ui_contracts.json')
const httpPath = path.join(root, 'docs/api/wave5_academy_http_contracts.json')
const appPath = path.join(root, 'apps/web/src/App.tsx')
const srcRoot = path.join(root, 'apps/web/src')
const tokenPath = path.join(srcRoot, 'styles/tokens.css')
const ui = JSON.parse(fs.readFileSync(uiPath, 'utf8'))
const http = JSON.parse(fs.readFileSync(httpPath, 'utf8'))
const app = fs.readFileSync(appPath, 'utf8')
const failures = []
let checks = 0

function check(condition, message) {
  checks += 1
  if (!condition) failures.push(message)
}

function normalizeRoute(route) {
  return route.replace(/:[A-Za-z][A-Za-z0-9]*/g, '{}').replace(/\{[^}]+\}/g, '{}')
}

function sourceFiles(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name)
    if (entry.isDirectory()) return entry.name === 'test' ? [] : sourceFiles(target)
    return /\.tsx?$/.test(entry.name) ? [target] : []
  })
}

function filesMatching(directory, expression) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name)
    if (entry.isDirectory()) return entry.name === 'test' ? [] : filesMatching(target, expression)
    return expression.test(entry.name) ? [target] : []
  })
}

const declaredRoutes = [...app.matchAll(/<Route\s+path="([^"]+)"/g)].map((match) => normalizeRoute(match[1]))
const httpEndpoints = new Set(http.endpoints.map((endpoint) => `${endpoint.method} ${endpoint.uri}`))
const permissions = new Set(http.endpoints.map((endpoint) => endpoint.permission))
const gaps = new Set(ui.contract_gaps.map((gap) => gap.id))

const tokenCss = fs.readFileSync(tokenPath, 'utf8')
function token(name) {
  const value = tokenCss.match(new RegExp(`${name}:\\s*(#[0-9a-f]{6})`, 'i'))?.[1]
  if (!value) throw new Error(`Token not found: ${name}`)
  return value
}
function contrast(a, b) {
  const luminance = (hex) => {
    const channels = hex.slice(1).match(/.{2}/g).map((part) => Number.parseInt(part, 16) / 255)
    const linear = channels.map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722
  }
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (light + 0.05) / (dark + 0.05)
}

check(ui.version === 'P0.3.5-A4.2', 'UI contract version must be P0.3.5-A4.2')
check(ui.contract_gaps.find((gap) => gap.id === 'A4_API_CONTRACT_GAP-01')?.status === 'RESOLVED', 'GAP-01 must be resolved by the A4.2 auth contract')
check(ui.defaults.page_size === 50, 'Default page size must be 50')
check(ui.defaults.max_page_size === 100, 'Maximum page size must be 100')
check(ui.defaults.search_minimum === 3, 'Search minimum must be 3')
for (const [foreground, background] of [
  ['--mepa-ink', '--mepa-blush'],
  ['--mepa-primary-strong', '--surface-card'],
  ['--mepa-danger-strong', '--surface-card'],
  ['--mepa-warning-strong', '--surface-card'],
]) check(contrast(token(foreground), token(background)) >= 4.5, `WCAG AA contrast failed: ${foreground} on ${background}`)

for (const screen of ui.screens) {
  check(declaredRoutes.includes(normalizeRoute(screen.route)), `Screen route does not exist: ${screen.route}`)
  for (const reference of screen.endpoints) check(httpEndpoints.has(reference), `Unknown HTTP contract reference: ${reference}`)
  for (const permission of screen.permission.split('|')) check(permissions.has(permission), `Unknown permission mapping: ${screen.route} -> ${permission}`)
  check(Array.isArray(screen.states) && screen.states.includes('loading'), `Critical loading state missing: ${screen.route}`)
  if (screen.paginated) {
    check(screen.inputs.includes('page'), `Paginated screen lacks page input: ${screen.route}`)
    check(screen.responsive.includes('stacked'), `Paginated screen lacks responsive stacked behaviour: ${screen.route}`)
  }
}

for (const action of ui.actions) {
  check(httpEndpoints.has(action.endpoint), `Action references unknown endpoint: ${action.action}`)
  check(permissions.has(action.permission), `Action lacks valid permission: ${action.action}`)
  for (const field of ['screen', 'request', 'response', 'success', 'errors', 'loading', 'confirmation', 'responsive_notes']) {
    check(Array.isArray(action[field]) ? action[field].length > 0 : Boolean(action[field]), `Action ${action.action} lacks ${field}`)
  }
  check(action.implemented === true, `CRUD action is not implemented: ${action.action}`)
  if (!action.implemented) check(gaps.has(action.gap), `Unimplemented action lacks a registered contract gap: ${action.action}`)
}

for (const gap of ui.contract_gaps) check(Boolean(gap.status), `Contract gap lacks closure status: ${gap.id}`)

for (const required of ['404', '401', '403', '409:STALE_WRITE', '422:ACADEMIC_POLICY_NOT_CONFIGURED', '422:STATE_POLICY_PENDING', '422:PARTICIPATION_REQUIREMENTS_NOT_MET']) {
  check(Boolean(ui.critical_error_mappings[required]), `Critical error mapping missing: ${required}`)
}
check(ui.critical_error_mappings['404'] === 'Recurso não encontrado ou indisponível.', 'F-06 404 wording must remain generic')

const production = sourceFiles(srcRoot).map((file) => fs.readFileSync(file, 'utf8')).join('\n')
const styleSources = filesMatching(srcRoot, /\.css$/)
  .filter((file) => path.basename(file) !== 'tokens.css')
  .map((file) => fs.readFileSync(file, 'utf8'))
  .join('\n')
check(!/\b(mockData|fakeStudent|fakeClasses)\b/i.test(production), 'Production screen depends on mock data')
check(!/\bpeople\.id\b|\bperson_id\b/.test(production), 'Raw internal Person ID is referenced in production UI')
check(!/academyPatch\([^\n]*enroll[^\n]*status/i.test(production), 'Forbidden generic Enrollment status PATCH found')
check(!/\b(?:actor|user|session|account)\.roles?\b|\broles?\s*\.\s*(?:includes|some)\s*\(/i.test(production), 'U7 role-based UI authority found')
check(!/#[0-9a-f]{3,8}\b/i.test(styleSources), 'U10 hardcoded colour found outside the token authority')
check(production.includes('useAcademyPage'), 'No server-side pagination hook found')
check(production.includes('UNAVAILABLE_MESSAGE'), 'Concealed 404 mapping is not used')
check(production.includes('PARTICIPATION_REQUIREMENTS_NOT_MET'), 'Child safety error mapping is missing')
check(ui.actions.find((action) => action.action === 'issue certificate')?.permission === 'ACADEMY_CERTIFY', 'U8 certificate issue must require ACADEMY_CERTIFY')
check(ui.actions.find((action) => action.action === 'assign instructor')?.permission === 'ACADEMY_MANAGE', 'U9 instructor assignment must require ACADEMY_MANAGE')

if (failures.length) {
  console.error(`UI_CONTRACTS_FAIL ${failures.length}/${checks}`)
  for (const failure of failures) console.error(`- ${failure}`)
  process.exit(1)
}

const incomplete = ui.actions.filter((action) => !action.implemented).length
console.log(`UI_CONTRACTS_PASS ${checks} checks; ${ui.screens.length} screens; ${ui.actions.length} actions; ${incomplete} contract-gapped actions`)
