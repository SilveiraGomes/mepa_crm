import { useEffect, useState, type FormEvent } from 'react'
import { Link, NavLink, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination, StatusBadge, type Column } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useAcademyItem, useAcademyPage, useListQuery } from '../hooks/useAcademy'
import { academyPost } from '../lib/academy/client'
import { ep, MIN_SEARCH_LENGTH } from '../lib/academy/endpoints'
import { toUiError, type UiError } from '../lib/academy/errors'
import { VOCABULARY_PENDING_MESSAGE } from '../lib/academy/vocabulary'
import { formatDateTime } from '../lib/format'
import type { ClassDetail, ClassInstructorAssignment, ClassSession, ClassSummary, EnrollmentCreated, EnrollmentDetail, PersonSearchResult, RosterRow } from '../types/academy'

const classColumns: Column<ClassSummary>[] = [
  { key: 'code', label: 'Código', render: (r) => r.code }, { key: 'course', label: 'Curso', render: (r) => `${r.course_name} · v${r.course_version}` }, { key: 'unit', label: 'Unidade', render: (r) => r.academic_unit_code }, { key: 'capacity', label: 'Capacidade', render: (r) => r.capacity ?? '—' }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/turmas/${r.public_id}`}>Abrir</Link> },
]

export function ClassesPage() {
  const { query, update, searchInput, changeSearch } = useListQuery(true)
  const result = useAcademyPage<ClassSummary>(ep.classes(), query)
  return <><PageHeader title="Turmas" description="Turmas disponíveis no seu âmbito institucional." /><div className="card filters"><div className="filters__panel"><label className="field"><span className="field__label">Pesquisar</span><input className="input" value={searchInput} onChange={(e) => changeSearch?.(e.target.value)} placeholder="Código ou curso" /></label><label className="field"><span className="field__label">Estado</span><input className="input" value={String(query.status ?? '')} onChange={(e) => update({ status: e.target.value || null })} /></label><label className="field"><span className="field__label">Ordenar por</span><select className="select" value={String(query.sort ?? '')} onChange={(e) => update({ sort: e.target.value || null })}><option value="">Ordem padrão</option><option value="code">Código</option><option value="name">Curso</option><option value="status">Estado</option></select></label><button className="btn btn--ghost" onClick={() => update({ search: null, status: null, sort: null })}>Limpar filtros</button></div></div>{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data?.data.length === 0 && <EmptyState />}{result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={classColumns} rowKey={(r) => r.public_id} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}</>
}

const tabs = [['', 'Resumo'], ['matriculas', 'Matrículas'], ['instrutores', 'Instrutores'], ['sessoes', 'Sessões'], ['avaliacoes', 'Avaliações'], ['certificados', 'Certificados']] as const

function ClassHeader({ item }: { item: ClassDetail }) {
  const base = `/academia/turmas/${item.public_id}`
  return <><PageHeader title={`Turma ${item.code}`} description={`${item.course_name} · versão ${item.course_version}`} back={{ to: '/academia/turmas', label: 'Voltar às turmas' }} /><nav className="tabs" aria-label="Áreas da turma">{tabs.map(([slug, label]) => <NavLink key={slug} end={slug === ''} to={slug ? `${base}/${slug}` : base}>{label}</NavLink>)}</nav></>
}

function useClass() {
  const classId = useParams().classId ?? null
  const result = useAcademyItem<ClassDetail>(classId ? ep.classDetail(classId) : null)
  return { classId, result }
}

function ClassFrame({ children }: { children: (classId: string, item: ClassDetail) => React.ReactNode }) {
  const { classId, result } = useClass()
  if (result.loading) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!classId || !result.data) return null
  return <><ClassHeader item={result.data} />{children(classId, result.data)}</>
}

export function ClassOverviewPage() {
  return <ClassFrame>{(_, item) => <section className="card"><dl className="dl"><div className="dl__item"><dt>Estado</dt><dd><StatusBadge value={item.status} /></dd></div><div className="dl__item"><dt>Unidade académica</dt><dd>{item.academic_unit_name}</dd></div><div className="dl__item"><dt>Coorte</dt><dd>{item.cohort_name ?? '—'}</dd></div><div className="dl__item"><dt>Local por defeito</dt><dd>{item.location_name ?? 'Não indicado'}</dd></div><div className="dl__item"><dt>Capacidade</dt><dd>{item.capacity ?? '—'}</dd></div></dl></section>}</ClassFrame>
}

function EnrollDialog({ classId, open, onClose, onDone }: { classId: string; open: boolean; onClose: () => void; onDone: () => void }) {
  const { notify } = useApp()
  const [term, setTerm] = useState('')
  const [selected, setSelected] = useState<PersonSearchResult | null>(null)
  const [results, setResults] = useState<PersonSearchResult[]>([])
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  useEffect(() => {
    if (!open || selected || term.trim().length < MIN_SEARCH_LENGTH) { setResults([]); return }
    const controller = new AbortController()
    const timer = window.setTimeout(() => { setLoading(true); import('../lib/academy/client').then(({ academyGet }) => academyGet<{ data: PersonSearchResult[] }>(ep.classPeopleSearch(classId), { search: term, page: 1, per_page: 50 }, controller.signal)).then((r) => setResults(r.data), (e: unknown) => { if (!(e instanceof DOMException && e.name === 'AbortError')) setError(toUiError(e)) }).finally(() => setLoading(false)) }, 400)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [classId, open, selected, term])
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selected) return
    setBusy(true); setError(null)
    try { await academyPost<EnrollmentCreated>(ep.classEnrollments(classId), { person: selected.public_id }); notify('Matrícula criada.'); onDone(); onClose() } catch (e) { setError(toUiError(e)) } finally { setBusy(false) }
  }
  return <Dialog open={open} title="Matricular pessoa" onClose={onClose}><ActionForm busy={busy} submitLabel="Matricular" onSubmit={submit} error={error}>{selected ? <div className="picker__selected"><span>{selected.display_name}</span><button type="button" className="btn btn--ghost btn--sm" onClick={() => setSelected(null)}>Alterar</button></div> : <div className="picker"><Field label="Pesquisar pessoa" name="person-search" hint="Pesquisa limitada ao âmbito autorizado. Mínimo de 3 caracteres."><input id="person-search" className="input" value={term} onChange={(e) => setTerm(e.target.value)} /></Field>{loading && <span role="status">A pesquisar…</span>}<ul className="picker__results">{results.map((person) => <li className="picker__option" key={person.public_id}><span>{person.display_name}</span><button className="btn btn--secondary btn--sm" type="button" onClick={() => setSelected(person)}>Seleccionar</button></li>)}</ul></div>}</ActionForm></Dialog>
}

export function EnrollmentsPage() {
  const { capabilities } = useApp(); const [open, setOpen] = useState(false)
  return <ClassFrame>{(classId) => <EnrollmentList classId={classId} canEnroll={capabilities.can('canEnroll')} open={open} setOpen={setOpen} />}</ClassFrame>
}
function EnrollmentList({ classId, canEnroll, open, setOpen }: { classId: string; canEnroll: boolean; open: boolean; setOpen: (v: boolean) => void }) {
  const { query, update, searchInput, changeSearch } = useListQuery(true)
  const result = useAcademyPage<RosterRow>(ep.classRoster(classId), query)
  const columns: Column<RosterRow>[] = [{ key: 'name', label: 'Pessoa', render: (r) => r.display_name }, { key: 'enrolled', label: 'Matrícula', render: (r) => formatDateTime(r.enrolled_at) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.enrollment_status} /> }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/turmas/${classId}/matriculas/${r.enrollment_public_id}`}>Abrir</Link> }]
  return <section className="stack"><div className="row"><label className="field" style={{ flex: 1 }}><span className="field__label">Pesquisar no roster</span><input className="input" value={searchInput} onChange={(e) => changeSearch?.(e.target.value)} /></label>{canEnroll && <button className="btn btn--primary" onClick={() => setOpen(true)}>Matricular pessoa</button>}</div>{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data?.data.length === 0 && <EmptyState title="Roster vazio" message="Ainda não existem matrículas visíveis nesta turma." />}{result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={(r) => r.enrollment_public_id} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}<EnrollDialog classId={classId} open={open} onClose={() => setOpen(false)} onDone={result.reload} /></section>
}

export function EnrollmentPage() {
  const { classId, enrollmentId } = useParams(); const result = useAcademyItem<EnrollmentDetail>(classId && enrollmentId ? ep.classEnrollment(classId, enrollmentId) : null)
  if (result.loading) return <LoadingState />; if (result.error) return <ErrorState error={result.error} retry={result.reload} />; if (!result.data) return null
  const item = result.data
  return <><PageHeader title={item.display_name} description="Detalhe da matrícula" back={{ to: `/academia/turmas/${classId}/matriculas`, label: 'Voltar às matrículas' }} actions={<Link className="btn btn--secondary" to={`/academia/matriculas/${item.public_id}/progresso`}>Ver progresso</Link>} /><section className="card"><dl className="dl"><div className="dl__item"><dt>Estado</dt><dd><StatusBadge value={item.status} /></dd></div><div className="dl__item"><dt>Data de matrícula</dt><dd>{formatDateTime(item.enrolled_at)}</dd></div><div className="dl__item"><dt>Versão do registo</dt><dd>{item.lock_version}</dd></div></dl></section><div className="alert alert--warning"><span className="alert__icon">i</span><div className="alert__body"><strong>Alterações de estado indisponíveis</strong><p>{VOCABULARY_PENDING_MESSAGE}</p></div></div></>
}

export function InstructorsPage() {
  return <ClassFrame>{(classId) => <InstructorList classId={classId} />}</ClassFrame>
}
function InstructorList({ classId }: { classId: string }) {
  const result = useAcademyItem<ClassInstructorAssignment[]>(ep.classInstructors(classId))
  const rows = result.data ?? []
  return <section className="stack">{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{!result.loading && !result.error && rows.length === 0 && <EmptyState title="Sem instrutores activos" />}{rows.length > 0 && <div className="card"><DataTable rows={rows} rowKey={(r) => r.assignment_id} columns={[{ key: 'start', label: 'Início', render: (r) => formatDateTime(r.starts_at) }, { key: 'end', label: 'Fim', render: (r) => formatDateTime(r.ends_at) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }]} /></div>}<div className="alert alert--warning"><span className="alert__icon">i</span><div className="alert__body"><strong>Atribuição limitada pelo contrato</strong><p>A resposta de instrutores activos não inclui uma identificação pública do instrutor e a pesquisa de pessoas exige uma permissão diferente. A interface não apresenta identificadores internos.</p></div></div></section>
}

export function SessionsPage() {
  return <ClassFrame>{(classId) => <SessionList classId={classId} />}</ClassFrame>
}
function SessionList({ classId }: { classId: string }) {
  const { query, update } = useListQuery(false); const result = useAcademyPage<ClassSession>(ep.classSessions(classId), query)
  const columns: Column<ClassSession>[] = [{ key: 'date', label: 'Início', render: (r) => formatDateTime(r.starts_at) }, { key: 'lesson', label: 'Aula', render: (r) => r.lesson_name ?? '—' }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/turmas/${classId}/sessoes/${r.id}`}>Abrir</Link> }]
  return <section className="stack">{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data?.data.length === 0 && <EmptyState title="Sem sessões" />}{result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={(r) => r.id} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}</section>
}

export function SessionPage() {
  const { classId, sessionId } = useParams(); const result = useAcademyItem<ClassSession>(classId && sessionId ? ep.classSession(classId, sessionId) : null)
  if (result.loading) return <LoadingState />; if (result.error) return <ErrorState error={result.error} retry={result.reload} />; if (!result.data) return null
  return <><PageHeader title="Sessão" description={formatDateTime(result.data.starts_at)} back={{ to: `/academia/turmas/${classId}/sessoes`, label: 'Voltar às sessões' }} actions={<Link className="btn btn--primary" to={`/academia/sessoes/${sessionId}/presencas`}>Presenças</Link>} /><section className="card"><dl className="dl"><div className="dl__item"><dt>Início</dt><dd>{formatDateTime(result.data.starts_at)}</dd></div><div className="dl__item"><dt>Fim</dt><dd>{formatDateTime(result.data.ends_at)}</dd></div><div className="dl__item"><dt>Aula</dt><dd>{result.data.lesson_name ?? '—'}</dd></div><div className="dl__item"><dt>Estado</dt><dd><StatusBadge value={result.data.status} /></dd></div></dl></section><div className="alert alert--warning"><span className="alert__icon">i</span><div className="alert__body"><strong>Transição indisponível</strong><p>{VOCABULARY_PENDING_MESSAGE}</p></div></div></>
}
