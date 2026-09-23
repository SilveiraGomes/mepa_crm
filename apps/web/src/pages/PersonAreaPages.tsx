import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ActionForm, Dialog, EmptyState, ErrorState, Field, LoadingState } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useCatalogs, usePeopleList } from '../hooks/usePeople'
import { peoplePatch, peoplePost } from '../lib/people/client'
import { pe } from '../lib/people/endpoints'
import { toPeopleError } from '../lib/people/errors'
import type { UiError } from '../lib/academy/errors'
import { formatDateTime } from '../lib/format'
import type { Address, Contact, HouseholdSummary, PersonDetail, Relationship, SelectorPerson } from '../types/people'
import { PersonPicker } from '../components/people'
import { ageBandLabel, HOUSEHOLD_STATUS_LABEL } from '../lib/people/format'
import { PersonFrame } from './PeoplePages'

export function AreaRestricted({ title }: { title: string }) {
  return <div className="alert alert--warning" role="note"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>{title}</strong><p>Dados sensíveis ocultos. Estes dados exigem uma permissão específica ou não estão disponíveis nesta projecção.</p></div></div>
}

/** Confirmation built into the page (no browser dialog), with an optional reason. */
export function EndDialog({ open, title, message, onClose, onConfirm, busy, error }: { open: boolean; title: string; message: string; onClose: () => void; onConfirm: (reason: string) => void; busy: boolean; error: UiError | null }) {
  const [reason, setReason] = useState('')
  return <Dialog open={open} title={title} onClose={onClose}><ActionForm submitLabel="Confirmar" busy={busy} error={error} onSubmit={(e) => { e.preventDefault(); onConfirm(reason) }}><p>{message}</p><Field label="Motivo (opcional)" name="end_reason"><textarea id="end_reason" className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} /></Field></ActionForm></Dialog>
}

function ContactDialog({ person, contact, open, onClose, onDone }: { person: PersonDetail; contact: Contact | null; open: boolean; onClose: () => void; onDone: () => void }) {
  const { notify } = useApp()
  const catalogs = useCatalogs()
  const [type, setType] = useState(contact?.type ?? 'PHONE')
  const [value, setValue] = useState(contact?.value ?? '')
  const [primary, setPrimary] = useState(contact?.is_primary ?? false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null)
    try {
      if (contact) await peoplePatch(pe.contact(person.public_id, contact.ref), { value, is_primary: primary, lock_version: contact.lock_version })
      else await peoplePost(pe.contacts(person.public_id), { type, value, is_primary: primary })
      notify(contact ? 'Contacto actualizado.' : 'Contacto adicionado.')
      onDone()
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <Dialog open={open} title={contact ? 'Editar contacto' : 'Adicionar contacto'} onClose={onClose}><ActionForm submitLabel={contact ? 'Guardar contacto' : 'Adicionar'} busy={busy} error={error} onSubmit={submit}>
    {!contact && <Field label="Tipo de contacto" name="contact_type" required><select id="contact_type" className="select" value={type} onChange={(e) => setType(e.target.value)} disabled={!catalogs.data}>{!catalogs.data && <option value="">A carregar…</option>}{(catalogs.data?.contact_types ?? []).map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}</select></Field>}
    <Field label={type === 'EMAIL' ? 'Email' : 'Número de telefone'} name="contact_value" required error={error?.fields.value}><input id="contact_value" className="input" value={value} onChange={(e) => setValue(e.target.value)} type={type === 'EMAIL' ? 'email' : 'tel'} inputMode={type === 'EMAIL' ? 'email' : 'tel'} autoComplete="off" required /></Field>
    <label className="checkbox"><input type="checkbox" checked={primary} onChange={(e) => setPrimary(e.target.checked)} /> Contacto principal deste tipo</label>
  </ActionForm></Dialog>
}

export function ContactsPage() {
  return <PersonFrame>{(person) => <ContactsArea person={person} />}</PersonFrame>
}

function ContactsArea({ person }: { person: PersonDetail }) {
  const { notify } = useApp()
  const allowed = person.capabilities.can_view_contacts
  const list = usePeopleList<Contact>(allowed ? pe.contacts(person.public_id) : null)
  const [editing, setEditing] = useState<Contact | null | 'new'>(null)
  const [ending, setEnding] = useState<Contact | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  if (!allowed) return <AreaRestricted title="Contactos" />
  async function end(reason: string) {
    if (!ending) return
    setBusy(true); setError(null)
    try { await peoplePost(pe.contactEnd(person.public_id, ending.ref), reason ? { reason } : {}); notify('Contacto terminado.'); setEnding(null); list.reload() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  const manage = person.capabilities.can_manage_contacts
  return <section className="card stack"><div className="section-head"><h2>Contactos</h2>{manage && <button className="btn btn--primary" type="button" onClick={() => setEditing('new')}>Adicionar contacto</button>}</div>
    {list.loading && <LoadingState />}
    {list.error && <ErrorState error={list.error} retry={list.reload} />}
    {list.data?.meta?.hidden === 'DECEASED' && <p className="muted">Os contactos de uma pessoa falecida não são apresentados por defeito.</p>}
    {list.data && list.data.data.length === 0 && list.data.meta?.hidden !== 'DECEASED' && <EmptyState title="Sem contactos" message="Não existem contactos registados." />}
    {list.data && list.data.data.length > 0 && <ul className="person-list">{list.data.data.map((c) => <li key={c.ref} className="person-list__item"><div className="person-list__main"><span className="muted">{c.type_name}{c.is_primary ? ' · principal' : ''}</span><strong>{c.value}</strong><span className="muted">{c.status === 'ACTIVE' ? `Registado ${formatDateTime(c.created_at)}` : 'Terminado'}</span></div>{manage && c.status === 'ACTIVE' && <div className="person-list__actions"><button className="btn btn--secondary btn--sm" type="button" onClick={() => setEditing(c)}>Editar</button><button className="btn btn--ghost btn--sm" type="button" onClick={() => { setError(null); setEnding(c) }}>Terminar</button></div>}</li>)}</ul>}
    {editing !== null && <ContactDialog key={editing === 'new' ? 'new' : editing.ref} person={person} contact={editing === 'new' ? null : editing} open onClose={() => setEditing(null)} onDone={() => { setEditing(null); list.reload() }} />}
    <EndDialog open={ending !== null} title="Terminar contacto" message="O contacto deixa de estar activo. O histórico é preservado." onClose={() => setEnding(null)} onConfirm={end} busy={busy} error={error} />
  </section>
}

function AddressDialog({ person, address, open, onClose, onDone }: { person: PersonDetail; address: Address | null; open: boolean; onClose: () => void; onDone: () => void }) {
  const { notify } = useApp()
  const catalogs = useCatalogs()
  const [line1, setLine1] = useState(address?.line1 ?? '')
  const [locality, setLocality] = useState(address?.locality ?? '')
  const [province, setProvince] = useState(address?.province?.code ?? '')
  const [municipality, setMunicipality] = useState(address?.municipality?.code ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const areas = catalogs.data?.territorial_areas ?? []
  const provinces = areas.filter((a) => a.parent_code === null)
  const municipalities = areas.filter((a) => province !== '' && a.parent_code === province)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null)
    const body = { line1, locality: locality || null, province: province || null, municipality: municipality || null }
    try {
      if (address) await peoplePatch(pe.address(person.public_id, address.ref), { ...body, lock_version: address.lock_version })
      else await peoplePost(pe.addresses(person.public_id), body)
      notify(address ? 'Endereço actualizado. O anterior ficou no histórico.' : 'Endereço adicionado.')
      onDone()
    } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  return <Dialog open={open} title={address ? 'Actualizar endereço' : 'Adicionar endereço'} onClose={onClose}><ActionForm submitLabel={address ? 'Guardar endereço' : 'Adicionar'} busy={busy} error={error} onSubmit={submit}>
    <Field label="Morada" name="address_line1" required hint="Rua, número e referências." error={error?.fields.line1}><input id="address_line1" className="input" value={line1} onChange={(e) => setLine1(e.target.value)} required minLength={3} maxLength={255} autoComplete="off" /></Field>
    <Field label="Localidade ou bairro" name="address_locality"><input id="address_locality" className="input" value={locality} onChange={(e) => setLocality(e.target.value)} maxLength={191} /></Field>
    <div className="form-grid">
      <Field label="Província" name="address_province" hint={provinces.length === 0 ? 'Catálogo territorial ainda não disponível.' : undefined} error={error?.fields.province}><select id="address_province" className="select" value={province} disabled={provinces.length === 0} onChange={(e) => { setProvince(e.target.value); setMunicipality('') }}><option value="">Não indicada</option>{provinces.map((a) => <option key={a.code} value={a.code}>{a.name}</option>)}</select></Field>
      <Field label="Município" name="address_municipality" error={error?.fields.municipality}><select id="address_municipality" className="select" value={municipality} disabled={municipalities.length === 0} onChange={(e) => setMunicipality(e.target.value)}><option value="">Não indicado</option>{municipalities.map((a) => <option key={a.code} value={a.code}>{a.name}</option>)}</select></Field>
    </div>
  </ActionForm></Dialog>
}

export function AddressesPage() {
  return <PersonFrame>{(person) => <AddressesArea person={person} />}</PersonFrame>
}

function AddressesArea({ person }: { person: PersonDetail }) {
  const { notify } = useApp()
  const allowed = person.capabilities.can_view_addresses
  const [history, setHistory] = useState(false)
  const list = usePeopleList<Address>(allowed ? pe.addresses(person.public_id) : null, history ? { include_history: 1 } : {})
  const [editing, setEditing] = useState<Address | null | 'new'>(null)
  const [ending, setEnding] = useState<Address | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  if (!allowed) return <AreaRestricted title="Endereços" />
  async function end(reason: string) {
    if (!ending) return
    setBusy(true); setError(null)
    try { await peoplePost(pe.addressEnd(person.public_id, ending.ref), reason ? { reason } : {}); notify('Endereço terminado.'); setEnding(null); list.reload() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  const manage = person.capabilities.can_manage_addresses
  return <section className="card stack"><div className="section-head"><h2>Endereços</h2><div className="row wrap"><label className="checkbox"><input type="checkbox" checked={history} onChange={(e) => setHistory(e.target.checked)} /> Mostrar histórico</label>{manage && <button className="btn btn--primary" type="button" onClick={() => setEditing('new')}>Adicionar endereço</button>}</div></div>
    {list.loading && <LoadingState />}
    {list.error && <ErrorState error={list.error} retry={list.reload} />}
    {list.data && list.data.data.length === 0 && <EmptyState title="Sem endereços" message="Não existem endereços activos registados." />}
    {list.data && list.data.data.length > 0 && <ul className="person-list">{list.data.data.map((a) => <li key={a.ref} className="person-list__item"><div className="person-list__main"><strong>{a.line1}</strong><span className="muted">{[a.locality, a.municipality?.name, a.province?.name, a.country_code].filter(Boolean).join(' · ')}</span><span className="muted">{a.status === 'ACTIVE' ? `Desde ${formatDateTime(a.starts_at)}` : `Terminado ${formatDateTime(a.ends_at)}`}</span></div>{manage && a.status === 'ACTIVE' && <div className="person-list__actions"><button className="btn btn--secondary btn--sm" type="button" onClick={() => setEditing(a)}>Actualizar</button><button className="btn btn--ghost btn--sm" type="button" onClick={() => { setError(null); setEnding(a) }}>Terminar</button></div>}</li>)}</ul>}
    {editing !== null && <AddressDialog key={editing === 'new' ? 'new' : editing.ref} person={person} address={editing === 'new' ? null : editing} open onClose={() => setEditing(null)} onDone={() => { setEditing(null); list.reload() }} />}
    <EndDialog open={ending !== null} title="Terminar endereço" message="O endereço deixa de estar activo e fica no histórico." onClose={() => setEnding(null)} onConfirm={end} busy={busy} error={error} />
  </section>
}

export function FamilyPage() {
  return <PersonFrame>{(person) => <FamilyArea person={person} />}</PersonFrame>
}

function FamilyArea({ person }: { person: PersonDetail }) {
  const allowed = person.capabilities.can_view_households
  const list = usePeopleList<HouseholdSummary>(allowed ? pe.personHouseholds(person.public_id) : null)
  if (!allowed) return <AreaRestricted title="Família" />
  return <section className="card stack"><div className="section-head"><h2>Família</h2>{person.capabilities.can_manage_households && person.status !== 'DECEASED' && <Link className="btn btn--primary" to={`/familias/nova?pessoa=${encodeURIComponent(person.public_id)}`}>Criar família com esta pessoa</Link>}</div>
    {list.loading && <LoadingState />}
    {list.error && <ErrorState error={list.error} retry={list.reload} />}
    {list.data && list.data.data.length === 0 && <EmptyState title="Sem família" message="Esta pessoa não é membro activo de nenhum agregado visível." />}
    {list.data && list.data.data.length > 0 && <ul className="person-list">{list.data.data.map((h) => <li key={h.public_id} className="person-list__item"><div className="person-list__main"><Link to={`/familias/${h.public_id}`}><strong>{h.name ?? 'Família sem nome'}</strong></Link><span className="muted">{h.role_name} · {HOUSEHOLD_STATUS_LABEL[h.status] ?? h.status}</span></div></li>)}</ul>}
  </section>
}

function RelationshipDialog({ person, open, onClose, onDone }: { person: PersonDetail; open: boolean; onClose: () => void; onDone: () => void }) {
  const { notify } = useApp()
  const catalogs = useCatalogs()
  const [related, setRelated] = useState<SelectorPerson | null>(null)
  const [type, setType] = useState('SPOUSE')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!related) { setError({ kind: 'validation', title: 'Dados em falta', message: 'Seleccione a outra pessoa da relação.', fields: {}, retryable: false }); return }
    setBusy(true); setError(null)
    try { await peoplePost(pe.relationships(person.public_id), { related_person: related.public_id, type }); notify('Relação registada.'); onDone() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  const types = catalogs.data?.relationship_types ?? []
  const chosen = types.find((t) => t.code === type)
  return <Dialog open={open} title="Registar relação" onClose={onClose}><ActionForm submitLabel="Registar relação" busy={busy} error={error} onSubmit={submit}>
    <Field label={`${person.display_name} é`} name="relationship_type" required hint={chosen?.semantics === 'INVERSE_PAIRED' ? 'A relação inversa é registada automaticamente para a outra pessoa.' : 'Relação recíproca: é registada uma única vez para as duas pessoas.'}><select id="relationship_type" className="select" value={type} onChange={(e) => setType(e.target.value)} disabled={!catalogs.data}>{!catalogs.data && <option value="">A carregar…</option>}{types.map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}</select></Field>
    <PersonPicker purpose="relationship" label="de" selected={related} onSelect={setRelated} exclude={[person.public_id]} />
    {type === 'GUARDIAN' && <p className="muted">Registo factual. Não concede qualquer autorização no domínio de crianças.</p>}
  </ActionForm></Dialog>
}

export function RelationshipsPage() {
  return <PersonFrame>{(person) => <RelationshipsArea person={person} />}</PersonFrame>
}

function RelationshipsArea({ person }: { person: PersonDetail }) {
  const { notify } = useApp()
  const allowed = person.capabilities.can_view_relationships
  const [history, setHistory] = useState(false)
  const list = usePeopleList<Relationship>(allowed ? pe.relationships(person.public_id) : null, history ? { include_history: 1 } : {})
  const [adding, setAdding] = useState(false)
  const [ending, setEnding] = useState<Relationship | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  if (!allowed) return <AreaRestricted title="Relações" />
  async function end(reason: string) {
    if (!ending) return
    setBusy(true); setError(null)
    try { await peoplePost(pe.relationshipEnd(person.public_id, ending.ref), reason ? { reason } : {}); notify('Relação terminada.'); setEnding(null); list.reload() } catch (failure) { setError(toPeopleError(failure)) } finally { setBusy(false) }
  }
  const manage = person.capabilities.can_manage_relationships
  return <section className="card stack"><div className="section-head"><h2>Relações</h2><div className="row wrap"><label className="checkbox"><input type="checkbox" checked={history} onChange={(e) => setHistory(e.target.checked)} /> Mostrar histórico</label>{manage && <button className="btn btn--primary" type="button" onClick={() => setAdding(true)}>Registar relação</button>}</div></div>
    <p className="muted">Relações factuais. Só são apresentadas pessoas que pode consultar.</p>
    {list.loading && <LoadingState />}
    {list.error && <ErrorState error={list.error} retry={list.reload} />}
    {list.data && list.data.data.length === 0 && <EmptyState title="Sem relações" message="Não existem relações activas registadas." />}
    {list.data && list.data.data.length > 0 && <ul className="person-list">{list.data.data.map((r) => <li key={r.ref} className="person-list__item"><div className="person-list__main"><span className="muted">{r.type_name}</span><Link to={`/pessoas/${r.person.public_id}`}><strong>{r.person.display_name}</strong></Link><span className="muted">{ageBandLabel(r.person.age_band)} · {r.status === 'ACTIVE' ? `desde ${formatDateTime(r.starts_at)}` : `terminada ${formatDateTime(r.ends_at)}`}</span></div>{manage && r.status === 'ACTIVE' && <div className="person-list__actions"><button className="btn btn--ghost btn--sm" type="button" onClick={() => { setError(null); setEnding(r) }}>Terminar</button></div>}</li>)}</ul>}
    {adding && <RelationshipDialog person={person} open onClose={() => setAdding(false)} onDone={() => { setAdding(false); list.reload() }} />}
    <EndDialog open={ending !== null} title="Terminar relação" message="A relação e a sua relação inversa deixam de estar activas. O histórico é preservado." onClose={() => setEnding(null)} onConfirm={end} busy={busy} error={error} />
  </section>
}
