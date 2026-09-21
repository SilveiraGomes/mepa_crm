import { useEffect, useRef, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { UiError } from '../lib/academy/errors'
import type { PageMeta } from '../types/academy'

export function PageHeader({ title, description, actions, back }: { title: string; description?: string; actions?: ReactNode; back?: { to: string; label: string } }) {
  return <header className="page-header"><div className="page-header__text">{back && <div className="breadcrumb"><Link to={back.to}>{back.label}</Link></div>}<h1>{title}</h1>{description && <p className="muted">{description}</p>}</div>{actions && <div className="page-header__actions">{actions}</div>}</header>
}

export function StatusBadge({ value }: { value?: string | null }) {
  return <span className="badge">{value || 'Não indicado'}</span>
}

export function LoadingState() {
  return <div className="card stack" role="status" aria-label="A carregar"><span className="skeleton" /><span className="skeleton" /><span className="skeleton" /></div>
}

export function EmptyState({ title = 'Sem resultados', message = 'Não existem registos para mostrar.' }: { title?: string; message?: string }) {
  return <div className="card empty"><div className="empty__title">{title}</div><p>{message}</p></div>
}

export function ErrorState({ error, retry }: { error: UiError; retry?: () => void }) {
  return <div className={`alert ${error.kind === 'policy' || error.kind === 'state-policy' ? 'alert--warning' : 'alert--danger'}`} role="alert"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>{error.title}</strong><p>{error.message}</p>{retry && error.retryable && <div className="alert__actions"><button className="btn btn--secondary" type="button" onClick={retry}>Tentar novamente</button></div>}</div></div>
}

export interface Column<T> { key: string; label: string; render: (row: T) => ReactNode }
export function DataTable<T>({ rows, columns, rowKey }: { rows: T[]; columns: Column<T>[]; rowKey: (row: T) => string | number }) {
  return <div className="table-wrap"><table className="table table--stack"><thead><tr>{columns.map((column) => <th key={column.key} scope="col">{column.label}</th>)}</tr></thead><tbody>{rows.map((row) => <tr key={rowKey(row)}>{columns.map((column) => <td key={column.key} data-label={column.label}>{column.render(row)}</td>)}</tr>)}</tbody></table></div>
}

export function Pagination({ meta, onPage }: { meta: PageMeta; onPage: (page: number) => void }) {
  return <nav className="pagination" aria-label="Paginação"><span className="pagination__info">Página {meta.current_page} de {Math.max(meta.last_page, 1)} · {meta.total} registos</span><div className="pagination__controls"><button className="btn btn--secondary btn--sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Anterior</button><button className="btn btn--secondary btn--sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Seguinte</button></div></nav>
}

export function Dialog({ open, title, children, onClose, wide = false }: { open: boolean; title: string; children: ReactNode; onClose: () => void; wide?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => { const dialog = ref.current; if (!dialog) return; if (open && !dialog.open) dialog.showModal(); if (!open && dialog.open) dialog.close() }, [open])
  return <dialog ref={ref} className={`dialog${wide ? ' dialog--wide' : ''}`} onCancel={onClose} onClose={onClose}><div className="dialog__body"><div className="row"><h2>{title}</h2><span style={{ flex: 1 }} /><button className="btn btn--ghost" type="button" onClick={onClose} aria-label="Fechar">Fechar</button></div>{children}</div></dialog>
}

export function ActionForm({ children, submitLabel, busy, onSubmit, error }: { children: ReactNode; submitLabel: string; busy: boolean; onSubmit: (event: FormEvent<HTMLFormElement>) => void; error?: UiError | null }) {
  return <form className="form" onSubmit={onSubmit}>{error && <ErrorState error={error} />}{children}<div className="form__actions"><button className="btn btn--primary" disabled={busy} type="submit">{busy && <span className="btn__spinner" aria-hidden="true" />}{busy ? 'A guardar…' : submitLabel}</button></div></form>
}

export function Field({ label, name, children, hint, error, required }: { label: string; name: string; children: ReactNode; hint?: string; error?: string; required?: boolean }) {
  return <div className="field"><label className="field__label" htmlFor={name}>{label}{required && <span className="field__required" aria-hidden="true">*</span>}</label>{children}{hint && <span className="field__hint">{hint}</span>}{error && <span className="field__error" id={`${name}-error`}>{error}</span>}</div>
}
