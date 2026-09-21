'use strict'

const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')

const root = path.resolve(__dirname, '..')
const contractPath = path.join(root, 'docs/ui/wave5_academy_ui_contracts.json')
const pagePath = path.join(root, 'apps/web/src/pages/ClassPages.tsx')
const originalContract = fs.readFileSync(contractPath, 'utf8')
const originalPage = fs.readFileSync(pagePath, 'utf8')
const digest = (value) => crypto.createHash('sha256').update(value).digest('hex')
const before = [digest(originalContract), digest(originalPage)]

function violations(contractText, source) {
  const contract = JSON.parse(contractText)
  const errors = []
  const allowedPermissions = /^ACADEMY_[A-Z_]+(?:\|ACADEMY_[A-Z_]+)*$/
  for (const screen of contract.screens) {
    if (!allowedPermissions.test(screen.permission)) errors.push('permission')
    if (screen.screen === 'Roster and enrolment' && !screen.paginated) errors.push('roster-pagination')
  }
  if (contract.critical_error_mappings['404'] !== 'Recurso não encontrado ou indisponível.') errors.push('concealment')
  if (/\bpeople\.id\b|\bperson_id\b/.test(source)) errors.push('person-id')
  if (/\b(mockData|fakeStudent|fakeClasses)\b/i.test(source)) errors.push('mock')
  if (/academyPatch\([^\n]*enroll[^\n]*status/i.test(source)) errors.push('generic-status-patch')
  return errors
}

const probes = [
  ['U1', () => { const c = JSON.parse(originalContract); c.screens[0].permission = ''; return [JSON.stringify(c), originalPage, 'permission'] }],
  ['U2', () => [originalContract, `${originalPage}\nconst leak = people.id`, 'person-id']],
  ['U3', () => { const c = JSON.parse(originalContract); c.screens.find((s) => s.screen === 'Roster and enrolment').paginated = false; return [JSON.stringify(c), originalPage, 'roster-pagination'] }],
  ['U4', () => { const c = JSON.parse(originalContract); c.critical_error_mappings['404'] = 'Sem permissão'; return [JSON.stringify(c), originalPage, 'concealment'] }],
  ['U5', () => [originalContract, `${originalPage}\nconst fakeStudent = {}`, 'mock']],
  ['U6', () => [originalContract, `${originalPage}\nacademyPatch('enrollments/x', { status: 'DONE' })`, 'generic-status-patch']],
]

let passed = 0
for (const [name, mutate] of probes) {
  const [contract, source, expected] = mutate()
  const found = violations(contract, source)
  if (!found.includes(expected)) throw new Error(`${name} was not detected (${expected})`)
  console.log(`${name} PASS`)
  passed += 1
}

const after = [digest(fs.readFileSync(contractPath, 'utf8')), digest(fs.readFileSync(pagePath, 'utf8'))]
if (before.join(':') !== after.join(':')) throw new Error('Mutation restore failed')
console.log(`UI_VALIDATOR_NEGATIVE_PROBES_PASS ${passed}/6; MUTATION_RESTORE_PASS`)
