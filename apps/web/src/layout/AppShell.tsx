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
const peopleLinks = [['/pessoas', 'Pessoas', 'PEOPLE_VIEW'], ['/pessoas/nova', 'Nova Pessoa', 'PEOPLE_CREATE'], ['/familias', 'Famílias', 'HOUSEHOLD_VIEW']] as const
const physicalLinks = [['/locais', 'Locais', ['PHYSICAL_LOCATION_VIEW']], ['/imoveis', 'Imóveis', ['PROPERTY_VIEW']], ['/templos', 'Templos', ['TEMPLE_VIEW']], ['/ligacoes', 'Ligações institucionais', ['PHYSICAL_LOCATION_VIEW', 'UNIT_LOCATION_LINK_MANAGE']]] as const

const membershipLinks = [['/membros', 'Membros', ['MEMBERSHIP_VIEW']], ['/membros/admissoes', 'Admissões', ['MEMBERSHIP_ADMISSION_MANAGE', 'MEMBERSHIP_APPROVE']], ['/membros/transferencias', 'Transferências', ['MEMBERSHIP_VIEW', 'MEMBERSHIP_TRANSFER']]] as const

// P0.10-F2A RH / Folha Salarial: shown only for the HR permissions the server reports. No processing, approval, posting
// or payment entry exists before F2B.
const hrLinks = [['/rh/funcionarios', 'Funcionários', ['HR_EMPLOYMENT_VIEW']], ['/rh/vinculos', 'Vínculos', ['HR_EMPLOYMENT_VIEW']], ['/rh/remuneracao', 'Remuneração', ['HR_COMPENSATION_VIEW']],
  ['/rh/componentes', 'Componentes', ['HR_EMPLOYMENT_VIEW', 'HR_COMPENSATION_VIEW', 'PAYROLL_MANAGE', 'PAYROLL_RULES_MANAGE', 'PAYROLL_RULES_APPROVE']],
  ['/rh/regras', 'Regras', ['PAYROLL_RULES_MANAGE', 'PAYROLL_RULES_APPROVE', 'PAYROLL_MANAGE', 'HR_COMPENSATION_VIEW']], ['/rh/prontidao', 'Prontidão da Folha', ['PAYROLL_MANAGE', 'HR_COMPENSATION_VIEW']]] as const
const financeLinks = [['/financas', 'Visão Geral', ['FINANCE_REPORT']], ['/financas/contas', 'Contas', ['FINANCE_VIEW']], ['/financas/a-receber', 'A receber', ['FINANCE_VIEW']], ['/financas/a-pagar', 'A pagar', ['FINANCE_VIEW']], ['/financas/contribuicoes', 'Contribuições', ['FINANCE_VIEW']], ['/financas/transferencias', 'Transferências', ['FINANCE_VIEW']], ['/financas/posicao', 'Posição de fundos', ['FINANCE_VIEW']], ['/financas/extractos', 'Extractos', ['FINANCE_VIEW']], ['/financas/reconciliacao', 'Reconciliação', ['FINANCE_VIEW']], ['/financas/orcamento', 'Orçamento', ['FINANCE_VIEW', 'FINANCE_BUDGET_MANAGE', 'FINANCE_BUDGET_APPROVE']], ['/financas/fechos', 'Fechos', ['FINANCE_VIEW', 'FINANCE_PERIOD_CLOSE', 'FINANCE_PERIOD_REOPEN']], ['/financas/relatorios', 'Relatórios', ['FINANCE_REPORT']]] as const

const filesLinks = [['/documentos', 'Documentos', ['DOCUMENTS_VIEW']], ['/ficheiros', 'Ficheiros', ['FILES_VIEW']], ['/ficheiros/carregar', 'Carregar ficheiro', ['FILES_UPLOAD']]] as const

export function AppShell() {
  const [menu, setMenu] = useState(false)
  const [online, setOnline] = useState(navigator.onLine)
  const [signingOut, setSigningOut] = useState(false)
  const { capabilities, people, territorial, physical, files, membership, finance, hr } = useApp()
  const location = useLocation()
  const navigate = useNavigate()
  useEffect(() => setMenu(false), [location.pathname])
  useEffect(() => { const on = () => setOnline(true); const off = () => setOnline(false); window.addEventListener('online', on); window.addEventListener('offline', off); return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) } }, [])
  async function signOut() { const token = getSession()?.token; setSigningOut(true); try { if (token) await logout(token) } finally { clearSession(); navigate('/entrar', { replace: true }) } }
  const visibleLinks = links.filter(([, , capability]) => capability === null || (capabilities.known && capabilities.can(capability)))
  const visiblePeople = peopleLinks.filter(([, , permission]) => people.known && people.has(permission))
  const visibleFiles = filesLinks.filter(([, , permissions]) => files.known && permissions.some((permission) => files.has(permission)))
  const visibleMembership = membershipLinks.filter(([, , permissions]) => membership.known && permissions.some((permission) => membership.has(permission)))
  const visibleHr = hrLinks.filter(([, , permissions]) => hr.known && permissions.some((permission) => hr.has(permission)))
  const visibleFinance = financeLinks.filter(([, , permissions]) => finance.known && permissions.some((permission) => finance.has(permission)))
  const visiblePhysical = physicalLinks.filter(([, , permissions]) => physical.known && permissions.some((permission) => physical.has(permission)))
  return <div className="shell"><a className="skip-link" href="#main-content">Saltar para o conteúdo</a><header className="topbar"><button className="btn topbar__menu" onClick={() => setMenu((value) => !value)} aria-expanded={menu} aria-controls="main-nav">Menu</button><Link className="topbar__brand" to="/academia">MEPA Gestão</Link><span className="topbar__spacer" /><button className="btn" onClick={signOut} disabled={signingOut}>{signingOut ? 'A terminar…' : 'Terminar sessão'}</button></header>{!online && <div className="offline-banner" role="status">Sem ligação. As alterações não serão enviadas.</div>}<div className="shell__body"><aside className="sidenav" data-open={menu} id="main-nav">{territorial.known && territorial.has('TERRITORIAL_VIEW') && <><h2 className="sidenav__title">Estrutura</h2><nav aria-label="Estrutura Territorial"><ul><li><NavLink to="/estrutura-territorial" end>Estrutura Territorial</NavLink></li><li><NavLink to="/estrutura-territorial/lista">Lista territorial</NavLink></li></ul></nav></>}{visiblePhysical.length > 0 && <><h2 className="sidenav__title">Locais e Património</h2><nav aria-label="Locais e Património"><ul>{visiblePhysical.map(([to, label]) => <li key={to}><NavLink to={to}>{label}</NavLink></li>)}</ul></nav></>}{visibleFiles.length > 0 && <><h2 className="sidenav__title">Documentos e Ficheiros</h2><nav aria-label="Documentos e Ficheiros"><ul>{visibleFiles.map(([to, label]) => <li key={to}><NavLink to={to} end>{label}</NavLink></li>)}</ul></nav></>}{visibleFinance.length > 0 && <><h2 className="sidenav__title">Finanças</h2><nav aria-label="Finanças"><ul>{visibleFinance.map(([to, label]) => <li key={to}><NavLink to={to} end>{label}</NavLink></li>)}</ul></nav></>}{visibleHr.length > 0 && <><h2 className="sidenav__title">RH / Folha Salarial</h2><nav aria-label="RH / Folha Salarial"><ul>{visibleHr.map(([to, label]) => <li key={to}><NavLink to={to} end>{label}</NavLink></li>)}</ul></nav></>}{visibleMembership.length > 0 && <><h2 className="sidenav__title">Membros</h2><nav aria-label="Membros"><ul>{visibleMembership.map(([to, label]) => <li key={to}><NavLink to={to} end>{label}</NavLink></li>)}</ul></nav></>}{visiblePeople.length > 0 && <><h2 className="sidenav__title">Pessoas</h2><nav aria-label="Pessoas"><ul>{visiblePeople.map(([to, label]) => <li key={to}><NavLink to={to} end>{label}</NavLink></li>)}</ul></nav></>}<h2 className="sidenav__title">Academia</h2><nav aria-label="Academia"><ul>{visibleLinks.map(([to, label]) => <li key={to}><NavLink to={to} end={to === '/academia'}>{label}</NavLink></li>)}</ul></nav></aside>{menu && <button className="sidenav__scrim" onClick={() => setMenu(false)} aria-label="Fechar menu" />}<main className="main" id="main-content"><div className="main__inner"><Outlet /></div></main></div></div>
}
