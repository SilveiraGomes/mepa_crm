import { useMemo, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFinanceItem, useFinancePage } from '../hooks/useFinance'
import { financePost } from '../lib/finance/client'
import { toFinanceError } from '../lib/finance/errors'
import { formatKz } from '../lib/finance/format'
import { formatDate, formatDateTime } from '../lib/format'
import { hr } from '../lib/hr/endpoints'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { CompensationLine, CompensationOverview, EmploymentDetail, EmploymentSummary, HrComponent, PayrollReadiness, PayrollRuleDetail, PayrollRules } from '../types/hr'

// P0.10-F2A RH / Folha Salarial (ADR 0021 D23-D28 + D-04A.15). Every value shown comes from the HR API, which projects
// salaries only to HR_COMPENSATION_VIEW (and audits every such read). Processing, approval, posting and payment of a
// payroll do not exist before F2B: their buttons are disabled and labelled as such.

const STATUS: Record<string, string> = { ACTIVE: 'Activo', ENDED: 'Encerrado', DRAFT: 'Rascunho', APPROVED: 'Aprovada', RETIRED: 'Retirada' }
const KIND: Record<string, string> = { EMPLOYEE: 'Funcionário', BENEFICIARY: 'Beneficiário (reforma / pensão / terceira idade)' }
const NATURE: Record<string, string> = { EARNING: 'Abono', EMPLOYEE_DEDUCTION: 'Desconto ao trabalhador', EMPLOYER_CHARGE: 'Encargo da entidade' }
const METHOD: Record<string, string> = { FIXED_AMOUNT: 'Montante fixo', RATE_RULE: 'Taxa por regra legal', BRACKET_RULE: 'Escalões por regra legal', MANUAL: 'Manual', FLAT_RATE: 'Taxa única', BRACKET: 'Escalões' }
const ISSUE: Record<string, string> = {
  NO_ACTIVE_EMPLOYMENT: 'Sem vínculos activos no mês', MISSING_COMPENSATION: 'Vínculo sem remuneração vigente', INVALID_EFFECTIVITY: 'Vigência de remuneração inválida',
  MISSING_RULE: 'Regra legal em falta (configuração pendente)', AMBIGUOUS_RULE: 'Regras legais ambíguas no mesmo período', INVALID_RULE: 'Regra legal incompleta',
  MISSING_DOCUMENT: 'Fonte normativa da regra em falta', PRODUCTION_DISABLED: 'Produção salarial desactivada',
}
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })
const label = (map: Record<string, string>, value: string) => map[value] ?? value

function useHrUnits(permission: string) {
  const { hr: access } = useApp()
  return access.context?.units.filter((u) => u.permissions.includes(permission)) ?? []
}

function useSubmit() {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function run(action: () => Promise<unknown>, done: () => void) {
    setBusy(true); setError(null)
    try { await action(); done() } catch (e) { setError(toFinanceError(e)) } finally { setBusy(false) }
  }
  return { busy, error, run, reset: () => setError(null) }
}

function Money({ value }: { value: string | null }) {
  return value === null ? <span className="muted">Por regra legal</span> : <span className="finance-amount">{formatKz(value)}</span>
}

// ---- Funcionários / Vínculos -------------------------------------------------------------------------------------------

function EmploymentList({ title, description, active }: { title: string; description: string; active: boolean }) {
  const { hr: access } = useApp()
  const units = useHrUnits('HR_EMPLOYMENT_VIEW')
  const [unit, setUnit] = useState('')
  const [status, setStatus] = useState(active ? 'ACTIVE' : '')
  const [page, setPage] = useState(1)
  const result = useFinancePage<EmploymentSummary>(hr.employments(), { page, per_page: 50, ...(unit ? { unit } : {}), ...(status ? { status } : {}) })
  const canCreate = access.has('HR_EMPLOYMENT_MANAGE')
  return <><PageHeader title={title} description={description} actions={canCreate ? <Link className="btn btn--primary" to="/rh/vinculos/novo">Novo vínculo</Link> : undefined} />
    <div className="card finance-filters"><Field label="Unidade empregadora" name="hr-unit"><select className="select" id="hr-unit" value={unit} onChange={(e) => { setUnit(e.target.value); setPage(1) }}><option value="">Todas no meu escopo</option>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
      {!active && <Field label="Estado" name="hr-status"><select className="select" id="hr-status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}><option value="">Todos</option><option value="ACTIVE">Activo</option><option value="ENDED">Encerrado</option></select></Field>}</div>
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && (result.data.data.length === 0 ? <EmptyState title="Sem vínculos" message="Não existem vínculos laborais para estes filtros." /> : <section className="card"><DataTable rows={result.data.data} rowKey={(e) => e.public_id} columns={[
      { key: 'person', label: 'Pessoa', render: (e) => <Link to={`/rh/funcionarios/${e.public_id}`}>{e.person.name}</Link> },
      { key: 'unit', label: 'Unidade empregadora', render: (e) => e.unit.name },
      { key: 'kind', label: 'Vínculo', render: (e) => label(KIND, e.relationship_kind) },
      { key: 'job', label: 'Função', render: (e) => e.job_title ?? '—' },
      { key: 'period', label: 'Período', render: (e) => `${formatDate(e.starts_on)} – ${e.ends_on ? formatDate(e.ends_on) : 'actual'}` },
      { key: 'status', label: 'Estado', render: (e) => label(STATUS, e.status) },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></section>)}</>
}

export function HrEmployeesPage() {
  return <EmploymentList active title="Funcionários" description="Pessoas com vínculo laboral activo na MEPA. A identidade é sempre a Pessoa; o vínculo não é cargo eclesiástico nem membresia." />
}

export function HrEmploymentsPage() {
  return <EmploymentList active={false} title="Vínculos" description="Histórico de vínculos laborais (activos e encerrados). Um vínculo encerrado nunca é apagado." />
}

export function HrEmploymentCreatePage() {
  const { hr: access, notify } = useApp()
  const navigate = useNavigate()
  const units = useHrUnits('HR_EMPLOYMENT_MANAGE')
  const [form, setForm] = useState({ person: '', unit: '', relationship_kind: 'EMPLOYEE', job_title: '', starts_on: today(), contract_document: '' })
  const submit = useSubmit()
  if (!access.has('HR_EMPLOYMENT_MANAGE')) return <><PageHeader title="Novo vínculo" back={{ to: '/rh/vinculos', label: 'Vínculos' }} /><EmptyState title="Sem permissão" message="Criar vínculos exige HR_EMPLOYMENT_MANAGE." /></>
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void submit.run(() => financePost<Item<{ public_id: string }>>(hr.employments(), { person: form.person.trim(), unit: form.unit || units[0]?.public_id, relationship_kind: form.relationship_kind, job_title: form.job_title || null, starts_on: form.starts_on,
      ...(form.contract_document.trim() ? { contract_document: form.contract_document.trim() } : {}) }).then((r) => { notify('Vínculo criado.'); navigate(`/rh/funcionarios/${r.data.public_id}`) }), () => undefined)
  }
  return <><PageHeader title="Novo vínculo laboral" description="Associa uma Pessoa existente (People) à unidade empregadora. Nenhum dado pessoal é copiado." back={{ to: '/rh/vinculos', label: 'Vínculos' }} />
    <div className="card"><ActionForm submitLabel="Criar vínculo" busy={submit.busy} error={submit.error} onSubmit={onSubmit}>
      <Field label="Pessoa (identificador público)" name="person" required hint="O identificador público da Pessoa no módulo Pessoas."><input className="input" id="person" value={form.person} onChange={set('person')} required maxLength={26} /></Field>
      <Field label="Unidade empregadora" name="unit" required><select className="select" id="unit" value={form.unit || units[0]?.public_id || ''} onChange={set('unit')}>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
      <Field label="Tipo de vínculo" name="relationship_kind" required><select className="select" id="relationship_kind" value={form.relationship_kind} onChange={set('relationship_kind')}>{Object.entries(KIND).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
      <Field label="Função laboral" name="job_title" hint="Texto livre; não é cargo eclesiástico."><input className="input" id="job_title" value={form.job_title} onChange={set('job_title')} maxLength={160} /></Field>
      <Field label="Início" name="starts_on" required><input className="input" id="starts_on" type="date" value={form.starts_on} onChange={set('starts_on')} required /></Field>
      <Field label="Contrato de trabalho (documento)" name="contract_document" hint="Opcional: identificador público de um documento CONTRACT da unidade."><input className="input" id="contract_document" value={form.contract_document} onChange={set('contract_document')} maxLength={26} /></Field>
    </ActionForm></div></>
}

function CompensationTable({ rows }: { rows: CompensationLine[] }) {
  return rows.length === 0 ? <p className="muted">Sem linhas de remuneração.</p> : <DataTable rows={rows} rowKey={(l) => `${l.component.code}-${l.starts_on}`} columns={[
    { key: 'component', label: 'Componente', render: (l) => l.component.label },
    { key: 'nature', label: 'Natureza', render: (l) => label(NATURE, l.component.nature) },
    { key: 'amount', label: 'Valor (AOA)', render: (l) => <Money value={l.amount} /> },
    { key: 'from', label: 'Desde', render: (l) => formatDate(l.starts_on) },
    { key: 'to', label: 'Até', render: (l) => (l.ends_on ? formatDate(l.ends_on) : 'em vigor') },
    { key: 'reason', label: 'Motivo', render: (l) => l.reason },
  ]} />
}

export function HrEmploymentDetailPage() {
  const { id = '' } = useParams()
  const { hr: access, notify } = useApp()
  const result = useFinanceItem<EmploymentDetail>(hr.employment(id))
  const [dialog, setDialog] = useState<'end' | 'change' | null>(null)
  const [end, setEnd] = useState({ ends_on: today(), end_reason: '' })
  const components = access.context?.components.filter((c) => c.active) ?? []
  const [change, setChange] = useState({ component: 'BASE_SALARY', amount: '', starts_on: today(), reason: '' })
  const submit = useSubmit()
  const e = result.data
  if (result.loading) return <LoadingState />
  if (result.error) return <><PageHeader title="Funcionário" back={{ to: '/rh/funcionarios', label: 'Funcionários' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!e) return null
  const selected = components.find((c) => c.code === change.component)
  const close = () => { setDialog(null); submit.reset() }
  function endEmployment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void submit.run(() => financePost(hr.endEmployment(id), end), () => { notify('Vínculo encerrado. O histórico mantém-se.'); close(); result.reload() })
  }
  function changeCompensation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void submit.run(() => financePost(hr.compensation(id), { component: change.component, amount: selected?.rule_based ? null : change.amount, starts_on: change.starts_on, reason: change.reason }),
      () => { notify('Nova linha de remuneração registada; a anterior foi encerrada, não alterada.'); close(); result.reload() })
  }
  return <><PageHeader title={e.person.name} description={`${label(KIND, e.relationship_kind)} · ${e.unit.name}`} back={{ to: '/rh/funcionarios', label: 'Funcionários' }}
    actions={<>{e.actions.includes('change_compensation') && <button className="btn btn--secondary" type="button" onClick={() => setDialog('change')}>Alterar remuneração</button>}{e.actions.includes('end') && <button className="btn btn--danger" type="button" onClick={() => setDialog('end')}>Encerrar vínculo</button>}</>} />
    <section className="card"><dl className="finance-summary">
      <div><dt>Pessoa</dt><dd>{e.person.name}</dd></div><div><dt>Unidade empregadora</dt><dd>{e.unit.name}</dd></div><div><dt>Tipo de vínculo</dt><dd>{label(KIND, e.relationship_kind)}</dd></div>
      <div><dt>Função</dt><dd>{e.job_title ?? '—'}</dd></div><div><dt>Estado</dt><dd>{label(STATUS, e.status)}</dd></div><div><dt>Início</dt><dd>{formatDate(e.starts_on)}</dd></div>
      <div><dt>Fim</dt><dd>{e.ends_on ? formatDate(e.ends_on) : '—'}</dd></div><div><dt>Motivo de fim</dt><dd>{e.end_reason ?? '—'}</dd></div>
      <div><dt>Contrato</dt><dd>{e.contract_document?.public_id ?? (e.contract_document ? 'Documento sem acesso' : '—')}</dd></div>
    </dl></section>
    <section className="card stack"><h2>Histórico de vínculos</h2><DataTable rows={e.history} rowKey={(h) => h.public_id} columns={[
      { key: 'unit', label: 'Unidade', render: (h) => h.current ? <strong>{h.unit.name}</strong> : <Link to={`/rh/funcionarios/${h.public_id}`}>{h.unit.name}</Link> },
      { key: 'kind', label: 'Vínculo', render: (h) => label(KIND, h.relationship_kind) },
      { key: 'period', label: 'Período', render: (h) => `${formatDate(h.starts_on)} – ${h.ends_on ? formatDate(h.ends_on) : 'actual'}` },
      { key: 'status', label: 'Estado', render: (h) => label(STATUS, h.status) },
    ]} /></section>
    {e.compensation_visible && e.compensation ? <>
      <section className="card stack"><h2>Remuneração vigente</h2><p className="muted">Em {formatDate(e.compensation.as_of)}. Valores em AOA; componentes por regra legal não têm valor manual.</p><CompensationTable rows={e.compensation.current} /></section>
      <section className="card stack"><h2>Histórico de remuneração</h2><CompensationTable rows={e.compensation.history} /></section>
    </> : <div className="alert alert--warning" role="status"><div className="alert__body"><strong>Remuneração reservada</strong><p>Os valores salariais só são mostrados com a permissão HR_COMPENSATION_VIEW.</p></div></div>}
    <Dialog open={dialog === 'end'} title="Encerrar vínculo" onClose={close}><ActionForm submitLabel="Encerrar vínculo" busy={submit.busy} error={submit.error} onSubmit={endEmployment}>
      <Field label="Data de fim" name="ends_on" required><input className="input" id="ends_on" type="date" value={end.ends_on} onChange={(ev) => setEnd((f) => ({ ...f, ends_on: ev.target.value }))} required /></Field>
      <Field label="Motivo" name="end_reason" required><textarea className="input" id="end_reason" value={end.end_reason} onChange={(ev) => setEnd((f) => ({ ...f, end_reason: ev.target.value }))} required /></Field>
    </ActionForm></Dialog>
    <Dialog open={dialog === 'change'} title="Alterar remuneração" onClose={close}><ActionForm submitLabel="Registar" busy={submit.busy} error={submit.error} onSubmit={changeCompensation}>
      <Field label="Componente" name="component" required><select className="select" id="component" value={change.component} onChange={(ev) => setChange((f) => ({ ...f, component: ev.target.value }))}>{components.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}</select></Field>
      {selected?.rule_based ? <p className="muted">Componente calculado por regra legal aprovada: regista-se apenas a aplicabilidade, sem valor.</p>
        : <Field label="Valor mensal (AOA)" name="amount" required hint="Até 2 casas decimais."><input className="input" id="amount" inputMode="decimal" value={change.amount} onChange={(ev) => setChange((f) => ({ ...f, amount: ev.target.value }))} required pattern="\d{1,15}(\.\d{1,2})?" /></Field>}
      <Field label="Vigência a partir de" name="starts_on" required><input className="input" id="starts_on" type="date" value={change.starts_on} onChange={(ev) => setChange((f) => ({ ...f, starts_on: ev.target.value }))} required /></Field>
      <Field label="Motivo" name="reason" required><textarea className="input" id="reason" value={change.reason} onChange={(ev) => setChange((f) => ({ ...f, reason: ev.target.value }))} required /></Field>
    </ActionForm></Dialog></>
}

// ---- Remuneração -----------------------------------------------------------------------------------------------------

export function HrCompensationPage() {
  const units = useHrUnits('HR_COMPENSATION_VIEW')
  const [unit, setUnit] = useState('')
  const selected = unit || units[0]?.public_id || ''
  const result = useFinanceItem<CompensationOverview>(selected ? hr.compensations() : null, { unit: selected })
  return <><PageHeader title="Remuneração" description="Salário base e abonos fixos vigentes por unidade. Dado sensível: cada consulta fica auditada." />
    <div className="card finance-filters"><Field label="Unidade empregadora" name="comp-unit"><select className="select" id="comp-unit" value={selected} onChange={(e) => setUnit(e.target.value)}>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field></div>
    {units.length === 0 && <EmptyState title="Sem acesso" message="Consultar remuneração exige HR_COMPENSATION_VIEW." />}
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && (result.data.data.length === 0 ? <EmptyState title="Sem vínculos activos" /> : <section className="card"><p className="muted">Vigente em {formatDate(result.data.as_of)}.</p><DataTable rows={result.data.data} rowKey={(r) => r.employment} columns={[
      { key: 'person', label: 'Pessoa', render: (r) => <Link to={`/rh/funcionarios/${r.employment}`}>{r.person.name}</Link> },
      { key: 'job', label: 'Função', render: (r) => r.job_title ?? '—' },
      { key: 'base', label: 'Salário base', render: (r) => r.base_salary === null ? <span className="muted">Sem salário base</span> : <Money value={r.base_salary} /> },
      { key: 'fixed', label: 'Abonos fixos', render: (r) => <Money value={r.fixed_earnings} /> },
      { key: 'components', label: 'Componentes', render: (r) => r.components },
    ]} /></section>)}</>
}

// ---- Componentes -----------------------------------------------------------------------------------------------------

export function HrComponentsPage() {
  const result = useFinanceItem<HrComponent[]>(hr.components())
  return <><PageHeader title="Componentes remuneratórios" description="Catálogo genérico (ADR 0021 D24). Não contém valores nem taxas: estes vêm da remuneração de cada vínculo ou de regras legais aprovadas." />
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && <section className="card"><DataTable rows={result.data} rowKey={(c) => c.code} columns={[
      { key: 'label', label: 'Componente', render: (c) => c.label },
      { key: 'nature', label: 'Natureza', render: (c) => label(NATURE, c.nature) },
      { key: 'method', label: 'Cálculo', render: (c) => label(METHOD, c.calculation_method) },
      { key: 'rubric', label: 'Rubrica / passivo', render: (c) => [c.rubric, c.liability_role].filter(Boolean).join(' · ') || '—' },
    ]} /></section>}</>
}

// ---- Regras ------------------------------------------------------------------------------------------------------------

export function HrRulesPage() {
  const { hr: access, notify } = useApp()
  const result = useFinanceItem<PayrollRules>(hr.rules())
  const [approve, setApprove] = useState<{ code: string; version: number } | null>(null)
  const [document, setDocument] = useState('')
  const submit = useSubmit()
  const close = () => { setApprove(null); setDocument(''); submit.reset() }
  function onApprove(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!approve) return
    void submit.run(() => financePost(hr.approveRule(approve.code, approve.version), document.trim() ? { source_document: document.trim() } : {}), () => { notify('Regra aprovada.'); close(); result.reload() })
  }
  return <><PageHeader title="Regras legais" description="INSS, IRT, 13.º, subsídio de férias, pensões e outras regras só existem quando carregadas da fonte oficial e aprovadas por outra pessoa. Nenhuma taxa é pré-definida."
    actions={access.has('PAYROLL_RULES_MANAGE') ? <Link className="btn btn--primary" to="/rh/regras/nova">Nova versão de regra</Link> : undefined} />
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && <>
      <section className="card stack"><h2>Cobertura em {formatDate(result.data.coverage_today.date)}</h2><DataTable rows={result.data.coverage_today.components} rowKey={(c) => c.component} columns={[
        { key: 'component', label: 'Componente', render: (c) => c.label },
        { key: 'status', label: 'Estado', render: (c) => c.status === 'CONFIGURED' ? 'Configurada' : c.status === 'PENDING_CONFIGURATION' ? <strong>Configuração pendente</strong> : c.status },
        { key: 'rule', label: 'Regra em vigor', render: (c) => (c.rule ? `${c.rule.code} v${c.rule.version}` : '—') },
      ]} /></section>
      <section className="card stack"><h2>Versões de regras</h2>{result.data.data.length === 0 ? <EmptyState title="Nenhuma regra carregada" message="Configuração pendente: carregue as regras a partir da fonte normativa oficial." /> : <DataTable rows={result.data.data} rowKey={(r) => `${r.code}-${r.version}`} columns={[
        { key: 'code', label: 'Regra', render: (r) => <Link to={`/rh/regras/${r.code}/${r.version}`}>{r.code} v{r.version}</Link> },
        { key: 'component', label: 'Componente', render: (r) => r.component },
        { key: 'method', label: 'Método', render: (r) => label(METHOD, r.method) },
        { key: 'period', label: 'Vigência', render: (r) => `${formatDate(r.starts_on)} – ${r.ends_on ? formatDate(r.ends_on) : 'sem fim'}` },
        { key: 'status', label: 'Estado', render: (r) => label(STATUS, r.status) },
        { key: 'source', label: 'Fonte normativa', render: (r) => r.source_document?.public_id ?? (r.source_document ? 'Sem acesso' : 'Em falta') },
        { key: 'actions', label: 'Acções', render: (r) => r.actions.includes('approve') ? <button className="btn btn--secondary btn--sm" type="button" onClick={() => setApprove({ code: r.code, version: r.version })}>Aprovar</button> : '—' },
      ]} />}</section>
    </>}
    <Dialog open={approve !== null} title={approve ? `Aprovar ${approve.code} v${approve.version}` : 'Aprovar regra'} onClose={close}><ActionForm submitLabel="Aprovar" busy={submit.busy} error={submit.error} onSubmit={onApprove}>
      <p className="muted">A aprovação exige a fonte normativa (documento PAYROLL_RULE_SOURCE) e é feita por pessoa diferente de quem preparou a regra.</p>
      <Field label="Fonte normativa (documento)" name="rule-document" hint="Deixe vazio para usar o documento indicado no rascunho."><input className="input" id="rule-document" value={document} onChange={(e) => setDocument(e.target.value)} maxLength={26} /></Field>
    </ActionForm></Dialog></>
}

export function HrRuleCreatePage() {
  const { hr: access, notify } = useApp()
  const navigate = useNavigate()
  const ruleComponents = useMemo(() => access.context?.components.filter((c) => c.rule_based) ?? [], [access.context])
  const earnings = useMemo(() => access.context?.components.filter((c) => c.nature === 'EARNING') ?? [], [access.context])
  const [form, setForm] = useState({ code: '', component: '', rate: '', starts_on: today(), ends_on: '', base: ['BASE_SALARY'] as string[], source_document: '' })
  const [brackets, setBrackets] = useState([{ lower_bound: '0.00', upper_bound: '', rate: '', fixed_amount: '0.00', excess_over: '0.00' }])
  const submit = useSubmit()
  const component = ruleComponents.find((c) => c.code === (form.component || ruleComponents[0]?.code))
  const bracket = component?.calculation_method === 'BRACKET_RULE'
  const key = useRef(0)
  if (!access.has('PAYROLL_RULES_MANAGE')) return <><PageHeader title="Nova regra" back={{ to: '/rh/regras', label: 'Regras' }} /><EmptyState title="Sem permissão" message="Preparar regras exige PAYROLL_RULES_MANAGE ao nível nacional." /></>
  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    key.current += 1
    void submit.run(() => financePost<Item<{ code: string; version: number }>>(hr.rules(), { code: form.code.trim().toUpperCase(), component: component?.code, method: bracket ? 'BRACKET' : 'FLAT_RATE', rate: bracket ? null : form.rate,
      starts_on: form.starts_on, ends_on: form.ends_on || null, base_components: form.base, ...(bracket ? { brackets: brackets.map((b) => ({ ...b, upper_bound: b.upper_bound || null })) } : {}),
      ...(form.source_document.trim() ? { source_document: form.source_document.trim() } : {}) }).then((r) => { notify('Rascunho de regra criado. Falta a aprovação por outra pessoa.'); navigate(`/rh/regras/${r.data.code}/${r.data.version}`) }), () => undefined)
  }
  return <><PageHeader title="Nova versão de regra legal" description="Os valores são transcritos da fonte oficial. Nada aqui é sugerido pelo sistema." back={{ to: '/rh/regras', label: 'Regras' }} />
    <div className="card"><ActionForm submitLabel="Criar rascunho" busy={submit.busy} error={submit.error} onSubmit={onSubmit}>
      <Field label="Código da regra" name="rule-code" required hint="Ex.: INSS_TRABALHADOR (estável entre versões)."><input className="input" id="rule-code" value={form.code} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))} required maxLength={64} /></Field>
      <Field label="Componente" name="rule-component" required><select className="select" id="rule-component" value={component?.code ?? ''} onChange={(e) => setForm((f) => ({ ...f, component: e.target.value }))}>{ruleComponents.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}</select></Field>
      {!bracket && <Field label="Taxa (fracção, ex. 0.030000)" name="rule-rate" required><input className="input" id="rule-rate" inputMode="decimal" value={form.rate} onChange={(e) => setForm((f) => ({ ...f, rate: e.target.value }))} required /></Field>}
      {bracket && <fieldset className="stack"><legend>Escalões</legend>{brackets.map((b, i) => <div className="finance-filters" key={i}>
        {(['lower_bound', 'upper_bound', 'rate', 'fixed_amount', 'excess_over'] as const).map((k) => <Field key={k} label={{ lower_bound: 'Desde', upper_bound: 'Até (vazio = sem limite)', rate: 'Taxa', fixed_amount: 'Parcela fixa', excess_over: 'Excesso sobre' }[k]} name={`b-${i}-${k}`}>
          <input className="input" id={`b-${i}-${k}`} inputMode="decimal" value={b[k]} onChange={(e) => setBrackets((all) => all.map((x, j) => (j === i ? { ...x, [k]: e.target.value } : x)))} /></Field>)}</div>)}
        <button className="btn btn--secondary btn--sm" type="button" onClick={() => setBrackets((all) => [...all, { lower_bound: all[all.length - 1]?.upper_bound ?? '', upper_bound: '', rate: '', fixed_amount: '0.00', excess_over: '0.00' }])}>Acrescentar escalão</button></fieldset>}
      <Field label="Base de incidência" name="rule-base" required><select className="select" id="rule-base" multiple value={form.base} onChange={(e) => setForm((f) => ({ ...f, base: Array.from(e.target.selectedOptions).map((o) => o.value) }))}>{earnings.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}</select></Field>
      <Field label="Vigência desde" name="rule-from" required><input className="input" id="rule-from" type="date" value={form.starts_on} onChange={(e) => setForm((f) => ({ ...f, starts_on: e.target.value }))} required /></Field>
      <Field label="Vigência até" name="rule-to"><input className="input" id="rule-to" type="date" value={form.ends_on} onChange={(e) => setForm((f) => ({ ...f, ends_on: e.target.value }))} /></Field>
      <Field label="Fonte normativa (documento PAYROLL_RULE_SOURCE)" name="rule-source"><input className="input" id="rule-source" value={form.source_document} onChange={(e) => setForm((f) => ({ ...f, source_document: e.target.value }))} maxLength={26} /></Field>
    </ActionForm></div></>
}

export function HrRuleDetailPage() {
  const { code = '', version = '1' } = useParams()
  const result = useFinanceItem<PayrollRuleDetail>(hr.rule(code, Number(version)))
  const r = result.data
  if (result.loading) return <LoadingState />
  if (result.error) return <><PageHeader title="Regra" back={{ to: '/rh/regras', label: 'Regras' }} /><ErrorState error={result.error} retry={result.reload} /></>
  if (!r) return null
  return <><PageHeader title={`${r.code} v${r.version}`} description={`${r.component} · ${label(METHOD, r.method)}`} back={{ to: '/rh/regras', label: 'Regras' }} />
    <section className="card"><dl className="finance-summary">
      <div><dt>Estado</dt><dd>{label(STATUS, r.status)}</dd></div><div><dt>Vigência</dt><dd>{formatDate(r.starts_on)} – {r.ends_on ? formatDate(r.ends_on) : 'sem fim'}</dd></div>
      <div><dt>Taxa</dt><dd>{r.rate ?? '—'}</dd></div><div><dt>Base de incidência</dt><dd>{r.base_components.join(', ')}</dd></div>
      <div><dt>Fonte normativa</dt><dd>{r.source_document?.public_id ?? (r.source_document ? 'Sem acesso' : 'Em falta')}</dd></div>
      <div><dt>Preparada por</dt><dd>{r.provenance.created_by} · {formatDateTime(r.provenance.created_at)}</dd></div>
      <div><dt>Aprovada por</dt><dd>{r.provenance.approved_by ? `${r.provenance.approved_by} · ${formatDateTime(r.provenance.approved_at ?? '')}` : 'Por aprovar'}</dd></div>
    </dl></section>
    {r.brackets.length > 0 && <section className="card stack"><h2>Escalões</h2><DataTable rows={r.brackets} rowKey={(b) => b.lower_bound} columns={[
      { key: 'from', label: 'Desde', render: (b) => formatKz(b.lower_bound) }, { key: 'to', label: 'Até', render: (b) => (b.upper_bound ? formatKz(b.upper_bound) : 'sem limite') },
      { key: 'rate', label: 'Taxa', render: (b) => b.rate }, { key: 'fixed', label: 'Parcela fixa', render: (b) => formatKz(b.fixed_amount) }, { key: 'excess', label: 'Excesso sobre', render: (b) => formatKz(b.excess_over) },
    ]} /></section>}</>
}

// ---- Prontidão da folha ----------------------------------------------------------------------------------------------

export function HrReadinessPage() {
  const { hr: access } = useApp()
  const units = access.context?.units.filter((u) => u.permissions.includes('PAYROLL_MANAGE') || u.permissions.includes('HR_COMPENSATION_VIEW')) ?? []
  const [unit, setUnit] = useState('')
  const [period, setPeriod] = useState(today().slice(0, 7))
  const selected = unit || units[0]?.public_id || ''
  const result = useFinanceItem<PayrollReadiness>(selected && period ? hr.readiness() : null, { unit: selected, period })
  const r = result.data
  return <><PageHeader title="Prontidão da Folha" description="Verificação feita no servidor a partir da configuração. A prontidão da configuração e o estado da produção salarial são independentes." />
    <div className="card finance-filters"><Field label="Unidade empregadora" name="ready-unit"><select className="select" id="ready-unit" value={selected} onChange={(e) => setUnit(e.target.value)}>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
      <Field label="Mês de serviço" name="ready-period"><input className="input" id="ready-period" type="month" value={period} onChange={(e) => setPeriod(e.target.value)} /></Field></div>
    {result.loading && <LoadingState />}{result.error && <ErrorState error={result.error} retry={result.reload} />}
    {r && <>
      <div className="finance-dashboard-grid">
        <section className="card finance-kpi" data-testid="configuration-readiness"><span>Configuração</span><strong>{r.configuration.status === 'READY' ? 'PRONTA' : 'NÃO PRONTA'}</strong><small className="muted">{r.configuration.employments} vínculo(s) no mês</small></section>
        <section className="card finance-kpi" data-testid="production-status"><span>Produção salarial</span><strong>{r.production.enabled ? 'ACTIVADA' : 'DESACTIVADA'}</strong><small className="muted">payroll.production_enabled</small></section>
      </div>
      <section className="card stack"><h2>Motivos da configuração</h2>{r.configuration.issues.length === 0 ? <p>Sem pendências de configuração para {r.period.code}.</p> : <ul className="stack">{r.configuration.issues.map((i, n) => <li key={n}><strong>{label(ISSUE, i.code)}</strong>{i.component ? ` · ${i.component}` : ''}{i.employment ? <> · <Link to={`/rh/funcionarios/${i.employment}`}>vínculo</Link></> : ''}</li>)}</ul>}
        {r.configuration.rules.length > 0 && <p className="muted">Regras aplicáveis: {r.configuration.rules.map((x) => `${x.component} → ${x.rule.code} v${x.rule.version}`).join('; ')}</p>}
        {r.input_hash_preview && <p className="muted">Hash canónico dos inputs ({r.input_hash_preview.algorithm}): <code className="hash">{r.input_hash_preview.value.slice(0, 16)}…</code></p>}</section>
      <section className="card stack"><h2>Produção salarial</h2>{r.production.issues.map((i) => <p key={i.code}><strong>{label(ISSUE, i.code)}</strong>. A configuração pode estar pronta; processar, aprovar, contabilizar e pagar continuam bloqueados.</p>)}
        <div className="row payroll-actions">{['Processar', 'Aprovar', 'Contabilizar', 'Pagar'].map((a) => <button key={a} className="btn btn--secondary" type="button" disabled title="Disponível na fase F2B">{a} (F2B)</button>)}</div></section>
    </>}</>
}
