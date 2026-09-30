import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFilesItem, useFilesPage, useFilesUnits } from '../hooks/useFiles'
import { filesDownload, filesPost, filesUpload } from '../lib/files/client'
import { fl } from '../lib/files/endpoints'
import { formatBytes, toFilesError } from '../lib/files/errors'
import { formatDateTime } from '../lib/format'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { StoredFile } from '../types/files'

const CLASSIFICATION_LABEL: Record<string, string> = { INTERNAL: 'Interno', RESTRICTED: 'Restrito', CONFIDENTIAL: 'Confidencial', HIGHLY_SENSITIVE: 'Altamente sensível' }
const STATUS_LABEL: Record<string, string> = { QUARANTINED: 'Em quarentena', AVAILABLE: 'Disponível', TOMBSTONE: 'Retirado', PURGED: 'Destruído' }
const RANK: Record<string, number> = { INTERNAL: 1, RESTRICTED: 2, CONFIDENTIAL: 3, HIGHLY_SENSITIVE: 4 }

export function ClassificationBadge({ value }: { value: string }) {
  const tone = value === 'HIGHLY_SENSITIVE' ? ' badge--danger' : value === 'CONFIDENTIAL' ? ' badge--warning' : ''
  return <span className={`badge${tone}`}>{CLASSIFICATION_LABEL[value] ?? 'Classificação desconhecida'}</span>
}

export function FileStatusBadge({ value }: { value: string }) {
  const tone = value === 'AVAILABLE' ? ' badge--info' : value === 'QUARANTINED' ? ' badge--warning' : ''
  return <span className={`badge${tone}`}>{STATUS_LABEL[value] ?? value}</span>
}

export function ClassificationSelect({ name, clearance, defaultValue, minimum }: { name: string; clearance: string | null; defaultValue?: string; minimum?: string }) {
  const { files } = useApp()
  const max = clearance ? RANK[clearance] ?? 2 : 2
  const min = minimum ? RANK[minimum] ?? 1 : 1
  const options = (files.context?.classifications ?? []).filter((c) => c.rank <= max && c.rank >= min)
  return <Field label="Classificação" name={name} required hint="Predefinição: Restrito. Só pode escolher classificações dentro da sua habilitação."><select className="select" id={name} name={name} required defaultValue={defaultValue ?? files.context?.default_classification ?? 'RESTRICTED'}>{options.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}</select></Field>
}

/** Confirmation built into the page (no browser dialog), with a reason the API may require. */
export function ReasonDialog({ open, title, message, submitLabel, reasonRequired, onClose, onConfirm, children }: { open: boolean; title: string; message: string; submitLabel: string; reasonRequired: boolean; onClose: () => void; onConfirm: (reason: string, form: FormData) => Promise<void>; children?: ReactNode }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true); setError(null)
    try { await onConfirm(String(form.get('reason') ?? '').trim(), form) } catch (failure) { setError(toFilesError(failure)) } finally { setBusy(false) }
  }
  const reasonId = `reason-${title.normalize('NFD').replace(/[^A-Za-z0-9]+/g, '-').toLowerCase()}`
  return <Dialog open={open} title={title} onClose={onClose}><ActionForm submitLabel={submitLabel} busy={busy} error={error} onSubmit={submit}><p>{message}</p>{children}<Field label={reasonRequired ? 'Motivo' : 'Motivo (opcional)'} name={reasonId} required={reasonRequired} error={error?.fields.reason}><textarea className="textarea" id={reasonId} name="reason" required={reasonRequired} minLength={reasonRequired ? 3 : undefined} maxLength={2000} /></Field></ActionForm></Dialog>
}

/** Download button; HIGHLY_SENSITIVE content opens the mandatory-reason dialog first (the reason is audited). */
export function DownloadButton({ path, filename, sensitive, label = 'Descarregar' }: { path: string; filename: string; sensitive: boolean; label?: string }) {
  const { notify } = useApp()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function plain() {
    setBusy(true); setError(null)
    try { await filesDownload(path, filename); notify('Descarregamento concluído.') } catch (failure) { setError(toFilesError(failure)) } finally { setBusy(false) }
  }
  return <>
    <button className="btn btn--secondary" type="button" disabled={busy} onClick={() => (sensitive ? setOpen(true) : void plain())}>{busy ? 'A descarregar…' : label}</button>
    {error && <ErrorState error={error} />}
    {sensitive && <ReasonDialog open={open} title="Descarregamento sensível" message="Este ficheiro é Altamente sensível. Indique o motivo do acesso: o descarregamento fica registado com o motivo." submitLabel="Descarregar" reasonRequired onClose={() => setOpen(false)} onConfirm={async (reason) => { await filesDownload(path, filename, reason); setOpen(false); notify('Descarregamento concluído e registado.') }} />}
  </>
}

// ---- Lista ---------------------------------------------------------------------------------------------------------

export function FilesListPage() {
  const { files } = useApp()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('AVAILABLE')
  const manage = files.has('FILES_MANAGE')
  const result = useFilesPage<StoredFile>(fl.files(), { page, per_page: 50, status, ...(search ? { search } : {}) })
  return <>
    <PageHeader title="Ficheiros" description="Ficheiros privados das unidades do seu escopo, dentro da sua habilitação de classificação." actions={files.has('FILES_UPLOAD') ? <Link className="btn btn--primary" to="/ficheiros/carregar">Carregar ficheiro</Link> : undefined} />
    <form className="card files-filters" role="search" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(draft.trim()) }}>
      <Field label="Pesquisar por nome" name="file-search"><input className="input" id="file-search" value={draft} onChange={(event) => setDraft(event.target.value)} maxLength={100} /></Field>
      <Field label="Estado" name="file-status"><select className="select" id="file-status" value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }}><option value="AVAILABLE">Disponíveis</option>{manage && <option value="TOMBSTONE">Retirados</option>}{manage && <option value="QUARANTINED">Em quarentena</option>}</select></Field>
      <button className="btn btn--secondary" type="submit">Pesquisar</button>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem ficheiros" message="Não existem ficheiros visíveis para estes filtros." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'name', label: 'Ficheiro', render: (row) => <Link className="files-name" to={`/ficheiros/${row.public_id}`}>{row.original_name}</Link> },
      { key: 'unit', label: 'Unidade', render: (row) => row.owner_unit.name ?? '—' },
      { key: 'classification', label: 'Classificação', render: (row) => <ClassificationBadge value={row.classification} /> },
      { key: 'status', label: 'Estado', render: (row) => <FileStatusBadge value={row.status} /> },
      { key: 'size', label: 'Tamanho', render: (row) => formatBytes(row.size_bytes) },
      { key: 'created', label: 'Carregado em', render: (row) => formatDateTime(row.created_at) },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

// ---- Carregar ------------------------------------------------------------------------------------------------------

export function FileUploadPage() {
  const { files, notify } = useApp()
  const navigate = useNavigate()
  const units = useFilesUnits('FILES_UPLOAD')
  const [unit, setUnit] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  const clearance = units.find((u) => u.public_id === unit)?.clearance ?? null
  const limits = files.context?.limits
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true); setError(null)
    try {
      const created = await filesUpload<Item<StoredFile>>(fl.files(), form)
      notify(created.data.warnings?.includes('DUPLICATE_CONTENT_IN_UNIT') ? 'Ficheiro carregado. Aviso: já existe um ficheiro com o mesmo conteúdo nesta unidade (nada foi fundido).' : 'Ficheiro carregado e disponível.')
      navigate(`/ficheiros/${created.data.public_id}`)
    } catch (failure) { setError(toFilesError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Carregar ficheiro" description="O ficheiro é cifrado, fica em quarentena e só fica disponível depois da verificação estrutural." back={{ to: '/ficheiros', label: 'Ficheiros' }} />
    <div className="alert alert--info" role="note"><span className="alert__icon" aria-hidden="true">i</span><div className="alert__body"><strong>Verificação estrutural</strong><p>Aceites: PDF, JPEG, PNG e WebP{limits ? `, até ${formatBytes(limits.max_file_bytes)}` : ''}. As imagens são recodificadas (metadados e localização removidos). PDF cifrado ou com conteúdo activo é recusado. Não é uma análise antivírus.</p></div></div>
    <div className="card"><ActionForm submitLabel="Carregar" busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid">
        <Field label="Unidade proprietária" name="owner_unit_public_id" required hint={files.known && units.length === 0 ? 'Não existem unidades no seu escopo para carregar ficheiros.' : undefined}><select className="select" id="owner_unit_public_id" name="owner_unit_public_id" required value={unit} onChange={(event) => setUnit(event.target.value)} disabled={!files.known}><option value="">{files.known ? 'Seleccione' : 'A carregar…'}</option>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
        <ClassificationSelect key={unit} name="classification" clearance={clearance} />
      </div>
      <Field label="Ficheiro" name="file" required hint="O tipo é verificado pelo conteúdo, não pela extensão declarada."><input className="input" id="file" name="file" type="file" required accept={(limits?.extensions ?? ['pdf', 'jpg', 'jpeg', 'png', 'webp']).map((e) => `.${e}`).join(',')} /></Field>
    </ActionForm></div>
  </>
}

// ---- Detalhe -------------------------------------------------------------------------------------------------------

export function FileDetailPage() {
  const { id } = useParams()
  const { notify } = useApp()
  const result = useFilesItem<StoredFile>(id ? fl.file(id) : null)
  const [dialog, setDialog] = useState<'tombstone' | 'restore' | 'classification' | 'owner' | null>(null)
  const units = useFilesUnits('FILES_MANAGE')
  if (result.loading) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return <EmptyState />
  const file = result.data
  const actions = file.actions
  const done = (message: string) => { setDialog(null); notify(message); result.reload() }
  return <>
    <PageHeader title={file.original_name} description={`${CLASSIFICATION_LABEL[file.classification] ?? ''} · ${file.owner_unit.name ?? ''}`} back={{ to: '/ficheiros', label: 'Ficheiros' }} actions={<div className="files-actions">
      {actions?.download && <DownloadButton path={fl.content(file.public_id)} filename={file.original_name} sensitive={actions.download_reason_required} />}
      {actions?.reclassify && <button className="btn btn--secondary" type="button" onClick={() => setDialog('classification')}>Alterar classificação</button>}
      {actions?.transfer && <button className="btn btn--secondary" type="button" onClick={() => setDialog('owner')}>Mudar unidade</button>}
      {actions?.tombstone && <button className="btn btn--danger" type="button" onClick={() => setDialog('tombstone')}>Retirar</button>}
      {actions?.restore && <button className="btn btn--primary" type="button" onClick={() => setDialog('restore')}>Restaurar</button>}
    </div>} />
    {file.status === 'QUARANTINED' && <div className="alert alert--warning" role="note"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>Em quarentena</strong><p>A verificação ainda não terminou. O ficheiro não pode ser descarregado nem anexado.</p></div></div>}
    {file.status === 'TOMBSTONE' && <div className="alert alert--warning" role="note"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>Retirado</strong><p>O ficheiro foi retirado do uso normal. O conteúdo é preservado e pode ser restaurado.</p></div></div>}
    <section className="card" aria-labelledby="file-meta"><h2 id="file-meta">Metadados</h2><dl className="files-meta">
      <div><dt>Estado</dt><dd><FileStatusBadge value={file.status} /></dd></div>
      <div><dt>Classificação</dt><dd><ClassificationBadge value={file.classification} /></dd></div>
      <div><dt>Unidade proprietária</dt><dd>{file.owner_unit.name ?? '—'}</dd></div>
      <div><dt>Tipo</dt><dd>{file.mime_type}</dd></div>
      <div><dt>Tamanho</dt><dd>{formatBytes(file.size_bytes)}</dd></div>
      <div><dt>Inspecção</dt><dd>Verificação estrutural</dd></div>
      <div><dt>Carregado em</dt><dd>{formatDateTime(file.created_at)}</dd></div>
      {file.deleted_at && <div><dt>Retirado em</dt><dd>{formatDateTime(file.deleted_at)}</dd></div>}
      <div><dt>Utilização</dt><dd>{file.document ? <Link to={`/documentos/${file.document.public_id}`}>{`${file.document.title} (versão ${file.document.version ?? '—'})`}</Link> : file.in_use ? 'Em uso' : 'Sem utilização'}</dd></div>
    </dl></section>
    <ReasonDialog open={dialog === 'tombstone'} title="Retirar ficheiro" message="O ficheiro deixa de estar disponível. O conteúdo é preservado e a operação fica registada." submitLabel="Retirar" reasonRequired onClose={() => setDialog(null)} onConfirm={async (reason) => { await filesPost(fl.tombstone(file.public_id), { reason, lock_version: file.lock_version }); done('Ficheiro retirado.') }} />
    <ReasonDialog open={dialog === 'restore'} title="Restaurar ficheiro" message="O ficheiro volta a ficar disponível depois de verificada a integridade do conteúdo." submitLabel="Restaurar" reasonRequired onClose={() => setDialog(null)} onConfirm={async (reason) => { await filesPost(fl.restore(file.public_id), { reason, lock_version: file.lock_version }); done('Ficheiro restaurado.') }} />
    <ReasonDialog open={dialog === 'classification'} title="Alterar classificação" message="Baixar a classificação exige motivo e nunca fica abaixo do mínimo exigido pela utilização." submitLabel="Alterar" reasonRequired={false} onClose={() => setDialog(null)} onConfirm={async (reason, form) => { await filesPost(fl.classification(file.public_id), { classification: form.get('new_classification'), reason: reason || null, lock_version: file.lock_version }); done('Classificação alterada.') }}>
      <ClassificationSelect name="new_classification" clearance={units.find((u) => u.public_id === file.owner_unit.public_id)?.clearance ?? null} defaultValue={file.classification} />
    </ReasonDialog>
    <ReasonDialog open={dialog === 'owner'} title="Mudar unidade proprietária" message="Exige autorização sobre a unidade actual e a de destino. Fica registado nas duas unidades." submitLabel="Mudar unidade" reasonRequired onClose={() => setDialog(null)} onConfirm={async (reason, form) => { await filesPost(fl.owner(file.public_id), { to_unit_public_id: form.get('to_unit_public_id'), reason, lock_version: file.lock_version }); done('Unidade proprietária alterada.') }}>
      <Field label="Unidade de destino" name="to_unit_public_id" required><select className="select" id="to_unit_public_id" name="to_unit_public_id" required><option value="">Seleccione</option>{units.filter((u) => u.public_id !== file.owner_unit.public_id).map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
    </ReasonDialog>
  </>
}
