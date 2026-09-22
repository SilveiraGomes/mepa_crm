'use strict'

const fs = require('node:fs')
const path = require('node:path')

const root = path.resolve(__dirname, '..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const files = {
  routes: read('apps/api/routes/api.php'),
  limiter: read('apps/api/app/Providers/RouteServiceProvider.php'),
  service: read('apps/api/app/Domain/Auth/AuthSessionService.php'),
  middleware: read('apps/api/app/Http/Middleware/AuthenticateApiSession.php'),
  controller: read('apps/api/app/Http/Controllers/Api/V1/AuthController.php'),
  errors: read('apps/api/app/Exceptions/Handler.php'),
  authClient: read('apps/web/src/lib/auth/client.ts'),
  academyClient: read('apps/web/src/lib/academy/client.ts'),
  session: read('apps/web/src/lib/auth/session.ts'),
  authPage: read('apps/web/src/pages/AuthPage.tsx'),
  shell: read('apps/web/src/layout/AppShell.tsx'),
  contract: read('docs/api/authentication_v1.json'),
}

let checks = 0
const failures = []
function check(value, message) { checks += 1; if (!value) failures.push(message) }

check(/post\('login'.*throttle:login/.test(files.routes), 'login route must use named throttle')
check(/post\('logout'/.test(files.routes), 'logout route missing')
check(/get\('me'.*api\.auth/.test(files.routes), 'current-user route must be protected')
check(/Limit::perMinute\(5\)/.test(files.limiter), 'login limit must be five per minute')
check(/auth_sessions/.test(files.service) && /auth_sessions/.test(files.middleware), 'canonical auth_sessions must be reused')
check(/random_bytes\(32\)/.test(files.service), 'token entropy is not explicit')
check(/hash\('sha256', \$token, true\)/.test(files.service), 'binary token digest missing')
check(/INVALID_CREDENTIALS/.test(files.service) && !/UNKNOWN_USER|WRONG_PASSWORD/.test(files.controller + files.service), 'credential failures must not enumerate accounts')
check(/revoked_at/.test(files.service) && /lockForUpdate/.test(files.service), 'logout must revoke under a row lock')
check(/SESSION_EXPIRED/.test(files.middleware) && /SESSION_REVOKED/.test(files.middleware) && /UNAUTHENTICATED/.test(files.middleware), 'session error contract incomplete')
check(/RATE_LIMITED/.test(files.errors), 'rate-limit JSON mapping missing')
check(/auth\/login/.test(files.authClient) && /auth\/logout/.test(files.authClient), 'frontend auth endpoint mapping incomplete')
check(/error\.status === 401\) clearSession/.test(files.academyClient), '401 must clear local session state')
check(/sessionStorage/.test(files.session) && !/window\.localStorage/.test(files.session), 'bearer must be tab scoped')
check(/safeRedirect/.test(files.session) && /startsWith\('\/\/'\)/.test(files.session), 'post-login redirect must reject external targets')
check(/showPassword/.test(files.authPage) && /role="alert"/.test(files.authPage), 'accessible login feedback missing')
check(/await logout\(token\)/.test(files.shell) && /finally\s*{\s*clearSession/.test(files.shell), 'logout UI must clear safely even after API denial')
check(!/[?&](?:token|access_token)=/.test(Object.values(files).join('\n')), 'token query-string pattern found')
check(!/(?:Log|console)\s*[:.].*(?:token|password)/i.test(files.service + files.controller + files.middleware + files.authPage), 'credential or raw-token logging pattern found')
const contract = JSON.parse(files.contract)
check(contract.refresh?.status === 'NOT_APPLICABLE' && Boolean(contract.refresh.reason), 'refresh decision must be explicit and justified')

if (failures.length) {
  console.error(`AUTH_CONTRACTS_FAIL ${failures.length}/${checks}`)
  failures.forEach((failure) => console.error(`- ${failure}`))
  process.exit(1)
}
console.log(`AUTH_CONTRACTS_PASS ${checks} checks; MEPA_LOCAL_AUTH_V1; refresh NOT_APPLICABLE`)
