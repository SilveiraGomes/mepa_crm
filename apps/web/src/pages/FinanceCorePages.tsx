import { useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFinanceItem, useFinancePage } from '../hooks/useFinance'
import { financePost, newIdempotencyKey } from '../lib/finance/client'
import { fin, type SubledgerKind } from '../lib/finance/endpoints'
import { toFinanceError } from '../lib/finance/errors'
import { formatKz } from '../lib/finance/format'
import { formatDate, formatDateTime } from '../lib/format'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type {
  AccountDetail, AccountMovement, AccountSummary, ActualVsBudget, BudgetAction, BudgetDetail, BudgetStatus, BudgetSummary, LedgerBankLine, MatchState, PeriodList, PeriodMonth,
  PeriodUnitStatus, ReconciliationDetail, ReconciliationSummary, Rubric, StatementDetail, StatementLine, StatementSummary, SubledgerDetail, SubledgerStatus, SubledgerSummary,
} from '../types/finance'

// P0.10-F1C Finanças: contas financeiras, valores a receber e a pagar (acréscimo), extractos e reconciliação bancária,
// orçamento e fechos. O servidor decide tudo (estados, saldos, montantes em aberto, correspondências): esta UI só mostra
// e pede transições explícitas. Valores são strings decimais do servidor, nunca float.

type Tone = '' | ' badge--warning' | ' badge--info' | ' badge--danger'
const Badge = ({ label, tone = '' }: { label: string; tone?: Tone }) => <span className={`badge${tone}`}>{label}</span>
const SUB_STATUS: Record<SubledgerStatus, [string, Tone]> = { PENDING: ['Pendente', ''], RECOGNIZED: ['Em aberto', ' badge--warning'], SETTLED: ['Liquidado', ' badge--info'], CANCELLED: ['Anulado', ''] }
const MATCH: Record<MatchState, [string, Tone]> = { UNMATCHED: ['Por reconciliar', ' badge--warning'], PARTIALLY_MATCHED: ['Parcial', ' badge--warning'], MATCHED: ['Reconciliada', ' badge--info'] }
const BUDGET: Record<BudgetStatus, [string, Tone]> = { DRAFT: ['Rascunho', ''], SUBMITTED: ['Submetido', ' badge--warning'], REVIEWED: ['Revisto', ' badge--warning'], APPROVED: ['Aprovado', ' badge--info'],
  SUPERSEDED: ['Substituído', ''], CLOSED: ['Encerrado', ''], CANCELLED: ['Cancelado', ''] }
const PERIOD: Record<PeriodUnitStatus, [string, Tone]> = { OPEN: ['Aberto', ''], CLOSED: ['Fechado', ' badge--info'], REOPENED: ['Reaberto', ' badge--warning'], NATIONALLY_CLOSED: ['Fecho nacional', ' badge--info'] }
const KIND_LABEL = { CASH: 'Caixa', BANK: 'Banco' } as const

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })
const text = (form: FormData, key: string) => String(form.get(key) ?? '').trim()
const money = (form: FormData, key: string) => text(form, key).replace(',', '.')
const validation = (message: string): UiError => ({ kind: 'validation', title: 'Dados inválidos', message, fields: {}, retryable: false })

function useUnits(permissions: string[]) {
  const { finance } = useApp()
  return (finance.context?.units ?? []).filter((unit) => permissions.some((permission) => unit.permissions.includes(permission)))
}

function UnitSelect({ id, label, permissions, value, onChange, all = true, required = false }: { id: string; label: string; permissions: string[]; value: string; onChange: (value: string) => void; all?: boolean; required?: boolean }) {
  const units = useUnits(permissions)
  const { finance } = useApp()
  return <Field label={label} name={id} required={required}><select className="select" id={id} name={id} value={value} required={required} onChange={(event) => onChange(event.target.value)}>
    <option value="">{all ? 'Todas no meu escopo' : finance.known ? 'Seleccione' : 'A carregar…'}</option>
    {units.map((unit) => <option key={unit.public_id} value={unit.public_id}>{unit.name}</option>)}
  </select></Field>
}

function Summary({ rows }: { rows: [string, ReactNode][] }) {
  return <section className="card"><dl className="finance-summary">{rows.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>
}

function ListState<T>({ result, empty }: { result: { loading: boolean; error: UiError | null; data: { data: T[] } | null; reload: () => void }; empty: [string, string] }) {
  return <>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title={empty[0]} message={empty[1]} />}
  </>
}

/** Confirmation dialog around one explicit transition (the server re-checks state, scope and permissions). */
function StepDialog({ open, title, submit, busy, error, onClose, onSubmit, children }: { open: boolean; title: string; submit: string; busy: boolean; error: UiError | null; onClose: () => void; onSubmit: (event: FormEvent<HTMLFormElement>) => void; children?: ReactNode }) {
  return <Dialog open={open} title={title} onClose={onClose}><ActionForm submitLabel={submit} busy={busy} error={error} onSubmit={onSubmit}>{children}</ActionForm></Dialog>
}

function ReasonField({ error }: { error: UiError | null }) {
  return <Field label="Motivo" name="reason" required error={error?.fields.reason}><textarea className="textarea" id="reason" name="reason" required minLength={3} maxLength={2000} /></Field>
}

/** One write at a time: busy flag, error and the dialog state live together. */
function useStep<P extends string>() {
  const [pending, setPending] = useState<P | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const close = () => { setPending(null); setError(null) }
  async function run(work: () => Promise<unknown>, done: () => void) {
    setBusy(true); setError(null)
    try { await work(); close(); done() } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  return { pending, open: (step: P) => { setError(null); setPending(step) }, close, busy, error, setError, run }
}

// ---- Contas ------------------------------------------------------------------------------------------------------------

export function AccountsPage() {
  const { finance } = useApp()
  const [page, setPage] = useState(1)
  const [unit, setUnit] = useState('')
  const [kind, setKind] = useState('')
  const result = useFinancePage<AccountSummary>(fin.accounts(), { page, per_page: 50, ...(unit ? { unit } : {}), ...(kind ? { kind } : {}) })
  return <>
    <PageHeader title="Contas financeiras" description="Caixas e contas bancárias de cada unidade. O saldo é sempre calculado a partir dos lançamentos publicados." actions={finance.has('FINANCE_ACCOUNT_MANAGE') ? <Link className="btn btn--primary" to="/financas/contas/nova">Abrir conta</Link> : undefined} />
    <div className="card finance-filters">
      <UnitSelect id="accounts-unit" label="Unidade" permissions={['FINANCE_VIEW']} value={unit} onChange={(value) => { setPage(1); setUnit(value) }} />
      <Field label="Tipo" name="accounts-kind"><select className="select" id="accounts-kind" value={kind} onChange={(event) => { setPage(1); setKind(event.target.value) }}><option value="">Todos</option><option value="CASH">Caixa</option><option value="BANK">Banco</option></select></Field>
    </div>
    <ListState result={result} empty={['Sem contas', 'Não existem contas financeiras no seu escopo.']} />
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'name', label: 'Conta', render: (row) => <Link to={`/financas/contas/${row.public_id}`}>{row.name}</Link> },
      { key: 'unit', label: 'Unidade', render: (row) => row.unit.name },
      { key: 'kind', label: 'Tipo', render: (row) => KIND_LABEL[row.kind] },
      { key: 'status', label: 'Estado', render: (row) => <Badge label={row.status === 'OPEN' ? 'Aberta' : 'Fechada'} tone={row.status === 'OPEN' ? ' badge--info' : ''} /> },
      { key: 'balance', label: 'Saldo', render: (row) => <span className="finance-amount">{formatKz(row.balance)}</span> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

export function AccountOpenPage() {
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const key = useRef(newIdempotencyKey())
  const [unit, setUnit] = useState('')
  const [kind, setKind] = useState<'CASH' | 'BANK'>('BANK')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const body: Record<string, string> = { unit, kind, code: text(form, 'code'), name: text(form, 'name') }
    for (const field of ['opened_on', 'opening_balance', 'custodian', 'bank_name', 'account_number']) {
      const value = field === 'opening_balance' ? money(form, field) : text(form, field)
      if (value) body[field] = value
    }
    setBusy(true); setError(null)
    try {
      const created = await financePost<Item<AccountDetail>>(fin.accounts(), body, key.current)
      notify('Conta aberta.')
      finance.refresh()
      navigate(`/financas/contas/${created.data.public_id}`)
    } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Abrir conta financeira" description="O saldo inicial é registado como lançamento de abertura, nunca como valor guardado na conta." back={{ to: '/financas/contas', label: 'Contas' }} />
    <div className="card"><ActionForm submitLabel="Abrir conta" busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid">
        <UnitSelect id="unit" label="Unidade" permissions={['FINANCE_ACCOUNT_MANAGE']} value={unit} onChange={setUnit} all={false} required />
        <Field label="Tipo" name="kind" required><select className="select" id="kind" value={kind} onChange={(event) => setKind(event.target.value as 'CASH' | 'BANK')}><option value="BANK">Banco</option><option value="CASH">Caixa</option></select></Field>
        <Field label="Código" name="code" required hint="Único na unidade, por exemplo BANCO-01."><input className="input" id="code" name="code" required maxLength={64} autoComplete="off" /></Field>
        <Field label="Nome" name="name" required><input className="input" id="name" name="name" required maxLength={191} /></Field>
        <Field label="Data de abertura" name="opened_on" hint="Hoje por omissão."><input className="input" id="opened_on" name="opened_on" type="date" max={today()} /></Field>
        <Field label="Saldo inicial (Kz)" name="opening_balance" hint="Opcional. Exige permissão de publicação." error={error?.fields.amount}><input className="input finance-input-amount" id="opening_balance" name="opening_balance" inputMode="decimal" pattern="\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
        {kind === 'BANK' ? <>
          <Field label="Banco" name="bank_name" required><input className="input" id="bank_name" name="bank_name" required maxLength={191} /></Field>
          <Field label="Número da conta" name="account_number" required hint="Guardado cifrado; só os 4 últimos caracteres são mostrados."><input className="input" id="account_number" name="account_number" required maxLength={64} autoComplete="off" /></Field>
        </> : <Field label="Custodiante (identificador da pessoa)" name="custodian" required hint="A pessoa tem de estar visível para si em Pessoas."><input className="input" id="custodian" name="custodian" required maxLength={26} autoComplete="off" /></Field>}
      </div>
    </ActionForm></div>
  </>
}

export function AccountDetailPage() {
  const { id = '' } = useParams()
  const { notify } = useApp()
  const result = useFinanceItem<AccountDetail>(fin.account(id))
  const [page, setPage] = useState(1)
  const history = useFinancePage<AccountMovement>(fin.accountHistory(id), { page, per_page: 50 })
  const step = useStep<'close'>()
  const a = result.data
  if (result.loading && !a) return <LoadingState />
  if (result.error) return <><PageHeader title="Conta" back={{ to: '/financas/contas', label: 'Contas' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!a) return null
  const account = a
  function close(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const on = text(new FormData(event.currentTarget), 'closed_on')
    void step.run(() => financePost(fin.accountClose(account.public_id), { lock_version: account.lock_version, ...(on ? { closed_on: on } : {}) }), () => { notify('Conta fechada. O histórico mantém-se.'); result.reload(); history.reload() })
  }
  return <>
    <PageHeader title={a.name} description={`${KIND_LABEL[a.kind]} · ${a.unit.name}`} back={{ to: '/financas/contas', label: 'Contas' }} actions={a.actions.includes('close') ? <button className="btn btn--secondary" type="button" onClick={() => step.open('close')}>Fechar conta</button> : undefined} />
    <Summary rows={[
      ['Código', a.code], ['Estado', <Badge key="s" label={a.status === 'OPEN' ? 'Aberta' : 'Fechada'} tone={a.status === 'OPEN' ? ' badge--info' : ''} />],
      ['Saldo', <span key="b" className="finance-amount">{formatKz(a.balance)}</span>], ['Origem do saldo', 'Lançamentos publicados'],
      ['Aberta em', formatDate(a.opened_on)], ['Fechada em', a.closed_on ? formatDate(a.closed_on) : '—'],
      ...(a.bank ? [['Banco', a.bank.bank_name], ['Número', a.bank.account_number ?? 'Indisponível']] as [string, ReactNode][] : []),
      ...(a.cash_register ? [['Custodiante', a.cash_register.custodian ? 'Pessoa registada' : 'Sem acesso à pessoa']] as [string, ReactNode][] : []),
      ['Saldo inicial', a.opening_entry ? formatKz(a.opening_entry.amount) : 'Sem lançamento de abertura'],
    ]} />
    <section className="card stack"><h2>Movimentos publicados</h2>
      {history.loading && <LoadingState />}
      {history.error && <ErrorState error={history.error} retry={history.reload} />}
      {history.data && history.data.data.length === 0 && <p className="muted">Ainda sem movimentos.</p>}
      {history.data && history.data.data.length > 0 && <><DataTable rows={history.data.data} rowKey={(row) => `${row.entry}-${row.entry_line}`} columns={[
        { key: 'date', label: 'Data', render: (row) => formatDate(row.entry_date) },
        { key: 'description', label: 'Descrição', render: (row) => row.description },
        { key: 'in', label: 'Entrada', render: (row) => row.debit === '0.00' ? '—' : <span className="finance-amount">{formatKz(row.debit)}</span> },
        { key: 'out', label: 'Saída', render: (row) => row.credit === '0.00' ? '—' : <span className="finance-amount">{formatKz(row.credit)}</span> },
      ]} /><Pagination meta={history.data.meta} onPage={setPage} /></>}
    </section>
    <StepDialog open={step.pending === 'close'} title="Fechar conta" submit="Fechar conta" busy={step.busy} error={step.error} onClose={step.close} onSubmit={close}>
      <p>Só é possível com saldo zero e sem lançamentos por publicar. A conta deixa de aceitar movimentos; o histórico e o saldo continuam disponíveis.</p>
      <Field label="Data de fecho" name="closed_on" hint="Hoje por omissão."><input className="input" id="closed_on" name="closed_on" type="date" max={today()} /></Field>
    </StepDialog>
  </>
}

// ---- A receber / A pagar ------------------------------------------------------------------------------------------------

const SUB_TEXT: Record<SubledgerKind, { title: string; one: string; base: string; create: string; description: string; party: string; direction: string }> = {
  receivables: { title: 'Valores a receber', one: 'Valor a receber', base: '/financas/a-receber', create: 'Novo valor a receber', party: 'Devedor', direction: 'Recebimento',
    description: 'Direitos já reconhecidos como receita (acréscimo). O recebimento só movimenta a caixa ou o banco; nunca volta a ser receita.' },
  payables: { title: 'Valores a pagar', one: 'Valor a pagar', base: '/financas/a-pagar', create: 'Novo valor a pagar', party: 'Fornecedor', direction: 'Pagamento',
    description: 'Obrigações já reconhecidas como gasto ou investimento (acréscimo). O pagamento só movimenta a caixa ou o banco; nunca volta a ser gasto.' },
}

export function SubledgerListPage({ kind }: { kind: SubledgerKind }) {
  const { finance } = useApp()
  const t = SUB_TEXT[kind]
  const [page, setPage] = useState(1)
  const [unit, setUnit] = useState('')
  const [status, setStatus] = useState('')
  const result = useFinancePage<SubledgerSummary>(fin.subledgers(kind), { page, per_page: 50, ...(unit ? { unit } : {}), ...(status ? { status } : {}) })
  return <>
    <PageHeader title={t.title} description={t.description} actions={finance.has('FINANCE_MANAGE') && finance.has('FINANCE_POST') ? <Link className="btn btn--primary" to={`${t.base}/novo`}>{t.create}</Link> : undefined} />
    <div className="card finance-filters">
      <UnitSelect id={`${kind}-unit`} label="Unidade" permissions={['FINANCE_VIEW']} value={unit} onChange={(value) => { setPage(1); setUnit(value) }} />
      <Field label="Estado" name={`${kind}-status`}><select className="select" id={`${kind}-status`} value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }}><option value="">Todos</option><option value="RECOGNIZED">Em aberto</option><option value="SETTLED">Liquidados</option><option value="CANCELLED">Anulados</option></select></Field>
    </div>
    <ListState result={result} empty={['Sem registos', 'Nada corresponde a esta vista no seu escopo.']} />
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'party', label: t.party, render: (row) => <Link to={`${t.base}/${row.public_id}`}>{row.party.name ?? 'Pessoa registada'}</Link> },
      { key: 'category', label: 'Rubrica', render: (row) => row.category?.label ?? '—' },
      { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{formatKz(row.amount)}</span> },
      { key: 'outstanding', label: 'Em aberto', render: (row) => <span className="finance-amount">{formatKz(row.outstanding)}</span> },
      { key: 'due', label: 'Vencimento', render: (row) => formatDate(row.due_on) },
      { key: 'status', label: 'Estado', render: (row) => <Badge label={SUB_STATUS[row.status][0]} tone={SUB_STATUS[row.status][1]} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

export const ReceivablesPage = () => <SubledgerListPage kind="receivables" />
export const PayablesPage = () => <SubledgerListPage kind="payables" />

export function SubledgerCreatePage({ kind }: { kind: SubledgerKind }) {
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const t = SUB_TEXT[kind]
  const key = useRef(newIdempotencyKey())
  const [unit, setUnit] = useState('')
  const [partyKind, setPartyKind] = useState<'EXTERNAL' | 'PERSON'>('EXTERNAL')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const categories: Rubric[] = (kind === 'receivables' ? finance.context?.receivable_categories : finance.context?.payable_categories) ?? []
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const party = partyKind === 'EXTERNAL' ? { kind: 'EXTERNAL', name: text(form, 'party_name') } : { kind: 'PERSON', person: text(form, 'party_person') }
    const body: Record<string, unknown> = { unit, party, category: text(form, 'category'), amount: money(form, 'amount'), due_on: text(form, 'due_on') }
    for (const field of ['recognized_on', 'document', 'description']) if (text(form, field)) body[field] = text(form, field)
    setBusy(true); setError(null)
    try {
      const created = await financePost<Item<SubledgerDetail>>(fin.subledgers(kind), body, key.current)
      notify(kind === 'receivables' ? 'Valor a receber reconhecido como receita.' : 'Valor a pagar reconhecido.')
      navigate(`${t.base}/${created.data.public_id}`)
    } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title={t.create} description={kind === 'receivables' ? 'Reconhece a receita agora; o dinheiro entra mais tarde, pelo recebimento.' : 'Reconhece o gasto (ou o investimento capitalizado) agora; o dinheiro sai mais tarde, pelo pagamento.'} back={{ to: t.base, label: t.title }} />
    <div className="card"><ActionForm submitLabel={kind === 'receivables' ? 'Reconhecer valor a receber' : 'Reconhecer valor a pagar'} busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid">
        <UnitSelect id="unit" label="Unidade" permissions={['FINANCE_MANAGE']} value={unit} onChange={setUnit} all={false} required />
        <Field label="Rubrica" name="category" required error={error?.fields.category}><select className="select" id="category" name="category" required><option value="">Seleccione</option>{categories.map((item) => <option key={item.code} value={item.code}>{item.label}{item.capitalized ? ' (investimento capitalizado)' : ''}</option>)}</select></Field>
        <Field label={`Tipo de ${t.party.toLowerCase()}`} name="party_kind"><select className="select" id="party_kind" value={partyKind} onChange={(event) => setPartyKind(event.target.value as 'EXTERNAL' | 'PERSON')}><option value="EXTERNAL">Entidade externa</option><option value="PERSON">Pessoa registada</option></select></Field>
        {partyKind === 'EXTERNAL'
          ? <Field label={t.party} name="party_name" required hint="Unidades MEPA não são clientes nem fornecedores: use uma transferência."><input className="input" id="party_name" name="party_name" required maxLength={191} /></Field>
          : <Field label="Identificador da pessoa" name="party_person" required><input className="input" id="party_person" name="party_person" required maxLength={26} autoComplete="off" /></Field>}
        <Field label="Valor (Kz)" name="amount" required error={error?.fields.amount} hint="Máximo 2 casas decimais."><input className="input finance-input-amount" id="amount" name="amount" inputMode="decimal" required pattern="\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
        <Field label="Vencimento" name="due_on" required><input className="input" id="due_on" name="due_on" type="date" required /></Field>
        <Field label="Data de reconhecimento" name="recognized_on" hint="Hoje por omissão."><input className="input" id="recognized_on" name="recognized_on" type="date" max={today()} /></Field>
        <Field label={kind === 'payables' ? 'Documento de suporte (identificador)' : 'Documento de suporte (opcional)'} name="document" required={kind === 'payables'} error={error?.fields.document} hint="Factura, recibo ou documento de despesa em Documentos e Ficheiros."><input className="input" id="document" name="document" required={kind === 'payables'} maxLength={26} autoComplete="off" /></Field>
        <Field label="Descrição" name="description"><input className="input" id="description" name="description" maxLength={191} /></Field>
      </div>
    </ActionForm></div>
  </>
}

export const ReceivableCreatePage = () => <SubledgerCreatePage kind="receivables" />
export const PayableCreatePage = () => <SubledgerCreatePage kind="payables" />

export function SubledgerDetailPage({ kind }: { kind: SubledgerKind }) {
  const { id = '' } = useParams()
  const { finance, notify } = useApp()
  const t = SUB_TEXT[kind]
  const result = useFinanceItem<SubledgerDetail>(fin.subledger(kind, id))
  const step = useStep<'settle' | 'cancel' | 'cancel_settlement'>()
  const [settlement, setSettlement] = useState('')
  const d = result.data
  const accounts = useMemo(() => (finance.context?.accounts ?? []).filter((item) => d && item.unit === d.unit.public_id), [finance.context, d])
  if (result.loading && !d) return <LoadingState />
  if (result.error) return <><PageHeader title={t.one} back={{ to: t.base, label: t.title }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!d) return null
  const doc = d
  function settle(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const body = { account: text(form, 'account'), amount: money(form, 'amount'), lock_version: doc.lock_version, ...(text(form, 'settled_on') ? { settled_on: text(form, 'settled_on') } : {}) }
    void step.run(() => financePost(fin.subledgerStep(kind, doc.public_id, 'settlements'), body, newIdempotencyKey()), () => { notify(`${t.direction} registado.`); result.reload() })
  }
  function cancel(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const reason = text(new FormData(event.currentTarget), 'reason')
    if (step.pending === 'cancel_settlement') void step.run(() => financePost(fin.settlementCancel(settlement), { reason }), () => { notify('Liquidação anulada por estorno. O valor volta a estar em aberto.'); result.reload() })
    else void step.run(() => financePost(fin.subledgerStep(kind, doc.public_id, 'cancel'), { reason, lock_version: doc.lock_version }), () => { notify('Reconhecimento anulado por estorno.'); result.reload() })
  }
  const canReverse = finance.has('FINANCE_REVERSE')
  return <>
    <PageHeader title={`${t.one} ${formatKz(d.amount)}`} description={kind === 'receivables' ? 'A receita foi reconhecida uma única vez, no reconhecimento.' : (d.capitalized ? 'Aquisição capitalizada: é investimento em activos, não gasto do período.' : 'O gasto foi reconhecido uma única vez, no reconhecimento.')}
      back={{ to: t.base, label: t.title }} actions={<div className="finance-actions">
        {d.actions.includes('settle') && <button className="btn btn--primary" type="button" onClick={() => step.open('settle')}>{`Registar ${t.direction.toLowerCase()}`}</button>}
        {d.actions.includes('cancel') && <button className="btn btn--secondary" type="button" onClick={() => step.open('cancel')}>Anular</button>}
      </div>} />
    <Summary rows={[
      [t.party, d.party.name ?? 'Pessoa registada'], ['Unidade', d.unit.name], ['Rubrica', d.category?.label ?? '—'],
      ['Conta económica', d.capitalized ? 'Activos (investimento capitalizado)' : kind === 'receivables' ? 'Receita' : 'Gasto'],
      ['Valor', <span key="a" className="finance-amount">{formatKz(d.amount)}</span>], ['Liquidado', <span key="s" className="finance-amount">{formatKz(d.settled)}</span>],
      ['Em aberto', <strong key="o" className="finance-amount">{formatKz(d.outstanding)}</strong>], ['Estado', <Badge key="st" label={SUB_STATUS[d.status][0]} tone={SUB_STATUS[d.status][1]} />],
      ['Reconhecido em', formatDate(d.recognized_on)], ['Vencimento', formatDate(d.due_on)], ['Descrição', d.description],
    ]} />
    <section className="card stack"><h2>Liquidações</h2>
      {d.settlements.length === 0 ? <p className="muted">Ainda sem {kind === 'receivables' ? 'recebimentos' : 'pagamentos'}.</p> : <DataTable rows={d.settlements} rowKey={(row) => row.public_id} columns={[
        { key: 'date', label: 'Data', render: (row) => formatDateTime(row.settled_at) },
        { key: 'account', label: 'Conta', render: (row) => row.account.name },
        { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{formatKz(row.amount)}</span> },
        { key: 'status', label: 'Estado', render: (row) => <Badge label={row.status === 'POSTED' ? 'Registada' : 'Anulada'} tone={row.status === 'POSTED' ? ' badge--info' : ''} /> },
        { key: 'actions', label: 'Acções', render: (row) => row.status === 'POSTED' && canReverse ? <button className="btn btn--ghost btn--sm" type="button" onClick={() => { setSettlement(row.public_id); step.open('cancel_settlement') }}>Anular</button> : '—' },
      ]} />}
    </section>
    <StepDialog open={step.pending === 'settle'} title={`Registar ${t.direction.toLowerCase()}`} submit={`Registar ${t.direction.toLowerCase()}`} busy={step.busy} error={step.error} onClose={step.close} onSubmit={settle}>
      <p>Em aberto: <strong>{formatKz(d.outstanding)}</strong>. Pode registar uma parte; nunca mais do que está em aberto.</p>
      <Field label="Conta" name="account" required><select className="select" id="account" name="account" required><option value="">Seleccione</option>{accounts.map((item) => <option key={item.public_id} value={item.public_id}>{item.name} ({KIND_LABEL[item.kind]})</option>)}</select></Field>
      <Field label="Valor (Kz)" name="amount" required error={step.error?.fields.amount}><input className="input finance-input-amount" id="amount" name="amount" inputMode="decimal" required defaultValue={d.outstanding} pattern="\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
      <Field label="Data" name="settled_on" hint="Hoje por omissão."><input className="input" id="settled_on" name="settled_on" type="date" max={today()} /></Field>
    </StepDialog>
    <StepDialog open={step.pending === 'cancel' || step.pending === 'cancel_settlement'} title={step.pending === 'cancel_settlement' ? 'Anular liquidação' : 'Anular reconhecimento'} submit="Anular" busy={step.busy} error={step.error} onClose={step.close} onSubmit={cancel}>
      <p>O lançamento original não é apagado: é registado um estorno com o motivo indicado.</p>
      <ReasonField error={step.error} />
    </StepDialog>
  </>
}

export const ReceivableDetailPage = () => <SubledgerDetailPage kind="receivables" />
export const PayableDetailPage = () => <SubledgerDetailPage kind="payables" />

// ---- Extractos bancários --------------------------------------------------------------------------------------------------

function MatchBadge({ state }: { state: MatchState }) {
  return <Badge label={MATCH[state][0]} tone={MATCH[state][1]} />
}

export function StatementsPage() {
  const { finance } = useApp()
  const [page, setPage] = useState(1)
  const [unit, setUnit] = useState('')
  const result = useFinancePage<StatementSummary>(fin.statements(), { page, per_page: 50, ...(unit ? { unit } : {}) })
  return <>
    <PageHeader title="Extractos bancários" description="Factos externos do banco, guardados como chegaram. Uma linha de extracto nunca é um lançamento contabilístico." actions={finance.has('FINANCE_RECONCILE') ? <Link className="btn btn--primary" to="/financas/extractos/novo">Importar extracto</Link> : undefined} />
    <div className="card finance-filters"><UnitSelect id="statements-unit" label="Unidade" permissions={['FINANCE_VIEW']} value={unit} onChange={(value) => { setPage(1); setUnit(value) }} /></div>
    <ListState result={result} empty={['Sem extractos', 'Ainda não foram importados extractos no seu escopo.']} />
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'account', label: 'Conta', render: (row) => <Link to={`/financas/extractos/${row.public_id}`}>{row.account.name}</Link> },
      { key: 'range', label: 'Período', render: (row) => `${formatDate(row.starts_on)} – ${formatDate(row.ends_on)}` },
      { key: 'lines', label: 'Linhas', render: (row) => row.lines_count },
      { key: 'closing', label: 'Saldo final', render: (row) => <span className="finance-amount">{formatKz(row.closing_balance)}</span> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

interface DraftLine { key: number; occurred_on: string; amount: string; description: string; reference: string }

export function StatementCreatePage() {
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const key = useRef(newIdempotencyKey())
  const counter = useRef(1)
  const [lines, setLines] = useState<DraftLine[]>([{ key: 0, occurred_on: '', amount: '', description: '', reference: '' }])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const units = new Set(useUnits(['FINANCE_RECONCILE']).map((unit) => unit.public_id))
  const accounts = (finance.context?.accounts ?? []).filter((item) => item.kind === 'BANK' && units.has(item.unit))
  const update = (index: number, patch: Partial<DraftLine>) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    if (lines.length === 0) { setError(validation('O extracto precisa de pelo menos uma linha.')); return }
    const body = {
      account: text(form, 'account'), starts_on: text(form, 'starts_on'), ends_on: text(form, 'ends_on'), opening_balance: money(form, 'opening_balance'), closing_balance: money(form, 'closing_balance'),
      document: text(form, 'document'),
      lines: lines.map((line) => ({ occurred_on: line.occurred_on, amount: line.amount.trim().replace(',', '.'), description: line.description.trim(), ...(line.reference.trim() ? { reference: line.reference.trim() } : {}) })),
    }
    setBusy(true); setError(null)
    try {
      const created = await financePost<Item<StatementDetail>>(fin.statements(), body, key.current)
      notify('Extracto importado. Nenhum lançamento contabilístico foi criado.')
      navigate(`/financas/extractos/${created.data.public_id}`)
    } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Importar extracto bancário" description="Registo manual das linhas do extracto, com o ficheiro do banco. Depois de importado, o extracto não é editado." back={{ to: '/financas/extractos', label: 'Extractos' }} />
    <div className="card"><ActionForm submitLabel="Importar extracto" busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid">
        <Field label="Conta bancária" name="account" required><select className="select" id="account" name="account" required><option value="">Seleccione</option>{accounts.map((item) => <option key={item.public_id} value={item.public_id}>{item.name}</option>)}</select></Field>
        <Field label="Ficheiro do extracto (identificador do documento)" name="document" required error={error?.fields.document}><input className="input" id="document" name="document" required maxLength={26} autoComplete="off" /></Field>
        <Field label="De" name="starts_on" required><input className="input" id="starts_on" name="starts_on" type="date" required max={today()} /></Field>
        <Field label="Até" name="ends_on" required><input className="input" id="ends_on" name="ends_on" type="date" required max={today()} /></Field>
        <Field label="Saldo inicial (Kz)" name="opening_balance" required><input className="input finance-input-amount" id="opening_balance" name="opening_balance" inputMode="decimal" required pattern="-?\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
        <Field label="Saldo final (Kz)" name="closing_balance" required error={error?.fields.closing_balance}><input className="input finance-input-amount" id="closing_balance" name="closing_balance" inputMode="decimal" required pattern="-?\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
      </div>
      <fieldset className="finance-lines"><legend>Linhas do extracto</legend>
        {lines.map((line, index) => <div className="finance-line" key={line.key} role="group" aria-label={`Linha ${index + 1}`}>
          <Field label="Data" name={`line-date-${line.key}`} required><input className="input" id={`line-date-${line.key}`} type="date" required value={line.occurred_on} max={today()} onChange={(event) => update(index, { occurred_on: event.target.value })} /></Field>
          <Field label="Valor (Kz)" name={`line-amount-${line.key}`} required hint="Negativo para saídas."><input className="input finance-input-amount" id={`line-amount-${line.key}`} required inputMode="decimal" pattern="-?\d{1,12}([.,]\d{1,2})?" value={line.amount} onChange={(event) => update(index, { amount: event.target.value })} /></Field>
          <Field label="Descrição" name={`line-description-${line.key}`} required><input className="input" id={`line-description-${line.key}`} required maxLength={191} value={line.description} onChange={(event) => update(index, { description: event.target.value })} /></Field>
          <Field label="Referência" name={`line-reference-${line.key}`}><input className="input" id={`line-reference-${line.key}`} maxLength={191} value={line.reference} onChange={(event) => update(index, { reference: event.target.value })} /></Field>
          <button className="btn btn--ghost btn--sm" type="button" disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>Remover linha</button>
        </div>)}
        <button className="btn btn--secondary btn--sm" type="button" onClick={() => setLines((current) => [...current, { key: counter.current++, occurred_on: '', amount: '', description: '', reference: '' }])}>Adicionar linha</button>
      </fieldset>
    </ActionForm></div>
  </>
}

function StatementLinesTable({ lines, here = false, onMatch }: { lines: StatementLine[]; here?: boolean; onMatch?: (line: StatementLine) => void }) {
  // Five columns at most: the reconciliation workspace stays inside a tablet viewport (the action sits with the state).
  return <DataTable rows={lines} rowKey={(row) => row.line_number} columns={[
    { key: 'line', label: 'Linha', render: (row) => `${row.line_number} · ${formatDate(row.occurred_on)}` },
    { key: 'description', label: 'Descrição', render: (row) => <span className="finance-unit">{row.description}</span> },
    { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{formatKz(row.amount)}</span> },
    { key: 'matched', label: here ? 'Reconciliado aqui' : 'Reconciliado', render: (row) => <span className="finance-amount">{formatKz(here ? row.matched_here ?? '0.00' : row.matched_total)}</span> },
    { key: 'state', label: 'Estado', render: (row) => <span className="finance-actions"><MatchBadge state={row.state} />{onMatch && row.state !== 'MATCHED' && <button className="btn btn--secondary btn--sm" type="button" onClick={() => onMatch(row)}>Corresponder</button>}</span> },
  ]} />
}

export function StatementDetailPage() {
  const { id = '' } = useParams()
  const result = useFinanceItem<StatementDetail>(fin.statement(id))
  const s = result.data
  if (result.loading && !s) return <LoadingState />
  if (result.error) return <><PageHeader title="Extracto" back={{ to: '/financas/extractos', label: 'Extractos' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!s) return null
  return <>
    <PageHeader title={`Extracto ${s.account.name}`} description="Facto externo do banco. Não altera saldos nem o resultado." back={{ to: '/financas/extractos', label: 'Extractos' }} />
    <Summary rows={[['Conta', s.account.name], ['Período', `${formatDate(s.starts_on)} – ${formatDate(s.ends_on)}`], ['Saldo inicial', formatKz(s.opening_balance)], ['Saldo final', formatKz(s.closing_balance)],
      ['Linhas', s.lines_count], ['Ficheiro', s.document?.public_id ? 'Anexado' : 'Sem acesso ao ficheiro'], ['Importado em', formatDateTime(s.created_at)]]} />
    <section className="card stack"><h2>Linhas</h2><StatementLinesTable lines={s.lines} /></section>
  </>
}

// ---- Reconciliação bancária ------------------------------------------------------------------------------------------------

export function ReconciliationsPage() {
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [unit, setUnit] = useState('')
  const [account, setAccount] = useState('')
  const key = useRef(newIdempotencyKey())
  const step = useStep<'open'>()
  const result = useFinancePage<ReconciliationSummary>(fin.reconciliations(), { page, per_page: 50, ...(unit ? { unit } : {}) })
  const statements = useFinancePage<StatementSummary>(step.pending === 'open' && account ? fin.statements() : null, { account, per_page: 100 })
  const units = new Set(useUnits(['FINANCE_RECONCILE']).map((item) => item.public_id))
  const accounts = (finance.context?.accounts ?? []).filter((item) => item.kind === 'BANK' && units.has(item.unit))
  function open(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    void step.run(async () => {
      const created = await financePost<Item<ReconciliationDetail>>(fin.reconciliations(), { account, period: text(form, 'period'), statement: text(form, 'statement') }, key.current)
      navigate(`/financas/reconciliacao/${created.data.public_id}`)
    }, () => { key.current = newIdempotencyKey(); notify('Reconciliação aberta.') })
  }
  return <>
    <PageHeader title="Reconciliação bancária" description="Relaciona linhas do extracto com movimentos publicados da mesma conta. Não altera o razão. Diferente da reconciliação de transferências entre unidades."
      actions={finance.has('FINANCE_RECONCILE') ? <button className="btn btn--primary" type="button" onClick={() => step.open('open')}>Nova reconciliação</button> : undefined} />
    <div className="card finance-filters"><UnitSelect id="recon-unit" label="Unidade" permissions={['FINANCE_VIEW']} value={unit} onChange={(value) => { setPage(1); setUnit(value) }} /></div>
    <ListState result={result} empty={['Sem reconciliações', 'Ainda não existem reconciliações no seu escopo.']} />
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'account', label: 'Conta', render: (row) => <Link to={`/financas/reconciliacao/${row.public_id}`}>{row.account.name}</Link> },
      { key: 'period', label: 'Mês', render: (row) => row.period },
      { key: 'version', label: 'Versão', render: (row) => row.version },
      { key: 'status', label: 'Estado', render: (row) => <Badge label={row.status === 'OPEN' ? 'Aberta' : 'Fechada'} tone={row.status === 'OPEN' ? ' badge--warning' : ' badge--info'} /> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
    <StepDialog open={step.pending === 'open'} title="Nova reconciliação" submit="Abrir reconciliação" busy={step.busy} error={step.error} onClose={step.close} onSubmit={open}>
      <Field label="Conta bancária" name="recon-account" required><select className="select" id="recon-account" required value={account} onChange={(event) => setAccount(event.target.value)}><option value="">Seleccione</option>{accounts.map((item) => <option key={item.public_id} value={item.public_id}>{item.name}</option>)}</select></Field>
      <Field label="Mês" name="period" required><input className="input" id="period" name="period" type="month" required max={today().slice(0, 7)} /></Field>
      <Field label="Extracto" name="statement" required><select className="select" id="statement" name="statement" required><option value="">{account ? 'Seleccione' : 'Escolha primeiro a conta'}</option>{(statements.data?.data ?? []).map((item) => <option key={item.public_id} value={item.public_id}>{formatDate(item.starts_on)} – {formatDate(item.ends_on)}</option>)}</select></Field>
    </StepDialog>
  </>
}

export function ReconciliationDetailPage() {
  const { id = '' } = useParams()
  const { notify } = useApp()
  const result = useFinanceItem<ReconciliationDetail>(fin.reconciliation(id))
  const step = useStep<'match' | 'close'>()
  const [line, setLine] = useState<StatementLine | null>(null)
  const r = result.data
  if (result.loading && !r) return <LoadingState />
  if (result.error) return <><PageHeader title="Reconciliação" back={{ to: '/financas/reconciliacao', label: 'Reconciliação' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!r) return null
  const rec = r
  const open = r.status === 'OPEN' && r.actions.includes('match')
  const candidates: LedgerBankLine[] = line ? r.ledger_lines.filter((item) => item.direction === line.direction && item.state !== 'MATCHED') : []
  function match(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const [entry, entryLine] = text(form, 'ledger').split('#')
    if (!line || !entry) { step.setError(validation('Seleccione o movimento contabilístico.')); return }
    void step.run(() => financePost(fin.reconciliationStep(rec.public_id, 'matches'), { statement_line: line.line_number, entry, entry_line: Number(entryLine), amount: money(form, 'amount') }), () => { notify('Correspondência registada.'); result.reload() })
  }
  function unmatch(m: ReconciliationDetail['matches'][number]) {
    void step.run(() => financePost(fin.reconciliationStep(rec.public_id, 'unmatch'), { statement_line: m.statement_line, entry: m.entry, entry_line: m.entry_line }), () => { notify('Correspondência desfeita.'); result.reload() })
  }
  function close(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void step.run(() => financePost(fin.reconciliationStep(rec.public_id, 'close'), { lock_version: rec.lock_version }), () => { notify('Reconciliação fechada.'); result.reload() })
  }
  return <>
    <PageHeader title={`Reconciliação ${r.account.name} · ${r.period}`} description="Cada valor só pode ser reconciliado uma vez. Linhas por reconciliar ficam visíveis como diferenças." back={{ to: '/financas/reconciliacao', label: 'Reconciliação' }}
      actions={r.actions.includes('close') ? <button className="btn btn--secondary" type="button" onClick={() => step.open('close')}>Fechar reconciliação</button> : undefined} />
    {step.error && step.pending === null && <ErrorState error={step.error} />}
    <Summary rows={[['Estado', <Badge key="s" label={r.status === 'OPEN' ? 'Aberta' : 'Fechada'} tone={r.status === 'OPEN' ? ' badge--warning' : ' badge--info'} />], ['Versão', r.version],
      ['Linhas reconciliadas', `${r.summary.matched} de ${r.summary.statement_lines}`], ['Parciais', r.summary.partially_matched], ['Por reconciliar', r.summary.unmatched],
      ['Saldo do extracto', formatKz(r.summary.statement_closing_balance)], ['Saldo contabilístico no fim do mês', formatKz(r.summary.ledger_balance_at_period_end)], ['Diferença', <strong key="d">{formatKz(r.summary.difference)}</strong>]]} />
    <section className="card stack"><h2>Linhas do extracto</h2><StatementLinesTable lines={r.statement_lines} here onMatch={open ? (item) => { setLine(item); step.open('match') } : undefined} /></section>
    <section className="card stack"><h2>Correspondências desta reconciliação</h2>
      {r.matches.length === 0 ? <p className="muted">Ainda sem correspondências.</p> : <DataTable rows={r.matches} rowKey={(row) => `${row.statement_line}-${row.entry}-${row.entry_line}`} columns={[
        { key: 'line', label: 'Linha', render: (row) => row.statement_line },
        { key: 'ledger', label: 'Movimento', render: (row) => r.ledger_lines.find((item) => item.entry === row.entry && item.entry_line === row.entry_line)?.description ?? 'Movimento publicado' },
        { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{formatKz(row.amount)}</span> },
        { key: 'action', label: 'Acções', render: (row) => open ? <button className="btn btn--ghost btn--sm" type="button" disabled={step.busy} onClick={() => unmatch(row)}>Desfazer</button> : '—' },
      ]} />}
    </section>
    <section className="card stack"><h2>Movimentos contabilísticos da conta</h2>
      {r.ledger_lines.length === 0 ? <p className="muted">Sem movimentos publicados até ao fim do mês.</p> : <DataTable rows={r.ledger_lines} rowKey={(row) => `${row.entry}-${row.entry_line}`} columns={[
        { key: 'date', label: 'Data', render: (row) => formatDate(row.entry_date) },
        { key: 'description', label: 'Descrição', render: (row) => row.description },
        { key: 'amount', label: 'Valor', render: (row) => <span className="finance-amount">{row.direction === 'OUT' ? '−' : ''}{formatKz(row.amount)}</span> },
        { key: 'state', label: 'Estado', render: (row) => <MatchBadge state={row.state} /> },
      ]} />}
    </section>
    <StepDialog open={step.pending === 'match'} title="Corresponder linha" submit="Corresponder" busy={step.busy} error={step.error} onClose={step.close} onSubmit={match}>
      {line && <p>Linha {line.line_number}: {line.description} · {formatKz(line.amount)}. Já reconciliado: {formatKz(line.matched_total)}.</p>}
      <Field label="Movimento contabilístico" name="ledger" required><select className="select" id="ledger" name="ledger" required><option value="">Seleccione</option>{candidates.map((item) => <option key={`${item.entry}#${item.entry_line}`} value={`${item.entry}#${item.entry_line}`}>{formatDate(item.entry_date)} · {item.description} · {formatKz(item.amount)}</option>)}</select></Field>
      <Field label="Valor a reconciliar (Kz)" name="amount" required error={step.error?.fields.amount}><input className="input finance-input-amount" id="amount" name="amount" required inputMode="decimal" pattern="\d{1,12}([.,]\d{1,2})?" autoComplete="off" /></Field>
    </StepDialog>
    <StepDialog open={step.pending === 'close'} title="Fechar reconciliação" submit="Fechar reconciliação" busy={step.busy} error={step.error} onClose={step.close} onSubmit={close}>
      <p>A versão fechada fica imutável. Diferenças por reconciliar ficam registadas e podem ser tratadas numa nova versão.</p>
    </StepDialog>
  </>
}

// ---- Orçamento -----------------------------------------------------------------------------------------------------------

function BudgetBadge({ status }: { status: BudgetStatus }) {
  return <Badge label={BUDGET[status][0]} tone={BUDGET[status][1]} />
}

export function BudgetsPage() {
  const { finance } = useApp()
  const [page, setPage] = useState(1)
  const [unit, setUnit] = useState('')
  const [year, setYear] = useState('')
  const result = useFinancePage<BudgetSummary>(fin.budgets(), { page, per_page: 50, ...(unit ? { unit } : {}), ...(year ? { year } : {}) })
  return <>
    <PageHeader title="Orçamento" description="Orçamento anual por unidade, fundo e versão. Uma versão aprovada nunca é alterada: revê-se com uma nova versão." actions={finance.has('FINANCE_BUDGET_MANAGE') ? <Link className="btn btn--primary" to="/financas/orcamento/novo">Novo orçamento</Link> : undefined} />
    <div className="card finance-filters">
      <UnitSelect id="budget-unit" label="Unidade" permissions={['FINANCE_VIEW', 'FINANCE_BUDGET_MANAGE', 'FINANCE_BUDGET_APPROVE']} value={unit} onChange={(value) => { setPage(1); setUnit(value) }} />
      <Field label="Ano" name="budget-year"><select className="select" id="budget-year" value={year} onChange={(event) => { setPage(1); setYear(event.target.value) }}><option value="">Todos</option>{(finance.context?.years ?? []).map((item) => <option key={item} value={item}>{item}</option>)}</select></Field>
    </div>
    <ListState result={result} empty={['Sem orçamentos', 'Ainda não existem orçamentos no seu escopo.']} />
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'unit', label: 'Unidade', render: (row) => <Link to={`/financas/orcamento/${row.public_id}`}>{row.unit.name}</Link> },
      { key: 'year', label: 'Ano', render: (row) => row.year },
      { key: 'version', label: 'Versão', render: (row) => row.version },
      { key: 'total', label: 'Aprovado', render: (row) => <span className="finance-amount">{formatKz(row.approved_total)}</span> },
      { key: 'status', label: 'Estado', render: (row) => <span className="finance-badges"><BudgetBadge status={row.status} />{row.in_execution && <Badge label="Em execução" tone=" badge--info" />}</span> },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

interface EditLine { key: number; category: string; amount: string }

function LinesEditor({ lines, setLines, categories, amountLabel }: { lines: EditLine[]; setLines: (update: (current: EditLine[]) => EditLine[]) => void; categories: Rubric[]; amountLabel: string }) {
  const counter = useRef(1000)
  const update = (index: number, patch: Partial<EditLine>) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  return <fieldset className="finance-lines"><legend>Linhas por rubrica</legend>
    {lines.map((line, index) => <div className="finance-line finance-line--short" key={line.key} role="group" aria-label={`Linha ${index + 1}`}>
      <Field label="Rubrica" name={`budget-category-${line.key}`} required><select className="select" id={`budget-category-${line.key}`} required value={line.category} onChange={(event) => update(index, { category: event.target.value })}><option value="">Seleccione</option>{categories.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}</select></Field>
      <Field label={amountLabel} name={`budget-amount-${line.key}`} required><input className="input finance-input-amount" id={`budget-amount-${line.key}`} required inputMode="decimal" pattern="\d{1,12}([.,]\d{1,2})?" value={line.amount} onChange={(event) => update(index, { amount: event.target.value })} /></Field>
      <button className="btn btn--ghost btn--sm" type="button" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>Remover linha</button>
    </div>)}
    <button className="btn btn--secondary btn--sm" type="button" onClick={() => setLines((current) => [...current, { key: counter.current++, category: '', amount: '' }])}>Adicionar linha</button>
  </fieldset>
}

const toBody = (lines: EditLine[], amountKey: string) => lines.map((line) => ({ category: line.category, [amountKey]: line.amount.trim().replace(',', '.') }))

export function BudgetCreatePage() {
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const key = useRef(newIdempotencyKey())
  const [unit, setUnit] = useState('')
  const [lines, setLines] = useState<EditLine[]>([{ key: 0, category: '', amount: '' }])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const year = text(new FormData(event.currentTarget), 'year')
    setBusy(true); setError(null)
    try {
      const created = await financePost<Item<BudgetDetail>>(fin.budgets(), { unit, year, lines: toBody(lines, 'requested_amount') }, key.current)
      notify('Orçamento criado em rascunho.')
      navigate(`/financas/orcamento/${created.data.public_id}`)
    } catch (failure) { setError(toFinanceError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Novo orçamento" description="Rascunho anual do fundo geral. O orçamento nunca movimenta o razão." back={{ to: '/financas/orcamento', label: 'Orçamento' }} />
    <div className="card"><ActionForm submitLabel="Criar rascunho" busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid">
        <UnitSelect id="unit" label="Unidade" permissions={['FINANCE_BUDGET_MANAGE']} value={unit} onChange={setUnit} all={false} required />
        <Field label="Ano" name="year" required><select className="select" id="year" name="year" required defaultValue={today().slice(0, 4)}>{(finance.context?.years ?? []).map((item) => <option key={item} value={item}>{item}</option>)}</select></Field>
      </div>
      <LinesEditor lines={lines} setLines={setLines} categories={finance.context?.budget_categories ?? []} amountLabel="Valor pedido (Kz)" />
    </ActionForm></div>
  </>
}

const BUDGET_STEP: Record<Exclude<BudgetAction, 'edit_lines'>, string> = { submit: 'Submeter', review: 'Rever', return: 'Devolver', approve: 'Aprovar', cancel: 'Cancelar', revise: 'Criar revisão' }

function ActualTable({ budget }: { budget: string }) {
  const [to, setTo] = useState('')
  const result = useFinanceItem<ActualVsBudget>(fin.actualVsBudget(budget), to ? { to } : {})
  const r = result.data
  return <section className="card stack"><h2>Orçamento vs real</h2>
    <p className="muted">Real = lançamentos publicados (acréscimo), do início do ano até à data. Desvio = real − orçamento.</p>
    <div className="finance-filters"><Field label="Até" name="actual-to"><input className="input" id="actual-to" type="date" value={to} max={today()} onChange={(event) => setTo(event.target.value)} /></Field></div>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {r && (r.rows.length === 0 ? <p className="muted">Sem rubricas orçamentadas nem movimentos.</p> : <DataTable rows={r.rows} rowKey={(row) => row.category.code} columns={[
      { key: 'category', label: 'Rubrica', render: (row) => <>{row.category.label}{!row.budgeted && <span className="muted"> · não orçamentada</span>}</> },
      { key: 'budget', label: 'Orçamento', render: (row) => <span className="finance-amount">{formatKz(row.budget_amount)}</span> },
      { key: 'actual', label: 'Real', render: (row) => <span className="finance-amount">{formatKz(row.actual_amount)}</span> },
      { key: 'variance', label: 'Desvio', render: (row) => <span className="finance-amount">{formatKz(row.variance)}</span> },
      { key: 'percent', label: 'Desvio %', render: (row) => row.variance_percent === null ? '—' : `${row.variance_percent.replace('.', ',')} %` },
    ]} />)}
  </section>
}

export function BudgetDetailPage() {
  const { id = '' } = useParams()
  const { finance, notify } = useApp()
  const navigate = useNavigate()
  const result = useFinanceItem<BudgetDetail>(fin.budget(id))
  const step = useStep<BudgetAction>()
  const [lines, setLines] = useState<EditLine[]>([])
  const b = result.data
  if (result.loading && !b) return <LoadingState />
  if (result.error) return <><PageHeader title="Orçamento" back={{ to: '/financas/orcamento', label: 'Orçamento' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!b) return null
  const budget = b
  const start = (action: BudgetAction) => {
    if (action === 'edit_lines') setLines(budget.lines.map((line, i) => ({ key: i, category: line.category.code, amount: line.requested_amount })))
    if (action === 'review') setLines(budget.lines.map((line, i) => ({ key: i, category: line.category.code, amount: line.approved_amount })))
    step.open(action)
  }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const action = step.pending
    if (!action) return
    const reason = text(new FormData(event.currentTarget), 'reason')
    if (action === 'revise') {
      void step.run(async () => { const created = await financePost<Item<BudgetDetail>>(fin.budgetStep(budget.public_id, 'revise'), {}, newIdempotencyKey()); navigate(`/financas/orcamento/${created.data.public_id}`) }, () => notify('Revisão criada como nova versão em rascunho.'))
      return
    }
    const body: Record<string, unknown> = { lock_version: budget.lock_version }
    if (action === 'edit_lines') body.lines = toBody(lines, 'requested_amount')
    if (action === 'review') body.approved_lines = toBody(lines, 'approved_amount')
    if (action === 'return' || action === 'cancel') body.reason = reason
    const path = fin.budgetStep(budget.public_id, action === 'edit_lines' ? 'lines' : action)
    void step.run(() => financePost(path, body), () => { notify(action === 'approve' ? 'Orçamento aprovado.' : 'Orçamento actualizado.'); result.reload() })
  }
  const title = step.pending === 'edit_lines' ? 'Alterar linhas' : step.pending ? BUDGET_STEP[step.pending] : ''
  return <>
    <PageHeader title={`Orçamento ${b.year} · ${b.unit.name}`} description={`Versão ${b.version} · fundo ${b.fund === 'GENERAL' ? 'geral' : b.fund}`} back={{ to: '/financas/orcamento', label: 'Orçamento' }}
      actions={<div className="finance-actions">{b.actions.map((action) => <button key={action} className={`btn ${action === 'approve' || action === 'submit' || action === 'review' ? 'btn--primary' : 'btn--secondary'}`} type="button" onClick={() => start(action)}>{action === 'edit_lines' ? 'Alterar linhas' : BUDGET_STEP[action]}</button>)}</div>} />
    {b.status === 'REVIEWED' && b.submitted_by_me && finance.has('FINANCE_BUDGET_APPROVE') && <div className="alert alert--warning" role="status"><div className="alert__body"><strong>Segregação de funções</strong><p>Submeteu este orçamento: a aprovação tem de ser feita por outra pessoa.</p></div></div>}
    <Summary rows={[['Estado', <span key="s" className="finance-badges"><BudgetBadge status={b.status} />{b.in_execution && <Badge label="Em execução" tone=" badge--info" />}</span>], ['Ano', b.year], ['Versão', b.version],
      ['Total pedido', formatKz(b.requested_total)], ['Total aprovado', formatKz(b.approved_total)], ['Aprovado em', b.approved_at ? formatDateTime(b.approved_at) : '—']]} />
    <section className="card stack"><h2>Linhas</h2>
      {b.lines.length === 0 ? <p className="muted">Sem linhas.</p> : <DataTable rows={b.lines} rowKey={(row) => row.category.code} columns={[
        { key: 'category', label: 'Rubrica', render: (row) => row.category.label },
        { key: 'requested', label: 'Pedido', render: (row) => <span className="finance-amount">{formatKz(row.requested_amount)}</span> },
        { key: 'approved', label: 'Aprovado', render: (row) => <span className="finance-amount">{formatKz(row.approved_amount)}</span> },
      ]} />}
    </section>
    <section className="card stack"><h2>Versões</h2><ol className="finance-timeline">{b.versions.map((v) => <li key={v.public_id} className="finance-timeline__item"><strong>{v.public_id === b.public_id ? `Versão ${v.version} (esta)` : <Link to={`/financas/orcamento/${v.public_id}`}>Versão {v.version}</Link>}</strong> <BudgetBadge status={v.status} /></li>)}</ol></section>
    <ActualTable budget={b.public_id} />
    <StepDialog open={step.pending !== null} title={title} submit={title || 'Confirmar'} busy={step.busy} error={step.error} onClose={step.close} onSubmit={submit}>
      {step.pending === 'edit_lines' && <LinesEditor lines={lines} setLines={setLines} categories={finance.context?.budget_categories ?? []} amountLabel="Valor pedido (Kz)" />}
      {step.pending === 'review' && <><p>Pode ajustar os valores aprovados por rubrica.</p>{lines.map((line, index) => <Field key={line.key} label={budget.lines[index]?.category.label ?? line.category} name={`review-${line.key}`}><input className="input finance-input-amount" id={`review-${line.key}`} inputMode="decimal" pattern="\d{1,12}([.,]\d{1,2})?" value={line.amount} onChange={(event) => setLines((current) => current.map((item, i) => (i === index ? { ...item, amount: event.target.value } : item)))} /></Field>)}</>}
      {step.pending === 'submit' && <p>Depois de submetido, o orçamento só volta a ser alterado se for devolvido.</p>}
      {step.pending === 'approve' && <p>A versão aprovada substitui a versão aprovada anterior, que fica no histórico.</p>}
      {step.pending === 'revise' && <p>Cria a versão {Math.max(...b.versions.map((v) => v.version)) + 1} em rascunho, copiada desta. Esta versão continua aprovada até a revisão ser aprovada.</p>}
      {(step.pending === 'return' || step.pending === 'cancel') && <ReasonField error={step.error} />}
    </StepDialog>
  </>
}

// ---- Fechos ------------------------------------------------------------------------------------------------------------

export function PeriodsPage() {
  const { finance, notify } = useApp()
  const units = useUnits(['FINANCE_VIEW', 'FINANCE_PERIOD_CLOSE', 'FINANCE_PERIOD_REOPEN'])
  const [unit, setUnit] = useState('')
  const [year, setYear] = useState(today().slice(0, 4))
  const selected = unit || units[0]?.public_id || ''
  const result = useFinanceItem<PeriodList>(selected ? fin.periods() : null, { unit: selected, year })
  const step = useStep<'close' | 'reopen' | 'national_close'>()
  const [month, setMonth] = useState<PeriodMonth | null>(null)
  const p = result.data
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!month || !step.pending) return
    const reason = text(new FormData(event.currentTarget), 'reason')
    const action = step.pending
    const path = fin.periodStep(month.code, action === 'national_close' ? 'national-close' : action)
    const body = action === 'national_close' ? {} : action === 'reopen' ? { unit: selected, reason } : { unit: selected }
    void step.run(() => financePost(path, body), () => { notify({ close: 'Mês fechado para a unidade.', reopen: 'Mês reaberto para a unidade.', national_close: 'Fecho nacional registado.' }[action]); result.reload() })
  }
  const label = { close: 'Fechar mês', reopen: 'Reabrir', national_close: 'Fecho nacional' } as const
  return <>
    <PageHeader title="Fechos" description="Cada unidade fecha os seus meses sem depender das outras. O fecho nacional é irreversível." />
    <form className="card finance-filters" onSubmit={(event) => event.preventDefault()}>
      <Field label="Unidade" name="periods-unit"><select className="select" id="periods-unit" value={selected} onChange={(event) => setUnit(event.target.value)}>{units.length === 0 && <option value="">{finance.known ? 'Sem unidades no seu escopo' : 'A carregar…'}</option>}{units.map((item) => <option key={item.public_id} value={item.public_id}>{item.name}</option>)}</select></Field>
      <Field label="Ano" name="periods-year"><select className="select" id="periods-year" value={year} onChange={(event) => setYear(event.target.value)}>{(finance.context?.years ?? [year]).map((item) => <option key={item} value={item}>{item}</option>)}</select></Field>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {p && <div className="card"><DataTable rows={p.items} rowKey={(row) => row.code} columns={[
      { key: 'month', label: 'Mês', render: (row) => row.code },
      { key: 'unit', label: 'Estado da unidade', render: (row) => <Badge label={PERIOD[row.unit_status][0]} tone={PERIOD[row.unit_status][1]} /> },
      { key: 'national', label: 'Nacional', render: (row) => row.national_status === 'OPEN' ? 'Aberto' : 'Fechado' },
      { key: 'closed', label: 'Fechado em', render: (row) => row.closed_at ? `${formatDateTime(row.closed_at)}${row.closed_by_me ? ' (por si)' : ''}` : '—' },
      { key: 'pending', label: 'Por publicar', render: (row) => row.pending_entries },
      { key: 'actions', label: 'Acções', render: (row) => row.actions.length === 0 ? '—' : <span className="finance-actions">{row.actions.map((action) => <button key={action} className={`btn btn--sm ${action === 'close' ? 'btn--primary' : 'btn--secondary'}`} type="button" onClick={() => { setMonth(row); step.open(action) }}>{label[action]}</button>)}</span> },
    ]} /></div>}
    <StepDialog open={step.pending !== null} title={step.pending ? `${label[step.pending]} ${month?.code ?? ''}` : ''} submit={step.pending ? label[step.pending] : 'Confirmar'} busy={step.busy} error={step.error} onClose={step.close} onSubmit={submit}>
      {step.pending === 'close' && <p>Depois do fecho, nenhum lançamento entra neste mês para a unidade. Exige que não haja lançamentos por publicar.</p>}
      {step.pending === 'reopen' && <><p>A reabertura tem de ser feita por quem não fechou o mês e fica registada com o motivo.</p><ReasonField error={step.error} /></>}
      {step.pending === 'national_close' && <div className="alert alert--warning" role="status"><div className="alert__body"><strong>Irreversível</strong><p>O fecho nacional não pode ser desfeito. Todas as unidades com movimentos no mês têm de estar fechadas.</p></div></div>}
    </StepDialog>
  </>
}
