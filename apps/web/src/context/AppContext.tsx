import { createContext, useContext, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from 'react'
import { capabilitiesFor, type Capabilities } from '../lib/academy/permissions'
import { NO_VOCABULARY, type Vocabulary } from '../lib/academy/vocabulary'
import { getSession, subscribeSession, type AuthSession } from '../lib/auth/session'
import { academyGet } from '../lib/academy/client'
import { ep } from '../lib/academy/endpoints'
import type { AcademyContextPayload, Item } from '../types/academy'

interface Toast { id: number; message: string; tone: 'success' | 'danger' }
interface AppValue {
  session: AuthSession | null
  capabilities: Capabilities
  vocabulary: Vocabulary
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
    <AppContext.Provider value={{ session, capabilities, vocabulary, notify }}>
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
