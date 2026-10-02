import { useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFinanceItem, useFinancePage } from '../hooks/useFinance'
import { financeDownload, financeGet, financePost, newIdempotencyKey } from '../lib/finance/client'
import { fin } from '../lib/finance/endpoints'
import { toFinanceError } from '../lib/finance/errors'
import { formatKz } from '../lib/finance/format'
import { formatDate, formatDateTime } from '../lib/format'
import { hr } from '../lib/hr/endpoints'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { AccountSummary } from '../types/finance'
import type { PayrollRunDetail, PayrollRunEmployees, PayrollRunSummary, PayrollTotals } from '../types/hr'

// P0.10-F2B RH / Folha Salarial — Folhas Salariais (ADR 0021 D26-D29 + D-04A.14/15). Every action shown is the one the
// SERVER reports as available for this run (status x permission x scope x production gate): nothing is inferred here.
// The per-person breakdown is a separate, audited read (HR_COMPENSATION_VIEW only); totals never identify a person.

const RUN_STATUS: Record<string, string> = { DRAFT: 'Rascunho', CALCULATED: 'Calculada', APPROVED: 'Aprovada', POSTED: 'Contabilizada', PAID: 'Paga', CANCELLED: 'Cancelada', REVERSED: 'Anulada' }
const INPUT_STATUS: Record<string, string> = { NOT_CALCULATED: 'Ainda não calculada', CURRENT: 'Inputs actuais (o cálculo corresponde à configuração vigente)', STALE: 'Inputs alterados desde o cálculo: recalcular antes de aprovar',
  FROZEN: 'Congelados (folha aprovada: alterações futuras não a modificam)', CANCELLED: 'Folha cancelada' }
const BLOCKED: Record<string, string> = { PAYROLL_PRODUCTION_DISABLED: 'Produção salarial desactivada (payroll.production_enabled)', PAYROLL_SEGREGATION_REQUIRED: 'Quem calculou não pode aprovar',
  PAYROLL_INPUT_STALE: 'Os inputs mudaram: recalcule a folha' }
const NATURE: Record<string, string> = { EARNING: 'Abono', EMPLOYEE_DEDUCTION: 'Desconto ao trabalhador', EMPLOYER_CHARGE: 'Encargo da entidade' }
const SOURCE: Record<string, string> = { FIXED: 'Montante fixo', RULE: 'Regra legal', MANUAL: 'Manual' }
const RUN_VIEW = ['PAYROLL_MANAGE', 'PAYROLL_APPROVE', 'PAYROLL_POST', 'HR_COMPENSATION_VIEW']
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })
const label = (map: Record<string, string>, value: string) => map[value] ?? value

function useSubmit() {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function run(action: () => Promise<unknown>, done: () => void) {
    setBusy(true); setError(null)
    try { await action(); done() } catch (e) { setError(toFinanceError(e)) } finally { setBusy(false) }
  }
  return { busy, error, run, reset: () => setError(null) }
}

function Totals({ totals, headcount }: { totals: PayrollTotals; headcount: number }) {
  return <div className="finance-dashboard-grid" data-testid="payroll-totals">
    <section className="card finance-kpi"><span>Trabalhadores</span><strong>{headcount}</strong></section>
    <section className="card finance-kpi"><span>Bruto</span><strong className="finance-amount">{formatKz(totals.gross)}</strong></section>
    <section className="card finance-kpi"><span>Descontos do trabalhador</span><strong className="finance-amount">{formatKz(totals.deductions)}</strong></section>
    <section className="card finance-kpi"><span>Encargos da entidade</span><strong className="finance-amount">{formatKz(totals.employer_charges)}</strong></section>
    <section className="card finance-kpi"><span>Líquido a pagar</span><strong className="finance-amount">{formatKz(totals.net)}</strong></section>
  </div>
}

// ---- Lista -------------------------------------------------------------------------------------------------------------

export function PayrollRunsPage() {
  const { hr: access } = useApp()
  const units = access.context?.units.filter((u) => RUN_VIEW.some((p) => u.permissions.includes(p))) ?? []
  const [unit, setUnit] = useState('')
  const [period, setPeriod] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const result = useFinancePage<PayrollRunSummary>(hr.runs(), { page, per_page: 50, ...(unit ? { unit } : {}), ...(period ? { period } : {}), ...(status ? { status } : {}) })
  const canCreate = access.has('PAYROLL_MANAGE')
  return <><PageHeader title="Folhas Salariais" description="Processamentos por unidade empregadora e mês de serviço. Calcular é sempre possível; aprovar, contabilizar e pagar dependem da produção salarial."
    actions={canCreate ? <Link className="btn btn--primary" to="/rh/folhas/nova">Nova folha</Link> : undefined} />
    <ProductionBanner />
    <div className="card finance-filters">
      <Field label="Unidade empregadora" name="run-unit"><select className="select" id="run-unit" value={unit} onChange={(e) => { setUnit(e.target.value); setPage(1) }}><option value="">Todas</option>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
      <Field label="Mês de serviço" name="run-period"><input className="input" id="run-period" type="month" value={period} onChange={(e) => { setPeriod(e.target.value); setPage(1) }} /></Field>
      <Field label="Estado" name="run-status"><select className="select" id="run-status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}><option value="">Todos</option>{Object.entries(RUN_STATUS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
    </div>
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && (result.data.data.length === 0 ? <EmptyState title="Sem folhas salariais" message="Ainda não existe nenhuma folha para os filtros escolhidos." /> : <>
      <section className="card"><DataTable rows={result.data.data} rowKey={(r) => r.public_id} columns={[
        { key: 'period', label: 'Mês de serviço', render: (r) => <Link to={`/rh/folhas/${r.public_id}`}>{r.period}</Link> },
        { key: 'unit', label: 'Unidade', render: (r) => r.unit.name },
        { key: 'kind', label: 'Tipo', render: (r) => `${r.run_kind === 'REGULAR' ? 'Regular' : r.run_kind} n.º ${r.sequence}` },
        { key: 'status', label: 'Estado', render: (r) => <strong>{label(RUN_STATUS, r.status)}</strong> },
        { key: 'headcount', label: 'Trabalhadores', render: (r) => r.headcount },
        { key: 'gross', label: 'Bruto', render: (r) => <span className="finance-amount">{formatKz(r.totals.gross)}</span> },
        { key: 'net', label: 'Líquido', render: (r) => <span className="finance-amount">{formatKz(r.totals.net)}</span> },
      ]} /><Pagination meta={result.data.meta} onPage={setPage} /></section></>)}</>
}

function ProductionBanner() {
  const { hr: access } = useApp()
  const production = access.context?.production
  if (!production || production.enabled) return null
  return <div className="alert alert--warning" role="status" data-testid="production-disabled"><div className="alert__body"><strong>Produção salarial desactivada</strong>
    <p>É possível criar e calcular folhas para validar a configuração. Aprovar, contabilizar e pagar estão bloqueados até a activação documentada (payroll.production_enabled).</p></div></div>
}

// ---- Nova folha ----------------------------------------------------------------------------------------------------------

export function PayrollRunCreatePage() {
  const { hr: access, notify } = useApp()
  const navigate = useNavigate()
  const units = access.context?.units.filter((u) => u.permissions.includes('PAYROLL_MANAGE')) ?? []
  const [form, setForm] = useState({ unit: '', period: today().slice(0, 7) })
  const submit = useSubmit()
  const key = useRef(newIdempotencyKey())
  const unit = form.unit || units[0]?.public_id || ''
  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    let created = ''
    void submit.run(async () => { created = (await financePost<Item<{ public_id: string }>>(hr.runs(), { unit, period: form.period, run_kind: 'REGULAR' }, key.current)).data.public_id },
      () => { notify('Folha criada em rascunho. Calcule-a a partir dos dados vigentes no mês de serviço.'); navigate(`/rh/folhas/${created}`) })
  }
  return <><PageHeader title="Nova folha salarial" description="Uma folha regular por unidade empregadora e mês de serviço." back={{ to: '/rh/folhas', label: 'Folhas Salariais' }} />
    <section className="card"><ActionForm submitLabel="Criar folha" busy={submit.busy} error={submit.error} onSubmit={create}>
      <Field label="Unidade empregadora" name="unit" required><select className="select" id="unit" value={unit} onChange={(e) => setForm((f) => ({ ...f, unit: e.target.value }))} required>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
      <Field label="Mês de serviço" name="period" required><input className="input" id="period" type="month" value={form.period} onChange={(e) => setForm((f) => ({ ...f, period: e.target.value }))} required /></Field>
      <Field label="Tipo de folha" name="run_kind" hint="13.º, subsídio de férias e ajustamento exigem uma política aprovada que ainda não existe."><select className="select" id="run_kind" value="REGULAR" disabled><option value="REGULAR">Regular</option></select></Field>
    </ActionForm></section></>
}

// ---- Detalhe / Cálculo / Aprovação / Contabilização / Pagamento ------------------------------------------------------------

type DialogKind = 'calculate' | 'approve' | 'post' | 'pay' | 'reverse' | 'cancel'

export function PayrollRunDetailPage() {
  const { id = '' } = useParams()
  const { notify } = useApp()
  const result = useFinanceItem<PayrollRunDetail>(hr.run(id))
  const [dialog, setDialog] = useState<DialogKind | null>(null)
  const [post, setPost] = useState({ entry_date: '', reason: '' })
  const [pay, setPay] = useState({ account: '', paid_on: today() })
  const [reason, setReason] = useState('')
  const [breakdown, setBreakdown] = useState<PayrollRunEmployees | null>(null)
  const [breakdownError, setBreakdownError] = useState<UiError | null>(null)
  const [exporting, setExporting] = useState(false)
  const submit = useSubmit()
  const r = result.data
  const accounts = useFinancePage<AccountSummary>(dialog === 'pay' && r ? fin.accounts() : null, { page: 1, per_page: 100, ...(r ? { unit: r.unit.public_id } : {}) })
  if (result.loading) return <LoadingState />
  if (result.error) return <><PageHeader title="Folha salarial" back={{ to: '/rh/folhas', label: 'Folhas Salariais' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!r) return null
  const close = () => { setDialog(null); submit.reset() }
  const done = (message: string) => () => { notify(message); close(); setBreakdown(null); result.reload() }
  const lock = { lock_version: r.lock_version }
  function act(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!r) return
    switch (dialog) {
      case 'calculate': return void submit.run(() => financePost(hr.runCalculate(r.public_id), lock), done('Folha calculada a partir dos dados vigentes no mês de serviço.'))
      case 'approve': return void submit.run(() => financePost(hr.runApprove(r.public_id), lock), done('Folha aprovada. As linhas ficam imutáveis.'))
      case 'post': return void submit.run(() => financePost(hr.runPost(r.public_id), { ...lock, ...(post.entry_date ? { entry_date: post.entry_date } : {}), ...(post.reason ? { reason: post.reason } : {}) }), done('Folha contabilizada no Finance (lançamento agregado).'))
      case 'pay': return void submit.run(() => financePost(hr.runPay(r.public_id), { ...lock, account: pay.account, paid_on: pay.paid_on }), done('Líquido pago. Retenções e encargos continuam a pagar até à sua liquidação própria.'))
      case 'reverse': return void submit.run(() => financePost(hr.runReverse(r.public_id), { ...lock, reason }), done('Processamento anulado por lançamento inverso.'))
      case 'cancel': return void submit.run(() => financePost(hr.runCancel(r.public_id), { ...lock, reason }), done('Folha cancelada. O registo mantém-se no histórico.'))
    }
  }
  async function loadBreakdown() {
    setBreakdownError(null)
    try { setBreakdown((await financeGet<Item<PayrollRunEmployees>>(hr.runEmployees(r!.public_id))).data) } catch (e) { setBreakdownError(toFinanceError(e)) }
  }
  async function exportCsv() { setExporting(true); try { await financeDownload(hr.runSummaryCsv(r!.public_id), {}, `folha-${r!.period}-${r!.public_id}.csv`) } catch { notify('Não foi possível exportar o resumo.') } finally { setExporting(false) } }
  const buttons: [DialogKind, string, string][] = [['calculate', r.status === 'CALCULATED' ? 'Recalcular' : 'Calcular', 'btn--primary'], ['approve', 'Aprovar', 'btn--primary'], ['post', 'Contabilizar', 'btn--primary'],
    ['pay', 'Pagar líquido', 'btn--primary'], ['reverse', 'Anular processamento', 'btn--danger'], ['cancel', 'Cancelar folha', 'btn--secondary']]
  const p = r.provenance
  return <><PageHeader title={`Folha ${r.period} · ${r.unit.name}`} description={`${r.run_kind === 'REGULAR' ? 'Regular' : r.run_kind} n.º ${r.sequence} · ${label(RUN_STATUS, r.status)}`} back={{ to: '/rh/folhas', label: 'Folhas Salariais' }}
    actions={<div className="row payroll-actions" data-testid="run-actions">{buttons.filter(([k]) => r.actions[k]).map(([k, text, style]) => <button key={k} className={`btn ${style}`} type="button" onClick={() => setDialog(k)}>{text}</button>)}</div>} />
    {!r.production.enabled && <div className="alert alert--warning" role="status" data-testid="production-disabled"><div className="alert__body"><strong>Produção salarial desactivada</strong><p>Calcular continua disponível. Aprovar, contabilizar e pagar estão bloqueados.</p></div></div>}
    {Object.entries(r.blocked).length > 0 && <div className="alert alert--info" role="status" data-testid="run-blocked"><div className="alert__body"><strong>Acções bloqueadas</strong>
      <ul>{Object.entries(r.blocked).map(([action, code]) => <li key={action}>{action === 'approve' ? 'Aprovar' : action === 'post' ? 'Contabilizar' : 'Pagar'}: {label(BLOCKED, code ?? '')}</li>)}</ul></div></div>}
    <section className="card"><dl className="finance-summary" data-testid="run-header">
      <div><dt>Unidade empregadora</dt><dd>{r.unit.name}</dd></div><div><dt>Mês de serviço</dt><dd>{r.period}</dd></div><div><dt>Tipo / sequência</dt><dd>{r.run_kind} n.º {r.sequence}</dd></div>
      <div><dt>Estado</dt><dd><strong>{label(RUN_STATUS, r.status)}</strong></dd></div>
      <div><dt>Calculada por</dt><dd>{p.calculated_by ? `${p.calculated_by} · ${formatDateTime(p.calculated_at ?? '')}` : '—'}</dd></div>
      <div><dt>Aprovada por</dt><dd>{p.approved_by ? `${p.approved_by} · ${formatDateTime(p.approved_at ?? '')}` : '—'}</dd></div>
      <div><dt>Contabilizada por</dt><dd>{p.posted_by ? `${p.posted_by} · ${formatDateTime(p.posted_at ?? '')}` : '—'}</dd></div>
      <div><dt>Paga por</dt><dd>{p.paid_by ? `${p.paid_by} · ${formatDateTime(p.paid_at ?? '')}` : '—'}</dd></div>
      {p.cancel_reason && <div><dt>Cancelamento</dt><dd>{p.cancel_reason}</dd></div>}{p.reversal_reason && <div><dt>Anulação</dt><dd>{p.reversal_reason}</dd></div>}
    </dl></section>
    <Totals totals={r.totals} headcount={r.headcount} />
    <section className="card stack" data-testid="run-input"><h2>Inputs do cálculo</h2><p><strong>{label(INPUT_STATUS, r.input.status)}</strong></p>
      {r.input.hash && <p className="muted">Hash canónico ({r.input.algorithm}, {r.input.version}): <code className="hash">{r.input.hash}</code></p>}</section>
    <section className="card stack" data-testid="run-finance"><h2>Finance</h2><dl className="finance-summary">
      <div><dt>Contabilização (processamento)</dt><dd>{r.finance.accrual ? `${r.finance.accrual.entry_kind} · ${formatDate(r.finance.accrual.entry_date)} · período ${r.finance.accrual.accounting_period}` : 'Não contabilizada'}</dd></div>
      <div><dt>Pagamento do líquido</dt><dd>{r.finance.payment ? `${formatDate(r.finance.payment.entry_date)} · período ${r.finance.payment.accounting_period}` : r.finance.payment_state === 'NET_PAYABLE_OPEN' ? 'Líquido por pagar' : '—'}</dd></div>
      {r.finance.reversal && <div><dt>Anulação</dt><dd>{formatDate(r.finance.reversal.entry_date)} · período {r.finance.reversal.accounting_period}</dd></div>}
      <div><dt>Retenções e encargos</dt><dd>{r.finance.statutory_liabilities === 'OPEN_UNTIL_LIABILITY_PAYMENT' ? 'A entregar (INSS / IRT / encargos): liquidação própria, não pela folha' : '—'}</dd></div>
    </dl><p className="muted">O Finance recebe só o lançamento agregado por rubrica e conta; nunca o detalhe por trabalhador.</p>
      <div className="row"><button className="btn btn--secondary" type="button" onClick={() => void exportCsv()} disabled={exporting}>{exporting ? 'A exportar…' : 'Exportar resumo (CSV)'}</button></div></section>
    <section className="card stack" data-testid="run-breakdown"><h2>Detalhe por trabalhador</h2>
      {!r.employee_detail_visible ? <p className="muted">Reservado: só a permissão HR_COMPENSATION_VIEW vê valores individuais.</p>
        : breakdown === null ? <><p className="muted">Cada consulta do detalhe individual fica registada na auditoria.</p><div className="row"><button className="btn btn--secondary" type="button" onClick={() => void loadBreakdown()}>Mostrar detalhe por trabalhador</button></div>
          {breakdownError && <ErrorState error={breakdownError} retry={() => void loadBreakdown()} />}</>
        : breakdown.employees.length === 0 ? <p className="muted">Sem linhas calculadas.</p>
        : breakdown.employees.map((e) => <article key={e.employment} className="stack payroll-employee"><h3>{e.person.name}{e.job_title ? ` · ${e.job_title}` : ''}</h3>
          <DataTable rows={e.lines} rowKey={(l) => l.component.code} columns={[
            { key: 'component', label: 'Componente', render: (l) => l.component.label },
            { key: 'nature', label: 'Natureza', render: (l) => label(NATURE, l.component.nature) },
            { key: 'source', label: 'Origem', render: (l) => l.rule ? `${label(SOURCE, l.source)} ${l.rule.code} v${l.rule.version}` : label(SOURCE, l.source) },
            { key: 'base', label: 'Base', render: (l) => l.base_amount ? formatKz(l.base_amount) : '—' },
            { key: 'rate', label: 'Taxa', render: (l) => l.rate ?? '—' },
            { key: 'amount', label: 'Montante', render: (l) => <span className="finance-amount">{formatKz(l.amount)}</span> },
          ]} />
          <p className="muted">Bruto {formatKz(e.gross)} · Descontos {formatKz(e.deductions)} · Encargos {formatKz(e.employer_charges)} · <strong>Líquido {formatKz(e.net)}</strong></p></article>)}
    </section>
    <Dialog open={dialog !== null} title={dialog ? buttons.find(([k]) => k === dialog)?.[1] ?? '' : ''} onClose={close}>
      <ActionForm submitLabel="Confirmar" busy={submit.busy} error={submit.error} onSubmit={act}>
        {dialog === 'calculate' && <p>O cálculo usa os vínculos, remunerações e regras aprovadas vigentes em {r.period}. Um mês com entrada, saída ou alteração a meio é recusado (sem política de proporcionalidade).</p>}
        {dialog === 'approve' && <p>Aprovar valida o cálculo (o hash dos inputs é recalculado no servidor); não altera números. Quem calculou não pode aprovar.</p>}
        {dialog === 'post' && <><p>Gera um único lançamento agregado (gasto com pessoal; retenções, encargos e líquido a pagar).</p>
          <Field label="Data contabilística" name="entry_date" hint={`Por omissão o fim de ${r.period}. Outro mês exige motivo (mês de serviço fechado).`}><input className="input" id="entry_date" type="date" value={post.entry_date} onChange={(e) => setPost((f) => ({ ...f, entry_date: e.target.value }))} /></Field>
          <Field label="Motivo" name="post_reason"><textarea className="input" id="post_reason" value={post.reason} onChange={(e) => setPost((f) => ({ ...f, reason: e.target.value }))} /></Field></>}
        {dialog === 'pay' && <><p>Paga exactamente o líquido contabilizado: <strong>{formatKz(r.totals.net)}</strong>. Retenções e encargos não são pagos aqui.</p>
          {accounts.error && <ErrorState error={accounts.error} retry={accounts.reload} />}
          <Field label="Conta de caixa / banco" name="pay_account" required><select className="select" id="pay_account" value={pay.account} onChange={(e) => setPay((f) => ({ ...f, account: e.target.value }))} required>
            <option value="">Escolher…</option>{(accounts.data?.data ?? []).filter((a) => a.status === 'OPEN').map((a) => <option key={a.public_id} value={a.public_id}>{a.name} ({a.kind})</option>)}</select></Field>
          <Field label="Data do pagamento" name="paid_on" required><input className="input" id="paid_on" type="date" value={pay.paid_on} onChange={(e) => setPay((f) => ({ ...f, paid_on: e.target.value }))} required /></Field></>}
        {(dialog === 'reverse' || dialog === 'cancel') && <Field label="Motivo" name="run_reason" required><textarea className="input" id="run_reason" value={reason} onChange={(e) => setReason(e.target.value)} required minLength={3} /></Field>}
      </ActionForm>
    </Dialog></>
}
