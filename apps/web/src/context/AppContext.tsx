import { createContext, useContext, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from 'react'
import { capabilitiesFor, type Capabilities } from '../lib/academy/permissions'
import { NO_VOCABULARY, type Vocabulary } from '../lib/academy/vocabulary'
import { getSession, subscribeSession, type AuthSession } from '../lib/auth/session'
import { academyGet } from '../lib/academy/client'
import { ep } from '../lib/academy/endpoints'
import type { AcademyContextPayload, Item } from '../types/academy'
import { peopleGet } from '../lib/people/client'
import { pe } from '../lib/people/endpoints'
import type { PeopleContext, WorkingUnit } from '../types/people'
import { territorialGet } from '../lib/territorial/client'
import { te } from '../lib/territorial/endpoints'
import type { TerritorialContext } from '../types/territorial'

/** People permissions projected by the API (presentation only; the backend decides every request). */
export interface PeopleAccess {
  known: boolean
  has: (permission: string) => boolean
  workingUnits: WorkingUnit[]
}
export interface TerritorialAccess { known: boolean; has: (permission: string) => boolean; types: { code: string; label: string }[] }

interface Toast { id: number; message: string; tone: 'success' | 'danger' }
interface AppValue {
  session: AuthSession | null
  capabilities: Capabilities
  vocabulary: Vocabulary
  people: PeopleAccess
  territorial: TerritorialAccess
  notify: (message: string, tone?: Toast['tone']) => void
}

const AppContext = createContext<AppValue | null>(null)

export function AppProvider({ children }: { children: ReactNode }) {
  const session = useSyncExternalStore(subscribeSession, getSession, () => null)
  const sessionToken = session?.token
  const [toasts, setToasts] = useState<Toast[]>([])
  const [academyContext, setAcademyContext] = useState<AcademyContextPayload | null>(null)
  useEffect(() => {
    if (!sessionToken) { setAcademyContext(null); return }
    const controller = new AbortController()
    academyGet<Item<AcademyContextPayload>>(ep.context(), {}, controller.signal).then((result) => setAcademyContext(result.data), () => setAcademyContext(null))
    return () => controller.abort()
  }, [sessionToken])
  const [peopleContext, setPeopleContext] = useState<PeopleContext | null | 'none'>(null)
  useEffect(() => {
    if (!sessionToken) { setPeopleContext(null); return }
    const controller = new AbortController()
    peopleGet<Item<PeopleContext>>(pe.context(), {}, controller.signal).then((result) => setPeopleContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setPeopleContext('none')
    })
    return () => controller.abort()
  }, [sessionToken])
  const people = useMemo<PeopleAccess>(() => {
    if (peopleContext === null) return { known: false, has: () => false, workingUnits: [] }
    if (peopleContext === 'none') return { known: true, has: () => false, workingUnits: [] }
    const held = new Set(peopleContext.permissions)
    return { known: true, has: (permission) => held.has(permission), workingUnits: peopleContext.working_units }
  }, [peopleContext])
  const [territorialContext, setTerritorialContext] = useState<TerritorialContext | null | 'none'>(null)
  useEffect(() => {
    if (!sessionToken) { setTerritorialContext(null); return }
    const controller = new AbortController()
    territorialGet<Item<TerritorialContext>>(te.context(), {}, controller.signal).then((result) => setTerritorialContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setTerritorialContext('none')
    })
    return () => controller.abort()
  }, [sessionToken])
  const territorial = useMemo<TerritorialAccess>(() => {
    if (territorialContext === null) return { known: false, has: () => false, types: [] }
    if (territorialContext === 'none') return { known: true, has: () => false, types: [] }
    const held = new Set(territorialContext.permissions)
    return { known: true, has: (permission) => held.has(permission), types: territorialContext.types }
  }, [territorialContext])
  const capabilities = useMemo(() => capabilitiesFor(session ? { permissions: academyContext?.permissions } : null), [academyContext, session])
  const vocabulary = useMemo<Vocabulary>(() => academyContext ? {
    'enrollment.transitions': academyContext.vocabulary.transitions.enrollment.map((item) => ({ value: item.to, label: item.to, from: item.from })),
    'session.transitions': academyContext.vocabulary.transitions.session.map((item) => ({ value: item.to, label: item.to, from: item.from })),
    'attempt.transitions': academyContext.vocabulary.transitions.attempt.map((item) => ({ value: item.to, label: item.to, from: item.from })),
    'attendance.statuses': academyContext.vocabulary.attendance_statuses.map((value) => ({ value, label: value })),
  } : NO_VOCABULARY, [academyContext])

  function notify(message: string, tone: Toast['tone'] = 'success') {
    const id = Date.now() + Math.random()
    setToasts((current) => [...current, { id, message, tone }])
    window.setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 5000)
  }

  return (
    <AppContext.Provider value={{ session, capabilities, vocabulary, people, territorial, notify }}>
      {children}
      <div className="toasts" aria-live="polite" aria-atomic="true">
        {toasts.map((toast) => <div key={toast.id} className={`toast${toast.tone === 'danger' ? ' toast--danger' : ''}`}><span>{toast.message}</span></div>)}
      </div>
    </AppContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useApp(): AppValue {
  const value = useContext(AppContext)
  if (!value) throw new Error('useApp must be used inside AppProvider')
  return value
}
