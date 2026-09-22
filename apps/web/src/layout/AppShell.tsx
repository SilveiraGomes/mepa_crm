import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import type { Capability } from '../lib/academy/permissions'
import { logout } from '../lib/auth/client'
import { clearSession, getSession } from '../lib/auth/session'

const links = [
  ['/academia', 'Visão geral', null], ['/academia/unidades', 'Unidades académicas', 'canView'], ['/academia/programas', 'Programas', 'canView'],
  ['/academia/curriculos', 'Currículos', 'canView'], ['/academia/cursos', 'Cursos', 'canView'], ['/academia/coortes', 'Coortes', 'canView'],
  ['/academia/turmas', 'Turmas', 'canView'], ['/academia/historicos', 'Históricos académicos', 'canCertify'],
] as const satisfies readonly (readonly [string, string, Capability | null])[]

export function AppShell() {
  const [menu, setMenu] = useState(false)
  const [online, setOnline] = useState(navigator.onLine)
  const [signingOut, setSigningOut] = useState(false)
  const { capabilities } = useApp()
  const location = useLocation()
  const navigate = useNavigate()
  useEffect(() => setMenu(false), [location.pathname])
  useEffect(() => { const on = () => setOnline(true); const off = () => setOnline(false); window.addEventListener('online', on); window.addEventListener('offline', off); return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) } }, [])

  async function signOut() {
    const token = getSession()?.token
    setSigningOut(true)
    try { if (token) await logout(token) } finally { clearSession(); navigate('/entrar', { replace: true }) }
  }

  const visibleLinks = links.filter(([, , capability]) => capability === null || (capabilities.known && capabilities.can(capability)))
  return <div className="shell"><a className="skip-link" href="#main-content">Saltar para o conteúdo</a><header className="topbar"><button className="btn topbar__menu" onClick={() => setMenu((value) => !value)} aria-expanded={menu} aria-controls="main-nav">Menu</button><Link className="topbar__brand" to="/academia">MEPA Gestão</Link><span className="topbar__spacer" /><button className="btn" onClick={signOut} disabled={signingOut}>{signingOut ? 'A terminar…' : 'Terminar sessão'}</button></header>{!online && <div className="offline-banner" role="status">Sem ligação. As alterações não serão enviadas.</div>}<div className="shell__body"><aside className="sidenav" data-open={menu} id="main-nav"><h2 className="sidenav__title">Academia</h2><nav aria-label="Academia"><ul>{visibleLinks.map(([to, label]) => <li key={to}><NavLink to={to} end={to === '/academia'}>{label}</NavLink></li>)}</ul></nav></aside>{menu && <button className="sidenav__scrim" onClick={() => setMenu(false)} aria-label="Fechar menu" />}<main className="main" id="main-content"><div className="main__inner"><Outlet /></div></main></div></div>
}
