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
import { physicalGet } from '../lib/physical/client'
import { ph } from '../lib/physical/endpoints'
import type { CodeLabel, PhysicalContext, PhysicalUnit } from '../types/physical'
import { filesGet } from '../lib/files/client'
import { fl } from '../lib/files/endpoints'
import type { FilesContext } from '../types/files'
import { membershipGet } from '../lib/membership/client'
import { mb } from '../lib/membership/endpoints'
import type { MembershipContext } from '../types/membership'
import { financeGet } from '../lib/finance/client'
import { fin } from '../lib/finance/endpoints'
import type { FinanceContext } from '../types/finance'
import { hr as hrPaths } from '../lib/hr/endpoints'
import type { HrContext } from '../types/hr'

/** People permissions projected by the API (presentation only; the backend decides every request). */
export interface PeopleAccess {
  known: boolean
  has: (permission: string) => boolean
  workingUnits: WorkingUnit[]
}
export interface TerritorialAccess { known: boolean; has: (permission: string) => boolean; types: { code: string; label: string }[] }
/** Physical permissions projected by the API (presentation only; the backend decides every request). */
export interface PhysicalAccess { known: boolean; has: (permission: string) => boolean; units: PhysicalUnit[]; occupationTypes: PhysicalContext['occupation_types']; ownershipStatuses: CodeLabel[] }

/** Documents/Files permissions projected by the API (presentation only; the backend decides every request). */
export interface FilesAccess { known: boolean; has: (permission: string) => boolean; context: FilesContext | null }

/** Membership permissions projected by the API (presentation only; the backend decides every request). */
export interface MembershipAccess { known: boolean; has: (permission: string) => boolean; context: MembershipContext | null; refresh: () => void }

/** Finance permissions, units and accounts projected by the API (presentation only; the backend decides every request). */
export interface FinanceAccess { known: boolean; has: (permission: string) => boolean; context: FinanceContext | null; refresh: () => void }
/** P0.10-F2A: HR permissions held on at least one unit (from GET hr/context; 403 => no HR area). */
export interface HrAccess { known: boolean; has: (permission: string) => boolean; context: HrContext | null; refresh: () => void }

interface Toast { id: number; message: string; tone: 'success' | 'danger' }
interface AppValue {
  session: AuthSession | null
  capabilities: Capabilities
  vocabulary: Vocabulary
  people: PeopleAccess
  territorial: TerritorialAccess
  physical: PhysicalAccess
  files: FilesAccess
  membership: MembershipAccess
  finance: FinanceAccess
  hr: HrAccess
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
  const [physicalContext, setPhysicalContext] = useState<PhysicalContext | null | 'none'>(null)
  useEffect(() => {
    if (!sessionToken) { setPhysicalContext(null); return }
    const controller = new AbortController()
    physicalGet<Item<PhysicalContext>>(ph.context(), {}, controller.signal).then((result) => setPhysicalContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setPhysicalContext('none')
    })
    return () => controller.abort()
  }, [sessionToken])
  const physical = useMemo<PhysicalAccess>(() => {
    if (physicalContext === null) return { known: false, has: () => false, units: [], occupationTypes: [], ownershipStatuses: [] }
    if (physicalContext === 'none') return { known: true, has: () => false, units: [], occupationTypes: [], ownershipStatuses: [] }
    const held = new Set(physicalContext.permissions)
    return { known: true, has: (permission) => held.has(permission), units: physicalContext.units, occupationTypes: physicalContext.occupation_types, ownershipStatuses: physicalContext.ownership_statuses }
  }, [physicalContext])
  const [filesContext, setFilesContext] = useState<FilesContext | null | 'none'>(null)
  useEffect(() => {
    if (!sessionToken) { setFilesContext(null); return }
    const controller = new AbortController()
    filesGet<Item<FilesContext>>(fl.context(), {}, controller.signal).then((result) => setFilesContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setFilesContext('none')
    })
    return () => controller.abort()
  }, [sessionToken])
  const files = useMemo<FilesAccess>(() => {
    if (filesContext === null) return { known: false, has: () => false, context: null }
    if (filesContext === 'none') return { known: true, has: () => false, context: null }
    const held = new Set(filesContext.permissions)
    return { known: true, has: (permission) => held.has(permission), context: filesContext }
  }, [filesContext])
  const [membershipContext, setMembershipContext] = useState<MembershipContext | null | 'none'>(null)
  const [membershipNonce, setMembershipNonce] = useState(0)
  useEffect(() => {
    if (!sessionToken) { setMembershipContext(null); return }
    const controller = new AbortController()
    membershipGet<Item<MembershipContext>>(mb.context(), {}, controller.signal).then((result) => setMembershipContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setMembershipContext('none')
    })
    return () => controller.abort()
  }, [sessionToken, membershipNonce])
  const membership = useMemo<MembershipAccess>(() => {
    const refresh = () => setMembershipNonce((value) => value + 1)
    if (membershipContext === null) return { known: false, has: () => false, context: null, refresh }
    if (membershipContext === 'none') return { known: true, has: () => false, context: null, refresh }
    const held = new Set(membershipContext.permissions)
    return { known: true, has: (permission) => held.has(permission), context: membershipContext, refresh }
  }, [membershipContext])
  const [financeContext, setFinanceContext] = useState<FinanceContext | null | 'none'>(null)
  const [financeNonce, setFinanceNonce] = useState(0)
  useEffect(() => {
    if (!sessionToken) { setFinanceContext(null); return }
    const controller = new AbortController()
    financeGet<Item<FinanceContext>>(fin.context(), {}, controller.signal).then((result) => setFinanceContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setFinanceContext('none')
    })
    return () => controller.abort()
  }, [sessionToken, financeNonce])
  const finance = useMemo<FinanceAccess>(() => {
    const refresh = () => setFinanceNonce((value) => value + 1)
    if (financeContext === null) return { known: false, has: () => false, context: null, refresh }
    if (financeContext === 'none') return { known: true, has: () => false, context: null, refresh }
    const held = new Set(financeContext.permissions)
    return { known: true, has: (permission) => held.has(permission), context: financeContext, refresh }
  }, [financeContext])
  const [hrContext, setHrContext] = useState<HrContext | null | 'none'>(null)
  const [hrNonce, setHrNonce] = useState(0)
  useEffect(() => {
    if (!sessionToken) { setHrContext(null); return }
    const controller = new AbortController()
    financeGet<Item<HrContext>>(hrPaths.context(), {}, controller.signal).then((result) => setHrContext(result.data), (error: unknown) => {
      if (!(error instanceof DOMException && error.name === 'AbortError')) setHrContext('none')
    })
    return () => controller.abort()
  }, [sessionToken, hrNonce])
  const hr = useMemo<HrAccess>(() => {
    const refresh = () => setHrNonce((value) => value + 1)
    if (hrContext === null) return { known: false, has: () => false, context: null, refresh }
    if (hrContext === 'none') return { known: true, has: () => false, context: null, refresh }
    const held = new Set(hrContext.permissions)
    return { known: true, has: (permission) => held.has(permission), context: hrContext, refresh }
  }, [hrContext])
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
    <AppContext.Provider value={{ session, capabilities, vocabulary, people, territorial, physical, files, membership, finance, hr, notify }}>
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
