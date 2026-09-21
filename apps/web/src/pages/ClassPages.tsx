import { useEffect, useState, type FormEvent } from 'react'
import { Link, NavLink, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination, StatusBadge, type Column } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useAcademyItem, useAcademyPage, useListQuery } from '../hooks/useAcademy'
import { academyGet, academyPost } from '../lib/academy/client'
import { ep, MIN_SEARCH_LENGTH } from '../lib/academy/endpoints'
import { toUiError, type UiError } from '../lib/academy/errors'
import { targetsFor, VOCABULARY_PENDING_MESSAGE } from '../lib/academy/vocabulary'
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
  const { classId, enrollmentId } = useParams(); const { capabilities, vocabulary, notify } = useApp(); const result = useAcademyItem<EnrollmentDetail>(classId && enrollmentId ? ep.classEnrollment(classId, enrollmentId) : null); const [target, setTarget] = useState(''); const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState<UiError | null>(null)
  if (result.loading) return <LoadingState />; if (result.error) return <ErrorState error={result.error} retry={result.reload} />; if (!result.data) return null
  const item = result.data
  const options = targetsFor(vocabulary, 'enrollment.transitions', item.status)
  async function transition(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!target || !enrollmentId) return; setBusy(true); setError(null); try { await academyPost(ep.enrollmentTransition(enrollmentId), { to_state: target, reason: reason || null, lock_version: item.lock_version }); notify('Estado da matrícula actualizado.'); setTarget(''); result.reload() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  async function complete() { if (!enrollmentId || !window.confirm('Concluir esta matrícula?')) return; setBusy(true); setError(null); try { await academyPost(ep.enrollmentComplete(enrollmentId), { lock_version: item.lock_version }); notify('Matrícula concluída.'); result.reload() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  return <><PageHeader title={item.display_name} description="Detalhe da matrícula" back={{ to: `/academia/turmas/${classId}/matriculas`, label: 'Voltar às matrículas' }} actions={<Link className="btn btn--secondary" to={`/academia/matriculas/${item.public_id}/progresso`}>Ver progresso</Link>} /><section className="card"><dl className="dl"><div className="dl__item"><dt>Estado</dt><dd><StatusBadge value={item.status} /></dd></div><div className="dl__item"><dt>Data de matrícula</dt><dd>{formatDateTime(item.enrolled_at)}</dd></div><div className="dl__item"><dt>Versão do registo</dt><dd>{item.lock_version}</dd></div></dl></section>{error && <ErrorState error={error} />}{capabilities.can('canEnroll') && options && <section className="card"><h2>Alterar estado</h2><ActionForm busy={busy} submitLabel="Guardar estado" onSubmit={transition}><Field label="Novo estado" name="enrollment-state" required><select id="enrollment-state" className="select" value={target} onChange={(e) => setTarget(e.target.value)} required><option value="">Seleccionar</option>{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></Field><Field label="Motivo" name="enrollment-reason"><textarea id="enrollment-reason" className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} /></Field></ActionForm></section>}{capabilities.can('canAssess') && <section className="card"><h2>Conclusão</h2><button className="btn btn--primary" disabled={busy} onClick={complete}>Concluir</button></section>}{!options && <div className="alert alert--warning"><span className="alert__icon">i</span><div className="alert__body"><strong>Alterações de estado indisponíveis</strong><p>{VOCABULARY_PENDING_MESSAGE}</p></div></div>}</>
}

export function InstructorsPage() {
  return <ClassFrame>{(classId) => <InstructorList classId={classId} />}</ClassFrame>
}
function InstructorList({ classId }: { classId: string }) {
  const { capabilities, notify } = useApp(); const result = useAcademyItem<ClassInstructorAssignment[]>(ep.classInstructors(classId)); const [open, setOpen] = useState(false); const [term, setTerm] = useState(''); const [candidates, setCandidates] = useState<PersonSearchResult[]>([]); const [selected, setSelected] = useState<PersonSearchResult | null>(null); const [busy, setBusy] = useState(false); const [error, setError] = useState<UiError | null>(null)
  const rows = result.data ?? []
  useEffect(() => { if (!open || selected || term.trim().length < MIN_SEARCH_LENGTH) { setCandidates([]); return }; const controller = new AbortController(); const timer = window.setTimeout(() => academyGet<{ data: PersonSearchResult[] }>(ep.classInstructorCandidates(classId), { search: term, page: 1, per_page: 50 }, controller.signal).then((value) => setCandidates(value.data), (value: unknown) => setError(toUiError(value))), 400); return () => { window.clearTimeout(timer); controller.abort() } }, [classId, open, selected, term])
  async function assign(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!selected) return; setBusy(true); setError(null); try { await academyPost(ep.classInstructors(classId), { person: selected.public_id }); notify('Instrutor atribuído.'); setOpen(false); setSelected(null); result.reload() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  async function end(row: ClassInstructorAssignment) { if (!window.confirm(`Terminar a atribuição de ${row.display_name}?`)) return; setBusy(true); setError(null); try { await academyPost(ep.instructorEnd(row.assignment_id), { lock_version: row.lock_version, reason: 'Encerramento confirmado na interface' }); notify('Atribuição terminada.'); result.reload() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  return <section className="stack">{capabilities.can('canManage') && <div className="row"><button className="btn btn--primary" onClick={() => setOpen(true)}>Atribuir instrutor</button></div>}{error && <ErrorState error={error} />}{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{!result.loading && !result.error && rows.length === 0 && <EmptyState title="Sem instrutores activos" />}{rows.length > 0 && <div className="card"><DataTable rows={rows} rowKey={(r) => r.assignment_id} columns={[{ key: 'name', label: 'Instrutor', render: (r) => r.display_name }, { key: 'start', label: 'Início', render: (r) => formatDateTime(r.starts_at) }, { key: 'end', label: 'Fim', render: (r) => formatDateTime(r.ends_at) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'action', label: '', render: (r) => capabilities.can('canManage') ? <button className="btn btn--danger btn--sm" disabled={busy} onClick={() => end(r)}>Terminar</button> : null }]} /></div>}<Dialog open={open} title="Atribuir instrutor" onClose={() => setOpen(false)}><ActionForm busy={busy} submitLabel="Atribuir" onSubmit={assign} error={error}>{selected ? <div className="picker__selected"><span>{selected.display_name}</span><button className="btn btn--ghost btn--sm" type="button" onClick={() => setSelected(null)}>Alterar</button></div> : <div className="picker"><Field label="Pesquisar candidato" name="instructor-search" hint="Pesquisa contextual autorizada para gestão da turma."><input id="instructor-search" className="input" value={term} onChange={(e) => setTerm(e.target.value)} /></Field><ul className="picker__results">{candidates.map((candidate) => <li className="picker__option" key={candidate.public_id}><span>{candidate.display_name}</span><button className="btn btn--secondary btn--sm" type="button" onClick={() => setSelected(candidate)}>Seleccionar</button></li>)}</ul></div>}</ActionForm></Dialog></section>
}

export function SessionsPage() {
  return <ClassFrame>{(classId) => <SessionList classId={classId} />}</ClassFrame>
}
function SessionList({ classId }: { classId: string }) {
  const { capabilities, notify } = useApp(); const { query, update } = useListQuery(false); const result = useAcademyPage<ClassSession>(ep.classSessions(classId), query); const [open, setOpen] = useState(false); const [starts, setStarts] = useState(''); const [ends, setEnds] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState<UiError | null>(null)
  const columns: Column<ClassSession>[] = [{ key: 'date', label: 'Início', render: (r) => formatDateTime(r.starts_at) }, { key: 'lesson', label: 'Aula', render: (r) => r.lesson_name ?? '—' }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={r.status} /> }, { key: 'open', label: '', render: (r) => <Link className="btn btn--secondary btn--sm" to={`/academia/turmas/${classId}/sessoes/${r.id}`}>Abrir</Link> }]
  async function schedule(event: FormEvent<HTMLFormElement>) { event.preventDefault(); setBusy(true); setError(null); try { await academyPost(ep.classSessions(classId), { starts_at: new Date(starts).toISOString(), ends_at: new Date(ends).toISOString() }); notify('Sessão agendada.'); setOpen(false); result.reload() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  return <section className="stack">{capabilities.can('canTeach') && <div className="row"><button className="btn btn--primary" onClick={() => setOpen(true)}>Agendar sessão</button></div>}{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data?.data.length === 0 && <EmptyState title="Sem sessões" />}{result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={(r) => r.id} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}<Dialog open={open} title="Agendar sessão" onClose={() => setOpen(false)}><ActionForm busy={busy} submitLabel="Agendar" onSubmit={schedule} error={error}><Field label="Início" name="session-start" required><input id="session-start" className="input" type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} required /></Field><Field label="Fim" name="session-end" required><input id="session-end" className="input" type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} required /></Field></ActionForm></Dialog></section>
}

export function SessionPage() {
  const { classId, sessionId } = useParams(); const { capabilities, vocabulary, notify } = useApp(); const result = useAcademyItem<ClassSession>(classId && sessionId ? ep.classSession(classId, sessionId) : null); const [target, setTarget] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState<UiError | null>(null)
  if (result.loading) return <LoadingState />; if (result.error) return <ErrorState error={result.error} retry={result.reload} />; if (!result.data) return null
  const options = targetsFor(vocabulary, 'session.transitions', result.data.status)
  async function transition(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!sessionId || !target) return; setBusy(true); setError(null); try { await academyPost(ep.sessionTransition(sessionId), { to_state: target, lock_version: result.data?.lock_version }); notify('Estado da sessão actualizado.'); result.reload() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  return <><PageHeader title="Sessão" description={formatDateTime(result.data.starts_at)} back={{ to: `/academia/turmas/${classId}/sessoes`, label: 'Voltar às sessões' }} actions={<Link className="btn btn--primary" to={`/academia/sessoes/${sessionId}/presencas`}>Presenças</Link>} /><section className="card"><dl className="dl"><div className="dl__item"><dt>Início</dt><dd>{formatDateTime(result.data.starts_at)}</dd></div><div className="dl__item"><dt>Fim</dt><dd>{formatDateTime(result.data.ends_at)}</dd></div><div className="dl__item"><dt>Aula</dt><dd>{result.data.lesson_name ?? '—'}</dd></div><div className="dl__item"><dt>Estado</dt><dd><StatusBadge value={result.data.status} /></dd></div></dl></section>{error && <ErrorState error={error} />}{capabilities.can('canTeach') && options ? <section className="card"><ActionForm busy={busy} submitLabel="Guardar estado" onSubmit={transition}><Field label="Novo estado" name="session-state" required><select id="session-state" className="select" value={target} onChange={(e) => setTarget(e.target.value)} required><option value="">Seleccionar</option>{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></Field></ActionForm></section> : <div className="alert alert--warning"><span className="alert__icon">i</span><div className="alert__body"><strong>Transição indisponível</strong><p>{VOCABULARY_PENDING_MESSAGE}</p></div></div>}</>
}
