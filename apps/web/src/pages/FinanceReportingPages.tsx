import { useMemo, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { DataTable, ErrorState, Field, LoadingState, PageHeader, Pagination, type Column } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFinanceItem, useFinancePage } from '../hooks/useFinance'
import { financeDownload } from '../lib/finance/client'
import { fin } from '../lib/finance/endpoints'
import { formatKz } from '../lib/finance/format'
import { formatDate, formatDateTime } from '../lib/format'
import type { ContributionDetail, ContributionSummary, FinanceReport, ReportPeriodKind, ReportView } from '../types/finance'

const REPORTS = [
  ['OWN_DRE', 'DRE própria'], ['CONSOLIDATED_DRE', 'DRE consolidada'], ['OWN_DOAF', 'DOAF próprio'], ['CONSOLIDATED_DOAF', 'DOAF consolidado'],
  ['REVENUE_SUMMARY', 'Resumo de receitas'], ['EXPENSE_SUMMARY', 'Resumo de despesas'], ['INTERNAL_FUNDS_RECEIVED', 'Fundos internos recebidos'],
  ['INTERNAL_FUNDS_SENT', 'Fundos internos enviados'], ['INTERUNIT_RECONCILIATION', 'Reconciliação interunidades'], ['INTERUNIT_POSITION', 'Posição interunidades'],
  ['FINANCIAL_ACCOUNT_MOVEMENTS', 'Movimentos por conta'], ['CASH_BANK_BALANCES', 'Saldos Caixa/Banco'], ['BUDGET_VS_ACTUAL', 'Orçamento vs realizado'],
  ['RECEIVABLES', 'Valores a receber'], ['PAYABLES', 'Valores a pagar'], ['FINANCE_PERIOD_SUMMARY', 'Resumo Finance do período'],
] as const

const TRANSFER_STATUS: Record<string, string> = { REQUESTED: 'Pedida', SENT: 'Enviada', RECEIVED: 'Recebida', CANCELLED: 'Cancelada' }

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })
const currentMonth = () => today().slice(0, 7)

/** The view a report type imposes (F1D-P04): OWN_* is always own, CONSOLIDATED_* always consolidated; others follow the filter. */
const lockedView = (code: string): ReportView | undefined => (code.startsWith('OWN_') ? 'OWN' : code.startsWith('CONSOLIDATED_') ? 'CONSOLIDATED' : undefined)

function Filters({ unit, setUnit, view, setView, kind, setKind, period, setPeriod, locked }: { unit: string; setUnit: (v: string) => void; view: ReportView; setView: (v: ReportView) => void; kind: ReportPeriodKind; setKind: (v: ReportPeriodKind) => void; period: string; setPeriod: (v: string) => void; locked?: ReportView }) {
  const { finance } = useApp()
  const units = finance.context?.units.filter((u) => u.permissions.includes('FINANCE_REPORT')) ?? []
  const consolidated = finance.has('FINANCE_CONSOLIDATED_VIEW')
  return <div className="card finance-filters finance-report-filters">
    <Field label="Unidade" name="report-unit" required><select className="select" id="report-unit" value={unit || units[0]?.public_id || ''} onChange={(e) => setUnit(e.target.value)}>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
    <Field label="Visão" name="report-view"><select className="select" id="report-view" value={locked ?? view} disabled={locked !== undefined} onChange={(e) => setView(e.target.value as ReportView)}><option value="OWN">PRÓPRIO</option>{consolidated && <option value="CONSOLIDATED">CONSOLIDADO</option>}</select></Field>
    <Field label="Período" name="report-kind"><select className="select" id="report-kind" value={kind} onChange={(e) => { const k = e.target.value as ReportPeriodKind; setKind(k); setPeriod(k === 'MONTH' ? currentMonth() : k === 'QUARTER' ? `${today().slice(0, 4)}-Q1` : k === 'SEMESTER' ? `${today().slice(0, 4)}-H1` : today().slice(0, 4)) }}><option value="MONTH">Mês</option><option value="QUARTER">Trimestre</option><option value="SEMESTER">Semestre</option><option value="YEAR">Ano</option></select></Field>
    <Field label={kind === 'MONTH' ? 'Mês' : kind === 'QUARTER' ? 'Trimestre (AAAA-Q1)' : kind === 'SEMESTER' ? 'Semestre (AAAA-H1)' : 'Ano'} name="report-period"><input className="input" id="report-period" type={kind === 'MONTH' ? 'month' : 'text'} value={period} onChange={(e) => setPeriod(e.target.value)} /></Field>
  </div>
}

function useReportSelection() {
  const { finance } = useApp(); const first = finance.context?.units.find((u) => u.permissions.includes('FINANCE_REPORT'))?.public_id ?? ''
  const [unit, setUnit] = useState(''); const [view, setView] = useState<ReportView>('OWN'); const [kind, setKind] = useState<ReportPeriodKind>('MONTH'); const [period, setPeriod] = useState(currentMonth())
  const selected = unit || first; const query = useMemo(() => ({ unit: selected, view, period_kind: kind, period }), [selected, view, kind, period])
  return { unit: selected, setUnit, view, setView, kind, setKind, period, setPeriod, query }
}

function Amount({ children }: { children: string }) { return <span className="finance-amount">{formatKz(children)}</span> }

/** Sum of decimal strings in integer cents (no floating point). */
function total(values: string[]): string {
  const cents = values.reduce((sum, value) => {
    const negative = value.startsWith('-'); const [integer, fraction = '00'] = (negative ? value.slice(1) : value).split('.')
    const amount = Number(integer) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
    return sum + (negative ? -amount : amount)
  }, 0)
  const abs = Math.abs(cents)
  return `${cents < 0 ? '-' : ''}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

function Kpis({ items }: { items: (readonly [string, string])[] }) {
  return <div className="finance-dashboard-grid">{items.map(([label, value]) => <section className="card finance-kpi" key={label}><span>{label}</span><strong><Amount>{value}</Amount></strong></section>)}</div>
}

function Rows<T>({ title, rows, rowKey, columns }: { title: string; rows: T[]; rowKey: (row: T) => string; columns: Column<T>[] }) {
  return <section className="card stack"><h2>{title}</h2>{rows.length === 0 ? <p className="muted">Sem registos no período.</p> : <DataTable rows={rows} rowKey={rowKey} columns={columns} />}</section>
}

/** Official header: institution, report, unit, active view (and perimeter), period, generation time and parameters. */
function ReportHeader({ r }: { r: FinanceReport }) {
  return <section className="card finance-official-header"><strong>MEPA</strong><h2>{r.report_name}</h2><p>{r.unit.name} · {r.view === 'OWN' ? 'PRÓPRIO' : 'CONSOLIDADO'}{r.view === 'CONSOLIDATED' ? ` (${r.perimeter_units} unidades)` : ''} · {formatDate(r.period.from)} a {formatDate(r.period.to)}</p><small>Gerado em {formatDateTime(r.generated_at)} · parâmetros {r.parameters_hash.slice(0, 12)}</small></section>
}

function DashboardKpis({ d }: { d: NonNullable<FinanceReport['dashboard']> }) {
  return <Kpis items={[['Receitas', d.revenue], ['Gastos', d.expenses], ['Resultado económico', d.economic_result], ['Fundos internos recebidos', d.internal_received], ['Fundos internos enviados', d.internal_sent], ['Em trânsito', d.in_transit], ['Caixa/Banco', d.cash_bank_position], ['A receber', d.receivables], ['A pagar', d.payables], ['Execução orçamental', d.budget_execution.actual]]} />
}

function DrillDown({ r }: { r: FinanceReport }) {
  if (!r.drill_down || r.drill_down.length === 0) return null
  return <section className="card stack"><h2>Unidades do perímetro</h2><DataTable rows={r.drill_down} rowKey={(x) => x.unit.public_id} columns={[{ key: 'unit', label: 'Unidade', render: (x) => x.unit.name }, { key: 'result', label: 'Resultado próprio', render: (x) => <Amount>{x.own_result}</Amount> }, { key: 'received', label: 'Recebido', render: (x) => <Amount>{x.funds_received}</Amount> }, { key: 'sent', label: 'Enviado', render: (x) => <Amount>{x.funds_sent}</Amount> }, { key: 'closing', label: 'Saldo final', render: (x) => <Amount>{x.closing_balance}</Amount> }]} /></section>
}

/** Body of every report type (F1D-P06): the totals are always visible, the rows in a table with its own scroll. */
function ReportBody({ r }: { r: FinanceReport }): ReactNode {
  if (r.dashboard) return <><DashboardKpis d={r.dashboard} /><DrillDown r={r} /></>
  if (r.dre) return <><Kpis items={[['Receitas', r.dre.revenue], ['Gastos', r.dre.expenses], ['Resultado económico', r.dre.economic_result]]} /><Rows title="Composição" rows={[...r.dre.revenue_lines.map((x) => ({ ...x, side: 'Receita' })), ...r.dre.expense_lines.map((x) => ({ ...x, side: 'Gasto' }))]} rowKey={(x) => `${x.side}-${x.category.code}`} columns={[{ key: 'side', label: 'Classe', render: (x) => x.side }, { key: 'rubric', label: 'Rubrica', render: (x) => x.category.label }, { key: 'amount', label: 'Valor', render: (x) => <Amount>{x.amount}</Amount> }]} /></>
  if (r.doaf) return <section className="card stack"><h2>Origens e aplicações</h2><dl className="finance-custody">{[['Saldo inicial', r.doaf.opening_balance], ['Recebimentos externos', r.doaf.external_funds_received], ['Fundos internos recebidos', r.doaf.internal_funds_received], ['Aplicações externas', r.doaf.external_applications], ['Fundos internos enviados', r.doaf.internal_funds_sent], ['Fundos em trânsito', r.doaf.funds_in_transit_under_custody], ['Saldo final', r.doaf.closing_balance], ['Total de origens', r.doaf.total_origins], ['Total de aplicações', r.doaf.total_applications]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd><Amount>{value}</Amount></dd></div>)}</dl><p className={r.doaf.balanced ? 'muted' : 'alert alert--danger'}>{r.doaf.balanced ? 'Origens e aplicações equilibradas.' : 'O relatório não está equilibrado.'}</p></section>
  if (r.summary) return <><Kpis items={[['Total', total(r.summary.map((x) => x.amount))]]} /><Rows title="Rubricas" rows={r.summary} rowKey={(x) => x.category.code} columns={[{ key: 'rubric', label: 'Rubrica', render: (x) => x.category.label }, { key: 'amount', label: 'Valor', render: (x) => <Amount>{x.amount}</Amount> }]} /></>
  if (r.transfers) return <><Kpis items={[['Total', total(r.transfers.map((x) => x.amount))]]} /><Rows title="Transferências" rows={r.transfers} rowKey={(x) => x.transfer} columns={[{ key: 'origin', label: 'Origem', render: (x) => x.origin.name }, { key: 'destination', label: 'Destino', render: (x) => x.destination.name }, { key: 'purpose', label: 'Finalidade', render: (x) => x.purpose?.label ?? '—' }, { key: 'status', label: 'Estado', render: (x) => TRANSFER_STATUS[x.status] ?? x.status }, { key: 'amount', label: 'Valor', render: (x) => <Amount>{x.amount}</Amount> }]} /></>
  if (r.reconciliation) return <Rows title="Transferências do perímetro" rows={r.reconciliation} rowKey={(x) => x.transfer} columns={[{ key: 'origin', label: 'Origem', render: (x) => x.origin.name }, { key: 'destination', label: 'Destino', render: (x) => x.destination.name }, { key: 'status', label: 'Estado', render: (x) => TRANSFER_STATUS[x.status] ?? x.status }, { key: 'reconciled', label: 'Reconciliada', render: (x) => (x.reconciled ? 'Sim' : 'Não') }, { key: 'amount', label: 'Valor', render: (x) => <Amount>{x.amount}</Amount> }]} />
  if (r.position) return <Kpis items={[['Enviado', r.position.sent], ['Recebido', r.position.received], ['Posição líquida', r.position.net_control_position], ['Em trânsito', r.position.in_transit]]} />
  if (r.movements) return <Rows title="Movimentos" rows={r.movements} rowKey={(x) => `${x.entry}-${x.account.public_id}-${x.debit}-${x.credit}`} columns={[{ key: 'date', label: 'Data', render: (x) => formatDate(x.date) }, { key: 'account', label: 'Conta', render: (x) => x.account.name }, { key: 'description', label: 'Descrição', render: (x) => x.description }, { key: 'debit', label: 'Entrada', render: (x) => <Amount>{x.debit}</Amount> }, { key: 'credit', label: 'Saída', render: (x) => <Amount>{x.credit}</Amount> }]} />
  if (r.treasury) return <><Kpis items={[['Saldo final', total(r.treasury.map((x) => x.closing))]]} /><Rows title="Contas Caixa/Banco" rows={r.treasury} rowKey={(x) => x.account.public_id} columns={[{ key: 'account', label: 'Conta', render: (x) => x.account.name }, { key: 'unit', label: 'Unidade', render: (x) => x.unit.name }, { key: 'opening', label: 'Saldo inicial', render: (x) => <Amount>{x.opening}</Amount> }, { key: 'in', label: 'Entradas', render: (x) => <Amount>{x.inflows}</Amount> }, { key: 'out', label: 'Saídas', render: (x) => <Amount>{x.outflows}</Amount> }, { key: 'closing', label: 'Saldo final', render: (x) => <Amount>{x.closing}</Amount> }]} /></>
  if (r.budget_vs_actual) return <><Kpis items={[['Orçamento aprovado', r.budget_vs_actual.approved_budget], ['Realizado', r.budget_vs_actual.actual], ['Desvio', r.budget_vs_actual.variance]]} /><p className="muted">Orçamento aprovado em vigor de {r.budget_vs_actual.year}{r.budget_vs_actual.variance_percent ? ` · desvio ${r.budget_vs_actual.variance_percent}` : ''}.</p></>
  const items = r.receivables ?? r.payables
  if (items) return <><Kpis items={[['Total em aberto', total(items.map((x) => x.outstanding))]]} /><Rows title="Em aberto no fim do período" rows={items} rowKey={(x) => x.public_id} columns={[{ key: 'unit', label: 'Unidade', render: (x) => x.unit.name }, { key: 'due', label: 'Vencimento', render: (x) => formatDate(x.due_on) }, { key: 'amount', label: 'Valor', render: (x) => <Amount>{x.amount}</Amount> }, { key: 'outstanding', label: 'Em aberto', render: (x) => <Amount>{x.outstanding}</Amount> }]} /></>
  return null
}

export function FinanceDashboardPage() {
  const selection = useReportSelection(); const result = useFinanceItem<FinanceReport>(selection.unit ? fin.dashboard() : null, selection.query); const r = result.data
  return <><PageHeader title="Visão Geral Finance" description="Indicadores derivados do ledger publicado. A vista própria e a consolidada são sempre explícitas." actions={<Link className="btn btn--secondary" to="/financas/relatorios">Relatórios</Link>} />
    <Filters {...selection} />{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {r?.dashboard && <><ReportHeader r={r} /><ReportBody r={r} /></>}</>
}

export function FinanceReportsPage() {
  const selection = useReportSelection(); const { finance } = useApp(); const consolidated = finance.has('FINANCE_CONSOLIDATED_VIEW')
  // F1D-P05: without FINANCE_CONSOLIDATED_VIEW the consolidated report types are not offered at all.
  const available = REPORTS.filter(([value]) => consolidated || !value.startsWith('CONSOLIDATED_'))
  const [code, setCode] = useState('OWN_DRE'); const locked = lockedView(code); const effective = locked ?? selection.view
  const query = { ...selection.query, view: effective }; const result = useFinanceItem<FinanceReport>(selection.unit ? fin.report(code) : null, query); const r = result.data
  const [exporting, setExporting] = useState(false); const [exportError, setExportError] = useState(false)
  async function exportCsv() { setExporting(true); setExportError(false); try { await financeDownload(fin.reportExport(code), query, `${code.toLowerCase()}-${selection.period}.csv`) } catch { setExportError(true) } finally { setExporting(false) } }
  return <><PageHeader title="Relatórios Finance" description="DRE por acréscimo, DOAF por custódia e relatórios de controlo derivados do mesmo snapshot." actions={<button className="btn btn--primary" type="button" disabled={!r || exporting} onClick={() => void exportCsv()}>{exporting ? 'A exportar…' : 'Exportar CSV'}</button>} />
    {exportError && <div className="alert alert--danger" role="alert">Não foi possível exportar o relatório. Tente novamente.</div>}
    <div className="card finance-filters"><Field label="Relatório" name="report-code"><select className="select" id="report-code" value={code} onChange={(e) => setCode(e.target.value)}>{available.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field></div>
    <Filters {...selection} locked={locked} />{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {r && <><ReportHeader r={r} /><ReportBody r={r} /></>}</>
}

export function ContributionsPage() {
  const [page, setPage] = useState(1); const [unit, setUnit] = useState(''); const { finance } = useApp(); const units = finance.context?.units.filter((u) => u.permissions.includes('FINANCE_VIEW')) ?? []
  const result = useFinancePage<ContributionSummary>(fin.contributions(), { page, per_page: 50, ...(unit ? { unit } : {}) })
  return <><PageHeader title="Contribuições" description="Contribuições monetárias e em espécie externas. Transferências internas não aparecem nesta lista." /><div className="card finance-filters"><Field label="Unidade" name="contribution-unit"><select className="select" id="contribution-unit" value={unit} onChange={(e) => { setUnit(e.target.value); setPage(1) }}><option value="">Todas no meu escopo</option>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field></div>
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}{result.data && <section className="card"><DataTable rows={result.data.data} rowKey={(x) => x.public_id} columns={[{ key: 'date', label: 'Data', render: (x) => formatDate(x.received_at) }, { key: 'kind', label: 'Tipo', render: (x) => x.kind === 'MONETARY' ? 'Monetária' : 'Em espécie' }, { key: 'category', label: 'Rubrica', render: (x) => <Link to={`/financas/contribuicoes/${x.public_id}`}>{x.category.label}</Link> }, { key: 'value', label: 'Valor', render: (x) => x.amount || x.valuation_amount ? <Amount>{x.amount ?? x.valuation_amount ?? '0.00'}</Amount> : 'Não valorizada' }, { key: 'status', label: 'Estado', render: (x) => x.valuation_status ?? x.status }]} /><Pagination meta={result.data.meta} onPage={setPage} /></section>}</>
}

export function ContributionDetailPage() {
  const { id = '' } = useParams(); const result = useFinanceItem<ContributionDetail>(fin.contribution(id)); const c = result.data
  if (result.loading) return <LoadingState />
  if (result.error) return <><PageHeader title="Contribuição" back={{ to: '/financas/contribuicoes', label: 'Contribuições' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!c) return null
  return <><PageHeader title={c.category.label} description={`${c.kind === 'MONETARY' ? 'Contribuição monetária' : 'Contribuição em espécie'} · ${c.unit.name}`} back={{ to: '/financas/contribuicoes', label: 'Contribuições' }} /><section className="card"><dl className="finance-summary"><div><dt>Data</dt><dd>{formatDate(c.received_at)}</dd></div><div><dt>Estado</dt><dd>{c.valuation_status ?? c.status}</dd></div><div><dt>Valor</dt><dd>{c.amount ? <Amount>{c.amount}</Amount> : c.valuation_amount ? <Amount>{c.valuation_amount}</Amount> : 'Sem valorização aprovada'}</dd></div><div><dt>Descrição</dt><dd>{c.description ?? '—'}</dd></div><div><dt>Documento</dt><dd>{c.document?.public_id ?? '—'}</dd></div><div><dt>Contribuinte</dt><dd>{c.party ? c.party.name ?? c.party.person ?? c.party.kind : 'Identidade protegida ou contribuição anónima'}</dd></div></dl></section>{c.kind === 'IN_KIND' && !c.entry && <div className="alert alert--warning" role="status"><div className="alert__body"><strong>Sem impacto monetário</strong><p>Enquanto não existir valorização aprovada, esta contribuição não entra na DRE e nunca simula Caixa/Banco.</p></div></div>}</>
}
