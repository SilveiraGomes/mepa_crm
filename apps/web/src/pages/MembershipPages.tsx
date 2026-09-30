import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useMembershipItem, useMembershipPage } from '../hooks/useMembership'
import { membershipGet, membershipPost } from '../lib/membership/client'
import { mb } from '../lib/membership/endpoints'
import { collectiveItems, itemMessage, toMembershipError, type CollectiveItemError } from '../lib/membership/errors'
import { peopleGet } from '../lib/people/client'
import { pe } from '../lib/people/endpoints'
import { formatDateTime } from '../lib/format'
import { isAbort, type UiError } from '../lib/academy/errors'
import type { Item, Paginated } from '../types/academy'
import type { PersonSummary } from '../types/people'
import type { CollectiveResult, LegacyIdentifier, MembershipDetail, MembershipPeriod, MembershipStatus, MembershipSummary, MembershipTransfer, Milestone, Precision, TransferAction } from '../types/membership'

const TONE: Record<string, string> = { ACTIVE: ' badge--info', VALIDATED: ' badge--info', INACTIVE: ' badge--warning', ENDED: ' badge--warning', REJECTED: ' badge--danger', WITHDRAWN: '', SUBMITTED: '', COMPLETED: ' badge--info', CANCELLED: '', CONFLICT: ' badge--danger', REVOKED: ' badge--warning' }
const PRECISION_LABEL: Record<Precision, string> = { EXACT: 'Data exacta', MONTH: 'Mês e ano', YEAR: 'Só o ano', UNKNOWN: 'Desconhecida' }

export function MemberBadge({ value, label }: { value: string; label?: string }) {
  return <span className={`badge${TONE[value] ?? ''}`}>{label ?? value}</span>
}

function DeceasedBadge() {
  return <span className="badge badge--warning">Falecido(a)</span>
}

function NumberValue({ value }: { value: string | null }) {
  return value ? <span className="mono member-number">{value}</span> : <span className="muted">Sem número</span>
}

function dateLabel(value: string | null, precision: Precision): string {
  if (precision === 'UNKNOWN' || !value) return 'Desconhecida'
  if (precision === 'YEAR') return value
  if (precision === 'MONTH') { const [year, month] = value.split('-'); return `${month}/${year}` }
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

/** Confirmation built into the page (no browser dialog), with a reason that the API may require. */
function ActionDialog({ open, title, message, submitLabel, reasonRequired, onClose, onConfirm, children }: { open: boolean; title: string; message: string; submitLabel: string; reasonRequired: boolean; onClose: () => void; onConfirm: (reason: string, form: FormData) => Promise<void>; children?: ReactNode }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true); setError(null)
    try { await onConfirm(String(form.get('reason') ?? '').trim(), form) } catch (failure) { setError(toMembershipError(failure)) } finally { setBusy(false) }
  }
  const id = `reason-${title.normalize('NFD').replace(/[^A-Za-z0-9]+/g, '-').toLowerCase()}`
  return <Dialog open={open} title={title} onClose={onClose}><ActionForm submitLabel={submitLabel} busy={busy} error={error} onSubmit={submit}><p>{message}</p>{children}<Field label={reasonRequired ? 'Motivo' : 'Motivo (opcional)'} name={id} required={reasonRequired} error={error?.fields.reason}><textarea className="textarea" id={id} name="reason" required={reasonRequired} minLength={reasonRequired ? 3 : undefined} maxLength={2000} /></Field></ActionForm></Dialog>
}

function CongregationSelect({ name, permission, label, exclude, required = true }: { name: string; permission: string; label: string; exclude?: string | null; required?: boolean }) {
  const { membership } = useApp()
  const options = (membership.context?.congregations ?? []).filter((unit) => unit.permissions.includes(permission) && unit.public_id !== exclude)
  return <Field label={label} name={name} required={required} hint={membership.known && options.length === 0 ? 'Não existem Congregações activas no seu escopo para esta operação.' : undefined}><select className="select" id={name} name={name} required={required} disabled={!membership.known}><option value="">{membership.known ? 'Seleccione' : 'A carregar…'}</option>{options.map((unit) => <option key={unit.public_id} value={unit.public_id}>{unit.name}</option>)}</select></Field>
}

/** Date + precision without inventing a day or month: the input follows the chosen precision. */
function PrecisionDate({ prefix, label, allowEmpty = false }: { prefix: string; label: string; allowEmpty?: boolean }) {
  const [precision, setPrecision] = useState<Precision | ''>(allowEmpty ? '' : 'EXACT')
  const today = new Date().toISOString().slice(0, 10)
  return <fieldset className="fieldset"><legend>{label}</legend><div className="form-grid">
    <Field label="Precisão" name={`${prefix}_precision`}><select className="select" id={`${prefix}_precision`} name={`${prefix}_precision`} value={precision} onChange={(event) => setPrecision(event.target.value as Precision | '')}>{allowEmpty && <option value="">Data da aprovação (hoje)</option>}{(['EXACT', 'MONTH', 'YEAR', 'UNKNOWN'] as Precision[]).map((code) => <option key={code} value={code}>{PRECISION_LABEL[code]}</option>)}</select></Field>
    {precision === 'EXACT' && <Field label="Data" name={`${prefix}_value`} required><input className="input" id={`${prefix}_value`} name={`${prefix}_value`} type="date" max={today} required /></Field>}
    {precision === 'MONTH' && <Field label="Mês" name={`${prefix}_value`} required><input className="input" id={`${prefix}_value`} name={`${prefix}_value`} type="month" max={today.slice(0, 7)} required /></Field>}
    {precision === 'YEAR' && <Field label="Ano" name={`${prefix}_value`} required><input className="input" id={`${prefix}_value`} name={`${prefix}_value`} type="number" min={1900} max={Number(today.slice(0, 4))} inputMode="numeric" required /></Field>}
  </div></fieldset>
}

function readDate(form: FormData, prefix: string): { value: string | null; precision: string | null } {
  const precision = String(form.get(`${prefix}_precision`) ?? '')
  if (precision === '') return { value: null, precision: null }
  return { value: precision === 'UNKNOWN' ? null : String(form.get(`${prefix}_value`) ?? '').trim() || null, precision }
}

const optional = (form: FormData, key: string) => { const value = String(form.get(key) ?? '').trim(); return value === '' ? null : value }

function DocumentField({ name = 'source_document' }: { name?: string }) {
  return <Field label="Documento de suporte (opcional)" name={name} hint="Identificador público do documento (acta, resolução ou ofício). Nunca é obrigatório."><input className="input mono" id={name} name={name} maxLength={26} autoComplete="off" pattern="[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}" /></Field>
}

// ---- Membros ------------------------------------------------------------------------------------------------------

export function MembersPage() {
  const { membership } = useApp()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [congregation, setCongregation] = useState('')
  const result = useMembershipPage<MembershipSummary>(mb.list(), { page, per_page: 50, ...(search ? { search } : {}), ...(status ? { status } : {}), ...(congregation ? { congregation_public_id: congregation } : {}) })
  const canAdmit = membership.has('MEMBERSHIP_ADMISSION_MANAGE') || membership.has('MEMBERSHIP_APPROVE')
  return <>
    <PageHeader title="Membros" description={membership.context ? `${membership.context.counts.active_members} membro(s) activo(s) no seu escopo.` : 'Membros e candidaturas no seu escopo.'} actions={<div className="member-actions">
      {canAdmit && <Link className="btn btn--primary" to="/membros/admissoes">Admissões</Link>}
      {(membership.has('MEMBERSHIP_TRANSFER') || membership.has('MEMBERSHIP_VIEW')) && <Link className="btn btn--secondary" to="/membros/transferencias">Transferências</Link>}
    </div>} />
    <form className="card member-filters" role="search" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(draft.trim()) }}>
      <Field label="Nome, número oficial ou identificador anterior" name="member-search"><input className="input" id="member-search" value={draft} onChange={(event) => setDraft(event.target.value)} maxLength={100} autoComplete="off" /></Field>
      <Field label="Estado" name="member-status"><select className="select" id="member-status" value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }}><option value="">Todos</option>{(membership.context?.statuses ?? []).map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}</select></Field>
      <Field label="Congregação" name="member-congregation"><select className="select" id="member-congregation" value={congregation} onChange={(event) => { setPage(1); setCongregation(event.target.value) }}><option value="">Todas</option>{(membership.context?.congregations ?? []).map((unit) => <option key={unit.public_id} value={unit.public_id}>{unit.name}</option>)}</select></Field>
      <button className="btn btn--secondary" type="submit">Pesquisar</button>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem membros" message="Nenhum membro ou candidatura corresponde a estes filtros no seu escopo." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'name', label: 'Pessoa', render: (row) => <span className="member-name"><Link to={`/membros/${row.public_id}`}>{row.person.display_name}</Link>{row.deceased && <> <DeceasedBadge /></>}</span> },
      { key: 'number', label: 'Número oficial', render: (row) => <NumberValue value={row.member_number} /> },
      { key: 'congregation', label: 'Congregação', render: (row) => row.congregation?.name ?? '—' },
      { key: 'status', label: 'Estado', render: (row) => <MemberBadge value={row.status} label={row.status_label} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

// ---- Admissões ------------------------------------------------------------------------------------------------------

type QueueAction = { kind: 'validate' | 'approve' | 'reject' | 'withdraw'; row: MembershipSummary } | null

function PersonPicker({ onPick }: { onPick: (person: PersonSummary | null) => void }) {
  const [term, setTerm] = useState('')
  const [results, setResults] = useState<PersonSummary[]>([])
  const [picked, setPicked] = useState<PersonSummary | null>(null)
  const [error, setError] = useState<UiError | null>(null)
  useEffect(() => {
    if (term.trim().length < 2 || picked) { setResults([]); return }
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      peopleGet<Paginated<PersonSummary>>(pe.people(), { search: term.trim(), per_page: 10 }, controller.signal).then((page) => { setResults(page.data); setError(null) }, (failure) => { if (!isAbort(failure)) setError(toMembershipError(failure)) })
    }, 250)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [term, picked])
  if (picked) return <div className="member-picked"><span><strong>{picked.display_name}</strong>{picked.protected_minor && <span className="muted"> · menor protegido</span>}</span><button className="btn btn--ghost btn--sm" type="button" onClick={() => { setPicked(null); onPick(null) }}>Alterar</button></div>
  return <div className="stack">
    <Field label="Pessoa existente" name="person-search" required hint="A Pessoa é criada primeiro em Pessoas. A Membresia nunca cria Pessoas."><input className="input" id="person-search" value={term} onChange={(event) => setTerm(event.target.value)} autoComplete="off" maxLength={100} placeholder="Nome da pessoa" /></Field>
    {error && <ErrorState error={error} />}
    {results.length > 0 && <ul className="person-list" aria-label="Pessoas encontradas">{results.map((person) => <li key={person.public_id} className="person-list__item"><div className="person-list__main"><strong>{person.display_name}</strong><span className="muted">{person.status}</span></div><div className="person-list__actions"><button className="btn btn--secondary btn--sm" type="button" onClick={() => { setPicked(person); onPick(person) }}>Seleccionar</button></div></li>)}</ul>}
  </div>
}

export function AdmissionsPage() {
  const { membership, notify } = useApp()
  const [page, setPage] = useState(1)
  const [person, setPerson] = useState<PersonSummary | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const [action, setAction] = useState<QueueAction>(null)
  const [formKey, setFormKey] = useState(0)
  const queue = useMembershipPage<MembershipSummary>(mb.list(), { queue: 1, page, per_page: 50 })
  const canSubmit = membership.has('MEMBERSHIP_ADMISSION_MANAGE')
  const canApprove = membership.has('MEMBERSHIP_APPROVE')
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!person) { setError({ kind: 'validation', title: 'Pessoa em falta', message: 'Seleccione uma Pessoa existente.', fields: {}, retryable: false }); return }
    const form = new FormData(event.currentTarget)
    setBusy(true); setError(null)
    try {
      await membershipPost<Item<MembershipDetail>>(mb.submit(), { person_public_id: person.public_id, congregation_public_id: form.get('congregation_public_id'), origin: form.get('origin') || 'ADMISSION', reason: optional(form, 'reason'), source_document: optional(form, 'source_document') })
      notify('Candidatura submetida. Nenhum número é emitido antes da aprovação.')
      setPerson(null); setFormKey((value) => value + 1); queue.reload()
    } catch (failure) { setError(toMembershipError(failure)) } finally { setBusy(false) }
  }
  async function transition(kind: 'validate' | 'approve' | 'reject' | 'withdraw', row: MembershipSummary, reason: string, form: FormData) {
    const date = readDate(form, 'admitted')
    const body: Record<string, unknown> = { lock_version: row.lock_version, reason: reason || null, source_document: optional(form, 'source_document') }
    if (kind === 'approve' && date.precision) { body.admitted_on = date.value; body.admitted_on_precision = date.precision }
    const result = await membershipPost<Item<MembershipDetail>>(mb.action(row.public_id, kind), body)
    notify(kind === 'approve' ? `Membro aprovado com o número ${result.data.member_number}.` : 'Candidatura actualizada.')
    setAction(null); queue.reload(); membership.refresh()
  }
  const current = action?.row ?? null
  return <>
    <PageHeader title="Admissões" description="Candidaturas submetidas e validadas. O número oficial só é emitido na aprovação." back={{ to: '/membros', label: 'Membros' }} actions={canApprove ? <Link className="btn btn--secondary" to="/membros/admissoes/colectiva">Aprovação colectiva</Link> : undefined} />
    {canSubmit && <section className="card stack" aria-labelledby="submit-title"><h2 id="submit-title">Nova candidatura</h2>
      <ActionForm key={formKey} submitLabel="Submeter candidatura" busy={busy} error={error} onSubmit={submit}>
        <PersonPicker onPick={setPerson} />
        <div className="form-grid"><CongregationSelect name="congregation_public_id" permission="MEMBERSHIP_ADMISSION_MANAGE" label="Congregação de admissão" />
          <Field label="Origem" name="origin"><select className="select" id="origin" name="origin" defaultValue="ADMISSION"><option value="ADMISSION">Admissão</option><option value="LEGACY_IMPORT">Regularização de membro histórico</option></select></Field></div>
        <DocumentField />
        <Field label="Observação (opcional)" name="submit-reason"><textarea className="textarea" id="submit-reason" name="reason" maxLength={2000} /></Field>
      </ActionForm></section>}
    <section className="stack" aria-labelledby="queue-title"><h2 id="queue-title">Fila de candidaturas</h2>
      {queue.loading && <LoadingState />}
      {queue.error && <ErrorState error={queue.error} retry={queue.reload} />}
      {queue.data && queue.data.data.length === 0 && <EmptyState title="Sem candidaturas" message="Não existem candidaturas submetidas ou validadas no seu escopo." />}
      {queue.data && queue.data.data.length > 0 && <div className="card"><DataTable rows={queue.data.data} rowKey={(row) => row.public_id} columns={[
        { key: 'name', label: 'Pessoa', render: (row) => <span className="member-name"><Link to={`/membros/${row.public_id}`}>{row.person.display_name}</Link>{row.deceased && <> <DeceasedBadge /></>}</span> },
        { key: 'congregation', label: 'Congregação', render: (row) => row.congregation?.name ?? '—' },
        { key: 'status', label: 'Estado', render: (row) => <MemberBadge value={row.status} label={row.status_label} /> },
        { key: 'actions', label: 'Acções', render: (row) => <div className="member-actions">
          {canSubmit && row.status === 'SUBMITTED' && <button className="btn btn--secondary btn--sm" type="button" onClick={() => setAction({ kind: 'validate', row })}>Validar</button>}
          {canApprove && row.status === 'VALIDATED' && <button className="btn btn--primary btn--sm" type="button" onClick={() => setAction({ kind: 'approve', row })}>Aprovar</button>}
          {canApprove && row.status === 'VALIDATED' && <button className="btn btn--ghost btn--sm" type="button" onClick={() => setAction({ kind: 'reject', row })}>Não aprovar</button>}
          {canSubmit && <button className="btn btn--ghost btn--sm" type="button" onClick={() => setAction({ kind: 'withdraw', row })}>Retirar</button>}
        </div> },
      ]} /><Pagination meta={queue.data.meta} onPage={setPage} /></div>}
    </section>
    <ActionDialog open={action?.kind === 'validate'} title="Validar candidatura" message={`A candidatura de ${current?.person.display_name ?? ''} passa a validada. Não é emitido número.`} submitLabel="Validar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => transition('validate', current!, reason, form)} />
    <ActionDialog key={`approve-${current?.public_id ?? 'none'}`} open={action?.kind === 'approve'} title="Aprovar admissão" message={`${current?.person.display_name ?? ''} passa a membro activo e recebe o número oficial nacional nesta operação.`} submitLabel="Aprovar e emitir número" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => transition('approve', current!, reason, form)}><PrecisionDate prefix="admitted" label="Data institucional da admissão" allowEmpty /><DocumentField /></ActionDialog>
    <ActionDialog open={action?.kind === 'reject'} title="Não aprovar candidatura" message="A candidatura fica registada como não aprovada. Nenhum número é emitido." submitLabel="Não aprovar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => transition('reject', current!, reason, form)} />
    <ActionDialog open={action?.kind === 'withdraw'} title="Retirar candidatura" message="A candidatura é retirada. Pode ser submetida de novo mais tarde." submitLabel="Retirar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => transition('withdraw', current!, reason, form)} />
  </>
}

// ---- Aprovação colectiva ----------------------------------------------------------------------------------------------

export function CollectiveAdmissionPage() {
  const { membership, notify } = useApp()
  const max = membership.context?.collective_max ?? 200
  const validated = useMembershipPage<MembershipSummary>(mb.list(), { status: 'VALIDATED', per_page: 100 })
  const [order, setOrder] = useState<MembershipSummary[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const [items, setItems] = useState<CollectiveItemError[]>([])
  const [done, setDone] = useState<CollectiveResult | null>(null)
  const selected = new Set(order.map((row) => row.public_id))
  function toggle(row: MembershipSummary) {
    setOrder((current) => current.some((item) => item.public_id === row.public_id) ? current.filter((item) => item.public_id !== row.public_id) : current.length >= max ? current : [...current, row])
  }
  function move(index: number, delta: number) {
    setOrder((current) => { const next = [...current]; const target = index + delta; if (target < 0 || target >= next.length) return current; [next[index], next[target]] = [next[target], next[index]]; return next })
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const date = readDate(form, 'admitted')
    setBusy(true); setError(null); setItems([])
    try {
      const result = await membershipPost<Item<CollectiveResult>>(mb.collective(), { memberships: order.map((row) => row.public_id), ...(date.precision ? { admitted_on: date.value, admitted_on_precision: date.precision } : {}), source_document: optional(form, 'source_document'), reason: optional(form, 'reason') })
      setDone(result.data); setOrder([]); validated.reload(); membership.refresh()
      notify(`${result.data.count} membro(s) aprovado(s) numa única operação.`)
    } catch (failure) { setError(toMembershipError(failure)); setItems(collectiveItems(failure)) } finally { setBusy(false) }
  }
  const byId = new Map(order.map((row) => [row.public_id, row]))
  return <>
    <PageHeader title="Aprovação colectiva" description={`Até ${max} candidaturas validadas, aprovadas numa única operação: ou todas, ou nenhuma. Os números seguem a ordem da lista (a da acta).`} back={{ to: '/membros/admissoes', label: 'Admissões' }} />
    {done && <section className="card stack" aria-labelledby="done-title"><h2 id="done-title">Números emitidos</h2><DataTable rows={done.approved} rowKey={(row) => row.public_id} columns={[
      { key: 'order', label: 'Ordem', render: (row) => String(done.approved.indexOf(row) + 1) },
      { key: 'number', label: 'Número oficial', render: (row) => <Link to={`/membros/${row.public_id}`} className="mono member-number">{row.member_number}</Link> },
    ]} /></section>}
    <div className="collective">
      <section className="stack" aria-labelledby="pool-title"><h2 id="pool-title">Candidaturas validadas</h2>
        {validated.loading && <LoadingState />}
        {validated.error && <ErrorState error={validated.error} retry={validated.reload} />}
        {validated.data && validated.data.data.length === 0 && <EmptyState title="Sem candidaturas validadas" message="Não existem candidaturas validadas no seu escopo." />}
        {validated.data && validated.data.data.length > 0 && <ul className="person-list">{validated.data.data.map((row) => <li key={row.public_id} className="person-list__item"><label className="checkbox member-check"><input type="checkbox" checked={selected.has(row.public_id)} onChange={() => toggle(row)} /> <span><strong>{row.person.display_name}</strong><span className="muted"> · {row.congregation?.name}</span></span></label></li>)}</ul>}
      </section>
      <section className="card stack" aria-labelledby="order-title"><h2 id="order-title">Lista a aprovar ({order.length})</h2>
        {order.length === 0 && <p className="muted">Seleccione as candidaturas pela ordem em que constam na acta. Pode reordenar a lista.</p>}
        {order.length > 0 && <ol className="collective-order">{order.map((row, index) => {
          const failed = items.find((item) => item.public_id === row.public_id)
          return <li key={row.public_id} className={failed ? 'collective-order__item collective-order__item--error' : 'collective-order__item'}><span className="collective-order__name">{index + 1}. {row.person.display_name}{failed && <span className="badge badge--danger">{itemMessage(failed.code)}</span>}</span><span className="member-actions"><button className="btn btn--ghost btn--sm" type="button" aria-label={`Subir ${row.person.display_name}`} disabled={index === 0} onClick={() => move(index, -1)}>↑</button><button className="btn btn--ghost btn--sm" type="button" aria-label={`Descer ${row.person.display_name}`} disabled={index === order.length - 1} onClick={() => move(index, 1)}>↓</button><button className="btn btn--ghost btn--sm" type="button" onClick={() => toggle(row)}>Remover</button></span></li>
        })}</ol>}
        {items.some((item) => !byId.has(item.public_id)) && <p className="muted">Existem itens indisponíveis na lista.</p>}
        <ActionForm submitLabel={`Aprovar ${order.length} candidatura(s)`} busy={busy || order.length === 0} error={error} onSubmit={submit}>
          <PrecisionDate prefix="admitted" label="Data institucional comum (acta)" allowEmpty />
          <DocumentField />
          <Field label="Observação (opcional)" name="collective-reason"><textarea className="textarea" id="collective-reason" name="reason" maxLength={2000} /></Field>
        </ActionForm>
      </section>
    </div>
  </>
}

// ---- Detalhe ---------------------------------------------------------------------------------------------------------

type DetailAction = MembershipStatus | 'validate' | 'approve' | 'reject' | 'withdraw' | 'inactivate' | 'reactivate' | 'end' | 'readmit' | null

function MemberTabs({ id, active }: { id: string; active: 'detail' | 'history' | 'transfers' }) {
  return <nav className="tabs member-tabs" aria-label="Secções do membro">
    <Link className={active === 'detail' ? 'btn btn--primary btn--sm' : 'btn btn--ghost btn--sm'} aria-current={active === 'detail' ? 'page' : undefined} to={`/membros/${id}`}>Resumo</Link>
    <Link className={active === 'history' ? 'btn btn--primary btn--sm' : 'btn btn--ghost btn--sm'} aria-current={active === 'history' ? 'page' : undefined} to={`/membros/${id}/historico`}>Histórico</Link>
    <Link className={active === 'transfers' ? 'btn btn--primary btn--sm' : 'btn btn--ghost btn--sm'} aria-current={active === 'transfers' ? 'page' : undefined} to={`/membros/${id}/transferencias`}>Transferências</Link>
  </nav>
}

export function MemberDetailPage() {
  const { id } = useParams()
  const { notify, membership } = useApp()
  const result = useMembershipItem<MembershipDetail>(id ? mb.membership(id) : null)
  const [action, setAction] = useState<DetailAction>(null)
  if (result.loading && !result.data) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return <EmptyState />
  const m = result.data
  const can = (permission: string) => m.can.includes(permission)
  const alive = !m.deceased
  async function run(kind: 'validate' | 'approve' | 'reject' | 'withdraw' | 'inactivate' | 'reactivate' | 'end' | 'readmit', reason: string, form: FormData, message: string) {
    const date = readDate(form, 'admitted')
    const body: Record<string, unknown> = { lock_version: m.lock_version, reason: reason || null, source_document: optional(form, 'source_document') }
    if (kind === 'approve' && date.precision) { body.admitted_on = date.value; body.admitted_on_precision = date.precision }
    await membershipPost(mb.action(m.public_id, kind), body)
    notify(message); setAction(null); result.reload(); membership.refresh()
  }
  const busyTransfer = Boolean(m.open_transfer)
  return <>
    <PageHeader title={m.person.display_name} description={m.congregation ? `Congregação actual: ${m.congregation.name}` : undefined} back={{ to: '/membros', label: 'Membros' }} actions={<div className="member-actions">
      {alive && can('MEMBERSHIP_ADMISSION_MANAGE') && m.status === 'SUBMITTED' && <button className="btn btn--primary" type="button" onClick={() => setAction('validate')}>Validar</button>}
      {alive && can('MEMBERSHIP_APPROVE') && m.status === 'VALIDATED' && <button className="btn btn--primary" type="button" onClick={() => setAction('approve')}>Aprovar</button>}
      {can('MEMBERSHIP_APPROVE') && m.status === 'VALIDATED' && <button className="btn btn--ghost" type="button" onClick={() => setAction('reject')}>Não aprovar</button>}
      {can('MEMBERSHIP_ADMISSION_MANAGE') && (m.status === 'SUBMITTED' || m.status === 'VALIDATED') && <button className="btn btn--ghost" type="button" onClick={() => setAction('withdraw')}>Retirar</button>}
      {alive && can('MEMBERSHIP_MANAGE') && m.status === 'ACTIVE' && !busyTransfer && <button className="btn btn--secondary" type="button" onClick={() => setAction('inactivate')}>Inactivar</button>}
      {alive && can('MEMBERSHIP_MANAGE') && m.status === 'INACTIVE' && <button className="btn btn--secondary" type="button" onClick={() => setAction('reactivate')}>Reactivar</button>}
      {can('MEMBERSHIP_MANAGE') && (m.status === 'ACTIVE' || m.status === 'INACTIVE') && !busyTransfer && <button className="btn btn--danger" type="button" onClick={() => setAction('end')}>Terminar</button>}
      {alive && can('MEMBERSHIP_APPROVE') && m.status === 'ENDED' && <button className="btn btn--primary" type="button" onClick={() => setAction('readmit')}>Readmitir</button>}
    </div>} />
    <MemberTabs id={m.public_id} active="detail" />
    {m.deceased && <div className="alert alert--warning" role="note"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>Falecido(a)</strong><p>O histórico, o número e os marcos são preservados. Só é possível terminar a membresia.</p></div></div>}
    {m.open_transfer && <div className="alert alert--info" role="status"><span className="alert__icon" aria-hidden="true">i</span><div className="alert__body"><strong>Transferência em curso</strong><p>{m.open_transfer.status_label}: {m.open_transfer.origin?.name} → {m.open_transfer.destination?.name}. Inactivação e fim ficam bloqueados até à sua conclusão.</p><div className="alert__actions"><Link className="btn btn--secondary btn--sm" to={`/membros/${m.public_id}/transferencias`}>Ver transferência</Link></div></div></div>}
    <section className="card"><dl className="dl member-summary">
      <div className="dl__item"><dt>Estado</dt><dd><MemberBadge value={m.status} label={m.status_label} />{m.deceased && <> <DeceasedBadge /></>}</dd></div>
      <div className="dl__item"><dt>Número oficial</dt><dd><NumberValue value={m.member_number} /></dd></div>
      <div className="dl__item"><dt>Congregação actual</dt><dd>{m.congregation?.name ?? '—'}</dd></div>
      <div className="dl__item"><dt>Admissão</dt><dd>{m.approved_at ? dateLabel(m.admitted_on, m.admitted_on_precision) : 'Candidatura (sem admissão)'}</dd></div>
      <div className="dl__item"><dt>Aprovação</dt><dd>{m.approved_at ? formatDateTime(m.approved_at) : '—'}</dd></div>
      <div className="dl__item"><dt>Origem</dt><dd>{m.origin === 'LEGACY_IMPORT' ? 'Regularização de membro histórico' : 'Admissão'}</dd></div>
      <div className="dl__item"><dt>Documento</dt><dd>{m.source_document ? <span className="mono">{m.source_document.public_id}</span> : m.has_document ? 'Documento associado (sem acesso)' : 'Nenhum'}</dd></div>
      <div className="dl__item"><dt>Pessoa</dt><dd>{m.person.protected_minor ? 'Menor protegido' : m.person.status}</dd></div>
    </dl></section>
    <LegacySection member={m} onChanged={result.reload} />
    <MilestonesSection member={m} />
    <ActionDialog open={action === 'validate'} title="Validar candidatura" message="A candidatura passa a validada. Não é emitido número." submitLabel="Validar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => run('validate', reason, form, 'Candidatura validada.')} />
    <ActionDialog open={action === 'approve'} title="Aprovar admissão" message="A pessoa passa a membro activo e recebe o número oficial nacional nesta operação." submitLabel="Aprovar e emitir número" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => run('approve', reason, form, 'Membro aprovado.')}><PrecisionDate prefix="admitted" label="Data institucional da admissão" allowEmpty /><DocumentField /></ActionDialog>
    <ActionDialog open={action === 'reject'} title="Não aprovar candidatura" message="Nenhum número é emitido." submitLabel="Não aprovar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => run('reject', reason, form, 'Candidatura não aprovada.')} />
    <ActionDialog open={action === 'withdraw'} title="Retirar candidatura" message="A candidatura é retirada. Pode ser submetida de novo mais tarde." submitLabel="Retirar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => run('withdraw', reason, form, 'Candidatura retirada.')} />
    <ActionDialog open={action === 'inactivate'} title="Inactivar membro" message="Inactivação administrativa, reversível; não é sanção. O número é preservado." submitLabel="Inactivar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => run('inactivate', reason, form, 'Membro inactivado.')}><DocumentField /></ActionDialog>
    <ActionDialog open={action === 'reactivate'} title="Reactivar membro" message="O membro volta a activo com o mesmo número." submitLabel="Reactivar" reasonRequired={false} onClose={() => setAction(null)} onConfirm={(reason, form) => run('reactivate', reason, form, 'Membro reactivado.')}><DocumentField /></ActionDialog>
    <ActionDialog open={action === 'end'} title="Terminar membresia" message="A membresia termina (saída, falecimento ou outro fim). O número e o histórico ficam preservados." submitLabel="Terminar" reasonRequired onClose={() => setAction(null)} onConfirm={(reason, form) => run('end', reason, form, 'Membresia terminada.')}><DocumentField /></ActionDialog>
    <ActionDialog open={action === 'readmit'} title="Readmitir" message="O membro volta a activo com o MESMO número. Nenhum número novo é emitido." submitLabel="Readmitir" reasonRequired onClose={() => setAction(null)} onConfirm={(reason, form) => run('readmit', reason, form, 'Membro readmitido com o mesmo número.')}><DocumentField /></ActionDialog>
  </>
}

function LegacySection({ member, onChanged }: { member: MembershipDetail; onChanged: () => void }) {
  const { notify } = useApp()
  const list = useMembershipPage<LegacyIdentifier>(mb.legacy(member.public_id))
  const [open, setOpen] = useState<'add' | LegacyIdentifier | null>(null)
  const manage = member.can.includes('MEMBERSHIP_LEGACY_MANAGE')
  const revoking = open && open !== 'add' ? open : null
  function done(message: string) { notify(message); setOpen(null); list.reload(); onChanged() }
  return <section className="stack" aria-labelledby="legacy-title">
    <div className="section-head"><h2 id="legacy-title">Identificadores anteriores</h2>{manage && !member.deceased && <button className="btn btn--secondary" type="button" onClick={() => setOpen('add')}>Registar identificador</button>}</div>
    {list.loading && <LoadingState />}
    {list.error && <ErrorState error={list.error} retry={list.reload} />}
    {list.data && list.data.data.length === 0 && <EmptyState title="Sem identificadores anteriores" message="Os números antigos nunca substituem o número oficial." />}
    {list.data && list.data.data.length > 0 && <ul className="person-list">{list.data.data.map((row) => <li key={`${row.source_system}-${row.normalized_number}-${row.registered_at}`} className="person-list__item">
      <div className="person-list__main"><strong className="mono">{row.raw_number}</strong><span className="muted">{row.source_system} · normalizado {row.normalized_number}</span>{row.status === 'CONFLICT' && <span className="muted">{row.conflict?.detail_visible ? `Em conflito com ${row.conflict.memberships.length} outra(s) membresia(s).` : 'Identificador em conflito.'}</span>}</div>
      <div className="person-list__actions"><MemberBadge value={row.status} label={row.status_label} />{manage && row.status !== 'REVOKED' && <button className="btn btn--ghost btn--sm" type="button" onClick={() => setOpen(row)}>Revogar</button>}</div>
    </li>)}</ul>}
    <ActionDialog open={open === 'add'} title="Registar identificador anterior" message="O valor é guardado exactamente como recebido e pesquisável na forma normalizada. Um valor já existente noutra membresia fica em conflito, sem bloquear." submitLabel="Registar" reasonRequired={false} onClose={() => setOpen(null)} onConfirm={async (reason, form) => {
      await membershipPost(mb.legacy(member.public_id), { raw_number: String(form.get('raw_number') ?? ''), reason: reason || null })
      done('Identificador registado.')
    }}><Field label="Identificador anterior" name="raw_number" required><input className="input mono" id="raw_number" name="raw_number" required maxLength={191} autoComplete="off" /></Field></ActionDialog>
    <ActionDialog key={`revoke-${revoking?.normalized_number ?? 'none'}`} open={Boolean(revoking)} title="Revogar identificador" message="A linha fica preservada como revogada; o valor não é reciclado." submitLabel="Revogar" reasonRequired onClose={() => setOpen(null)} onConfirm={async (reason) => {
      if (!revoking) return
      await membershipPost(mb.legacyRevoke(member.public_id), { source_system: revoking.source_system, normalized_number: revoking.normalized_number, reason, lock_version: revoking.lock_version })
      done('Identificador revogado.')
    }} />
  </section>
}

function MilestonesSection({ member }: { member: MembershipDetail }) {
  const { notify, membership } = useApp()
  const list = useMembershipPage<Milestone>(mb.milestones(member.public_id))
  const [open, setOpen] = useState<'add' | Milestone | null>(null)
  const manage = member.can.includes('MEMBERSHIP_MANAGE') && !member.deceased
  const existing = new Set((list.data?.data ?? []).map((row) => row.type))
  const types = (membership.context?.milestone_types ?? []).filter((type) => !existing.has(type.code as Milestone['type']))
  const correcting = open && open !== 'add' ? open : null
  function done(message: string) { notify(message); setOpen(null); list.reload() }
  return <section className="stack" aria-labelledby="milestones-title">
    <div className="section-head"><h2 id="milestones-title">Marcos eclesiásticos</h2>{manage && types.length > 0 && <button className="btn btn--secondary" type="button" onClick={() => setOpen('add')}>Registar marco</button>}</div>
    {list.loading && <LoadingState />}
    {list.error && <ErrorState error={list.error} retry={list.reload} />}
    {list.data && list.data.data.length === 0 && <EmptyState title="Sem marcos" message="Conversão e baptismo ainda não registados." />}
    {list.data && list.data.data.length > 0 && <ul className="person-list">{list.data.data.map((row) => <li key={row.type} className="person-list__item"><div className="person-list__main"><strong>{row.type_label}</strong><span className="muted">{dateLabel(row.occurred_on, row.date_precision)} · {PRECISION_LABEL[row.date_precision]}{row.unit ? ` · ${row.unit.name}` : ''}</span></div><div className="person-list__actions">{manage && <button className="btn btn--ghost btn--sm" type="button" onClick={() => setOpen(row)}>Corrigir</button>}</div></li>)}</ul>}
    <ActionDialog open={open === 'add'} title="Registar marco" message="Um marco por tipo e por pessoa. A data nunca é inventada: escolha a precisão conhecida." submitLabel="Registar" reasonRequired={false} onClose={() => setOpen(null)} onConfirm={async (reason, form) => {
      const date = readDate(form, 'occurred')
      await membershipPost(mb.milestones(member.public_id), { type: form.get('type'), occurred_on: date.value, date_precision: date.precision ?? 'UNKNOWN', source_document: optional(form, 'source_document'), reason: reason || null })
      done('Marco registado.')
    }}><Field label="Tipo" name="type" required><select className="select" id="type" name="type" required>{types.map((type) => <option key={type.code} value={type.code}>{type.label}</option>)}</select></Field><PrecisionDate prefix="occurred" label="Data" /><DocumentField /></ActionDialog>
    <ActionDialog key={`correct-${correcting?.type ?? 'none'}`} open={Boolean(correcting)} title={`Corrigir ${correcting?.type_label ?? 'marco'}`} message="A correcção fica auditada com o valor anterior e o novo." submitLabel="Corrigir" reasonRequired onClose={() => setOpen(null)} onConfirm={async (reason, form) => {
      if (!correcting) return
      const date = readDate(form, 'occurred')
      await membershipPost(mb.milestoneCorrect(member.public_id, correcting.type), { occurred_on: date.value, date_precision: date.precision ?? 'UNKNOWN', reason, lock_version: correcting.lock_version })
      done('Marco corrigido.')
    }}><PrecisionDate prefix="occurred" label="Nova data" /></ActionDialog>
  </section>
}

// ---- Histórico -------------------------------------------------------------------------------------------------------

export function MemberHistoryPage() {
  const { id } = useParams()
  const member = useMembershipItem<MembershipDetail>(id ? mb.membership(id) : null)
  const [page, setPage] = useState(1)
  const periods = useMembershipPage<MembershipPeriod>(id ? mb.periods(id) : null, { page, per_page: 50 })
  if (member.loading && !member.data) return <LoadingState />
  if (member.error) return <ErrorState error={member.error} retry={member.reload} />
  if (!member.data) return <EmptyState />
  const m = member.data
  return <>
    <PageHeader title={`Histórico · ${m.person.display_name}`} description={m.member_number ? `Número oficial ${m.member_number}` : 'Candidatura sem número'} back={{ to: `/membros/${m.public_id}`, label: m.person.display_name }} />
    <MemberTabs id={m.public_id} active="history" />
    <section className="stack" aria-labelledby="periods-title"><h2 id="periods-title">Períodos (estado × Congregação)</h2>
      {periods.loading && <LoadingState />}
      {periods.error && <ErrorState error={periods.error} retry={periods.reload} />}
      {periods.data && <><ol className="member-timeline">{periods.data.data.map((row) => <li key={`${row.starts_at}-${row.status}`} className={row.open ? 'member-timeline__item member-timeline__item--open' : 'member-timeline__item'}>
        <div className="member-timeline__head"><MemberBadge value={row.status} label={row.status_label} /><strong>{row.congregation.name}</strong>{row.open && <span className="badge badge--info">Actual</span>}</div>
        <span className="muted">Desde {formatDateTime(row.starts_at)}{row.ends_at ? ` até ${formatDateTime(row.ends_at)}` : ''}</span>
        {row.reason && <span className="member-reason">{row.reason}</span>}
        {row.has_document && <span className="muted">Documento associado{row.source_document ? ` · ${row.source_document.public_id}` : ''}</span>}
      </li>)}</ol><Pagination meta={periods.data.meta} onPage={setPage} /></>}
    </section>
  </>
}

// ---- Transferências ---------------------------------------------------------------------------------------------------

const STAGE: Record<TransferAction, { path: 'validate-origin' | 'accept' | 'reject' | 'complete' | 'cancel'; label: string; title: string; message: string; tone: string }> = {
  validate_origin: { path: 'validate-origin', label: 'Validar origem', title: 'Validar pela origem', message: 'A Congregação de origem valida o pedido.', tone: 'btn--secondary' },
  accept: { path: 'accept', label: 'Aceitar', title: 'Aceitar no destino', message: 'A Congregação de destino aceita receber o membro.', tone: 'btn--secondary' },
  complete: { path: 'complete', label: 'Efectivar', title: 'Efectivar transferência', message: 'Numa única operação: fecha o período na origem, abre o período activo no destino e conclui a transferência. O número oficial não muda.', tone: 'btn--primary' },
  reject: { path: 'reject', label: 'Rejeitar', title: 'Rejeitar transferência', message: 'A transferência termina sem efeito. O membro fica na origem.', tone: 'btn--ghost' },
  cancel: { path: 'cancel', label: 'Cancelar', title: 'Cancelar transferência', message: 'A transferência termina sem efeito e liberta o membro para novo pedido.', tone: 'btn--ghost' },
}

function TransferList({ rows, onChanged, showPerson }: { rows: MembershipTransfer[]; onChanged: () => void; showPerson: boolean }) {
  const { notify } = useApp()
  const [action, setAction] = useState<{ transfer: MembershipTransfer; kind: TransferAction } | null>(null)
  const current = action ? STAGE[action.kind] : null
  return <>
    <ul className="person-list">{rows.map((row) => <li key={row.public_id} className="person-list__item">
      <div className="person-list__main">
        {showPerson && row.person && <strong>{row.person.display_name}</strong>}
        <span>{row.origin?.name ?? '—'} → {row.destination?.name ?? '—'}</span>
        <span className="muted">Pedido {formatDateTime(row.requested_at)}{row.effective_at ? ` · efectivada ${formatDateTime(row.effective_at)}` : row.closed_at ? ` · encerrada ${formatDateTime(row.closed_at)}` : ''}</span>
      </div>
      <div className="person-list__actions"><MemberBadge value={row.status} label={row.status_label} />{(row.actions ?? []).map((kind) => <button key={kind} className={`btn btn--sm ${STAGE[kind].tone}`} type="button" onClick={() => setAction({ transfer: row, kind })}>{STAGE[kind].label}</button>)}</div>
    </li>)}</ul>
    <ActionDialog key={`${action?.transfer.public_id ?? 'none'}-${action?.kind ?? ''}`} open={Boolean(action)} title={current?.title ?? ''} message={current?.message ?? ''} submitLabel={current?.label ?? 'Confirmar'} reasonRequired={false} onClose={() => setAction(null)} onConfirm={async (reason, form) => {
      if (!action || !current) return
      await membershipPost(mb.transferStage(action.transfer.public_id, current.path), { reason: reason || null, lock_version: action.transfer.lock_version, ...(action.kind === 'accept' ? { source_document: optional(form, 'source_document') } : {}) })
      notify('Transferência actualizada.'); setAction(null); onChanged()
    }}>{action?.kind === 'accept' && <DocumentField />}</ActionDialog>
  </>
}

export function MemberTransfersPage() {
  const { id } = useParams()
  const { notify, membership } = useApp()
  const member = useMembershipItem<MembershipDetail>(id ? mb.membership(id) : null)
  const [page, setPage] = useState(1)
  const transfers = useMembershipPage<MembershipTransfer>(id ? mb.transfersOf(id) : null, { page, per_page: 50 })
  const [requesting, setRequesting] = useState(false)
  const [detailed, setDetailed] = useState<Record<string, MembershipTransfer>>({})
  useEffect(() => {
    // Stage actions come from the transfer projection of the global endpoint (the actor's side of each transfer).
    const controller = new AbortController()
    for (const row of transfers.data?.data ?? []) {
      if (row.open) membershipGet<Item<MembershipTransfer>>(mb.transfer(row.public_id), {}, controller.signal).then((item) => setDetailed((current) => ({ ...current, [row.public_id]: item.data })), () => undefined)
    }
    return () => controller.abort()
  }, [transfers.data])
  if (member.loading && !member.data) return <LoadingState />
  if (member.error) return <ErrorState error={member.error} retry={member.reload} />
  if (!member.data) return <EmptyState />
  const m = member.data
  const canRequest = m.can.includes('MEMBERSHIP_TRANSFER') && m.status === 'ACTIVE' && !m.open_transfer && !m.deceased
  const rows = (transfers.data?.data ?? []).map((row) => detailed[row.public_id] ?? row)
  const reload = () => { transfers.reload(); member.reload(); setDetailed({}) }
  return <>
    <PageHeader title={`Transferências · ${m.person.display_name}`} description={m.member_number ? `Número oficial ${m.member_number} (não muda com a transferência)` : undefined} back={{ to: `/membros/${m.public_id}`, label: m.person.display_name }} actions={canRequest ? <button className="btn btn--primary" type="button" onClick={() => setRequesting(true)}>Pedir transferência</button> : undefined} />
    <MemberTabs id={m.public_id} active="transfers" />
    {transfers.loading && <LoadingState />}
    {transfers.error && <ErrorState error={transfers.error} retry={transfers.reload} />}
    {transfers.data && rows.length === 0 && <EmptyState title="Sem transferências" message="Esta membresia nunca foi transferida." />}
    {transfers.data && rows.length > 0 && <><TransferList rows={rows} onChanged={reload} showPerson={false} /><Pagination meta={transfers.data.meta} onPage={setPage} /></>}
    <ActionDialog open={requesting} title="Pedir transferência" message={`Origem: ${m.congregation?.name ?? '—'}. O pedido segue as etapas validação da origem, aceitação do destino e efectivação.`} submitLabel="Pedir transferência" reasonRequired={false} onClose={() => setRequesting(false)} onConfirm={async (reason, form) => {
      const destination = optional(form, 'destination_other') ?? optional(form, 'destination_public_id')
      await membershipPost(mb.transfersOf(m.public_id), { destination_public_id: destination, reason: reason || null, source_document: optional(form, 'source_document') })
      notify('Transferência pedida.'); setRequesting(false); reload(); membership.refresh()
    }}><CongregationSelect name="destination_public_id" permission="MEMBERSHIP_VIEW" label="Congregação de destino" exclude={m.congregation?.public_id} required={false} /><Field label="Ou identificador público da Congregação de destino" name="destination_other" hint="Para uma Congregação fora do seu escopo."><input className="input mono" id="destination_other" name="destination_other" maxLength={26} autoComplete="off" /></Field><DocumentField /></ActionDialog>
  </>
}

export function TransfersPage() {
  const [direction, setDirection] = useState<'all' | 'incoming' | 'outgoing'>('all')
  const [state, setState] = useState<'open' | 'closed' | 'all'>('open')
  const [page, setPage] = useState(1)
  const result = useMembershipPage<MembershipTransfer>(mb.transfers(), { direction, state, page, per_page: 50 })
  return <>
    <PageHeader title="Transferências" description="Recebidas e enviadas pelas Congregações do seu escopo, com as acções da etapa." back={{ to: '/membros', label: 'Membros' }} />
    <div className="card member-filters">
      <Field label="Sentido" name="transfer-direction"><select className="select" id="transfer-direction" value={direction} onChange={(event) => { setPage(1); setDirection(event.target.value as typeof direction) }}><option value="all">Todas</option><option value="incoming">Recebidas</option><option value="outgoing">Enviadas</option></select></Field>
      <Field label="Situação" name="transfer-state"><select className="select" id="transfer-state" value={state} onChange={(event) => { setPage(1); setState(event.target.value as typeof state) }}><option value="open">Em curso</option><option value="closed">Encerradas</option><option value="all">Todas</option></select></Field>
    </div>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem transferências" message="Não existem transferências com estes filtros." />}
    {result.data && result.data.data.length > 0 && <div className="card"><TransferList rows={result.data.data} onChanged={result.reload} showPerson /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}
