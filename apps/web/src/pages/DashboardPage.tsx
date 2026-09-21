import { Link } from 'react-router-dom'
import { PageHeader } from '../components/ui'
import { useApp } from '../context/AppContext'

const shortcuts = [
  ['/academia/turmas', 'Turmas', 'Consulte turmas, sessões, matrículas e actividade lectiva.'],
  ['/academia/programas', 'Programas', 'Navegue pelos programas e respectivos currículos.'],
  ['/academia/curriculos', 'Currículos', 'Consulte versões e os cursos associados.'],
  ['/academia/cursos', 'Cursos', 'Abra versões, módulos, aulas e recursos.'],
] as const

export function DashboardPage() {
  const { capabilities } = useApp()
  return <><PageHeader title="Academia" description="Acesso operacional à formação, turmas e documentação académica." /><div className="alert alert--info"><span className="alert__icon">i</span><div className="alert__body"><strong>Resumo operacional</strong><p>A API actual não fornece métricas agregadas. Os atalhos abaixo abrem listas paginadas sem carregar colecções completas.</p>{!capabilities.known && <p>As permissões da sessão estão a ser verificadas. As acções de alteração permanecem ocultas até à confirmação.</p>}</div></div><section aria-labelledby="shortcuts-title" className="stack"><h2 id="shortcuts-title">Atalhos</h2><div className="tiles">{shortcuts.map(([to, label, description]) => <Link key={to} to={to} className="tile"><strong>{label}</strong><span className="muted">{description}</span></Link>)}</div></section></>
}
