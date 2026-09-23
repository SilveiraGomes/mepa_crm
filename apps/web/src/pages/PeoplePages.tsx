import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination, type Column } from '../components/ui'
import { BirthFields, MinorNotice, PersonStatusBadge, PersonTabs, RestrictedNotice } from '../components/people'
import { ageBandLabel, birthFromDetail, birthPayload, EMPTY_BIRTH, formatBirth, PRECISION_LABEL, type BirthValue } from '../lib/people/format'
import { useApp } from '../context/AppContext'
import { usePeoplePage, usePeopleQuery, usePerson } from '../hooks/usePeople'
import { peoplePatch, peoplePost } from '../lib/people/client'
import { pe } from '../lib/people/endpoints'
import { toPeopleError } from '../lib/people/errors'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { ExportResult, PersonDetail, PersonSummary } from '../types/people'

const columns: Column<PersonSummary>[] = [
  { key: 'name', label: 'Nome', render: (r) => <Link to={`/pessoas/${r.public_id}`}>{r.display_name}</Link> },
  { key: 'status', label: 'Estado', render: (r) => <PersonStatusBadge status={r.status} /> },
  { key: 'birth', label: 'Nascimento', render: (r) => PRECISION_LABEL[r.birth_precision] },
  { key: 'age', label: 'Faixa etária', render: (r) => ageBandLabel(r.age_band) },
]

export function PeopleListPage() {
  const { people } = useApp()
  const { query, update, searchInput, changeSearch } = usePeopleQuery(['search', 'status'])
  const result = usePeoplePage<PersonSummary>(pe.people(), query)
  const actions = <>{people.has('PEOPLE_CREATE') && <Link className="btn btn--primary" to="/pessoas/nova">Nova Pessoa</Link>}{people.has('PEOPLE_EXPORT') && <Link className="btn btn--secondary" to="/pessoas/exportar">Exportar</Link>}</>
  return <><PageHeader title="Pessoas" description="Pessoas com contexto institucional no seu âmbito." actions={actions} />
    <div className="card filters"><div className="filters__panel">
      <label className="field"><span className="field__label">Pesquisar pessoa</span><input className="input" type="search" value={searchInput} onChange={(e) => changeSearch(e.target.value)} placeholder="Nome (mínimo 2 letras)" /></label>
      <label className="field"><span className="field__label">Estado</span><select className="select" value={String(query.status ?? '')} onChange={(e) => update({ status: e.target.value || null })}><option value="">Todos</option><option value="ACTIVE">Activa</option><option value="INACTIVE">Inactiva</option><option value="DECEASED">Falecida</option></select></label>
      <button className="btn btn--ghost" type="button" onClick={() => update({ search: null, status: null })}>Limpar filtros</button>
    </div></div>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data?.data.length === 0 && <EmptyState title="Sem pessoas" message="Nenhuma pessoa corresponde aos filtros no seu âmbito." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={(r) => r.public_id} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}
  </>
}

function PersonForm({ initialName, initialBirth, submitLabel, busy, error, onSubmit, extra, showBirth = true }: { initialName: string; initialBirth: BirthValue; submitLabel: string; busy: boolean; error: UiError | null; onSubmit: (name: string, birth: BirthValue) => void; extra?: ReactNode; showBirth?: boolean }) {
  const [name, setName] = useState(initialName)
  const [birth, setBirth] = useState<BirthValue>(initialBirth)
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); onSubmit(name, birth) }
  return <ActionForm submitLabel={submitLabel} busy={busy} error={error} onSubmit={submit}>
    <Field label="Nome completo" name="full_name" required error={error?.fields.full_name}><input id="full_name" className="input" value={name} onChange={(e) => setName(e.target.value)} required minLength={2} maxLength={191} autoComplete="off" /></Field>
    {showBirth && <BirthFields value={birth} onChange={setBirth} errors={error?.fields ?? {}} />}
    {extra}
  </ActionForm>
}

export function PersonCreatePage() {
  const { people, notify } = useApp()
  const navigate = useNavigate()
  const [unit, setUnit] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const units = people.workingUnits
  async function save(name: string, birth: BirthValue) {
    setBusy(true); setError(null)
    try {
      const created = await peoplePost<Item<PersonDetail>>(pe.people(), { full_name: name, ...birthPayload(birth), ...(unit ? { unit } : {}) })
      notify('Pessoa criada.')
      navigate(`/pessoas/${created.data.public_id}`)
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  const unitField = units.length > 1 ? <Field label="Unidade de registo" name="unit" required hint="O contexto institucional é confirmado pelo servidor." error={error?.fields.unit}><select id="unit" className="select" value={unit} onChange={(e) => setUnit(e.target.value)} required><option value="">Seleccione</option>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field> : null
  return <><PageHeader title="Nova Pessoa" description="Registe uma pessoa. Não é criada qualquer filiação nem número de membro." back={{ to: '/pessoas', label: 'Voltar às pessoas' }} />
    <section className="card"><PersonForm initialName="" initialBirth={EMPTY_BIRTH} submitLabel="Criar pessoa" busy={busy} error={error} onSubmit={save} extra={unitField} /></section></>
}

export function PersonFrame({ children }: { children: (person: PersonDetail, reload: () => void) => ReactNode }) {
  const { result } = usePerson()
  if (result.loading && !result.data) return <LoadingState />
  if (result.error) return <><PageHeader title="Pessoa" back={{ to: '/pessoas', label: 'Voltar às pessoas' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!result.data) return null
  const person = result.data
  return <><PageHeader title={person.display_name} description="Ficha da pessoa" back={{ to: '/pessoas', label: 'Voltar às pessoas' }} actions={<PersonStatusBadge status={person.status} />} /><PersonTabs person={person} />{person.projection === 'MINIMAL' && <MinorNotice />}{children(person, result.reload)}</>
}

function LifecycleActions({ person, onDone }: { person: PersonDetail; onDone: () => void }) {
  const { notify } = useApp()
  const [deceased, setDeceased] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function run(action: 'inactivate' | 'reactivate' | 'mark-deceased', body: Record<string, unknown> = {}) {
    setBusy(true); setError(null)
    try {
      await peoplePost(pe.personAction(person.public_id, action), { lock_version: person.lock_version, ...body })
      notify(action === 'mark-deceased' ? 'Falecimento registado.' : action === 'inactivate' ? 'Pessoa inactivada.' : 'Pessoa reactivada.')
      setDeceased(false); onDone()
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  if (!person.capabilities.can_edit) return null
  return <div className="stack">{error && !deceased && <ErrorState error={error} />}<div className="row wrap">
    <Link className="btn btn--primary" to={`/pessoas/${person.public_id}/editar`}>Editar</Link>
    {person.status === 'ACTIVE' && <button className="btn btn--secondary" type="button" disabled={busy} onClick={() => run('inactivate')}>Inactivar</button>}
    {person.status === 'INACTIVE' && <button className="btn btn--secondary" type="button" disabled={busy} onClick={() => run('reactivate')}>Reactivar</button>}
    <button className="btn btn--danger" type="button" disabled={busy} onClick={() => { setError(null); setDeceased(true) }}>Registar falecimento</button>
  </div>
    <Dialog open={deceased} title="Registar falecimento" onClose={() => setDeceased(false)}><ActionForm submitLabel="Confirmar falecimento" busy={busy} error={error} onSubmit={(e) => { e.preventDefault(); run('mark-deceased', { reason }) }}><p>A identidade e o histórico são preservados. Esta alteração não pode ser desfeita nesta área.</p><Field label="Motivo" name="reason" required error={error?.fields.reason}><textarea id="reason" className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} required minLength={3} /></Field></ActionForm></Dialog>
  </div>
}

export function PersonDetailPage() {
  return <PersonFrame>{(person, reload) => <section className="card stack">
    <dl className="dl">
      <div className="dl__item"><dt>Nome</dt><dd>{person.display_name}</dd></div>
      <div className="dl__item"><dt>Estado</dt><dd><PersonStatusBadge status={person.status} /></dd></div>
      <div className="dl__item"><dt>Precisão do nascimento</dt><dd>{PRECISION_LABEL[person.birth_precision]}</dd></div>
      <div className="dl__item"><dt>Nascimento</dt><dd>{formatBirth(person.birth)}</dd></div>
      <div className="dl__item"><dt>Faixa etária</dt><dd>{ageBandLabel(person.age_band)}</dd></div>
      <div className="dl__item"><dt>Identificador</dt><dd className="mono">{person.public_id}</dd></div>
    </dl>
    <RestrictedNotice areas={person.restricted} />
    {person.status !== 'DECEASED' && <LifecycleActions person={person} onDone={reload} />}
  </section>}</PersonFrame>
}

export function PersonEditPage() {
  const navigate = useNavigate()
  const { notify } = useApp()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  return <PersonFrame>{(person) => {
    if (!person.capabilities.can_edit) return <ErrorState error={{ kind: 'forbidden', title: 'Sem autorização', message: 'Não tem autorização para editar esta pessoa.', fields: {}, retryable: false }} />
    async function save(name: string, birth: BirthValue) {
      setBusy(true); setError(null)
      try {
        const sendBirth = person.birth !== undefined
        await peoplePatch(pe.person(person.public_id), { full_name: name, lock_version: person.lock_version, ...(sendBirth ? birthPayload(birth) : {}) })
        notify('Dados guardados.')
        navigate(`/pessoas/${person.public_id}`)
      } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
    }
    return <section className="card stack"><h2>Editar dados</h2>{person.birth === undefined && <p className="muted">O nascimento está oculto no seu perfil de acesso e não será alterado.</p>}
      <PersonForm initialName={person.display_name} initialBirth={birthFromDetail(person.birth, person.birth_precision)} submitLabel="Guardar alterações" busy={busy} error={error} onSubmit={save} showBirth={person.birth !== undefined} /></section>
  }}</PersonFrame>
}

export function PeopleExportPage() {
  const { people, notify } = useApp()
  const [kind, setKind] = useState('STANDARD')
  const [reason, setReason] = useState('')
  const [search, setSearch] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const [last, setLast] = useState<ExportResult | null>(null)
  if (people.known && !people.has('PEOPLE_EXPORT')) return <><PageHeader title="Exportar pessoas" back={{ to: '/pessoas', label: 'Voltar às pessoas' }} /><ErrorState error={{ kind: 'forbidden', title: 'Sem autorização', message: 'Não tem autorização para exportar pessoas.', fields: {}, retryable: false }} /></>
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null)
    try {
      const result = await peoplePost<Item<ExportResult>>(pe.exports(), { class: kind, ...(reason ? { reason } : {}), ...(search.trim().length >= 2 ? { search: search.trim() } : {}) })
      setLast(result.data)
      const url = URL.createObjectURL(new Blob([result.data.csv], { type: result.data.content_type }))
      const link = document.createElement('a')
      link.href = url; link.download = result.data.filename; link.click()
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
      notify(`Exportação concluída: ${result.data.row_count} registos.`)
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <><PageHeader title="Exportar pessoas" description="A exportação inclui apenas pessoas do seu âmbito e fica registada na auditoria." back={{ to: '/pessoas', label: 'Voltar às pessoas' }} />
    <section className="card"><ActionForm submitLabel="Exportar CSV" busy={busy} error={error} onSubmit={submit}>
      <Field label="Classe de dados" name="export_class" required><select id="export_class" className="select" value={kind} onChange={(e) => setKind(e.target.value)}>
        <option value="STANDARD">Operacional (identificador, nome, estado)</option>
        {people.has('PEOPLE_SENSITIVE_VIEW') && <option value="SENSITIVE">Classe B (nascimento e contactos principais)</option>}
        {people.has('PEOPLE_EXPORT_CLASS_C') && <option value="CLASS_C">Classe C (documentos de identidade)</option>}
      </select></Field>
      <Field label="Filtrar por nome" name="export_search" hint="Opcional, mínimo 2 letras."><input id="export_search" className="input" value={search} onChange={(e) => setSearch(e.target.value)} /></Field>
      <Field label={kind === 'CLASS_C' ? 'Motivo' : 'Motivo (opcional)'} name="export_reason" required={kind === 'CLASS_C'} error={error?.fields.reason}><textarea id="export_reason" className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} required={kind === 'CLASS_C'} minLength={kind === 'CLASS_C' ? 5 : undefined} maxLength={500} /></Field>
      <p className="muted">Menores não recebem colunas de Classe B ou C.</p>
    </ActionForm>{last && <p className="muted" role="status">Último ficheiro: {last.filename} ({last.row_count} registos).</p>}</section></>
}
