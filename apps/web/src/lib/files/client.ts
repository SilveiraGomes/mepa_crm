import { ApiError, apiRequest, buildUrl, NetworkError, type ApiErrorBody, type QueryValue } from '../../services/api'
import { clearSession, getSession } from '../auth/session'

type Query = Record<string, QueryValue>

function token(): string {
  const session = getSession()
  if (!session) throw new ApiError(401, 'UNAUTHENTICATED')
  return session.token
}

async function send<T>(method: 'GET' | 'POST' | 'PATCH', path: string, query?: Query, body?: unknown, signal?: AbortSignal): Promise<T> {
  try {
    return await apiRequest<T>(path, { method, query, body, signal, token: token() })
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) clearSession()
    throw error
  }
}

async function failure(response: Response): Promise<never> {
  const body = (await response.json().catch(() => ({}))) as ApiErrorBody
  if (response.status === 401) clearSession()
  throw new ApiError(response.status, body.error?.code ?? 'UNKNOWN', body.error?.details?.fields ?? {})
}

export const filesGet = <T>(path: string, query: Query = {}, signal?: AbortSignal) => send<T>('GET', path, query, undefined, signal)
export const filesPost = <T>(path: string, body: unknown = {}) => send<T>('POST', path, undefined, body)
export const filesPatch = <T>(path: string, body: unknown = {}) => send<T>('PATCH', path, undefined, body)

/** Multipart upload (the browser sets the boundary; the declared MIME type is ignored by the server). */
export async function filesUpload<T>(path: string, form: FormData): Promise<T> {
  let response: Response
  try {
    response = await fetch(buildUrl(path), { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token()}` }, body: form, cache: 'no-store', credentials: 'omit' })
  } catch {
    throw new NetworkError()
  }
  if (!response.ok) return failure(response)
  return (await response.json()) as T
}

/**
 * Authorized download: fetched with the bearer token (never a public or signed URL), kept only in memory as a Blob and
 * handed to the browser as an attachment. HIGHLY_SENSITIVE content carries the reason in X-Access-Reason.
 */
export async function filesDownload(path: string, filename: string, reason?: string): Promise<number> {
  const headers: Record<string, string> = { Authorization: `Bearer ${token()}` }
  // Percent-encoded: HTTP header values are not UTF-8 safe and the reason is Portuguese free text.
  if (reason) headers['X-Access-Reason'] = encodeURIComponent(reason)
  let response: Response
  try {
    response = await fetch(buildUrl(path), { headers, cache: 'no-store', credentials: 'omit' })
  } catch {
    throw new NetworkError()
  }
  if (!response.ok) return failure(response)
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.rel = 'noopener'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  setTimeout(() => URL.revokeObjectURL(url), 30_000)
  return blob.size
}
