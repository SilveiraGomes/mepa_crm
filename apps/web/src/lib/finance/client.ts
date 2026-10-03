import { ApiError, apiRequest, buildUrl, type QueryValue } from '../../services/api'
import { clearSession, getSession } from '../auth/session'

type Query = Record<string, QueryValue>

async function send<T>(method: 'GET' | 'POST', path: string, query?: Query, body?: unknown, signal?: AbortSignal, headers?: Record<string, string>): Promise<T> {
  const session = getSession()
  if (!session) throw new ApiError(401, 'UNAUTHENTICATED')
  try {
    return await apiRequest<T>(path, { method, query, body, signal, token: session.token, headers })
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) clearSession()
    throw error
  }
}

export const financeGet = <T>(path: string, query: Query = {}, signal?: AbortSignal) => send<T>('GET', path, query, undefined, signal)
/** Writes that create a resource carry an Idempotency-Key: a retry of the same form never creates a second transfer. */
export const financePost = <T>(path: string, body: unknown = {}, idempotencyKey?: string) => send<T>('POST', path, undefined, body, undefined, idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined)

export async function financeDownload(path: string, query: Query, filename: string): Promise<void> {
  const session = getSession()
  if (!session) throw new ApiError(401, 'UNAUTHENTICATED')
  const response = await fetch(buildUrl(path, query), { headers: { Accept: 'text/csv', Authorization: `Bearer ${session.token}` }, cache: 'no-store', credentials: 'omit' })
  if (!response.ok) throw new ApiError(response.status, 'EXPORT_FAILED')
  const url = URL.createObjectURL(await response.blob())
  const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.click(); URL.revokeObjectURL(url)
}

export function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(36).slice(2)}`
}
