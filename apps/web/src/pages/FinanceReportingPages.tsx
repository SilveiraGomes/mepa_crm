import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { DataTable, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
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

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })
const currentMonth = () => today().slice(0, 7)

function Filters({ unit, setUnit, view, setView, kind, setKind, period, setPeriod }: { unit: string; setUnit: (v: string) => void; view: ReportView; setView: (v: ReportView) => void; kind: ReportPeriodKind; setKind: (v: ReportPeriodKind) => void; period: string; setPeriod: (v: string) => void }) {
  const { finance } = useApp()
  const units = finance.context?.units.filter((u) => u.permissions.includes('FINANCE_REPORT')) ?? []
  const consolidated = finance.has('FINANCE_CONSOLIDATED_VIEW')
  return <div className="card finance-filters finance-report-filters">
    <Field label="Unidade" name="report-unit" required><select className="select" id="report-unit" value={unit || units[0]?.public_id || ''} onChange={(e) => setUnit(e.target.value)}>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
    <Field label="Visão" name="report-view"><select className="select" id="report-view" value={view} onChange={(e) => setView(e.target.value as ReportView)}><option value="OWN">PRÓPRIO</option>{consolidated && <option value="CONSOLIDATED">CONSOLIDADO</option>}</select></Field>
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

export function FinanceDashboardPage() {
  const selection = useReportSelection(); const result = useFinanceItem<FinanceReport>(selection.unit ? fin.dashboard() : null, selection.query); const d = result.data?.dashboard
  return <><PageHeader title="Visão Geral Finance" description="Indicadores derivados do ledger publicado. A vista própria e a consolidada são sempre explícitas." actions={<Link className="btn btn--secondary" to="/financas/relatorios">Relatórios</Link>} />
    <Filters {...selection} />{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {d && <><div className="finance-dashboard-grid">{[['Receitas', d.revenue], ['Gastos', d.expenses], ['Resultado económico', d.economic_result], ['Fundos internos recebidos', d.internal_received], ['Fundos internos enviados', d.internal_sent], ['Em trânsito', d.in_transit], ['Caixa/Banco', d.cash_bank_position], ['A receber', d.receivables], ['A pagar', d.payables], ['Execução orçamental', d.budget_execution.actual]].map(([label, value]) => <section className="card finance-kpi" key={label}><span>{label}</span><strong><Amount>{value}</Amount></strong></section>)}</div>
      {result.data?.drill_down && result.data.drill_down.length > 0 && <section className="card stack"><h2>Unidades do perímetro</h2><DataTable rows={result.data.drill_down} rowKey={(r) => r.unit.public_id} columns={[{ key: 'unit', label: 'Unidade', render: (r) => r.unit.name }, { key: 'result', label: 'Resultado próprio', render: (r) => <Amount>{r.own_result}</Amount> }, { key: 'received', label: 'Recebido', render: (r) => <Amount>{r.funds_received}</Amount> }, { key: 'sent', label: 'Enviado', render: (r) => <Amount>{r.funds_sent}</Amount> }, { key: 'closing', label: 'Saldo final', render: (r) => <Amount>{r.closing_balance}</Amount> }]} /></section>}
    </>}</>
}

export function FinanceReportsPage() {
  const selection = useReportSelection(); const [code, setCode] = useState('OWN_DRE'); const effective = code.startsWith('CONSOLIDATED_') ? 'CONSOLIDATED' : selection.view
  const query = { ...selection.query, view: effective }; const result = useFinanceItem<FinanceReport>(selection.unit ? fin.report(code) : null, query); const r = result.data
  const [exporting, setExporting] = useState(false)
  async function exportCsv() { setExporting(true); try { await financeDownload(fin.reportExport(code), query, `${code.toLowerCase()}-${selection.period}.csv`) } finally { setExporting(false) } }
  return <><PageHeader title="Relatórios Finance" description="DRE por acréscimo, DOAF por custódia e relatórios de controlo derivados do mesmo snapshot." actions={<button className="btn btn--primary" type="button" disabled={!r || exporting} onClick={() => void exportCsv()}>{exporting ? 'A exportar…' : 'Exportar CSV'}</button>} />
    <div className="card finance-filters"><Field label="Relatório" name="report-code"><select className="select" id="report-code" value={code} onChange={(e) => setCode(e.target.value)}>{REPORTS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field></div>
    <Filters {...selection} view={effective as ReportView} setView={selection.setView} />{result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {r && <><section className="card finance-official-header"><strong>MEPA</strong><h2>{r.report_name}</h2><p>{r.unit.name} · {r.view === 'OWN' ? 'PRÓPRIO' : 'CONSOLIDADO'} · {formatDate(r.period.from)} a {formatDate(r.period.to)}</p><small>Gerado em {formatDateTime(r.generated_at)} · parâmetros {r.parameters_hash.slice(0, 12)}</small></section>
      {r.dre && <><div className="finance-dashboard-grid"><section className="card finance-kpi"><span>Receitas</span><strong><Amount>{r.dre.revenue}</Amount></strong></section><section className="card finance-kpi"><span>Gastos</span><strong><Amount>{r.dre.expenses}</Amount></strong></section><section className="card finance-kpi"><span>Resultado económico</span><strong><Amount>{r.dre.economic_result}</Amount></strong></section></div><section className="card stack"><h2>Composição</h2><DataTable rows={[...r.dre.revenue_lines.map((x) => ({ ...x, side: 'Receita' })), ...r.dre.expense_lines.map((x) => ({ ...x, side: 'Gasto' }))]} rowKey={(x) => `${x.side}-${x.category.code}`} columns={[{ key: 'side', label: 'Classe', render: (x) => x.side }, { key: 'rubric', label: 'Rubrica', render: (x) => x.category.label }, { key: 'amount', label: 'Valor', render: (x) => <Amount>{x.amount}</Amount> }]} /></section></>}
      {r.doaf && <section className="card stack"><h2>Origens e aplicações</h2><dl className="finance-custody">{[['Saldo inicial', r.doaf.opening_balance], ['Recebimentos externos', r.doaf.external_funds_received], ['Fundos internos recebidos', r.doaf.internal_funds_received], ['Aplicações externas', r.doaf.external_applications], ['Fundos internos enviados', r.doaf.internal_funds_sent], ['Fundos em trânsito', r.doaf.funds_in_transit_under_custody], ['Saldo final', r.doaf.closing_balance], ['Total de origens', r.doaf.total_origins], ['Total de aplicações', r.doaf.total_applications]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd><Amount>{value}</Amount></dd></div>)}</dl><p className={r.doaf.balanced ? 'muted' : 'alert alert--danger'}>{r.doaf.balanced ? 'Origens e aplicações equilibradas.' : 'O relatório não está equilibrado.'}</p></section>}
    </>}</>
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
