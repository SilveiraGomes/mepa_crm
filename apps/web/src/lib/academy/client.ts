import { ApiError, apiRequest, buildUrl, type QueryValue } from '../../services/api'
import { clearSession, getSession, getSessionEpoch } from '../auth/session'

type Query = Record<string, QueryValue>

const PREFIX = 'academy/'

async function send<T>(method: 'GET' | 'POST' | 'PUT' | 'PATCH', path: string, query: Query | undefined, body: unknown, signal?: AbortSignal): Promise<T> {
  const session = getSession()
  if (!session) throw new ApiError(401, 'UNAUTHENTICATED')
  try {
    return await apiRequest<T>(PREFIX + path, { method, query, body, signal, token: session.token })
  } catch (error) {
    // An expired or revoked token ends the session; the route guard then sends the user to sign in.
    if (error instanceof ApiError && error.status === 401) clearSession()
    throw error
  }
}

interface Inflight {
  promise: Promise<unknown>
  controller: AbortController
  subscribers: number
}

// Identical GETs issued together (class detail, catalogue selects) share one network request.
// The key contains the session epoch, so a response can never cross from one actor to another,
// and entries live only while the request is pending — nothing is cached afterwards.
const inflight = new Map<string, Inflight>()

export function academyGet<T>(path: string, query: Query = {}, signal?: AbortSignal): Promise<T> {
  const key = `${getSessionEpoch()}|${buildUrl(PREFIX + path, query)}`
  let entry = inflight.get(key)
  if (!entry) {
    const controller = new AbortController()
    const created: Inflight = {
      controller,
      subscribers: 0,
      promise: send<T>('GET', path, query, undefined, controller.signal).finally(() => {
        if (inflight.get(key) === created) inflight.delete(key)
      }),
    }
    inflight.set(key, created)
    entry = created
  }
  const shared = entry
  shared.subscribers += 1

  return new Promise<T>((resolve, reject) => {
    const abort = () => {
      shared.subscribers -= 1
      if (shared.subscribers <= 0) {
        shared.controller.abort()
        if (inflight.get(key) === shared) inflight.delete(key)
      }
      reject(new DOMException('Aborted', 'AbortError'))
    }
    if (signal?.aborted) return abort()
    signal?.addEventListener('abort', abort, { once: true })
    shared.promise.then(
      (value) => {
        signal?.removeEventListener('abort', abort)
        resolve(value as T)
      },
      (error: unknown) => {
        signal?.removeEventListener('abort', abort)
        reject(error)
      },
    )
  })
}

export const academyPost = <T>(path: string, body: unknown = {}): Promise<T> => send<T>('POST', path, undefined, body)
export const academyPut = <T>(path: string, body: unknown = {}): Promise<T> => send<T>('PUT', path, undefined, body)
export const academyPatch = <T>(path: string, body: unknown = {}): Promise<T> => send<T>('PATCH', path, undefined, body)
