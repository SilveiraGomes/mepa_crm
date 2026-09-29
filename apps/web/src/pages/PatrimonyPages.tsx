import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ActionForm, DataTable, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { usePhysicalItem, usePhysicalPage } from '../hooks/usePhysical'
import { physicalGet, physicalPatch, physicalPost } from '../lib/physical/client'
import { ph } from '../lib/physical/endpoints'
import { toPhysicalError } from '../lib/physical/errors'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { PhysicalLocation, PhysicalProperty, Temple } from '../types/physical'
import { PhysicalBadge, ReasonDialog } from './PhysicalPages'

/** Locations the actor can see (first page, bounded to 100 by the API) for a create form. */
function LocationSelect({ defaultValue }: { defaultValue: string }) {
  const locations = usePhysicalPage<PhysicalLocation>(ph.locations(), { per_page: 100 })
  return <Field label="Local" name="location_public_id" required hint={locations.error ? locations.error.message : undefined}>
    <select className="select" id="location_public_id" name="location_public_id" required defaultValue={defaultValue} key={locations.data ? 'loaded' : 'loading'} disabled={!locations.data}>
      <option value="">{locations.data ? 'Seleccione' : 'A carregar…'}</option>
      {(locations.data?.data ?? []).filter((location) => location.status !== 'CLOSED').map((location) => <option key={location.public_id} value={location.public_id}>{location.name}</option>)}
    </select>
  </Field>
}

function StatusFilter({ id, value, onChange }: { id: string; value: string; onChange: (value: string) => void }) {
  return <Field label="Estado" name={id}><select className="select" id={id} value={value} onChange={(event) => onChange(event.target.value)}><option value="">Todos</option><option value="DRAFT">Rascunho</option><option value="ACTIVE">Activo</option><option value="CLOSED">Encerrado</option></select></Field>
}

// ---- Imóveis -------------------------------------------------------------------------------------------------------

export function PropertiesPage() {
  const { physical } = useApp()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const result = usePhysicalPage<PhysicalProperty>(ph.properties(), { page, per_page: 50, ...(search ? { search } : {}), ...(status ? { status } : {}) })
  return <>
    <PageHeader title="Imóveis" description="Imóveis sobre locais com vínculo activo no seu escopo. A situação documental não concede autoridade." actions={physical.has('PROPERTY_MANAGE') ? <Link className="btn btn--primary" to="/imoveis/novo">Novo imóvel</Link> : undefined} />
    <form className="card physical-filters" role="search" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(draft.trim()) }}>
      <Field label="Pesquisar por código" name="property-search"><input className="input" id="property-search" value={draft} onChange={(event) => setDraft(event.target.value)} maxLength={100} /></Field>
      <StatusFilter id="property-status" value={status} onChange={(value) => { setPage(1); setStatus(value) }} />
      <button className="btn btn--secondary" type="submit">Pesquisar</button>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem imóveis" message="Não existem imóveis visíveis para estes filtros." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'code', label: 'Imóvel', render: (row) => <Link to={`/imoveis/${row.public_id}`}>{row.code}</Link> },
      { key: 'location', label: 'Local', render: (row) => <Link to={`/locais/${row.location.public_id}`}>{row.location.name}</Link> },
      { key: 'ownership', label: 'Situação documental', render: (row) => row.ownership_status_label },
      { key: 'status', label: 'Estado', render: (row) => <PhysicalBadge value={row.status} label={row.status_label} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

function OwnerFields({ property }: { property?: PhysicalProperty }) {
  const initial = property?.owner.kind ?? 'NONE'
  const [kind, setKind] = useState<string>(initial)
  return <fieldset className="fieldset"><legend>Proprietário</legend>
    <Field label="Tipo de proprietário" name="owner_kind"><select className="select" id="owner_kind" name="owner_kind" value={kind} onChange={(event) => setKind(event.target.value)}><option value="NONE">Não registado</option><option value="PERSON">Pessoa registada no sistema</option><option value="EXTERNAL">Proprietário externo</option></select></Field>
    {kind === 'PERSON' && <Field label="Identificador público da Pessoa" name="owner_person" required={initial !== 'PERSON'} hint={initial === 'PERSON' ? 'Deixe em branco para manter o proprietário registado.' : 'Exige autoridade própria no módulo Pessoas sobre essa Pessoa.'}><input className="input mono" id="owner_person" name="owner_person" required={initial !== 'PERSON'} minLength={26} maxLength={26} defaultValue={property?.owner.person?.public_id ?? ''} autoComplete="off" /></Field>}
    {kind === 'EXTERNAL' && <Field label="Nome do proprietário externo" name="owner_external" required={initial !== 'EXTERNAL'} hint={initial === 'EXTERNAL' ? 'Deixe em branco para manter o nome registado.' : 'Dado restrito: nunca aparece em listas; a consulta é registada.'}><input className="input" id="owner_external" name="owner_external" required={initial !== 'EXTERNAL'} maxLength={191} autoComplete="off" /></Field>}
  </fieldset>
}

function ownerPayload(form: FormData, editing: PhysicalProperty | null): Record<string, string | null> {
  const kind = String(form.get('owner_kind') ?? 'NONE')
  const person = String(form.get('owner_person') ?? '').trim()
  const external = String(form.get('owner_external') ?? '').trim()
  // An owner the actor cannot see (People authority / restricted name) is kept when the field is left blank.
  if (editing && kind === editing.owner.kind && kind !== 'NONE' && (kind === 'PERSON' ? person : external) === '') return {}
  if (kind === 'PERSON') return { owner_person_public_id: person, owner_name_external: null }
  if (kind === 'EXTERNAL') return { owner_name_external: external, owner_person_public_id: null }
  return { owner_person_public_id: null, owner_name_external: null }
}

export function PropertyFormPage() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const { physical, notify } = useApp()
  const navigate = useNavigate()
  const existing = usePhysicalItem<PhysicalProperty>(id ? ph.property(id) : null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  if (id && existing.loading) return <LoadingState />
  if (existing.error) return <ErrorState error={existing.error} retry={existing.reload} />
  const property = existing.data
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true); setError(null)
    try {
      const reason = String(form.get('reason') ?? '').trim() || null
      const saved = property
        ? await physicalPatch<Item<PhysicalProperty>>(ph.property(property.public_id), { code: form.get('code'), ...ownerPayload(form, property), reason, lock_version: property.lock_version })
        : await physicalPost<Item<PhysicalProperty>>(ph.properties(), { location_public_id: form.get('location_public_id'), code: form.get('code'), ownership_status: form.get('ownership_status'), ...ownerPayload(form, null), reason })
      notify(property ? 'Imóvel actualizado.' : 'Imóvel criado.')
      navigate(`/imoveis/${saved.data.public_id}`)
    } catch (failure) { setError(toPhysicalError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title={property ? 'Editar imóvel' : 'Novo imóvel'} back={property ? { to: `/imoveis/${property.public_id}`, label: property.code } : { to: '/imoveis', label: 'Imóveis' }} />
    <div className="card"><ActionForm submitLabel={property ? 'Guardar alterações' : 'Criar imóvel'} busy={busy} error={error} onSubmit={submit}>
      {!property && <LocationSelect defaultValue={params.get('location') ?? ''} />}
      <div className="form-grid">
        <Field label="Código do imóvel" name="code" required><input className="input" id="code" name="code" required maxLength={64} defaultValue={property?.code ?? ''} autoComplete="off" /></Field>
        {!property && <Field label="Situação documental" name="ownership_status" hint="Documental: não concede autoridade."><select className="select" id="ownership_status" name="ownership_status" defaultValue="UNKNOWN">{physical.ownershipStatuses.map((status) => <option key={status.code} value={status.code}>{status.label}</option>)}</select></Field>}
      </div>
      <OwnerFields property={property ?? undefined} />
      <Field label="Motivo/observação" name="reason"><textarea className="textarea" id="reason" name="reason" maxLength={2000} /></Field>
    </ActionForm></div>
  </>
}

export function PropertyDetailPage() {
  const { id } = useParams()
  const { physical, notify } = useApp()
  const result = usePhysicalItem<PhysicalProperty>(id ? ph.property(id) : null)
  const [action, setAction] = useState<'ownership' | 'activate' | 'close' | null>(null)
  const [external, setExternal] = useState<string | null | undefined>(undefined)
  const [externalError, setExternalError] = useState<UiError | null>(null)
  if (result.loading && !result.data) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return <EmptyState />
  const property = result.data
  const manage = (property.can ?? []).includes('PROPERTY_MANAGE') && property.status !== 'CLOSED'
  async function act(path: string, body: Record<string, unknown>, message: string) {
    await physicalPost(path, { ...body, lock_version: property.lock_version })
    notify(message); setAction(null); result.reload()
  }
  async function revealExternal() {
    setExternalError(null)
    try { setExternal((await physicalGet<Item<{ name: string | null }>>(ph.externalOwner(property.public_id))).data.name) } catch (failure) { setExternalError(toPhysicalError(failure)) }
  }
  const owner = property.owner
  return <>
    <PageHeader title={`Imóvel ${property.code}`} description={property.location.name} back={{ to: '/imoveis', label: 'Imóveis' }} actions={manage ? <div className="physical-actions">
      <Link className="btn btn--secondary" to={`/imoveis/${property.public_id}/editar`}>Editar</Link>
      <button className="btn btn--secondary" type="button" onClick={() => setAction('ownership')}>Situação documental</button>
      {property.status === 'DRAFT' && <button className="btn btn--primary" type="button" onClick={() => setAction('activate')}>Activar</button>}
      <button className="btn btn--danger" type="button" onClick={() => setAction('close')}>Encerrar</button>
    </div> : undefined} />
    <section className="card"><dl className="dl">
      <div className="dl__item"><dt>Local</dt><dd><Link to={`/locais/${property.location.public_id}`}>{property.location.name}</Link></dd></div>
      <div className="dl__item"><dt>Estado</dt><dd><PhysicalBadge value={property.status} label={property.status_label} /></dd></div>
      <div className="dl__item"><dt>Situação documental</dt><dd>{property.ownership_status_label}</dd></div>
      <div className="dl__item"><dt>Proprietário</dt><dd>
        {owner.kind === 'NONE' && 'Não registado'}
        {owner.kind === 'PERSON' && (owner.person ? <Link to={`/pessoas/${owner.person.public_id}`}>{owner.person.display_name}</Link> : 'Pessoa registada (sem acesso aos dados pessoais)')}
        {owner.kind === 'EXTERNAL' && (external === undefined ? 'Proprietário externo registado' : external ?? '—')}
      </dd></div>
    </dl>
      {owner.kind === 'EXTERNAL' && (property.can ?? []).includes('PROPERTY_MANAGE') && external === undefined && <div className="physical-actions"><button className="btn btn--secondary btn--sm" type="button" onClick={revealExternal}>Mostrar nome (leitura registada)</button></div>}
      {externalError && <ErrorState error={externalError} />}
    </section>
    {physical.has('TEMPLE_VIEW') && <p className="muted">Os templos deste local estão em <Link to={`/locais/${property.location.public_id}`}>{property.location.name}</Link>.</p>}
    <ReasonDialog open={action === 'ownership'} title="Alterar situação documental" message="Campo documental: não altera autoridade nem publicação." submitLabel="Guardar situação" reasonRequired onClose={() => setAction(null)} onConfirm={(reason, form) => act(ph.ownership(property.public_id), { ownership_status: form.get('ownership_status'), reason }, 'Situação documental actualizada.')}>
      <Field label="Situação documental" name="ownership_status" required><select className="select" id="ownership_status" name="ownership_status" defaultValue={property.ownership_status} required>{physical.ownershipStatuses.map((status) => <option key={status.code} value={status.code}>{status.label}</option>)}</select></Field>
    </ReasonDialog>
    <ReasonDialog open={action === 'activate'} title="Activar imóvel" message="O local tem de estar activo." submitLabel="Activar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason) => act(ph.propertyActivate(property.public_id), { reason: reason || null }, 'Imóvel activado.')} />
    <ReasonDialog open={action === 'close'} title="Encerrar imóvel" message="O encerramento é definitivo nesta versão; o registo é preservado." submitLabel="Encerrar imóvel" reasonRequired onClose={() => setAction(null)} onConfirm={(reason) => act(ph.propertyClose(property.public_id), { reason }, 'Imóvel encerrado.')} />
  </>
}

// ---- Templos -------------------------------------------------------------------------------------------------------

export function TemplesPage() {
  const { physical } = useApp()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const result = usePhysicalPage<Temple>(ph.temples(), { page, per_page: 50, ...(search ? { search } : {}), ...(status ? { status } : {}) })
  return <>
    <PageHeader title="Templos" description="Uso religioso dos locais. Um templo não é uma Congregação nem cria unidades." actions={physical.has('TEMPLE_MANAGE') ? <Link className="btn btn--primary" to="/templos/novo">Novo templo</Link> : undefined} />
    <form className="card physical-filters" role="search" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(draft.trim()) }}>
      <Field label="Pesquisar por nome" name="temple-search"><input className="input" id="temple-search" value={draft} onChange={(event) => setDraft(event.target.value)} maxLength={100} /></Field>
      <StatusFilter id="temple-status" value={status} onChange={(value) => { setPage(1); setStatus(value) }} />
      <button className="btn btn--secondary" type="submit">Pesquisar</button>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem templos" message="Não existem templos visíveis para estes filtros." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'name', label: 'Templo', render: (row) => <Link to={`/templos/${row.public_id}`}>{row.name}</Link> },
      { key: 'location', label: 'Local', render: (row) => <Link to={`/locais/${row.location.public_id}`}>{row.location.name}</Link> },
      { key: 'capacity', label: 'Capacidade', render: (row) => row.capacity ?? '—' },
      { key: 'status', label: 'Estado', render: (row) => <PhysicalBadge value={row.status} label={row.status_label} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

export function TempleFormPage() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const { notify } = useApp()
  const navigate = useNavigate()
  const existing = usePhysicalItem<Temple>(id ? ph.temple(id) : null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  if (id && existing.loading) return <LoadingState />
  if (existing.error) return <ErrorState error={existing.error} retry={existing.reload} />
  const temple = existing.data
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const capacity = String(form.get('capacity') ?? '').trim()
    setBusy(true); setError(null)
    try {
      const body = { name: form.get('name'), capacity: capacity === '' ? null : Number(capacity), reason: String(form.get('reason') ?? '').trim() || null }
      const saved = temple
        ? await physicalPatch<Item<Temple>>(ph.temple(temple.public_id), { ...body, lock_version: temple.lock_version })
        : await physicalPost<Item<Temple>>(ph.temples(), { ...body, location_public_id: form.get('location_public_id') })
      notify(temple ? 'Templo actualizado.' : 'Templo criado.')
      navigate(`/templos/${saved.data.public_id}`)
    } catch (failure) { setError(toPhysicalError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title={temple ? 'Editar templo' : 'Novo templo'} description="O templo pertence ao local; não cria Congregação nem entra na estrutura territorial." back={temple ? { to: `/templos/${temple.public_id}`, label: temple.name } : { to: '/templos', label: 'Templos' }} />
    <div className="card"><ActionForm submitLabel={temple ? 'Guardar alterações' : 'Criar templo'} busy={busy} error={error} onSubmit={submit}>
      {!temple && <LocationSelect defaultValue={params.get('location') ?? ''} />}
      <div className="form-grid">
        <Field label="Nome do templo" name="name" required><input className="input" id="name" name="name" required maxLength={191} defaultValue={temple?.name ?? ''} /></Field>
        <Field label="Capacidade (lugares)" name="capacity"><input className="input" id="capacity" name="capacity" type="number" min={0} step={1} inputMode="numeric" defaultValue={temple?.capacity ?? ''} /></Field>
      </div>
      <Field label="Motivo/observação" name="reason"><textarea className="textarea" id="reason" name="reason" maxLength={2000} /></Field>
    </ActionForm></div>
  </>
}

export function TempleDetailPage() {
  const { id } = useParams()
  const { notify } = useApp()
  const result = usePhysicalItem<Temple>(id ? ph.temple(id) : null)
  const [action, setAction] = useState<'activate' | 'close' | null>(null)
  if (result.loading && !result.data) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return <EmptyState />
  const temple = result.data
  const manage = (temple.can ?? []).includes('TEMPLE_MANAGE') && temple.status !== 'CLOSED'
  async function act(path: string, reason: string, message: string) {
    await physicalPost(path, { reason: reason || null, lock_version: temple.lock_version })
    notify(message); setAction(null); result.reload()
  }
  return <>
    <PageHeader title={temple.name} description={temple.location.name} back={{ to: '/templos', label: 'Templos' }} actions={manage ? <div className="physical-actions">
      <Link className="btn btn--secondary" to={`/templos/${temple.public_id}/editar`}>Editar</Link>
      {temple.status === 'DRAFT' && <button className="btn btn--primary" type="button" onClick={() => setAction('activate')}>Activar</button>}
      <button className="btn btn--danger" type="button" onClick={() => setAction('close')}>Encerrar</button>
    </div> : undefined} />
    <section className="card"><dl className="dl">
      <div className="dl__item"><dt>Local</dt><dd><Link to={`/locais/${temple.location.public_id}`}>{temple.location.name}</Link></dd></div>
      <div className="dl__item"><dt>Estado</dt><dd><PhysicalBadge value={temple.status} label={temple.status_label} /></dd></div>
      <div className="dl__item"><dt>Capacidade</dt><dd>{temple.capacity ?? 'Não indicada'}</dd></div>
    </dl></section>
    <p className="muted">Um templo representa o uso religioso do local. Vários templos no mesmo local não criam Congregações.</p>
    <ReasonDialog open={action === 'activate'} title="Activar templo" message="O local tem de estar activo." submitLabel="Activar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason) => act(ph.templeActivate(temple.public_id), reason, 'Templo activado.')} />
    <ReasonDialog open={action === 'close'} title="Encerrar templo" message="O encerramento é definitivo nesta versão; o registo é preservado." submitLabel="Encerrar templo" reasonRequired onClose={() => setAction(null)} onConfirm={(reason) => act(ph.templeClose(temple.public_id), reason, 'Templo encerrado.')} />
  </>
}
