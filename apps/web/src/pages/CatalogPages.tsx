import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination, StatusBadge, type Column } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useAcademyItem, useAcademyPage, useListQuery } from '../hooks/useAcademy'
import { academyPost } from '../lib/academy/client'
import { ep } from '../lib/academy/endpoints'
import { toUiError, type UiError } from '../lib/academy/errors'
import { formatDate, readableCode } from '../lib/format'

type Row = Record<string, unknown>
type Resource = 'units' | 'programs' | 'curricula' | 'courses' | 'cohorts'

const configs: Record<Resource, {
  title: string; description: string; path: () => string; detailPath: (row: Row) => string; key: (row: Row) => string | number
  columns: Column<Row>[]; search?: boolean; sorts: { value: string; label: string }[]
}> = {
  units: { title: 'Unidades académicas', description: 'Unidades disponíveis no âmbito da sua sessão.', path: ep.academicUnits, detailPath: (r) => `/academia/unidades/${r.id}`, key: (r) => Number(r.id), search: true, sorts: [{ value: 'code', label: 'Código' }, { value: 'name', label: 'Nome' }, { value: 'status', label: 'Estado' }], columns: [
    { key: 'code', label: 'Código', render: (r) => String(r.code) }, { key: 'name', label: 'Nome', render: (r) => String(r.name) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={String(r.status)} /> },
  ] },
  programs: { title: 'Programas', description: 'Programas académicos disponíveis por unidade.', path: ep.programs, detailPath: (r) => `/academia/programas/${r.public_id}`, key: (r) => String(r.public_id), search: true, sorts: [{ value: 'code', label: 'Código' }, { value: 'name', label: 'Nome' }, { value: 'status', label: 'Estado' }], columns: [
    { key: 'code', label: 'Código', render: (r) => String(r.code) }, { key: 'name', label: 'Nome', render: (r) => String(r.name) }, { key: 'unit', label: 'Unidade', render: (r) => String(r.academic_unit_code) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={String(r.status)} /> },
  ] },
  curricula: { title: 'Currículos', description: 'Versões curriculares disponíveis.', path: ep.curricula, detailPath: (r) => `/academia/curriculos/${r.id}`, key: (r) => Number(r.id), sorts: [{ value: 'version', label: 'Versão' }, { value: 'status', label: 'Estado' }], columns: [
    { key: 'program', label: 'Programa', render: (r) => String(r.program_code) }, { key: 'version', label: 'Versão', render: (r) => String(r.version) }, { key: 'published', label: 'Publicação', render: (r) => formatDate(r.published_at as string) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={String(r.status)} /> },
  ] },
  courses: { title: 'Cursos', description: 'Catálogo de cursos acessível à sua sessão.', path: ep.courses, detailPath: (r) => `/academia/cursos/${r.public_id}`, key: (r) => String(r.public_id), search: true, sorts: [{ value: 'code', label: 'Código' }, { value: 'name', label: 'Nome' }, { value: 'status', label: 'Estado' }], columns: [
    { key: 'code', label: 'Código', render: (r) => String(r.code) }, { key: 'name', label: 'Nome', render: (r) => String(r.name) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={String(r.status)} /> },
  ] },
  cohorts: { title: 'Coortes', description: 'Grupos de formação disponíveis.', path: ep.cohorts, detailPath: (r) => `/academia/coortes/${r.public_id}`, key: (r) => String(r.public_id), search: true, sorts: [{ value: 'code', label: 'Código' }, { value: 'name', label: 'Nome' }, { value: 'starts_at', label: 'Início' }, { value: 'status', label: 'Estado' }], columns: [
    { key: 'code', label: 'Código', render: (r) => String(r.code) }, { key: 'name', label: 'Nome', render: (r) => String(r.name) }, { key: 'starts', label: 'Início', render: (r) => formatDate(r.starts_at as string) }, { key: 'status', label: 'Estado', render: (r) => <StatusBadge value={String(r.status)} /> },
  ] },
}

export function CatalogListPage({ resource }: { resource: Resource }) {
  const config = configs[resource]
  const { query, update, searchInput, changeSearch } = useListQuery(config.search)
  const result = useAcademyPage<Row>(config.path(), query)
  const columns: Column<Row>[] = [...config.columns, { key: 'actions', label: '', render: (row) => <Link className="btn btn--secondary btn--sm" to={config.detailPath(row)}>Abrir</Link> }]
  return <><PageHeader title={config.title} description={config.description} /><div className="card filters"><div className="filters__panel"><>{config.search && <label className="field"><span className="field__label">Pesquisar</span><input className="input" value={searchInput} onChange={(e) => changeSearch?.(e.target.value)} placeholder="Escreva pelo menos 3 caracteres" /></label>}</><label className="field"><span className="field__label">Estado</span><input className="input" value={String(query.status ?? '')} onChange={(e) => update({ status: e.target.value || null })} /></label><label className="field"><span className="field__label">Ordenar por</span><select className="select" value={String(query.sort ?? '')} onChange={(e) => update({ sort: e.target.value || null })}><option value="">Ordem padrão</option>{config.sorts.map((sort) => <option key={sort.value} value={sort.value}>{sort.label}</option>)}</select></label><button className="btn btn--ghost" onClick={() => update({ search: null, status: null, sort: null, direction: null })}>Limpar filtros</button></div></div>{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data && result.data.data.length === 0 && <EmptyState />}{result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={config.key} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}</>
}

const details: Record<Resource, { title: (r: Row) => string; path: (id: string) => string; back: string; fields: [string, string, (v: unknown) => string][] }> = {
  units: { title: (r) => String(r.name), path: (id) => ep.academicUnit(id), back: '/academia/unidades', fields: [['Código', 'code', String], ['Nome', 'name', String], ['Estado', 'status', (v) => readableCode(String(v))], ['Unidade organizacional', 'organizational_unit_public_id', String]] },
  programs: { title: (r) => String(r.name), path: ep.program, back: '/academia/programas', fields: [['Código', 'code', String], ['Nome', 'name', String], ['Unidade', 'academic_unit_name', String], ['Estado', 'status', (v) => readableCode(String(v))]] },
  curricula: { title: (r) => `Currículo ${String(r.program_code)} · versão ${String(r.version)}`, path: ep.curriculum, back: '/academia/curriculos', fields: [['Programa', 'program_name', String], ['Versão', 'version', String], ['Estado', 'status', (v) => readableCode(String(v))], ['Publicado em', 'published_at', (v) => formatDate(v as string)], ['Cursos', 'course_count', String]] },
  courses: { title: (r) => String(r.name), path: ep.course, back: '/academia/cursos', fields: [['Código', 'code', String], ['Nome', 'name', String], ['Estado', 'status', (v) => readableCode(String(v))]] },
  cohorts: { title: (r) => String(r.name), path: ep.cohort, back: '/academia/coortes', fields: [['Código', 'code', String], ['Nome', 'name', String], ['Unidade', 'academic_unit_code', String], ['Início', 'starts_at', (v) => formatDate(v as string)], ['Fim', 'ends_at', (v) => formatDate(v as string)], ['Estado', 'status', (v) => readableCode(String(v))]] },
}

export function CatalogDetailPage({ resource }: { resource: Resource }) {
  const id = useParams().id ?? null
  const config = details[resource]
  const result = useAcademyItem<Row>(id ? config.path(id) : null)
  if (result.loading) return <LoadingState />
  if (result.error) return <><PageHeader title="Recurso indisponível" back={{ to: config.back, label: 'Voltar à lista' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!result.data) return null
  const row = result.data
  const extra = resource === 'curricula' ? <Link className="btn btn--secondary" to={`/academia/curriculos/${id}/cursos`}>Ver cursos</Link> : resource === 'courses' ? <Link className="btn btn--primary" to={`/academia/cursos/${id}/versoes`}>Ver versões</Link> : undefined
  return <><PageHeader title={config.title(row)} back={{ to: config.back, label: 'Voltar à lista' }} actions={extra} /><section className="card"><dl className="dl">{config.fields.map(([label, key, format]) => <div className="dl__item" key={key}><dt>{label}</dt><dd>{row[key] === null || row[key] === undefined ? '—' : format(row[key])}</dd></div>)}</dl></section>{resource === 'programs' && id && <ProgramMutation programId={id} onDone={result.reload} />}{resource === 'curricula' && id && <CurriculumMutations curriculumId={id} lockVersion={Number(row.lock_version)} onDone={result.reload} />}</>
}

function ProgramMutation({ programId, onDone }: { programId: string; onDone: () => void }) {
  const { capabilities, notify } = useApp(); const [busy, setBusy] = useState(false); const [error, setError] = useState<UiError | null>(null)
  if (!capabilities.can('canManage')) return null
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); setBusy(true); setError(null); try { await academyPost(ep.programCurriculaCreate(programId)); notify('Nova versão curricular criada.'); onDone() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  return <section className="card"><h2>Nova versão curricular</h2><p className="muted">A versão é atribuída pelo servidor.</p><ActionForm busy={busy} submitLabel="Criar versão" onSubmit={submit} error={error}><span /></ActionForm></section>
}

function CurriculumMutations({ curriculumId, lockVersion, onDone }: { curriculumId: string; lockVersion: number; onDone: () => void }) {
  const { capabilities, notify } = useApp(); const [courseOpen, setCourseOpen] = useState(false); const [course, setCourse] = useState(''); const [sequence, setSequence] = useState('1'); const [required, setRequired] = useState(true); const [busy, setBusy] = useState(false); const [error, setError] = useState<UiError | null>(null)
  if (!capabilities.can('canManage')) return null
  async function publish() { if (!window.confirm('Publicar esta versão curricular?')) return; setBusy(true); setError(null); try { await academyPost(ep.curriculumPublish(curriculumId), { lock_version: lockVersion }); notify('Currículo publicado.'); onDone() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  async function add(event: FormEvent<HTMLFormElement>) { event.preventDefault(); setBusy(true); setError(null); try { await academyPost(ep.curriculumCourses(curriculumId), { course, sequence: Number(sequence), required }); notify('Curso associado ao currículo.'); setCourseOpen(false); onDone() } catch (value) { setError(toUiError(value)) } finally { setBusy(false) } }
  return <section className="card stack"><h2>Gestão do currículo</h2>{error && <ErrorState error={error} />}<div className="row"><button className="btn btn--primary" disabled={busy} onClick={publish}>Publicar</button><button className="btn btn--secondary" disabled={busy} onClick={() => setCourseOpen(true)}>Associar curso</button></div><Dialog open={courseOpen} title="Associar curso" onClose={() => setCourseOpen(false)}><ActionForm busy={busy} submitLabel="Associar" onSubmit={add} error={error}><Field label="Identificador público do curso" name="course" required><input id="course" className="input" minLength={26} maxLength={26} value={course} onChange={(e) => setCourse(e.target.value.toUpperCase())} required /></Field><Field label="Ordem" name="sequence" required><input id="sequence" className="input" type="number" min="1" value={sequence} onChange={(e) => setSequence(e.target.value)} required /></Field><label className="row"><input type="checkbox" checked={required} onChange={(e) => setRequired(e.target.checked)} /> Curso obrigatório</label></ActionForm></Dialog></section>
}
