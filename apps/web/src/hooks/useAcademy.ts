import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { academyGet } from '../lib/academy/client'
import { DEFAULT_PAGE_SIZE, MIN_SEARCH_LENGTH } from '../lib/academy/endpoints'
import { isAbort, toUiError, type UiError } from '../lib/academy/errors'
import type { Item, ListQuery, Paginated } from '../types/academy'

interface LoadState<T> { data: T | null; loading: boolean; error: UiError | null }

export function useAcademyItem<T>(path: string | null) {
  const [nonce, setNonce] = useState(0)
  const [state, setState] = useState<LoadState<T>>({ data: null, loading: Boolean(path), error: null })
  useEffect(() => {
    if (!path) { setState({ data: null, loading: false, error: null }); return }
    const controller = new AbortController()
    setState((current) => ({ ...current, loading: true, error: null }))
    academyGet<Item<T>>(path, {}, controller.signal).then(
      (result) => setState({ data: result.data, loading: false, error: null }),
      (error: unknown) => { if (!isAbort(error)) setState({ data: null, loading: false, error: toUiError(error) }) },
    )
    return () => controller.abort()
  }, [path, nonce])
  return { ...state, reload: () => setNonce((value) => value + 1) }
}

export function useAcademyPage<T>(path: string | null, query: ListQuery) {
  const [nonce, setNonce] = useState(0)
  const [state, setState] = useState<LoadState<Paginated<T>>>({ data: null, loading: Boolean(path), error: null })
  const key = JSON.stringify(query)
  useEffect(() => {
    if (!path) { setState({ data: null, loading: false, error: null }); return }
    const controller = new AbortController()
    setState((current) => ({ ...current, loading: true, error: null }))
    const requestQuery = JSON.parse(key) as ListQuery
    academyGet<Paginated<T>>(path, requestQuery, controller.signal).then(
      (result) => setState({ data: result, loading: false, error: null }),
      (error: unknown) => { if (!isAbort(error)) setState({ data: null, loading: false, error: toUiError(error) }) },
    )
    return () => controller.abort()
  }, [path, key, nonce])
  return { ...state, reload: () => setNonce((value) => value + 1) }
}

export function useListQuery(searchEnabled = true) {
  const [params, setParams] = useSearchParams()
  const rawSearch = params.get('search') ?? ''
  const [searchInput, setSearchInput] = useState(rawSearch)
  const timer = useRef<number | null>(null)
  useEffect(() => setSearchInput(rawSearch), [rawSearch])
  useEffect(() => () => { if (timer.current) window.clearTimeout(timer.current) }, [])

  const query = useMemo<ListQuery>(() => {
    const page = Math.max(1, Number(params.get('page')) || 1)
    const result: ListQuery = { page, per_page: DEFAULT_PAGE_SIZE }
    for (const key of ['search', 'status', 'academic_unit', 'program', 'curriculum', 'course_version', 'cohort', 'date_from', 'date_to', 'sort', 'direction']) {
      const value = params.get(key)
      if (value) result[key] = value
    }
    return result
  }, [params])

  const update = useCallback((updates: Record<string, string | number | null | undefined>) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      for (const [key, value] of Object.entries(updates)) {
        if (value === null || value === undefined || value === '') next.delete(key)
        else next.set(key, String(value))
      }
      if (!Object.prototype.hasOwnProperty.call(updates, 'page')) next.set('page', '1')
      return next
    })
  }, [setParams])

  const changeSearch = useCallback((value: string) => {
    setSearchInput(value)
    if (timer.current) window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => update({ search: value.length >= MIN_SEARCH_LENGTH ? value : null }), 400)
  }, [update])

  return { query, params, update, searchInput, changeSearch: searchEnabled ? changeSearch : undefined }
}
