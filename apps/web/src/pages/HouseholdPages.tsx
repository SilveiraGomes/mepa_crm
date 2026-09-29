import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination, type Column } from '../components/ui'
import { PersonPicker } from '../components/people'
import { ageBandLabel, HOUSEHOLD_STATUS_LABEL } from '../lib/people/format'
import { useApp } from '../context/AppContext'
import { useCatalogs, usePeopleItem, usePeoplePage, usePeopleQuery } from '../hooks/usePeople'
import { peoplePatch, peoplePost } from '../lib/people/client'
import { pe } from '../lib/people/endpoints'
import { toPeopleError } from '../lib/people/errors'
import type { UiError } from '../lib/academy/errors'
import { formatDateTime } from '../lib/format'
import type { Item } from '../types/academy'
import type { HouseholdDetail, HouseholdMember, HouseholdSummary, SelectorPerson } from '../types/people'
import { EndDialog } from './PersonAreaPages'

function HouseholdBadge({ status }: { status: string }) {
  const tone = status === 'ACTIVE' ? 'badge--info' : status === 'ARCHIVED' ? 'badge--danger' : 'badge--warning'
  return <span className={`badge ${tone}`}>{HOUSEHOLD_STATUS_LABEL[status] ?? status}</span>
}

const columns: Column<HouseholdSummary>[] = [
  { key: 'name', label: 'Família', render: (h) => <Link to={`/familias/${h.public_id}`}>{h.name ?? 'Sem nome'}</Link> },
  { key: 'code', label: 'Código', render: (h) => <span className="mono">{h.code}</span> },
  { key: 'status', label: 'Estado', render: (h) => <HouseholdBadge status={h.status} /> },
]

export function HouseholdsPage() {
  const { people } = useApp()
  const { query, update, searchInput, changeSearch } = usePeopleQuery(['search', 'status'])
  const result = usePeoplePage<HouseholdSummary>(pe.households(), query)
  return <><PageHeader title="Famílias" description="Agregados familiares com pelo menos um membro activo no seu âmbito." actions={people.has('HOUSEHOLD_MANAGE') ? <Link className="btn btn--primary" to="/familias/nova">Nova família</Link> : undefined} />
    <div className="card filters"><div className="filters__panel">
      <label className="field"><span className="field__label">Pesquisar família</span><input className="input" type="search" value={searchInput} onChange={(e) => changeSearch(e.target.value)} placeholder="Nome ou código" /></label>
      <label className="field"><span className="field__label">Estado</span><select className="select" value={String(query.status ?? '')} onChange={(e) => update({ status: e.target.value || null })}><option value="">Todos</option><option value="ACTIVE">Activa</option><option value="INACTIVE">Inactiva</option><option value="ARCHIVED">Arquivada</option></select></label>
    </div></div>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data?.data.length === 0 && <EmptyState title="Sem famílias" message="Nenhuma família corresponde aos filtros no seu âmbito." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} columns={columns} rowKey={(h) => h.public_id} /><Pagination meta={result.data.meta} onPage={(page) => update({ page })} /></div>}
  </>
}

export function HouseholdCreatePage() {
  const { notify } = useApp()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [name, setName] = useState('')
  const [reference, setReference] = useState<SelectorPerson | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const preset = params.get('pessoa')
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const person = reference?.public_id ?? preset
    if (!person) { setError({ kind: 'validation', title: 'Dados em falta', message: 'Seleccione a pessoa de referência.', fields: {}, retryable: false }); return }
    setBusy(true); setError(null)
    try {
      const created = await peoplePost<Item<HouseholdDetail>>(pe.households(), { name: name || null, reference_person: person })
      notify('Família criada.')
      navigate(`/familias/${created.data.public_id}`)
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <><PageHeader title="Nova família" description="A família não pertence a uma unidade: é alcançada através dos membros activos." back={{ to: '/familias', label: 'Voltar às famílias' }} />
    <section className="card"><ActionForm submitLabel="Criar família" busy={busy} error={error} onSubmit={submit}>
      <Field label="Nome da família" name="household_name" hint="Opcional."><input id="household_name" className="input" value={name} onChange={(e) => setName(e.target.value)} maxLength={191} /></Field>
      {preset && !reference ? <p className="muted">A pessoa de referência foi seleccionada na ficha da pessoa.</p> : <PersonPicker purpose="household" label="Pessoa de referência" selected={reference} onSelect={setReference} />}
    </ActionForm></section></>
}

function AddMemberDialog({ household, open, onClose, onDone }: { household: HouseholdDetail; open: boolean; onClose: () => void; onDone: () => void }) {
  const { notify } = useApp()
  const catalogs = useCatalogs()
  const [person, setPerson] = useState<SelectorPerson | null>(null)
  const [role, setRole] = useState('MEMBER')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!person) { setError({ kind: 'validation', title: 'Dados em falta', message: 'Seleccione a pessoa a adicionar.', fields: {}, retryable: false }); return }
    setBusy(true); setError(null)
    try { await peoplePost(pe.householdMembers(household.public_id), { person: person.public_id, role }); notify('Membro adicionado.'); onDone() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <Dialog open={open} title="Adicionar membro" onClose={onClose}><ActionForm submitLabel="Adicionar membro" busy={busy} error={error} onSubmit={submit}>
    <PersonPicker purpose="household" label="Pesquisar pessoa" selected={person} onSelect={setPerson} exclude={household.members.map((m) => m.person.public_id)} />
    <Field label="Papel no agregado" name="member_role" required><select id="member_role" className="select" value={role} onChange={(e) => setRole(e.target.value)} disabled={!catalogs.data}>{!catalogs.data && <option value="">A carregar…</option>}{(catalogs.data?.household_roles ?? []).map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}</select></Field>
  </ActionForm></Dialog>
}

function HouseholdLifecycle({ household, onDone }: { household: HouseholdDetail; onDone: () => void }) {
  const { notify } = useApp()
  const [action, setAction] = useState<'archive' | 'restore' | 'rename' | null>(null)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function run(kind: 'inactivate' | 'reactivate' | 'archive' | 'restore', reason?: string) {
    setBusy(true); setError(null)
    try { await peoplePost(pe.householdAction(household.public_id, kind), { lock_version: household.lock_version, ...(reason ? { reason } : {}) }); notify('Estado da família actualizado.'); setAction(null); onDone() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  async function rename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null)
    try { await peoplePatch(pe.household(household.public_id), { name: text || null, lock_version: household.lock_version }); notify('Família actualizada.'); setAction(null); onDone() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <>{error && action === null && <ErrorState error={error} />}<div className="row wrap">
    <button className="btn btn--secondary" type="button" onClick={() => { setText(household.name ?? ''); setError(null); setAction('rename') }}>Alterar nome</button>
    {household.status === 'ACTIVE' && <button className="btn btn--secondary" type="button" disabled={busy} onClick={() => run('inactivate')}>Inactivar</button>}
    {household.status === 'INACTIVE' && <button className="btn btn--secondary" type="button" disabled={busy} onClick={() => run('reactivate')}>Reactivar</button>}
    {household.status !== 'ARCHIVED' && <button className="btn btn--danger" type="button" onClick={() => { setText(''); setError(null); setAction('archive') }}>Arquivar</button>}
    {household.status === 'ARCHIVED' && <button className="btn btn--secondary" type="button" onClick={() => { setText(''); setError(null); setAction('restore') }}>Restaurar</button>}
  </div>
    <Dialog open={action === 'rename'} title="Alterar nome" onClose={() => setAction(null)}><ActionForm submitLabel="Guardar" busy={busy} error={error} onSubmit={rename}><Field label="Nome da família" name="rename_household"><input id="rename_household" className="input" value={text} onChange={(e) => setText(e.target.value)} maxLength={191} /></Field></ActionForm></Dialog>
    <Dialog open={action === 'archive' || action === 'restore'} title={action === 'archive' ? 'Arquivar família' : 'Restaurar família'} onClose={() => setAction(null)}><ActionForm submitLabel="Confirmar" busy={busy} error={error} onSubmit={(e) => { e.preventDefault(); if (action === 'archive' || action === 'restore') run(action, text) }}><p>{action === 'archive' ? 'A família sai das operações comuns. Nada é apagado.' : 'A família volta a estar activa.'}</p><Field label="Motivo" name="household_reason" required error={error?.fields.reason}><textarea id="household_reason" className="textarea" value={text} onChange={(e) => setText(e.target.value)} required minLength={3} /></Field></ActionForm></Dialog>
  </>
}

export function HouseholdDetailPage() {
  const { notify } = useApp()
  const householdId = useParams().householdId ?? null
  const result = usePeopleItem<HouseholdDetail>(householdId ? pe.household(householdId) : null)
  const [adding, setAdding] = useState(false)
  const [ending, setEnding] = useState<HouseholdMember | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const navigate = useNavigate()
  if (result.loading && !result.data) return <LoadingState />
  if (result.error) return <><PageHeader title="Família" back={{ to: '/familias', label: 'Voltar às famílias' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!result.data) return null
  const household = result.data
  const manage = household.capabilities.can_manage
  async function end(reason: string) {
    if (!ending) return
    setBusy(true); setError(null)
    try {
      await peoplePost(pe.householdMemberEnd(household.public_id, ending.ref), reason ? { reason } : {})
      notify('Membro removido do agregado.')
      const last = household.members.length === 1
      setEnding(null)
      if (last) navigate('/familias'); else result.reload()
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <><PageHeader title={household.name ?? 'Família sem nome'} description={`Código ${household.code}`} back={{ to: '/familias', label: 'Voltar às famílias' }} actions={<HouseholdBadge status={household.status} />} />
    {manage && <section className="card stack"><h2>Gestão</h2><HouseholdLifecycle household={household} onDone={result.reload} /></section>}
    <section className="card stack"><div className="section-head"><h2>Membros</h2>{manage && household.status === 'ACTIVE' && <button className="btn btn--primary" type="button" onClick={() => setAdding(true)}>Adicionar membro</button>}</div>
      <p className="muted">Só são apresentados os membros que pode consultar no seu âmbito.</p>
      {household.members.length === 0 ? <EmptyState title="Sem membros visíveis" message="Não existem membros activos visíveis." /> : <ul className="person-list">{household.members.map((m) => <li key={m.ref} className="person-list__item"><div className="person-list__main"><Link to={`/pessoas/${m.person.public_id}`}><strong>{m.person.display_name}</strong></Link><span className="muted">{m.role_name} · {ageBandLabel(m.person.age_band)} · desde {formatDateTime(m.starts_at)}</span></div>{manage && household.status === 'ACTIVE' && <div className="person-list__actions"><button className="btn btn--ghost btn--sm" type="button" onClick={() => { setError(null); setEnding(m) }}>Remover</button></div>}</li>)}</ul>}
    </section>
    {adding && <AddMemberDialog household={household} open onClose={() => setAdding(false)} onDone={() => { setAdding(false); result.reload() }} />}
    <EndDialog open={ending !== null} title="Remover membro" message="A pessoa deixa de ser membro activo. O histórico é preservado." onClose={() => setEnding(null)} onConfirm={end} busy={busy} error={error} />
  </>
}
