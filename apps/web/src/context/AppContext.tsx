import { createContext, useContext, useMemo, useState, useSyncExternalStore, type ReactNode } from 'react'
import { capabilitiesFor, type Capabilities } from '../lib/academy/permissions'
import { NO_VOCABULARY, type Vocabulary } from '../lib/academy/vocabulary'
import { getSession, subscribeSession, type AuthSession } from '../lib/auth/session'

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
  const [toasts, setToasts] = useState<Toast[]>([])
  const capabilities = useMemo(() => capabilitiesFor(session), [session])

  function notify(message: string, tone: Toast['tone'] = 'success') {
    const id = Date.now() + Math.random()
    setToasts((current) => [...current, { id, message, tone }])
    window.setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 5000)
  }

  return (
    <AppContext.Provider value={{ session, capabilities, vocabulary: NO_VOCABULARY, notify }}>
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
