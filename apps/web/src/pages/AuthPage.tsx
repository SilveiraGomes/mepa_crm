import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { Field } from '../components/ui'
import { login } from '../lib/auth/client'
import { getSession, safeRedirect, setSession } from '../lib/auth/session'
import { ApiError, NetworkError } from '../services/api'

export function AuthPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [loginId, setLoginId] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (getSession()) return <Navigate to="/academia" replace />

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!loginId.trim() || !password || busy) return
    setBusy(true)
    setError('')
    try {
      const result = await login(loginId.trim(), password)
      setSession({ token: result.session.token, expiresAt: result.session.expires_at, user: result.user })
      const from = (location.state as { from?: string } | null)?.from
      navigate(safeRedirect(from), { replace: true })
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 429) {
        setError(failure.retryAfterSeconds ? `Foram feitas demasiadas tentativas. Tente novamente dentro de ${failure.retryAfterSeconds} segundos.` : 'Foram feitas demasiadas tentativas. Aguarde um momento e tente novamente.')
      } else if (failure instanceof ApiError && failure.code === 'INVALID_CREDENTIALS') {
        setError('O utilizador ou a palavra-passe não estão correctos.')
      } else if (failure instanceof NetworkError) {
        setError('Não foi possível ligar ao servidor. Verifique a ligação e tente novamente.')
      } else {
        setError('Não foi possível iniciar a sessão. Tente novamente.')
      }
    } finally {
      setBusy(false)
    }
  }

  return <main className="centered auth-page"><section className="card centered__card stack" aria-labelledby="sign-in-title"><div className="auth-heading"><span className="auth-mark" aria-hidden="true">M</span><div><p className="muted">Missão Evangélica Pentecostal de Angola</p><h1 id="sign-in-title">Aceder ao MEPA Gestão</h1></div></div>{error && <div className="alert alert--danger" role="alert"><span className="alert__icon">!</span><div className="alert__body">{error}</div></div>}<form className="form" onSubmit={submit} noValidate><Field label="Utilizador" name="login" required><input id="login" className="input" value={loginId} onChange={(event) => setLoginId(event.target.value)} autoComplete="username" required disabled={busy} /></Field><Field label="Palavra-passe" name="password" required><div className="password-field"><input id="password" className="input" type={showPassword ? 'text' : 'password'} value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required disabled={busy} /><button className="btn btn--secondary" type="button" aria-pressed={showPassword} onClick={() => setShowPassword((value) => !value)}>{showPassword ? 'Ocultar' : 'Mostrar'}</button></div></Field><button className="btn btn--primary" type="submit" disabled={busy || !loginId.trim() || !password}>{busy && <span className="btn__spinner" aria-hidden="true" />}{busy ? 'A entrar…' : 'Entrar'}</button></form><p className="muted auth-note">A sessão fica apenas neste separador e termina quando o fechar.</p></section></main>
}
