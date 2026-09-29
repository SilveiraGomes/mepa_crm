import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { usePhysicalItem, usePhysicalPage } from '../hooks/usePhysical'
import { physicalGet, physicalPatch, physicalPost, physicalPut } from '../lib/physical/client'
import { ph } from '../lib/physical/endpoints'
import { toPhysicalError } from '../lib/physical/errors'
import { formatDateTime } from '../lib/format'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { LocationAddress, PhysicalLink, PhysicalLocation, PhysicalProperty, Temple } from '../types/physical'

const VISIBILITY_LABEL: Record<string, string> = { PRIVATE: 'Privado', APPROVED_PUBLIC: 'Público aprovado' }

export function PhysicalBadge({ value, label }: { value: string; label?: string }) {
  const tone = value === 'ACTIVE' || value === 'APPROVED_PUBLIC' ? ' badge--info' : value === 'CLOSED' || value === 'ENDED' ? ' badge--warning' : ''
  return <span className={`badge${tone}`}>{label ?? VISIBILITY_LABEL[value] ?? value}</span>
}

/** Confirmation built into the page (no browser dialog), with a reason that the API may require. */
export function ReasonDialog({ open, title, message, submitLabel, reasonRequired, onClose, onConfirm, children }: { open: boolean; title: string; message: string; submitLabel: string; reasonRequired: boolean; onClose: () => void; onConfirm: (reason: string, form: FormData) => Promise<void>; children?: ReactNode }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true); setError(null)
    try { await onConfirm(String(form.get('reason') ?? '').trim(), form) } catch (failure) { setError(toPhysicalError(failure)) } finally { setBusy(false) }
  }
  const reasonId = `reason-${title.normalize('NFD').replace(/[^A-Za-z0-9]+/g, '-').toLowerCase()}`
  return <Dialog open={open} title={title} onClose={onClose}><ActionForm submitLabel={submitLabel} busy={busy} error={error} onSubmit={submit}><p>{message}</p>{children}<Field label={reasonRequired ? 'Motivo' : 'Motivo (opcional)'} name={reasonId} required={reasonRequired} error={error?.fields.reason}><textarea className="textarea" id={reasonId} name="reason" required={reasonRequired} minLength={reasonRequired ? 3 : undefined} maxLength={2000} /></Field></ActionForm></Dialog>
}

function UnitSelect({ name, permission, exclude, label, required = true }: { name: string; permission: string; exclude?: string | null; label: string; required?: boolean }) {
  const { physical } = useApp()
  const units = physical.units.filter((unit) => unit.permissions.includes(permission) && unit.public_id !== exclude)
  return <Field label={label} name={name} required={required} hint={physical.known && units.length === 0 ? 'Não existem unidades disponíveis no seu escopo para esta operação.' : undefined}><select className="select" id={name} name={name} required={required} disabled={!physical.known}><option value="">{physical.known ? 'Seleccione' : 'A carregar…'}</option>{units.map((unit) => <option key={unit.public_id} value={unit.public_id}>{unit.name}</option>)}</select></Field>
}

function OccupationSelect({ name, defaultValue }: { name: string; defaultValue?: string }) {
  const { physical } = useApp()
  return <Field label="Forma de ocupação" name={name} required hint="Como a unidade ocupa o local (não é o tipo de edifício). «Outro» exige motivo."><select className="select" id={name} name={name} required defaultValue={defaultValue ?? ''}><option value="">Seleccione</option>{physical.occupationTypes.map((type) => <option key={type.code} value={type.code}>{type.label}</option>)}</select></Field>
}

// ---- Locais ------------------------------------------------------------------------------------------------------

export function LocationsPage() {
  const { physical } = useApp()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const result = usePhysicalPage<PhysicalLocation>(ph.locations(), { page, per_page: 50, ...(search ? { search } : {}), ...(status ? { status } : {}) })
  return <>
    <PageHeader title="Locais" description="Locais físicos com vínculo institucional activo no seu escopo." actions={physical.has('PHYSICAL_LOCATION_MANAGE') ? <Link className="btn btn--primary" to="/locais/novo">Novo local</Link> : undefined} />
    <form className="card physical-filters" role="search" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(draft.trim()) }}>
      <Field label="Pesquisar por nome" name="location-search"><input className="input" id="location-search" value={draft} onChange={(event) => setDraft(event.target.value)} maxLength={100} /></Field>
      <Field label="Estado" name="location-status"><select className="select" id="location-status" value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }}><option value="">Todos</option><option value="DRAFT">Rascunho</option><option value="ACTIVE">Activo</option><option value="CLOSED">Encerrado</option></select></Field>
      <button className="btn btn--secondary" type="submit">Pesquisar</button>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem locais" message="Não existem locais com vínculo activo no seu escopo para estes filtros." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'name', label: 'Local', render: (row) => <Link to={`/locais/${row.public_id}`}>{row.name}</Link> },
      { key: 'unit', label: 'Unidade', render: (row) => row.unit ? `${row.unit.name}${row.unit.is_primary ? ' (principal)' : ''}` : '—' },
      { key: 'status', label: 'Estado', render: (row) => <PhysicalBadge value={row.status} label={row.status_label} /> },
      { key: 'visibility', label: 'Visibilidade', render: (row) => <PhysicalBadge value={row.public_visibility} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

export function LocationCreatePage() {
  const { notify } = useApp()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const latitude = String(form.get('latitude') ?? '').trim()
    const longitude = String(form.get('longitude') ?? '').trim()
    setBusy(true); setError(null)
    try {
      const created = await physicalPost<Item<PhysicalLocation>>(ph.locations(), {
        unit_public_id: form.get('unit_public_id'), name: form.get('name'), occupation_type_code: form.get('occupation_type_code'),
        address: { country_code: form.get('country_code'), line1: form.get('line1'), locality: String(form.get('locality') ?? '').trim() || null },
        latitude: latitude === '' ? null : Number(latitude), longitude: longitude === '' ? null : Number(longitude),
        reason: String(form.get('reason') ?? '').trim() || null,
      })
      notify('Local criado com o primeiro vínculo institucional.')
      navigate(`/locais/${created.data.public_id}`)
    } catch (failure) { setError(toPhysicalError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Novo local" description="O local, a morada protegida e o primeiro vínculo à unidade são criados numa única operação." back={{ to: '/locais', label: 'Locais' }} />
    <div className="card"><ActionForm submitLabel="Criar local" busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid"><UnitSelect name="unit_public_id" permission="PHYSICAL_LOCATION_MANAGE" label="Unidade responsável (primeiro vínculo)" /><OccupationSelect name="occupation_type_code" /></div>
      <Field label="Nome do local" name="name" required><input className="input" id="name" name="name" required maxLength={191} /></Field>
      <fieldset className="fieldset"><legend>Morada (cifrada)</legend><div className="form-grid">
        <Field label="País (código)" name="country_code" required><input className="input" id="country_code" name="country_code" defaultValue="AO" required pattern="[A-Za-z]{2,3}" maxLength={3} autoComplete="off" /></Field>
        <Field label="Localidade" name="locality"><input className="input" id="locality" name="locality" maxLength={191} autoComplete="off" /></Field>
      </div><Field label="Endereço" name="line1" required hint="Guardado cifrado; só é mostrado a quem gere o local, com registo de leitura."><input className="input" id="line1" name="line1" required minLength={3} maxLength={500} autoComplete="off" /></Field></fieldset>
      <fieldset className="fieldset"><legend>Coordenadas (opcional, privadas por omissão)</legend><div className="form-grid">
        <Field label="Latitude" name="latitude"><input className="input" id="latitude" name="latitude" type="number" step="any" min={-90} max={90} inputMode="decimal" /></Field>
        <Field label="Longitude" name="longitude"><input className="input" id="longitude" name="longitude" type="number" step="any" min={-180} max={180} inputMode="decimal" /></Field>
      </div></fieldset>
      <Field label="Motivo/observação" name="reason" error={error?.fields.reason}><textarea className="textarea" id="reason" name="reason" maxLength={2000} /></Field>
    </ActionForm></div>
  </>
}

export function LocationEditPage() {
  const { id } = useParams()
  const { notify } = useApp()
  const navigate = useNavigate()
  const existing = usePhysicalItem<PhysicalLocation>(id ? ph.location(id) : null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  if (existing.loading) return <LoadingState />
  if (existing.error) return <ErrorState error={existing.error} retry={existing.reload} />
  if (!existing.data) return <EmptyState />
  const location = existing.data
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const latitude = String(form.get('latitude') ?? '').trim()
    const longitude = String(form.get('longitude') ?? '').trim()
    setBusy(true); setError(null)
    try {
      await physicalPatch(ph.location(location.public_id), { name: form.get('name'), latitude: latitude === '' ? null : Number(latitude), longitude: longitude === '' ? null : Number(longitude), reason: String(form.get('reason') ?? '').trim() || null, lock_version: location.lock_version })
      notify('Local actualizado.')
      navigate(`/locais/${location.public_id}`)
    } catch (failure) { setError(toPhysicalError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Editar local" description={location.name} back={{ to: `/locais/${location.public_id}`, label: location.name }} />
    {location.public_visibility === 'APPROVED_PUBLIC' && <div className="alert alert--warning" role="note"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>Local publicado</strong><p>Alterar as coordenadas retira automaticamente a publicação. A nova posição exige nova publicação.</p></div></div>}
    <div className="card"><ActionForm submitLabel="Guardar alterações" busy={busy} error={error} onSubmit={submit}>
      <Field label="Nome do local" name="name" required><input className="input" id="name" name="name" defaultValue={location.name} required maxLength={191} /></Field>
      <div className="form-grid">
        <Field label="Latitude" name="latitude"><input className="input" id="latitude" name="latitude" type="number" step="any" min={-90} max={90} defaultValue={location.latitude ?? ''} inputMode="decimal" /></Field>
        <Field label="Longitude" name="longitude"><input className="input" id="longitude" name="longitude" type="number" step="any" min={-180} max={180} defaultValue={location.longitude ?? ''} inputMode="decimal" /></Field>
      </div>
      <Field label="Motivo/observação" name="reason"><textarea className="textarea" id="reason" name="reason" maxLength={2000} /></Field>
    </ActionForm></div>
  </>
}

type LocationAction = 'activate' | 'close' | 'publish' | 'unpublish' | 'address' | null

export function LocationDetailPage() {
  const { id } = useParams()
  const { physical, notify } = useApp()
  const result = usePhysicalItem<PhysicalLocation>(id ? ph.location(id) : null)
  const [action, setAction] = useState<LocationAction>(null)
  const [address, setAddress] = useState<LocationAddress | null>(null)
  const [addressError, setAddressError] = useState<UiError | null>(null)
  if (result.loading && !result.data) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return <EmptyState />
  const location = result.data
  const can = (permission: string) => (location.can ?? []).includes(permission)
  const manage = can('PHYSICAL_LOCATION_MANAGE') && location.status !== 'CLOSED'
  async function lifecycle(path: string, reason: string, message: string) {
    await physicalPost(path, { lock_version: location.lock_version, reason: reason || null })
    notify(message); setAction(null); result.reload()
  }
  async function revealAddress() {
    setAddressError(null)
    try { setAddress((await physicalGet<Item<LocationAddress>>(ph.address(location.public_id))).data) } catch (failure) { setAddressError(toPhysicalError(failure)) }
  }
  return <>
    <PageHeader title={location.name} description={location.unit ? `Vinculado a ${location.unit.name}` : undefined} back={{ to: '/locais', label: 'Locais' }} actions={<div className="physical-actions">
      {manage && <Link className="btn btn--secondary" to={`/locais/${location.public_id}/editar`}>Editar</Link>}
      {manage && location.status === 'DRAFT' && <button className="btn btn--primary" type="button" onClick={() => setAction('activate')}>Activar</button>}
      {can('PHYSICAL_LOCATION_PUBLISH') && location.status === 'ACTIVE' && location.public_visibility === 'PRIVATE' && <button className="btn btn--primary" type="button" onClick={() => setAction('publish')}>Publicar coordenadas</button>}
      {can('PHYSICAL_LOCATION_PUBLISH') && location.public_visibility === 'APPROVED_PUBLIC' && <button className="btn btn--secondary" type="button" onClick={() => setAction('unpublish')}>Retirar publicação</button>}
      {manage && <button className="btn btn--danger" type="button" onClick={() => setAction('close')}>Encerrar</button>}
    </div>} />
    <section className="card"><dl className="dl">
      <div className="dl__item"><dt>Estado</dt><dd><PhysicalBadge value={location.status} label={location.status_label} /></dd></div>
      <div className="dl__item"><dt>Visibilidade</dt><dd><PhysicalBadge value={location.public_visibility} /></dd></div>
      <div className="dl__item"><dt>Latitude</dt><dd>{location.latitude ?? 'Não indicada'}</dd></div>
      <div className="dl__item"><dt>Longitude</dt><dd>{location.longitude ?? 'Não indicada'}</dd></div>
      <div className="dl__item"><dt>País</dt><dd>{location.country_code ?? '—'}</dd></div>
      <div className="dl__item"><dt>Vínculos activos</dt><dd>{location.active_links ?? '—'}</dd></div>
    </dl></section>
    {location.public_projection && <section className="card stack"><h2>Projecção pública aprovada</h2><p className="muted">Apenas estes quatro campos podem ser divulgados. Não existe mapa público anónimo nesta versão.</p><dl className="dl">
      <div className="dl__item"><dt>Nome</dt><dd>{location.public_projection.name}</dd></div>
      <div className="dl__item"><dt>Latitude</dt><dd>{location.public_projection.latitude}</dd></div>
      <div className="dl__item"><dt>Longitude</dt><dd>{location.public_projection.longitude}</dd></div>
      <div className="dl__item"><dt>Identificador</dt><dd className="mono">{location.public_projection.public_id}</dd></div>
    </dl></section>}
    {can('PHYSICAL_LOCATION_MANAGE') && <section className="card stack"><div className="section-head"><h2>Morada protegida</h2><div className="physical-actions">{!address && <button className="btn btn--secondary" type="button" onClick={revealAddress}>Mostrar morada</button>}{manage && <button className="btn btn--secondary" type="button" onClick={() => setAction('address')}>Alterar morada</button>}</div></div>
      <p className="muted">A consulta da morada em claro fica registada na auditoria.</p>
      {addressError && <ErrorState error={addressError} />}
      {address && <dl className="dl"><div className="dl__item"><dt>Endereço</dt><dd>{address.line1}</dd></div><div className="dl__item"><dt>Localidade</dt><dd>{address.locality ?? '—'}</dd></div><div className="dl__item"><dt>País</dt><dd>{address.country_code}</dd></div></dl>}
    </section>}
    <LinksSection location={location} onChanged={result.reload} />
    {physical.has('PROPERTY_VIEW') && <LocationProperties location={location} />}
    {physical.has('TEMPLE_VIEW') && <LocationTemples location={location} />}
    <ReasonDialog open={action === 'activate'} title="Activar local" message="O local passa a activo e pode receber imóveis e templos activos." submitLabel="Activar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason) => lifecycle(ph.activate(location.public_id), reason, 'Local activado.')} />
    <ReasonDialog open={action === 'close'} title="Encerrar local" message="O encerramento é definitivo nesta versão e retira qualquer publicação. Imóveis e templos activos impedem o encerramento." submitLabel="Encerrar local" reasonRequired onClose={() => setAction(null)} onConfirm={(reason) => lifecycle(ph.close(location.public_id), reason, 'Local encerrado.')} />
    <ReasonDialog open={action === 'publish'} title="Publicar coordenadas" message="Só o nome, a latitude e a longitude ficam aprovados para divulgação pública." submitLabel="Publicar" reasonRequired onClose={() => setAction(null)} onConfirm={(reason) => lifecycle(ph.publish(location.public_id), reason, 'Coordenadas publicadas.')} />
    <ReasonDialog open={action === 'unpublish'} title="Retirar publicação" message="O local volta a ser privado." submitLabel="Retirar publicação" reasonRequired onClose={() => setAction(null)} onConfirm={(reason) => lifecycle(ph.unpublish(location.public_id), reason, 'Publicação retirada.')} />
    <ReasonDialog open={action === 'address'} title="Alterar morada" message="É criada uma nova morada cifrada; a anterior fica preservada no histórico." submitLabel="Guardar morada" reasonRequired={false} onClose={() => setAction(null)} onConfirm={async (reason, form) => {
      await physicalPut(ph.address(location.public_id), { country_code: form.get('country_code'), line1: form.get('line1'), locality: String(form.get('locality') ?? '').trim() || null, reason: reason || null, lock_version: location.lock_version })
      notify('Morada actualizada.'); setAddress(null); setAction(null); result.reload()
    }}>
      <div className="form-grid"><Field label="País (código)" name="address-country" required><input className="input" id="address-country" name="country_code" defaultValue={location.country_code ?? 'AO'} required pattern="[A-Za-z]{2,3}" maxLength={3} /></Field><Field label="Localidade" name="address-locality"><input className="input" id="address-locality" name="locality" maxLength={191} /></Field></div>
      <Field label="Endereço" name="address-line1" required><input className="input" id="address-line1" name="line1" required minLength={3} maxLength={500} autoComplete="off" /></Field>
    </ReasonDialog>
  </>
}

// ---- Ligações --------------------------------------------------------------------------------------------------

type LinkAction = { kind: 'end' | 'transfer' | 'primary'; link: PhysicalLink } | { kind: 'link' } | null

function LinksSection({ location, onChanged }: { location: PhysicalLocation; onChanged: () => void }) {
  const { physical, notify } = useApp()
  const [history, setHistory] = useState(false)
  const [action, setAction] = useState<LinkAction>(null)
  const links = usePhysicalPage<PhysicalLink>(ph.links(location.public_id), { per_page: 50, ...(history ? { history: 1 } : {}) })
  const manageLinks = physical.has('UNIT_LOCATION_LINK_MANAGE') && location.status !== 'CLOSED'
  const canCloseLocation = (location.can ?? []).includes('PHYSICAL_LOCATION_MANAGE')
  const others = (links.data?.meta as { other_active_links?: number } | undefined)?.other_active_links ?? 0
  function done(message: string) { notify(message); setAction(null); links.reload(); onChanged() }
  const current = action && action.kind !== 'link' ? action.link : null
  return <section className="stack" aria-labelledby="links-title">
    <div className="section-head"><h2 id="links-title">Ligações institucionais</h2><div className="physical-actions">
      <label className="checkbox"><input type="checkbox" checked={history} onChange={(event) => setHistory(event.target.checked)} /> Mostrar histórico</label>
      {manageLinks && <button className="btn btn--secondary" type="button" onClick={() => setAction({ kind: 'link' })}>Ligar a outra unidade</button>}
    </div></div>
    {links.loading && <LoadingState />}
    {links.error && <ErrorState error={links.error} retry={links.reload} />}
    {links.data && links.data.data.length === 0 && <EmptyState title="Sem ligações visíveis" message="Não existem vínculos visíveis no seu escopo." />}
    {links.data && links.data.data.length > 0 && <ul className="person-list">{links.data.data.map((link) => <li key={link.ref} className="person-list__item">
      <div className="person-list__main"><strong>{link.unit?.name ?? 'Unidade'}</strong><span className="muted">{link.occupation_type?.label ?? '—'}{link.property ? ` · Imóvel ${link.property.code}` : ''}</span><span className="muted">Desde {formatDateTime(link.starts_at)}{link.ends_at ? ` até ${formatDateTime(link.ends_at)}` : ''}</span></div>
      <div className="person-list__actions">{link.is_primary && link.status === 'ACTIVE' && <span className="badge badge--info">Principal</span>}<PhysicalBadge value={link.status} label={link.status === 'ACTIVE' ? 'Activo' : 'Terminado'} />
        {manageLinks && link.status === 'ACTIVE' && <>
          {!link.is_primary && <button className="btn btn--secondary btn--sm" type="button" onClick={() => setAction({ kind: 'primary', link })}>Tornar principal</button>}
          <button className="btn btn--secondary btn--sm" type="button" onClick={() => setAction({ kind: 'transfer', link })}>Transferir</button>
          <button className="btn btn--ghost btn--sm" type="button" onClick={() => setAction({ kind: 'end', link })}>Terminar</button>
        </>}
      </div>
    </li>)}</ul>}
    {others > 0 && <p className="muted">Existem {others} vínculo(s) activo(s) com unidades fora do seu escopo.</p>}
    <ReasonDialog open={action?.kind === 'link'} title="Ligar a outra unidade" message="Exige autoridade sobre este local e sobre a unidade de destino. O primeiro vínculo activo de uma unidade fica principal." submitLabel="Criar vínculo" reasonRequired={false} onClose={() => setAction(null)} onConfirm={async (reason, form) => {
      await physicalPost(ph.links(location.public_id), { unit_public_id: form.get('link_unit'), occupation_type_code: form.get('link_occupation'), is_primary: form.get('link_primary') === 'on', reason: reason || null })
      done('Vínculo criado.')
    }}><UnitSelect name="link_unit" permission="UNIT_LOCATION_LINK_MANAGE" label="Unidade" /><OccupationSelect name="link_occupation" /><label className="checkbox"><input type="checkbox" name="link_primary" /> Tornar o local principal desta unidade</label></ReasonDialog>
    <ReasonDialog key={`transfer-${current?.ref ?? 'none'}`} open={action?.kind === 'transfer'} title="Transferir vínculo" message={`O vínculo de ${current?.unit?.name ?? 'esta unidade'} termina e começa, no mesmo instante, um vínculo da unidade de destino. O histórico é preservado.`} submitLabel="Transferir" reasonRequired onClose={() => setAction(null)} onConfirm={async (reason, form) => {
      if (!current) return
      await physicalPost(ph.transfer(location.public_id, current.ref), { to_unit_public_id: form.get('transfer_unit'), occupation_type_code: form.get('transfer_occupation'), is_primary: form.get('transfer_primary') === 'on', reason, lock_version: current.lock_version })
      done('Vínculo transferido.')
    }}><UnitSelect name="transfer_unit" permission="UNIT_LOCATION_LINK_MANAGE" exclude={current?.unit?.public_id} label="Unidade de destino" /><OccupationSelect name="transfer_occupation" defaultValue={current?.occupation_type?.code} /><label className="checkbox"><input type="checkbox" name="transfer_primary" /> Tornar principal na unidade de destino</label></ReasonDialog>
    <ReasonDialog open={action?.kind === 'end'} title="Terminar vínculo" message="O último vínculo activo só pode terminar por transferência ou com o encerramento do local na mesma operação." submitLabel="Terminar vínculo" reasonRequired onClose={() => setAction(null)} onConfirm={async (reason, form) => {
      if (!current) return
      await physicalPost(ph.endLink(location.public_id, current.ref), { reason, lock_version: current.lock_version, close_location: form.get('close_location') === 'on' })
      done('Vínculo terminado.')
    }}>{canCloseLocation && <label className="checkbox"><input type="checkbox" name="close_location" /> Encerrar também o local (último vínculo)</label>}</ReasonDialog>
    <ReasonDialog open={action?.kind === 'primary'} title="Tornar principal" message={`Este local passa a ser o principal de ${current?.unit?.name ?? 'a unidade'}; o principal anterior é despromovido.`} submitLabel="Tornar principal" reasonRequired={false} onClose={() => setAction(null)} onConfirm={async (reason) => {
      if (!current) return
      await physicalPost(ph.setPrimary(location.public_id, current.ref), { lock_version: current.lock_version, reason: reason || null })
      done('Local principal actualizado.')
    }} />
  </section>
}

function LocationProperties({ location }: { location: PhysicalLocation }) {
  const result = usePhysicalPage<PhysicalProperty>(ph.properties(), { location_public_id: location.public_id, per_page: 50 })
  const canCreate = (location.can ?? []).includes('PROPERTY_MANAGE') && location.status !== 'CLOSED'
  return <section className="stack"><div className="section-head"><h2>Imóveis</h2>{canCreate && <Link className="btn btn--secondary" to={`/imoveis/novo?location=${location.public_id}`}>Novo imóvel</Link>}</div>
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && (result.data.data.length === 0 ? <EmptyState title="Sem imóveis" message="Não existem imóveis registados neste local." /> : <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'code', label: 'Imóvel', render: (row) => <Link to={`/imoveis/${row.public_id}`}>{row.code}</Link> },
      { key: 'ownership', label: 'Situação documental', render: (row) => row.ownership_status_label },
      { key: 'status', label: 'Estado', render: (row) => <PhysicalBadge value={row.status} label={row.status_label} /> },
    ]} /></div>)}
  </section>
}

function LocationTemples({ location }: { location: PhysicalLocation }) {
  const result = usePhysicalPage<Temple>(ph.temples(), { location_public_id: location.public_id, per_page: 50 })
  const canCreate = (location.can ?? []).includes('TEMPLE_MANAGE') && location.status !== 'CLOSED'
  return <section className="stack"><div className="section-head"><h2>Templos</h2>{canCreate && <Link className="btn btn--secondary" to={`/templos/novo?location=${location.public_id}`}>Novo templo</Link>}</div>
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && (result.data.data.length === 0 ? <EmptyState title="Sem templos" message="Não existem templos registados neste local." /> : <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'name', label: 'Templo', render: (row) => <Link to={`/templos/${row.public_id}`}>{row.name}</Link> },
      { key: 'capacity', label: 'Capacidade', render: (row) => row.capacity ?? '—' },
      { key: 'status', label: 'Estado', render: (row) => <PhysicalBadge value={row.status} label={row.status_label} /> },
    ]} /></div>)}
  </section>
}

// ---- Ligações por unidade ------------------------------------------------------------------------------------------

export function UnitLinksPage() {
  const { physical } = useApp()
  const units = physical.units.filter((unit) => unit.permissions.includes('PHYSICAL_LOCATION_VIEW') || unit.permissions.includes('UNIT_LOCATION_LINK_MANAGE'))
  const [unit, setUnit] = useState('')
  const [status, setStatus] = useState('ACTIVE')
  const [page, setPage] = useState(1)
  const selected = unit || units[0]?.public_id || ''
  const result = usePhysicalPage<PhysicalLink>(selected ? ph.unitLinks() : null, { unit_public_id: selected, status, page, per_page: 50 })
  return <>
    <PageHeader title="Ligações institucionais" description="Locais ligados a cada unidade, com o principal e o histórico." />
    <div className="card physical-filters">
      <Field label="Unidade" name="links-unit"><select className="select" id="links-unit" value={selected} onChange={(event) => { setPage(1); setUnit(event.target.value) }}>{units.map((item) => <option key={item.public_id} value={item.public_id}>{item.name}</option>)}</select></Field>
      <Field label="Estado" name="links-status"><select className="select" id="links-status" value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }}><option value="ACTIVE">Activos</option><option value="ENDED">Terminados</option><option value="ALL">Todos</option></select></Field>
    </div>
    {!selected && <EmptyState title="Sem unidades" message="Não existem unidades no seu escopo para consultar ligações." />}
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem ligações" message="Esta unidade não tem ligações com este estado." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.ref} columns={[
      { key: 'location', label: 'Local', render: (row) => row.location ? <Link to={`/locais/${row.location.public_id}`}>{row.location.name}</Link> : '—' },
      { key: 'occupation', label: 'Ocupação', render: (row) => row.occupation_type?.label ?? '—' },
      { key: 'primary', label: 'Principal', render: (row) => row.is_primary && row.status === 'ACTIVE' ? 'Sim' : 'Não' },
      { key: 'period', label: 'Período', render: (row) => `${formatDateTime(row.starts_at)}${row.ends_at ? ` – ${formatDateTime(row.ends_at)}` : ''}` },
      { key: 'status', label: 'Estado', render: (row) => <PhysicalBadge value={row.status} label={row.status === 'ACTIVE' ? 'Activo' : 'Terminado'} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}
