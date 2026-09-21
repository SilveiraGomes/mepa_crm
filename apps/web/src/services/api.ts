const apiBaseUrl = import.meta.env.VITE_API_URL

if (!apiBaseUrl) console.warn('VITE_API_URL não está definida.')

export type QueryValue = string | number | boolean | null | undefined

export interface ApiErrorBody {
  error?: { code?: string; message?: string; details?: { fields?: Record<string, string[]> } }
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly fields: Record<string, string[]>
  readonly retryAfterSeconds: number | null

  constructor(status: number, code: string, fields: Record<string, string[]> = {}, retryAfterSeconds: number | null = null) {
    super(`API ${status} ${code}`)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
    this.retryAfterSeconds = retryAfterSeconds
  }
}

export class NetworkError extends Error {
  constructor() {
    super('Network request failed')
    this.name = 'NetworkError'
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH'
  query?: Record<string, QueryValue>
  body?: unknown
  signal?: AbortSignal
  token?: string | null
}

export function buildUrl(path: string, query?: Record<string, QueryValue>): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const suffix = params.size > 0 ? `?${params.toString()}` : ''
  return `${apiBaseUrl ?? ''}/${path.replace(/^\//, '')}${suffix}`
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  if (options.token) headers.Authorization = `Bearer ${options.token}`

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
      cache: 'no-store',
      credentials: 'omit',
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new NetworkError()
  }

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ApiErrorBody
    const retryAfter = Number(response.headers.get('Retry-After'))
    throw new ApiError(
      response.status,
      body.error?.code ?? 'UNKNOWN',
      body.error?.details?.fields ?? {},
      Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : null,
    )
  }

  if (response.status === 204) return undefined as T
  try {
    return (await response.json()) as T
  } catch {
    throw new ApiError(response.status, 'INVALID_RESPONSE')
  }
}

export { apiBaseUrl }
