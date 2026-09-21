import { Link, useParams } from 'react-router-dom'
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader, Pagination, StatusBadge, type Column } from '../components/ui'
import { useAcademyItem, useAcademyPage, useListQuery } from '../hooks/useAcademy'
import { ep } from '../lib/academy/endpoints'
import { safeHttpUrl } from '../lib/format'
import type { CourseModule, CourseVersion, CurriculumCourse, Lesson, LessonResource } from '../types/academy'

function PagedSection<T>({ title, description, path, columns, rowKey, back }: { title: string; description: string; path: string | null; columns: Column<T>[]; rowKey: (row: T) => string | number; back: { to: string; label: string } }) {
  const { query, update } = useListQuery(false)
  const result = useAcademyPage<T>(path, query)
  return <><PageHeader title={title} description={description} back={back} />{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data?.data.length === 0 && <EmptyState />}{result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={rowKey} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}</>
}

export function CurriculumCoursesPage() {
  const { id } = useParams()
  return <PagedSection<CurriculumCourse> title="Cursos do currículo" description="Colecção paginada. O currículo não carrega a árvore completa." path={id ? ep.curriculumCourses(id) : null} back={{ to: `/academia/curriculos/${id}`, label: 'Voltar ao currículo' }} rowKey={(r) => r.public_id} columns={[
    { key: 'sequence', label: 'Ordem', render: (r) => r.sequence }, { key: 'code', label: 'Código', render: (r) => r.code }, { key: 'name', label: 'Curso', render: (r) => r.name }, { key: 'required', label: 'Obrigatório', render: (r) => r.required ? 'Sim' : 'Não' }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/cursos/${r.public_id}`}>Abrir</Link> },
  ]} />
}

export function CourseVersionsPage() {
  const { id } = useParams()
  return <PagedSection<CourseVersion> title="Versões do curso" description="Cada versão conserva o conteúdo e o estado próprios." path={id ? ep.courseVersions(id) : null} back={{ to: `/academia/cursos/${id}`, label: 'Voltar ao curso' }} rowKey={(r) => r.id} columns={[
    { key: 'version', label: 'Versão', render: (r) => r.version }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/versoes/${r.id}`}>Abrir</Link> },
  ]} />
}

export function CourseVersionPage() {
  const { versionId } = useParams()
  const result = useAcademyItem<CourseVersion>(versionId ? ep.courseVersion(versionId) : null)
  if (result.loading) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return null
  const item = result.data
  return <><PageHeader title={`${item.course_name ?? 'Curso'} · versão ${item.version}`} back={{ to: `/academia/cursos/${item.course_public_id}`, label: 'Voltar ao curso' }} actions={<Link className="btn btn--primary" to={`/academia/versoes/${item.id}/modulos`}>Abrir módulos</Link>} /><section className="tiles"><div className="tile"><span className="tile__value">{item.module_count ?? 0}</span><span className="tile__label">Módulos</span></div><div className="tile"><span className="tile__value">{item.lesson_count ?? 0}</span><span className="tile__label">Aulas</span></div><div className="tile"><span className="tile__value">{item.resource_count ?? 0}</span><span className="tile__label">Recursos</span></div></section><section className="card"><dl className="dl"><div className="dl__item"><dt>Código</dt><dd>{item.course_code}</dd></div><div className="dl__item"><dt>Estado</dt><dd><StatusBadge value={item.status} /></dd></div></dl></section></>
}

export function ModulesPage() {
  const { versionId } = useParams()
  return <PagedSection<CourseModule> title="Módulos" description="Abra um módulo para carregar apenas as respectivas aulas." path={versionId ? ep.courseVersionModules(versionId) : null} back={{ to: `/academia/versoes/${versionId}`, label: 'Voltar à versão' }} rowKey={(r) => r.id} columns={[
    { key: 'sequence', label: 'Ordem', render: (r) => r.sequence }, { key: 'name', label: 'Módulo', render: (r) => r.name }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/versoes/${versionId}/modulos/${r.id}/aulas`}>Ver aulas</Link> },
  ]} />
}

export function LessonsPage() {
  const { versionId, moduleId } = useParams()
  return <PagedSection<Lesson> title="Aulas" description="Abra uma aula para carregar os seus recursos." path={versionId && moduleId ? ep.moduleLessons(versionId, moduleId) : null} back={{ to: `/academia/versoes/${versionId}/modulos`, label: 'Voltar aos módulos' }} rowKey={(r) => r.id} columns={[
    { key: 'sequence', label: 'Ordem', render: (r) => r.sequence }, { key: 'name', label: 'Aula', render: (r) => r.name }, { key: 'required', label: 'Obrigatória', render: (r) => r.required ? 'Sim' : 'Não' }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/versoes/${versionId}/modulos/${moduleId}/aulas/${r.id}/recursos`}>Ver recursos</Link> },
  ]} />
}

export function ResourcesPage() {
  const { versionId, moduleId, lessonId } = useParams()
  return <PagedSection<LessonResource> title="Recursos" description="Recursos associados à aula." path={versionId && moduleId && lessonId ? ep.lessonResources(versionId, moduleId, lessonId) : null} back={{ to: `/academia/versoes/${versionId}/modulos/${moduleId}/aulas`, label: 'Voltar às aulas' }} rowKey={(r) => r.public_id} columns={[
    { key: 'sequence', label: 'Ordem', render: (r) => r.sequence }, { key: 'kind', label: 'Tipo', render: (r) => r.kind }, { key: 'provider', label: 'Fornecedor', render: (r) => r.provider ?? '—' }, { key: 'required', label: 'Obrigatório', render: (r) => r.required ? 'Sim' : 'Não' }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'link', label: '', render: (r) => { const url = safeHttpUrl(r.external_url); return url ? <a className="btn btn--secondary btn--sm" href={url} target="_blank" rel="noopener noreferrer">Abrir recurso</a> : <span className="muted">Sem ligação segura</span> } },
  ]} />
}
