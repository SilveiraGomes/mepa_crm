import { useCallback, useEffect, useState } from 'react'
import { membershipGet } from '../lib/membership/client'
import { toMembershipError } from '../lib/membership/errors'
import { isAbort, type UiError } from '../lib/academy/errors'
import type { Item, Paginated } from '../types/academy'

export function useMembershipItem<T>(path: string | null) {
  const [state, setState] = useState<{ data: T | null; loading: boolean; error: UiError | null }>({ data: null, loading: Boolean(path), error: null })
  const [nonce, setNonce] = useState(0)
  useEffect(() => {
    if (!path) { setState({ data: null, loading: false, error: null }); return }
    const controller = new AbortController()
    setState((current) => ({ ...current, loading: true, error: null }))
    membershipGet<Item<T>>(path, {}, controller.signal).then(
      (result) => setState({ data: result.data, loading: false, error: null }),
      (error) => { if (!isAbort(error)) setState({ data: null, loading: false, error: toMembershipError(error) }) },
    )
    return () => controller.abort()
  }, [path, nonce])
  return { ...state, reload: useCallback(() => setNonce((value) => value + 1), []) }
}

/** Server-paginated collection (default 50, max 100 per page: the API refuses larger pages). */
export function useMembershipPage<T>(path: string | null, query: Record<string, string | number> = {}) {
  const [state, setState] = useState<{ data: Paginated<T> | null; loading: boolean; error: UiError | null }>({ data: null, loading: Boolean(path), error: null })
  const [nonce, setNonce] = useState(0)
  const key = JSON.stringify(query)
  useEffect(() => {
    if (!path) { setState({ data: null, loading: false, error: null }); return }
    const controller = new AbortController()
    setState((current) => ({ ...current, loading: true, error: null }))
    membershipGet<Paginated<T>>(path, JSON.parse(key), controller.signal).then(
      (result) => setState({ data: result, loading: false, error: null }),
      (error) => { if (!isAbort(error)) setState({ data: null, loading: false, error: toMembershipError(error) }) },
    )
    return () => controller.abort()
  }, [path, key, nonce])
  return { ...state, reload: useCallback(() => setNonce((value) => value + 1), []) }
}
