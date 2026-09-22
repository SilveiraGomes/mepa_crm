'use strict'

const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')

const root = path.resolve(__dirname, '..')
const paths = {
  routes: path.join(root, 'apps/api/routes/api.php'),
  limiter: path.join(root, 'apps/api/app/Providers/RouteServiceProvider.php'),
  service: path.join(root, 'apps/api/app/Domain/Auth/AuthSessionService.php'),
  client: path.join(root, 'apps/web/src/lib/auth/client.ts'),
}
const originals = Object.fromEntries(Object.entries(paths).map(([key, file]) => [key, fs.readFileSync(file)]))
const digest = (value) => crypto.createHash('sha256').update(value).digest('hex')
const before = Object.fromEntries(Object.entries(originals).map(([key, value]) => [key, digest(value)]))

function violations(source) {
  const errors = []
  if (!/Limit::perMinute\(5\)/.test(source.limiter)) errors.push('rate-limit')
  if (/UNKNOWN_USER|WRONG_PASSWORD/.test(source.service)) errors.push('enumeration')
  if (!/revoked_at/.test(source.service)) errors.push('revocation')
  if (/mock-token|MOCK_TOKEN/.test(source.client)) errors.push('mock-token')
  if (/[?&](?:token|access_token)=/.test(Object.values(source).join('\n'))) errors.push('query-token')
  return errors
}

const text = Object.fromEntries(Object.entries(originals).map(([key, value]) => [key, value.toString('utf8')]))
const probes = [
  ['AUTH-M1', 'rate-limit', { ...text, limiter: text.limiter.replace('Limit::perMinute(5)', 'Limit::perMinute(5000)') }],
  ['AUTH-M2', 'enumeration', { ...text, service: `${text.service}\n// UNKNOWN_USER and WRONG_PASSWORD` }],
  ['AUTH-M3', 'revocation', { ...text, service: text.service.replaceAll('revoked_at', 'disabled_revoke_field') }],
  ['AUTH-M4', 'mock-token', { ...text, client: `${text.client}\nconst MOCK_TOKEN = 'mock-token'` }],
  ['AUTH-M5', 'query-token', { ...text, client: `${text.client}\nconst unsafe = '?access_token='` }],
]

for (const [name, expected, mutation] of probes) {
  if (!violations(mutation).includes(expected)) throw new Error(`${name} was not detected`)
  console.log(`${name} PASS`)
}

const after = Object.fromEntries(Object.entries(paths).map(([key, file]) => [key, digest(fs.readFileSync(file))]))
if (JSON.stringify(before) !== JSON.stringify(after)) throw new Error('Mutation restore failed')
console.log('AUTH_VALIDATOR_NEGATIVE_PROBES_PASS 5/5; MUTATION_RESTORE_PASS')
