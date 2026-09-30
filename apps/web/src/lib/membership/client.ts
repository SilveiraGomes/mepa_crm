import { ApiError, apiRequest, type QueryValue } from '../../services/api'
import { clearSession, getSession } from '../auth/session'

type Query = Record<string, QueryValue>

async function send<T>(method: 'GET' | 'POST', path: string, query?: Query, body?: unknown, signal?: AbortSignal): Promise<T> {
  const session = getSession()
  if (!session) throw new ApiError(401, 'UNAUTHENTICATED')
  try {
    return await apiRequest<T>(path, { method, query, body, signal, token: session.token })
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) clearSession()
    throw error
  }
}

export const membershipGet = <T>(path: string, query: Query = {}, signal?: AbortSignal) => send<T>('GET', path, query, undefined, signal)
export const membershipPost = <T>(path: string, body: unknown = {}) => send<T>('POST', path, undefined, body)
