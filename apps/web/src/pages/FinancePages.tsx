import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFinanceItem, useFinancePage } from '../hooks/useFinance'
import { financeGet, financePost, newIdempotencyKey } from '../lib/finance/client'
import { fin } from '../lib/finance/endpoints'
import { toFinanceError } from '../lib/finance/errors'
import { formatKz } from '../lib/finance/format'
import { formatDate, formatDateTime } from '../lib/format'
import { isAbort, type UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { CustodyGroup, CustodyPosition, ReconciliationState, TransferDetail, TransferStatus, TransferSummary, TransitItem, UnitRef } from '../types/finance'

// P0.10-F1B Finanças: transferências interunidades e posição de fundos (custódia). Uma transferência interna NÃO é
// receita nem despesa: muda apenas quem gere os fundos (D-04A). Valores são strings decimais do servidor (nunca float).

const STATUS_LABEL: Record<TransferStatus, string> = { DRAFT: 'Rascunho', SENT: 'Enviada', RECEIVED: 'Recebida', CANCELLED: 'Cancelada' }
const STATUS_TONE: Record<TransferStatus, string> = { DRAFT: '', SENT: ' badge--warning', RECEIVED: ' badge--info', CANCELLED: '' }
const RECON_LABEL: Record<ReconciliationState, string> = { NOT_APPLICABLE: 'Não aplicável', IN_TRANSIT: 'Em trânsito', AWAITING_RECONCILIATION: 'Por reconciliar', RECONCILED: 'Reconciliada', RETURNED: 'Devolvida' }
const RECON_TONE: Record<ReconciliationState, string> = { NOT_APPLICABLE: '', IN_TRANSIT: ' badge--warning', AWAITING_RECONCILIATION: ' badge--warning', RECONCILED: ' badge--info', RETURNED: '' }
const STAGE_LABEL: Record<string, string> = { SEND: 'Envio (origem)', RECEIVE: 'Recepção (destino)', REVERSE_SEND: 'Devolução (origem)' }

function StatusBadge({ status }: { status: TransferStatus }) {
  return <span className={`badge${STATUS_TONE[status]}`}>{STATUS_LABEL[status]}</span>
}

function ReconBadge({ state }: { state: ReconciliationState }) {
  return <span className={`badge${RECON_TONE[state]}`}>{RECON_LABEL[state]}</span>
}

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })

function FinanceTabs({ active }: { active: 'sent' | 'received' | 'in_transit' | 'custody' }) {
  const tabs = [['sent', '/financas/transferencias', 'Enviadas'], ['received', '/financas/transferencias/recebidas', 'Recebidas'], ['in_transit', '/financas/transferencias/em-transito', 'Em trânsito'], ['custody', '/financas/posicao', 'Posição de fundos']] as const
  return <nav className="finance-tabs" aria-label="Secções de Finanças">{tabs.map(([key, to, label]) => <Link key={key} className={`btn btn--sm ${active === key ? 'btn--primary' : 'btn--secondary'}`} aria-current={active === key ? 'page' : undefined} to={to}>{label}</Link>)}</nav>
}

function UnitName({ unit }: { unit: UnitRef }) {
  return <span className="finance-unit">{unit.name}</span>
}

// ---- Transferências ----------------------------------------------------------------------------------------------

export function TransfersPage({ direction }: { direction: 'sent' | 'received' | 'in_transit' }) {
  const { finance } = useApp()
  const [page, setPage] = useState(1)
  const [unit, setUnit] = useState('')
  useEffect(() => setPage(1), [direction])
  const result = useFinancePage<TransferSummary>(fin.transfers(), { direction, page, per_page: 50, ...(unit ? { unit } : {}) })
  const title = direction === 'sent' ? 'Transferências enviadas' : direction === 'received' ? 'Transferências recebidas' : 'Fundos em trânsito'
  const description = direction === 'in_transit'
    ? 'Envios já registados na origem e ainda não recebidos no destino. Não são receita nem despesa.'
    : 'Movimentos de fundos entre unidades MEPA: mudam quem gere os fundos, nunca o resultado económico.'
  const units = (finance.context?.units ?? []).filter((item) => item.permissions.includes('FINANCE_VIEW'))
  return <>
    <PageHeader title={title} description={description} actions={finance.has('FINANCE_TRANSFER') ? <Link className="btn btn--primary" to="/financas/transferencias/nova">Nova transferência</Link> : undefined} />
    <FinanceTabs active={direction} />
    {units.length > 1 && <div className="card finance-filters"><Field label="Unidade" name="finance-unit"><select className="select" id="finance-unit" value={unit} onChange={(event) => { setPage(1); setUnit(event.target.value) }}><option value="">Todas no meu escopo</option>{units.map((item) => <option key={item.public_id} value={item.public_id}>{item.name}</option>)}</select></Field></div>}
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem transferências" message="Nenhuma transferência corresponde a esta vista no seu escopo." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'origin', label: 'Origem', render: (row) => <UnitName unit={row.origin} /> },
      { key: 'destination', label: 'Destino', render: (row) => <UnitName unit={row.destination} /> },
      { key: 'purpose', label: 'Finalidade', render: (row) => row.purpose.label },
      { key: 'amount', label: 'Valor', render: (row) => <Link className="finance-amount" to={`/financas/transferencias/${row.public_id}`}>{formatKz(row.amount)}</Link> },
      { key: 'sent', label: 'Enviada em', render: (row) => row.sent_at ? formatDate(row.sent_at) : '—' },
      { key: 'received', label: direction === 'in_transit' ? 'Idade' : 'Recebida em', render: (row) => direction === 'in_transit' ? `${row.age_days ?? 0} dia(s)` : (row.received_at ? formatDate(row.received_at) : '—') },
      { key: 'status', label: 'Estado', render: (row) => <span className="finance-badges"><StatusBadge status={row.status} /><ReconBadge state={row.reconciliation_state} /></span> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

export const SentTransfersPage = () => <TransfersPage direction="sent" />
export const ReceivedTransfersPage = () => <TransfersPage direction="received" />
export const InTransitPage = () => <TransfersPage direction="in_transit" />

function DestinationPicker({ exclude, onPick }: { exclude: string | null; onPick: (unit: UnitRef | null) => void }) {
  const [term, setTerm] = useState('')
  const [results, setResults] = useState<UnitRef[]>([])
  const [picked, setPicked] = useState<UnitRef | null>(null)
  const [error, setError] = useState<UiError | null>(null)
  useEffect(() => {
    if (term.trim().length < 2 || picked) { setResults([]); return }
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      financeGet<Item<{ items: UnitRef[] }>>(fin.units(), { search: term.trim() }, controller.signal).then((page) => { setResults(page.data.items.filter((unit) => unit.public_id !== exclude)); setError(null) }, (failure) => { if (!isAbort(failure)) setError(toFinanceError(failure)) })
    }, 250)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [term, picked, exclude])
  if (picked) return <div className="finance-picked"><span>Destino: <strong>{picked.name}</strong></span><button className="btn btn--ghost btn--sm" type="button" onClick={() => { setPicked(null); onPick(null) }}>Alterar</button></div>
  return <div className="stack">
    <Field label="Unidade de destino" name="destination-search" required hint="Qualquer unidade MEPA activa. Escreva pelo menos 2 letras do nome."><input className="input" id="destination-search" value={term} onChange={(event) => setTerm(event.target.value)} autoComplete="off" maxLength={100} placeholder="Nome da unidade" /></Field>
    {error && <ErrorState error={error} />}
    {results.length > 0 && <ul className="finance-results" aria-label="Unidades encontradas">{results.map((unit) => <li key={unit.public_id}><span className="finance-unit">{unit.name}</span><button className="btn btn--secondary btn--sm" type="button" onClick={() => { setPicked(unit); onPick(unit) }}>Seleccionar</button></li>)}</ul>}
  </div>
}

export function TransferCreatePage() {
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const key = useRef(newIdempotencyKey())
  const [destination, setDestination] = useState<UnitRef | null>(null)
  const [account, setAccount] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const transferUnits = new Set((finance.context?.units ?? []).filter((unit) => unit.permissions.includes('FINANCE_TRANSFER')).map((unit) => unit.public_id))
  const accounts = (finance.context?.accounts ?? []).filter((item) => transferUnits.has(item.unit))
  const originUnit = accounts.find((item) => item.public_id === account)?.unit ?? null
  const unitName = (id: string) => finance.context?.units.find((unit) => unit.public_id === id)?.name ?? ''
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    if (!destination) { setError({ kind: 'validation', title: 'Destino obrigatório', message: 'Seleccione a unidade de destino.', fields: {}, retryable: false }); return }
    setBusy(true); setError(null)
    try {
      const created = await financePost<Item<TransferDetail>>(fin.transfers(), { origin_account: account, destination_unit: destination.public_id, amount: String(form.get('amount') ?? '').trim().replace(',', '.'), purpose: String(form.get('purpose') ?? '') }, key.current)
      notify('Transferência preparada. Confirme o envio para registar a saída.')
      navigate(`/financas/transferencias/${created.data.public_id}`)
    } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Nova transferência" description="Preparar o envio de fundos para outra unidade MEPA. Nada é contabilizado até ao envio." back={{ to: '/financas/transferencias', label: 'Transferências' }} />
    <div className="card"><ActionForm submitLabel="Preparar transferência" busy={busy} error={error} onSubmit={submit}>
      <Field label="Conta de origem" name="origin_account" required hint={finance.known && accounts.length === 0 ? 'Não existem contas abertas nas unidades do seu escopo.' : undefined}><select className="select" id="origin_account" name="origin_account" required value={account} onChange={(event) => setAccount(event.target.value)}><option value="">{finance.known ? 'Seleccione' : 'A carregar…'}</option>{accounts.map((item) => <option key={item.public_id} value={item.public_id}>{unitName(item.unit)} · {item.name} ({item.kind === 'CASH' ? 'Caixa' : 'Banco'})</option>)}</select></Field>
      <DestinationPicker exclude={originUnit} onPick={setDestination} />
      <div className="form-grid">
        <Field label="Valor (Kz)" name="amount" required error={error?.fields.amount} hint="Máximo 2 casas decimais, por exemplo 60000.00"><input className="input finance-input-amount" id="amount" name="amount" inputMode="decimal" required pattern="\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
        <Field label="Finalidade" name="purpose" required hint="A finalidade classifica a remessa; não prova em que despesa o dinheiro será aplicado."><select className="select" id="purpose" name="purpose" required><option value="">Seleccione</option>{(finance.context?.purposes ?? []).map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}</select></Field>
      </div>
    </ActionForm></div>
  </>
}

// ---- Detalhe ----------------------------------------------------------------------------------------------------------

type Pending = 'send' | 'receive' | 'cancel' | 'reverse_send' | 'reconcile' | null

export function TransferDetailPage() {
  const { id = '' } = useParams()
  const { finance, notify } = useApp()
  const result = useFinanceItem<TransferDetail>(fin.transfer(id))
  const [pending, setPending] = useState<Pending>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const t = result.data
  const destinationAccounts = useMemo(() => (finance.context?.accounts ?? []).filter((item) => t && item.unit === t.destination.public_id), [finance.context, t])
  if (result.loading && !t) return <LoadingState />
  if (result.error) return <><PageHeader title="Transferência" back={{ to: '/financas/transferencias', label: 'Transferências' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!t) return null
  const close = () => { setPending(null); setError(null) }
  async function run(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const reason = String(form.get('reason') ?? '').trim()
    const stage = pending === 'reverse_send' ? 'reverse-send' : pending
    if (!stage || !t) return
    const body: Record<string, unknown> = { lock_version: t.lock_version }
    if (stage === 'receive') { body.destination_account = String(form.get('destination_account') ?? ''); const on = String(form.get('received_on') ?? ''); if (on) body.received_on = on }
    if (stage === 'send') { const on = String(form.get('sent_on') ?? ''); if (on) body.sent_on = on }
    if (stage === 'cancel' || stage === 'reverse-send') body.reason = reason
    setBusy(true); setError(null)
    try {
      await financePost(fin.stage(t.public_id, stage), body)
      notify({ send: 'Envio registado na origem. Os fundos ficam em trânsito até à recepção.', receive: 'Recepção registada no destino.', cancel: 'Transferência cancelada.', 'reverse-send': 'Envio devolvido: os fundos voltaram à conta de origem.', reconcile: 'Transferência reconciliada.' }[stage])
      close(); result.reload()
    } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  const actionLabel: Record<Exclude<Pending, null>, string> = { send: 'Confirmar envio', receive: 'Confirmar recepção', cancel: 'Cancelar transferência', reverse_send: 'Devolver envio', reconcile: 'Reconciliar' }
  return <>
    <PageHeader title={`Transferência ${formatKz(t.amount)}`} description="Movimento interno de fundos: não é receita nem despesa de nenhuma das unidades." back={{ to: '/financas/transferencias', label: 'Transferências' }} actions={<div className="finance-actions">{t.actions.map((action) => <button key={action} className={`btn ${action === 'send' || action === 'receive' || action === 'reconcile' ? 'btn--primary' : 'btn--secondary'}`} type="button" onClick={() => { setError(null); setPending(action) }}>{actionLabel[action]}</button>)}</div>} />
    <section className="card"><dl className="finance-summary">
      <div><dt>Origem</dt><dd>{t.origin.name}</dd></div>
      <div><dt>Destino</dt><dd>{t.destination.name}</dd></div>
      <div><dt>Finalidade</dt><dd>{t.purpose.label}{t.purpose.regular && <span className="muted"> · remessa regular</span>}</dd></div>
      <div><dt>Valor</dt><dd className="finance-amount">{formatKz(t.amount)}</dd></div>
      <div><dt>Data de envio</dt><dd>{t.sent_at ? formatDateTime(t.sent_at) : '—'}</dd></div>
      <div><dt>Data de recepção</dt><dd>{t.received_at ? formatDateTime(t.received_at) : '—'}</dd></div>
      <div><dt>Estado</dt><dd><StatusBadge status={t.status} /></dd></div>
      <div><dt>Reconciliação</dt><dd><ReconBadge state={t.reconciliation_state} />{t.age_days !== null && <span className="muted"> · {t.age_days} dia(s) em trânsito</span>}</dd></div>
      {t.origin_account && <div><dt>Conta de origem</dt><dd>{t.origin_account.name}</dd></div>}
      {t.destination_account && <div><dt>Conta de destino</dt><dd>{t.destination_account.name}</dd></div>}
      {t.cancel_reason && <div><dt>Motivo</dt><dd className="finance-reason">{t.cancel_reason}</dd></div>}
      <div><dt>Efeito económico</dt><dd>{formatKz(t.economic_effect)} (transferência interna)</dd></div>
    </dl></section>
    <section className="card stack"><h2>Histórico</h2>
      {t.stages.length === 0 ? <p className="muted">Ainda nada foi contabilizado: a transferência está em preparação.</p> : <ol className="finance-timeline">{t.stages.map((stage) => <li key={stage.stage} className="finance-timeline__item"><strong>{STAGE_LABEL[stage.stage] ?? stage.stage}</strong><span className="muted">Lançamento de {formatDate(stage.entry_date)} · registado {formatDateTime(stage.posted_at)}</span></li>)}</ol>}
      {t.pairing && t.pairing.length > 0 && <div className="alert alert--danger" role="alert"><div className="alert__body"><strong>Envio e recepção não correspondem</strong><p>A reconciliação está bloqueada até a divergência ser analisada.</p></div></div>}
    </section>
    <Dialog open={pending !== null} title={pending ? actionLabel[pending] : ''} onClose={close}>
      <ActionForm submitLabel={pending ? actionLabel[pending] : 'Confirmar'} busy={busy} error={error} onSubmit={run}>
        {pending === 'send' && <><p>Regista a saída de {formatKz(t.amount)} da conta de origem para {t.destination.name}. Fica em trânsito até à recepção.</p><Field label="Data do envio" name="sent_on" hint="Hoje por omissão."><input className="input" id="sent_on" name="sent_on" type="date" max={today()} /></Field></>}
        {pending === 'receive' && <><p>Regista a entrada de exactamente {formatKz(t.amount)} vindos de {t.origin.name}. Tarifas bancárias registam-se à parte.</p><Field label="Conta de destino" name="destination_account" required><select className="select" id="destination_account" name="destination_account" required><option value="">Seleccione</option>{destinationAccounts.map((item) => <option key={item.public_id} value={item.public_id}>{item.name} ({item.kind === 'CASH' ? 'Caixa' : 'Banco'})</option>)}</select></Field><Field label="Data da recepção" name="received_on" hint="Hoje por omissão. Se o mês estiver fechado, o lançamento vai para o primeiro mês aberto."><input className="input" id="received_on" name="received_on" type="date" max={today()} /></Field></>}
        {pending === 'reconcile' && <p>Confirma que o envio na origem e a recepção no destino correspondem (mesmo valor, finalidade e unidades).</p>}
        {(pending === 'cancel' || pending === 'reverse_send') && <><p>{pending === 'cancel' ? 'A transferência preparada é cancelada; nada foi contabilizado.' : 'O envio é devolvido à conta de origem. Só é possível antes da recepção.'}</p><Field label="Motivo" name="reason" required error={error?.fields.reason}><textarea className="textarea" id="reason" name="reason" required minLength={3} maxLength={2000} /></Field></>}
      </ActionForm>
    </Dialog>
  </>
}

// ---- Posição de fundos (custódia) ---------------------------------------------------------------------------------

function GroupTable({ rows, side }: { rows: CustodyGroup[]; side: 'origin' | 'destination' }) {
  if (rows.length === 0) return <p className="muted">Sem movimentos neste período.</p>
  return <DataTable rows={rows} rowKey={(row) => `${(row[side] as UnitRef).public_id}-${row.purpose.code}`} columns={[
    { key: 'unit', label: side === 'origin' ? 'Unidade de origem' : 'Unidade de destino', render: (row) => <UnitName unit={row[side] as UnitRef} /> },
    { key: 'purpose', label: 'Finalidade', render: (row) => row.purpose.label },
    { key: 'n', label: 'Transferências', render: (row) => row.transfers },
    { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{formatKz(row.amount)}</span> },
  ]} />
}

function TransitTable({ rows }: { rows: TransitItem[] }) {
  if (rows.length === 0) return <p className="muted">Nada em trânsito.</p>
  return <DataTable rows={rows} rowKey={(row) => row.transfer} columns={[
    { key: 'counterpart', label: 'Contraparte', render: (row) => <Link to={`/financas/transferencias/${row.transfer}`}>{row.counterpart.name}</Link> },
    { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{formatKz(row.amount)}</span> },
    { key: 'sent', label: 'Enviada em', render: (row) => formatDate(row.sent_at) },
    { key: 'age', label: 'Idade', render: (row) => `${row.age_days} dia(s)` },
  ]} />
}

export function CustodyPage() {
  const { finance } = useApp()
  const units = (finance.context?.units ?? []).filter((unit) => unit.permissions.includes('FINANCE_VIEW'))
  const [unit, setUnit] = useState('')
  const [from, setFrom] = useState(`${today().slice(0, 8)}01`)
  const [to, setTo] = useState(today())
  const selected = unit || units[0]?.public_id || ''
  const result = useFinanceItem<CustodyPosition>(selected ? fin.custody(selected) : null, { from, to })
  const c = result.data
  const rows: [string, string, ReactNode][] = c ? [
    ['a', 'Saldo inicial', formatKz(c.opening_balance)],
    ['b', '+ Fundos recebidos do exterior', formatKz(c.external_funds_received)],
    ['c', '+ Fundos internos recebidos', formatKz(c.internal_funds_received)],
    ['e', '− Aplicações e pagamentos', formatKz(c.external_applications)],
    ['f', '− Fundos internos enviados', formatKz(c.internal_funds_sent)],
    ['h', '= Saldo final sob gestão', <strong key="h">{formatKz(c.closing_balance)}</strong>],
  ] : []
  return <>
    <PageHeader title="Posição de fundos" description="De onde vieram e para onde foram os fundos geridos pela unidade. Base operacional do demonstrativo de origem e aplicação." />
    <FinanceTabs active="custody" />
    <form className="card finance-filters" onSubmit={(event) => event.preventDefault()}>
      <Field label="Unidade" name="custody-unit"><select className="select" id="custody-unit" value={selected} onChange={(event) => setUnit(event.target.value)}>{units.length === 0 && <option value="">{finance.known ? 'Sem unidades no seu escopo' : 'A carregar…'}</option>}{units.map((item) => <option key={item.public_id} value={item.public_id}>{item.name}</option>)}</select></Field>
      <Field label="De" name="custody-from"><input className="input" id="custody-from" type="date" value={from} max={to} onChange={(event) => event.target.value && setFrom(event.target.value)} /></Field>
      <Field label="Até" name="custody-to"><input className="input" id="custody-to" type="date" value={to} min={from} max={today()} onChange={(event) => event.target.value && setTo(event.target.value)} /></Field>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {c && <>
      <section className="card stack"><h2>Fundos sob gestão · {c.unit.name}</h2>
        <dl className="finance-custody">{rows.map(([k, label, value]) => <div key={k}><dt>{label}</dt><dd className="finance-amount">{value}</dd></div>)}</dl>
        {!c.balanced && <div className="alert alert--danger" role="alert"><div className="alert__body"><strong>Posição não equilibrada</strong><p>Contacte a contabilidade.</p></div></div>}
      </section>
      <section className="card stack"><h2>Resultado económico (separado da custódia)</h2>
        <p className="muted">As transferências internas nunca alteram o resultado: só receitas e gastos próprios contam.</p>
        <dl className="finance-custody"><div><dt>Receitas</dt><dd className="finance-amount">{formatKz(c.economic_result.income)}</dd></div><div><dt>Gastos</dt><dd className="finance-amount">{formatKz(c.economic_result.expense)}</dd></div><div><dt>Resultado</dt><dd className="finance-amount">{formatKz(c.economic_result.result)}</dd></div></dl>
      </section>
      <section className="card stack"><h2>Fundos internos recebidos · de onde vieram</h2><GroupTable rows={c.received_by_origin} side="origin" /></section>
      <section className="card stack"><h2>Fundos internos enviados · para onde foram</h2><GroupTable rows={c.sent_by_destination} side="destination" /></section>
      <section className="card stack"><h2>Em trânsito</h2><h3>Enviados, por receber no destino ({formatKz(c.in_transit_outgoing_total)})</h3><TransitTable rows={c.in_transit_outgoing} /><h3>A receber de outras unidades</h3><TransitTable rows={c.in_transit_incoming} /></section>
    </>}
  </>
}
