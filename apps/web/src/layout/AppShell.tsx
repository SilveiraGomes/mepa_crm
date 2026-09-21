import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { clearSession } from '../lib/auth/session'

const links = [
  ['/academia', 'Visão geral'], ['/academia/unidades', 'Unidades académicas'], ['/academia/programas', 'Programas'],
  ['/academia/curriculos', 'Currículos'], ['/academia/cursos', 'Cursos'], ['/academia/coortes', 'Coortes'],
  ['/academia/turmas', 'Turmas'], ['/academia/historicos', 'Históricos académicos'],
] as const

export function AppShell() {
  const [menu, setMenu] = useState(false)
  const [online, setOnline] = useState(navigator.onLine)
  const location = useLocation()
  const navigate = useNavigate()
  useEffect(() => setMenu(false), [location.pathname])
  useEffect(() => { const on = () => setOnline(true); const off = () => setOnline(false); window.addEventListener('online', on); window.addEventListener('offline', off); return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) } }, [])
  function signOut() { clearSession(); navigate('/entrar', { replace: true }) }
  return <div className="shell"><a className="skip-link" href="#main-content">Saltar para o conteúdo</a><header className="topbar"><button className="btn topbar__menu" onClick={() => setMenu((value) => !value)} aria-expanded={menu} aria-controls="main-nav">Menu</button><Link className="topbar__brand" to="/academia">MEPA Gestão</Link><span className="topbar__spacer" /><button className="btn" onClick={signOut}>Terminar sessão</button></header>{!online && <div className="offline-banner" role="status">Sem ligação. As alterações não serão enviadas.</div>}<div className="shell__body"><aside className="sidenav" data-open={menu} id="main-nav"><h2 className="sidenav__title">Academia</h2><nav aria-label="Academia"><ul>{links.map(([to, label]) => <li key={to}><NavLink to={to} end={to === '/academia'}>{label}</NavLink></li>)}</ul></nav></aside>{menu && <button className="sidenav__scrim" onClick={() => setMenu(false)} aria-label="Fechar menu" />}<main className="main" id="main-content"><div className="main__inner"><Outlet /></div></main></div></div>
}
