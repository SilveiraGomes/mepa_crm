# ADR-0016: Authentication and session model

Status: Accepted

Date: 2026-09-21

## Context

The canonical schema already defines `users.login`, a Laravel-compatible `users.password_hash`, account lifecycle fields, scoped role grants, and `auth_sessions` with a unique binary token digest, expiry and revocation. Academy already authenticates these sessions. A4.1 lacked only the institutional HTTP and browser contract; creating another identity or token system would conflict with the existing architecture.

## Decision

MEPA Local Auth V1 verifies `users.login` and `users.password_hash` through Laravel's hasher. Successful authentication requires an active, non-archived user, no unresolved MFA requirement, and at least one effective active grant. It issues 32 random bytes as a base64url bearer and stores only its binary SHA-256 digest in `auth_sessions`.

Sessions have a fixed issue-time expiry (480 minutes by default). Every protected request rechecks expiry, revocation, account lifecycle and effective grant availability. Logout revokes the current digest and is idempotent. External failures for a missing account and a wrong password are identical. Login is limited to five attempts per minute per source IP without requiring Redis.

The browser keeps the bearer in `sessionStorage`, so it is tab-scoped. It never places the token in a URL, query string or log. A `401` clears local state and protected routes return to sign-in. The public identity projection is limited to `public_id`, `login` and `account_kind`; Academy permissions remain in `/api/v1/academy/context`.

Refresh is `NOT_APPLICABLE` in V1. The current table has no independent refresh credential, rotation family or absolute renewal boundary. Treating the access bearer as an unlimited renewal credential would weaken revocation and expiry. Expiry therefore requires a new login. A future SSO or renewable-session ADR may replace credential verification while retaining the user/session boundary.

## Consequences

- GAP-01 can close without schema or Academy-domain changes.
- Revoked and expired sessions cannot be renewed or resurrected.
- Compromise of a live tab bearer remains possible until expiry or revocation; short fixed lifetime, TLS and browser XSS controls remain required.
- MFA-required accounts fail closed until an approved MFA flow exists.
