'use strict'

const fs = require('node:fs')
const path = require('node:path')

const root = path.resolve(__dirname, '..')
const uiPath = path.join(root, 'docs/ui/wave5_academy_ui_contracts.json')
const httpPath = path.join(root, 'docs/api/wave5_academy_http_contracts.json')
const appPath = path.join(root, 'apps/web/src/App.tsx')
const srcRoot = path.join(root, 'apps/web/src')
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

const declaredRoutes = [...app.matchAll(/<Route\s+path="([^"]+)"/g)].map((match) => normalizeRoute(match[1]))
const httpEndpoints = new Set(http.endpoints.map((endpoint) => `${endpoint.method} ${endpoint.uri}`))
const permissions = new Set(http.endpoints.map((endpoint) => endpoint.permission))
const gaps = new Set(ui.contract_gaps.map((gap) => gap.id))

check(ui.defaults.page_size === 50, 'Default page size must be 50')
check(ui.defaults.max_page_size === 100, 'Maximum page size must be 100')
check(ui.defaults.search_minimum === 3, 'Search minimum must be 3')

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
  if (!action.implemented) check(gaps.has(action.gap), `Unimplemented action lacks a registered contract gap: ${action.action}`)
}

for (const required of ['404', '401', '403', '409:STALE_WRITE', '422:ACADEMIC_POLICY_NOT_CONFIGURED', '422:STATE_POLICY_PENDING', '422:PARTICIPATION_REQUIREMENTS_NOT_MET']) {
  check(Boolean(ui.critical_error_mappings[required]), `Critical error mapping missing: ${required}`)
}
check(ui.critical_error_mappings['404'] === 'Recurso não encontrado ou indisponível.', 'F-06 404 wording must remain generic')

const production = sourceFiles(srcRoot).map((file) => fs.readFileSync(file, 'utf8')).join('\n')
check(!/\b(mockData|fakeStudent|fakeClasses)\b/i.test(production), 'Production screen depends on mock data')
check(!/\bpeople\.id\b|\bperson_id\b/.test(production), 'Raw internal Person ID is referenced in production UI')
check(!/academyPatch\([^\n]*enroll[^\n]*status/i.test(production), 'Forbidden generic Enrollment status PATCH found')
check(production.includes('useAcademyPage'), 'No server-side pagination hook found')
check(production.includes('UNAVAILABLE_MESSAGE'), 'Concealed 404 mapping is not used')
check(production.includes('PARTICIPATION_REQUIREMENTS_NOT_MET'), 'Child safety error mapping is missing')

if (failures.length) {
  console.error(`UI_CONTRACTS_FAIL ${failures.length}/${checks}`)
  for (const failure of failures) console.error(`- ${failure}`)
  process.exit(1)
}

const incomplete = ui.actions.filter((action) => !action.implemented).length
console.log(`UI_CONTRACTS_PASS ${checks} checks; ${ui.screens.length} screens; ${ui.actions.length} actions; ${incomplete} contract-gapped actions`)
