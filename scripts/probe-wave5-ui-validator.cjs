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

function violations(contractText, source, styles = '') {
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
  if (/\b(?:actor|user|session|account)\.roles?\b|\broles?\s*\.\s*(?:includes|some)\s*\(/i.test(source)) errors.push('role-authority')
  if (contract.actions.find((action) => action.action === 'issue certificate')?.permission !== 'ACADEMY_CERTIFY') errors.push('certificate-permission')
  if (contract.actions.find((action) => action.action === 'assign instructor')?.permission !== 'ACADEMY_MANAGE') errors.push('instructor-permission')
  if (/#[0-9a-f]{3,8}\b/i.test(styles)) errors.push('hardcoded-colour')
  return errors
}

const probes = [
  ['U1', () => { const c = JSON.parse(originalContract); c.screens[0].permission = ''; return [JSON.stringify(c), originalPage, 'permission'] }],
  ['U2', () => [originalContract, `${originalPage}\nconst leak = people.id`, 'person-id']],
  ['U3', () => { const c = JSON.parse(originalContract); c.screens.find((s) => s.screen === 'Roster and enrolment').paginated = false; return [JSON.stringify(c), originalPage, 'roster-pagination'] }],
  ['U4', () => { const c = JSON.parse(originalContract); c.critical_error_mappings['404'] = 'Sem permissão'; return [JSON.stringify(c), originalPage, 'concealment'] }],
  ['U5', () => [originalContract, `${originalPage}\nconst fakeStudent = {}`, 'mock']],
  ['U6', () => [originalContract, `${originalPage}\nacademyPatch('enrollments/x', { status: 'DONE' })`, '', 'generic-status-patch']],
  ['U7', () => [originalContract, `${originalPage}\nconst allowed = actor.role === 'ADMIN'`, '', 'role-authority']],
  ['U8', () => { const c = JSON.parse(originalContract); c.actions.find((a) => a.action === 'issue certificate').permission = 'ACADEMY_VIEW'; return [JSON.stringify(c), originalPage, '', 'certificate-permission'] }],
  ['U9', () => { const c = JSON.parse(originalContract); c.actions.find((a) => a.action === 'assign instructor').permission = 'ACADEMY_ENROLL'; return [JSON.stringify(c), originalPage, '', 'instructor-permission'] }],
  ['U10', () => [originalContract, originalPage, '.button { background: #123456; }', 'hardcoded-colour']],
]

let passed = 0
for (const [name, mutate] of probes) {
  const mutated = mutate()
  const [contract, source, third, fourth] = mutated
  const styles = fourth ? third : ''
  const expected = fourth || third
  const found = violations(contract, source, styles)
  if (!found.includes(expected)) throw new Error(`${name} was not detected (${expected})`)
  console.log(`${name} PASS`)
  passed += 1
}

const after = [digest(fs.readFileSync(contractPath, 'utf8')), digest(fs.readFileSync(pagePath, 'utf8'))]
if (before.join(':') !== after.join(':')) throw new Error('Mutation restore failed')
console.log(`UI_VALIDATOR_NEGATIVE_PROBES_PASS ${passed}/10; MUTATION_RESTORE_PASS`)
