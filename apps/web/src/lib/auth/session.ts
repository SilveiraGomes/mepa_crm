// MEPA Local Auth V1 uses an opaque bearer with fixed issue-time expiry. There is deliberately no
// client-side refresh or sliding expiration; expiry requires a new credential verification.
// The token lives in sessionStorage only (tab-scoped, gone when the tab closes) — never localStorage.

import type { AuthUser } from './client'

export interface AuthSession {
  token: string
  expiresAt: string
  user: AuthUser
  /** Academy permission codes granted to the actor. Absent = the API does not tell the UI. */
  permissions?: readonly string[]
}

const STORAGE_KEY = 'mepa.session'
const listeners = new Set<() => void>()

function read(): AuthSession | null {
  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<AuthSession>
    if (typeof parsed.token !== 'string' || parsed.token === '' || typeof parsed.expiresAt !== 'string' || !parsed.user) return null
    if (Date.parse(parsed.expiresAt) <= Date.now()) {
      window.sessionStorage.removeItem(STORAGE_KEY)
      return null
    }
    const permissions = Array.isArray(parsed.permissions) ? parsed.permissions.filter((p): p is string => typeof p === 'string') : undefined
    return { token: parsed.token, expiresAt: parsed.expiresAt, user: parsed.user as AuthUser, permissions }
  } catch {
    return null
  }
}

let current: AuthSession | null = read()
let epoch = 0

function emit() {
  epoch += 1
  listeners.forEach((listener) => listener())
}

export function getSession(): AuthSession | null {
  return current
}

/** Changes whenever the actor changes, so caches keyed on it can never serve one actor's data to another. */
export function getSessionEpoch(): number {
  return epoch
}

export function setSession(session: AuthSession): void {
  current = session
  try {
    window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(session))
  } catch {
    /* storage blocked: the session then lives in memory for this page only */
  }
  emit()
}

export function clearSession(): void {
  current = null
  try {
    window.sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    /* nothing to clear */
  }
  emit()
}

export function subscribeSession(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** Only same-app paths are accepted as a post-sign-in destination (no open redirect). */
export function safeRedirect(target: string | null | undefined, fallback = '/academia'): string {
  if (!target || !target.startsWith('/') || target.startsWith('//') || target.includes('\\')) return fallback
  return target
}
