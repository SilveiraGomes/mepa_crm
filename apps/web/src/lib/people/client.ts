import { ApiError, apiRequest, type QueryValue } from '../../services/api'
import { clearSession, getSession } from '../auth/session'

type Query = Record<string, QueryValue>

// Same session handling as the Academy client: the bearer lives in sessionStorage only, a 401 ends the
// session. People responses are never cached (the service worker keeps /api/ NetworkOnly).
async function send<T>(method: 'GET' | 'POST' | 'PATCH', path: string, query: Query | undefined, body: unknown, signal?: AbortSignal): Promise<T> {
  const session = getSession()
  if (!session) throw new ApiError(401, 'UNAUTHENTICATED')
  try {
    return await apiRequest<T>(path, { method, query, body, signal, token: session.token })
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) clearSession()
    throw error
  }
}

export const peopleGet = <T>(path: string, query: Query = {}, signal?: AbortSignal): Promise<T> => send<T>('GET', path, query, undefined, signal)
export const peoplePost = <T>(path: string, body: unknown = {}): Promise<T> => send<T>('POST', path, undefined, body)
export const peoplePatch = <T>(path: string, body: unknown = {}): Promise<T> => send<T>('PATCH', path, undefined, body)
