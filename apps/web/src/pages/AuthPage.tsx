import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { getSession, safeRedirect, setSession } from '../lib/auth/session'
import { Field } from '../components/ui'

export function AuthPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [token, setToken] = useState('')
  if (getSession()) return <Navigate to="/academia" replace />
  function submit(event: FormEvent) {
    event.preventDefault()
    if (!token.trim()) return
    setSession({ token: token.trim() })
    const from = (location.state as { from?: string } | null)?.from
    navigate(safeRedirect(from), { replace: true })
  }
  return <main className="centered"><section className="card centered__card stack" aria-labelledby="sign-in-title"><div><p className="muted">Missão Evangélica Pentecostal de Angola</p><h1 id="sign-in-title">Aceder à Academia</h1></div><div className="alert alert--warning"><span className="alert__icon">i</span><div className="alert__body"><strong>Integração de autenticação pendente</strong><p>A API Academy ainda não disponibiliza login. Use um token de sessão emitido pelo ambiente autorizado.</p></div></div><form className="form" onSubmit={submit}><Field label="Token de sessão" name="token" required hint="O token fica apenas neste separador e é apagado ao terminar a sessão."><input id="token" className="input" type="password" value={token} onChange={(event) => setToken(event.target.value)} autoComplete="off" required /></Field><button className="btn btn--primary" type="submit">Entrar</button></form></section></main>
}
