import { useEffect, useState } from 'react'
import { NavLink } from 'react-router-dom'
import { peopleGet } from '../lib/people/client'
import { pe, PEOPLE_MIN_SEARCH } from '../lib/people/endpoints'
import { Field } from './ui'
import type { Item } from '../types/academy'
import type { BirthPrecision, PersonDetail, PersonStatus, SelectorPerson } from '../types/people'
import { ageBandLabel, EMPTY_BIRTH, MONTHS, PRECISION_LABEL, STATUS_LABEL, type BirthValue } from '../lib/people/format'

export function PersonStatusBadge({ status }: { status: PersonStatus }) {
  const tone = status === 'ACTIVE' ? 'badge--info' : status === 'DECEASED' ? 'badge--danger' : 'badge--warning'
  return <span className={`badge ${tone}`}>{STATUS_LABEL[status] ?? status}</span>
}

export function BirthFields({ value, onChange, errors }: { value: BirthValue; onChange: (value: BirthValue) => void; errors: Record<string, string> }) {
  const thisYear = new Date().getFullYear()
  return <fieldset className="fieldset"><legend className="field__label">Nascimento</legend><div className="form-grid">
    <Field label="Precisão" name="birth_precision" error={errors.birth_precision}><select id="birth_precision" className="select" value={value.precision} onChange={(e) => onChange({ ...EMPTY_BIRTH, precision: e.target.value as BirthPrecision })}>{(Object.keys(PRECISION_LABEL) as BirthPrecision[]).map((p) => <option key={p} value={p}>{PRECISION_LABEL[p]}</option>)}</select></Field>
    {value.precision === 'EXACT' && <Field label="Data de nascimento" name="birth_date" required error={errors.birth_date}><input id="birth_date" className="input" type="date" max={new Date().toISOString().slice(0, 10)} value={value.date} onChange={(e) => onChange({ ...value, date: e.target.value })} required /></Field>}
    {value.precision === 'MONTH' && <Field label="Mês" name="birth_month" required error={errors.birth_month}><select id="birth_month" className="select" value={value.month} onChange={(e) => onChange({ ...value, month: e.target.value })} required><option value="">Seleccione</option>{MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}</select></Field>}
    {(value.precision === 'MONTH' || value.precision === 'YEAR') && <Field label="Ano" name="birth_year" required error={errors.birth_year ?? (value.precision === 'MONTH' ? errors.birth_month : undefined)}><input id="birth_year" className="input" type="number" inputMode="numeric" min={1900} max={thisYear} value={value.year} onChange={(e) => onChange({ ...value, year: e.target.value })} required /></Field>}
  </div>{value.precision === 'UNKNOWN' && <p className="field__hint">Nenhuma data será registada. Não são criados dias ou meses fictícios.</p>}{value.precision === 'MONTH' && <p className="field__hint">Só o mês e o ano são registados.</p>}{value.precision === 'YEAR' && <p className="field__hint">Só o ano é registado.</p>}</fieldset>
}

const TABS = [
  ['', 'Dados', null],
  ['contactos', 'Contactos', 'can_view_contacts'],
  ['enderecos', 'Endereços', 'can_view_addresses'],
  ['familia', 'Família', 'can_view_households'],
  ['relacoes', 'Relações', 'can_view_relationships'],
] as const

export function PersonTabs({ person }: { person: PersonDetail }) {
  const base = `/pessoas/${person.public_id}`
  return <nav className="tabs" aria-label="Áreas da pessoa">{TABS.filter(([, , cap]) => cap === null || person.capabilities[cap]).map(([slug, label]) => <NavLink key={slug} end={slug === ''} to={slug ? `${base}/${slug}` : base}>{label}</NavLink>)}</nav>
}

export function MinorNotice() {
  return <div className="alert alert--info" role="note"><span className="alert__icon" aria-hidden="true">i</span><div className="alert__body"><strong>Projecção protegida</strong><p>Esta pessoa é tratada como menor ou foi consultada fora de um contexto geral. Apenas os dados mínimos são apresentados.</p></div></div>
}

export function RestrictedNotice({ areas }: { areas: string[] }) {
  if (areas.length === 0) return null
  const names: Record<string, string> = { birth: 'data de nascimento', contacts: 'contactos', addresses: 'endereços', households: 'família', relationships: 'relações' }
  return <p className="muted restricted-note">Dados sensíveis ocultos: {areas.map((a) => names[a] ?? a).join(', ')}.</p>
}

/** Context-safe person search for pickers: the server returns only People the purpose permission covers. */
export function PersonPicker({ purpose, label, selected, onSelect, exclude = [] }: { purpose: 'household' | 'relationship'; label: string; selected: SelectorPerson | null; onSelect: (person: SelectorPerson | null) => void; exclude?: string[] }) {
  const [term, setTerm] = useState('')
  const [results, setResults] = useState<SelectorPerson[]>([])
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)
  const excluded = exclude.join(',')
  useEffect(() => {
    if (selected || term.trim().length < PEOPLE_MIN_SEARCH) { setResults([]); return }
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setLoading(true); setFailed(false)
      peopleGet<Item<SelectorPerson[]>>(pe.selector(), { purpose, search: term.trim() }, controller.signal)
        .then((r) => setResults(r.data.filter((p) => !excluded.split(',').includes(p.public_id))), (error: unknown) => { if (!(error instanceof DOMException)) setFailed(true) })
        .finally(() => setLoading(false))
    }, 300)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [term, purpose, selected, excluded])
  if (selected) return <div className="picker__selected"><span><strong>{selected.display_name}</strong> <span className="muted">· {ageBandLabel(selected.age_band)}</span></span><button className="btn btn--ghost btn--sm" type="button" onClick={() => onSelect(null)}>Alterar</button></div>
  return <div className="picker"><Field label={label} name={`picker-${purpose}`} hint="Escreva pelo menos 2 letras. Só aparecem pessoas do seu âmbito."><input id={`picker-${purpose}`} className="input" type="search" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" /></Field>
    {loading && <p className="muted" role="status">A pesquisar…</p>}
    {failed && <p className="field__error">Não foi possível pesquisar.</p>}
    {results.length > 0 && <ul className="picker__results">{results.map((p) => <li key={p.public_id} className="picker__option"><span>{p.display_name} <span className="muted">· {ageBandLabel(p.age_band)}</span></span><button className="btn btn--secondary btn--sm" type="button" onClick={() => onSelect(p)}>Seleccionar</button></li>)}</ul>}
    {!loading && term.trim().length >= PEOPLE_MIN_SEARCH && results.length === 0 && !failed && <p className="muted">Sem resultados no seu âmbito.</p>}
  </div>
}
