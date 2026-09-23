import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { isAbort, type UiError } from '../lib/academy/errors'
import { peopleGet } from '../lib/people/client'
import { pe, PEOPLE_MIN_SEARCH, PEOPLE_PAGE_SIZE } from '../lib/people/endpoints'
import { toPeopleError } from '../lib/people/errors'
import type { Item, Paginated } from '../types/academy'
import type { PeopleCatalogs, PersonDetail } from '../types/people'

interface LoadState<T> { data: T | null; loading: boolean; error: UiError | null }

function useLoad<T>(path: string | null, query: Record<string, string | number>, unwrap: (raw: unknown) => T) {
  const [nonce, setNonce] = useState(0)
  const [state, setState] = useState<LoadState<T>>({ data: null, loading: Boolean(path), error: null })
  const key = JSON.stringify(query)
  const unwrapRef = useRef(unwrap)
  useEffect(() => {
    if (!path) { setState({ data: null, loading: false, error: null }); return }
    const controller = new AbortController()
    setState((current) => ({ ...current, loading: true, error: null }))
    peopleGet<unknown>(path, JSON.parse(key) as Record<string, string | number>, controller.signal).then(
      (result) => setState({ data: unwrapRef.current(result), loading: false, error: null }),
      (error: unknown) => { if (!isAbort(error)) setState({ data: null, loading: false, error: toPeopleError(error) }) },
    )
    return () => controller.abort()
  }, [path, key, nonce])
  return { ...state, reload: () => setNonce((value) => value + 1) }
}

export function usePeopleItem<T>(path: string | null) {
  return useLoad<T>(path, {}, (raw) => (raw as Item<T>).data)
}

export function usePeopleList<T>(path: string | null, query: Record<string, string | number> = {}) {
  return useLoad<{ data: T[]; meta?: { hidden?: string | null } }>(path, query, (raw) => raw as { data: T[]; meta?: { hidden?: string | null } })
}

export function usePeoplePage<T>(path: string | null, query: Record<string, string | number>) {
  return useLoad<Paginated<T>>(path, query, (raw) => raw as Paginated<T>)
}

/** URL-backed list filters (search debounced, page reset on filter change). */
export function usePeopleQuery(keys: string[]) {
  const [params, setParams] = useSearchParams()
  const rawSearch = params.get('search') ?? ''
  const [searchInput, setSearchInput] = useState(rawSearch)
  const timer = useRef<number | null>(null)
  useEffect(() => setSearchInput(rawSearch), [rawSearch])
  useEffect(() => () => { if (timer.current) window.clearTimeout(timer.current) }, [])
  const keyList = keys.join(',')
  const query = useMemo(() => {
    const result: Record<string, string | number> = { page: Math.max(1, Number(params.get('page')) || 1), per_page: PEOPLE_PAGE_SIZE }
    for (const key of keyList.split(',')) {
      const value = params.get(key)
      if (value) result[key] = value
    }
    return result
  }, [params, keyList])
  const update = useCallback((updates: Record<string, string | number | null>) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      for (const [key, value] of Object.entries(updates)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, String(value))
      }
      if (!Object.prototype.hasOwnProperty.call(updates, 'page')) next.set('page', '1')
      return next
    })
  }, [setParams])
  const changeSearch = useCallback((value: string) => {
    setSearchInput(value)
    if (timer.current) window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => update({ search: value.trim().length >= PEOPLE_MIN_SEARCH ? value.trim() : null }), 350)
  }, [update])
  return { query, update, searchInput, changeSearch }
}

export function usePerson() {
  const personId = useParams().personId ?? null
  const result = usePeopleItem<PersonDetail>(personId ? pe.person(personId) : null)
  return { personId, result }
}

export function useCatalogs() {
  return usePeopleItem<PeopleCatalogs>(pe.catalogs())
}
