import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ActionForm, DataTable, Dialog, EmptyState, ErrorState, Field, LoadingState, PageHeader, Pagination } from '../components/ui'
import { useApp } from '../context/AppContext'
import { useFilesItem, useFilesPage, useFilesUnits } from '../hooks/useFiles'
import { filesPost, filesUpload } from '../lib/files/client'
import { fl } from '../lib/files/endpoints'
import { formatBytes, toFilesError } from '../lib/files/errors'
import { formatDate, formatDateTime } from '../lib/format'
import type { UiError } from '../lib/academy/errors'
import type { Item } from '../types/academy'
import type { LegalDocument } from '../types/files'
import { ClassificationBadge, ClassificationSelect, DownloadButton, ReasonDialog } from './FilesPages'

export function DocumentsListPage() {
  const { files } = useApp()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('ACTIVE')
  const result = useFilesPage<LegalDocument>(fl.documents(), { page, per_page: 50, status, ...(search ? { search } : {}) })
  return <>
    <PageHeader title="Documentos" description="Documentos institucionais com versões imutáveis. A versão corrente é a mais recente." actions={files.has('DOCUMENTS_MANAGE') && files.has('FILES_UPLOAD') ? <Link className="btn btn--primary" to="/documentos/novo">Novo documento</Link> : undefined} />
    <form className="card files-filters" role="search" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(draft.trim()) }}>
      <Field label="Pesquisar por título ou referência" name="document-search"><input className="input" id="document-search" value={draft} onChange={(event) => setDraft(event.target.value)} maxLength={100} /></Field>
      <Field label="Estado" name="document-status"><select className="select" id="document-status" value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }}><option value="ACTIVE">Activos</option>{files.has('DOCUMENTS_MANAGE') && <option value="ARCHIVED">Arquivados</option>}</select></Field>
      <button className="btn btn--secondary" type="submit">Pesquisar</button>
    </form>
    {result.loading && <LoadingState />}
    {result.error && <ErrorState error={result.error} retry={result.reload} />}
    {result.data && result.data.data.length === 0 && <EmptyState title="Sem documentos" message="Não existem documentos visíveis para estes filtros." />}
    {result.data && result.data.data.length > 0 && <div className="card"><DataTable rows={result.data.data} rowKey={(row) => row.public_id} columns={[
      { key: 'title', label: 'Documento', render: (row) => <Link className="files-name" to={`/documentos/${row.public_id}`}>{row.title}</Link> },
      { key: 'reference', label: 'Referência', render: (row) => row.reference },
      { key: 'type', label: 'Tipo', render: (row) => row.type.label },
      { key: 'classification', label: 'Classificação', render: (row) => <ClassificationBadge value={row.classification} /> },
      { key: 'version', label: 'Versão', render: (row) => row.current_version ?? '—' },
      { key: 'unit', label: 'Unidade', render: (row) => row.owner_unit.name ?? '—' },
    ]} /><Pagination meta={result.data.meta} onPage={setPage} /></div>}
  </>
}

export function DocumentCreatePage() {
  const { files, notify } = useApp()
  const navigate = useNavigate()
  const units = useFilesUnits('DOCUMENTS_MANAGE').filter((u) => u.permissions.includes('FILES_UPLOAD'))
  const [unit, setUnit] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    if (!String(form.get('issued_on') ?? '')) form.delete('issued_on')
    setBusy(true); setError(null)
    try {
      const created = await filesUpload<Item<LegalDocument>>(fl.documents(), form)
      notify('Documento criado com a versão 1.')
      navigate(`/documentos/${created.data.public_id}`)
    } catch (failure) { setError(toFilesError(failure)) } finally { setBusy(false) }
  }
  return <>
    <PageHeader title="Novo documento" description="O documento e a primeira versão são criados numa única operação." back={{ to: '/documentos', label: 'Documentos' }} />
    <div className="card"><ActionForm submitLabel="Criar documento" busy={busy} error={error} onSubmit={submit}>
      <div className="form-grid">
        <Field label="Unidade proprietária" name="owner_unit_public_id" required><select className="select" id="owner_unit_public_id" name="owner_unit_public_id" required value={unit} onChange={(event) => setUnit(event.target.value)}><option value="">Seleccione</option>{units.map((u) => <option key={u.public_id} value={u.public_id}>{u.name}</option>)}</select></Field>
        <Field label="Tipo de documento" name="type_code" required hint="«Outro» exige um título descritivo."><select className="select" id="type_code" name="type_code" required><option value="">Seleccione</option>{(files.context?.document_types ?? []).map((t) => <option key={t.code} value={t.code}>{t.label}</option>)}</select></Field>
        <Field label="Referência" name="reference" required><input className="input" id="reference" name="reference" required maxLength={64} autoComplete="off" /></Field>
        <Field label="Data de emissão" name="issued_on"><input className="input" id="issued_on" name="issued_on" type="date" /></Field>
      </div>
      <Field label="Título" name="title" required><input className="input" id="title" name="title" required maxLength={191} /></Field>
      <ClassificationSelect key={unit} name="classification" clearance={units.find((u) => u.public_id === unit)?.clearance ?? null} />
      <Field label="Ficheiro da versão 1" name="file" required hint="PDF, JPEG, PNG ou WebP. Verificação estrutural antes de ficar disponível."><input className="input" id="file" name="file" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp" /></Field>
    </ActionForm></div>
  </>
}

function NewVersionDialog({ open, document, onClose, onDone }: { open: boolean; document: LegalDocument; onClose: () => void; onDone: () => void }) {
  const units = useFilesUnits('DOCUMENTS_VERSION_MANAGE')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<UiError | null>(null)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    form.set('lock_version', String(document.lock_version ?? 0))
    if (!String(form.get('issued_on') ?? '')) form.delete('issued_on')
    setBusy(true); setError(null)
    try { await filesUpload(fl.versions(document.public_id), form); onDone() } catch (failure) { setError(toFilesError(failure)) } finally { setBusy(false) }
  }
  const current = document.versions?.[0]?.file.classification
  return <Dialog open={open} title="Nova versão" onClose={onClose}><ActionForm submitLabel="Criar versão" busy={busy} error={error} onSubmit={submit}>
    <p>A nova versão é um ficheiro novo. As versões anteriores ficam preservadas e descarregáveis.</p>
    <ClassificationSelect name="classification" clearance={units.find((u) => u.public_id === document.owner_unit.public_id)?.clearance ?? null} defaultValue={current} minimum={current} />
    <Field label="Data de emissão" name="version_issued_on"><input className="input" id="version_issued_on" name="issued_on" type="date" /></Field>
    <Field label="Ficheiro" name="version_file" required><input className="input" id="version_file" name="file" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp" /></Field>
  </ActionForm></Dialog>
}

export function DocumentDetailPage() {
  const { id } = useParams()
  const { notify } = useApp()
  const result = useFilesItem<LegalDocument>(id ? fl.document(id) : null)
  const [dialog, setDialog] = useState<'version' | 'archive' | 'restore' | null>(null)
  if (result.loading) return <LoadingState />
  if (result.error) return <ErrorState error={result.error} retry={result.reload} />
  if (!result.data) return <EmptyState />
  const doc = result.data
  const done = (message: string) => { setDialog(null); notify(message); result.reload() }
  return <>
    <PageHeader title={doc.title} description={`${doc.type.label} · ${doc.reference} · ${doc.owner_unit.name ?? ''}`} back={{ to: '/documentos', label: 'Documentos' }} actions={<div className="files-actions">
      {doc.actions?.new_version && <button className="btn btn--primary" type="button" onClick={() => setDialog('version')}>Nova versão</button>}
      {doc.actions?.archive && <button className="btn btn--secondary" type="button" onClick={() => setDialog('archive')}>Arquivar</button>}
      {doc.actions?.restore && <button className="btn btn--secondary" type="button" onClick={() => setDialog('restore')}>Restaurar</button>}
    </div>} />
    {doc.status === 'ARCHIVED' && <div className="alert alert--warning" role="note"><span className="alert__icon" aria-hidden="true">!</span><div className="alert__body"><strong>Arquivado</strong><p>O documento está arquivado e não aceita novas versões.</p></div></div>}
    <section className="card" aria-labelledby="doc-meta"><h2 id="doc-meta">Documento</h2><dl className="files-meta">
      <div><dt>Estado</dt><dd><span className="badge">{doc.status_label ?? doc.status}</span></dd></div>
      <div><dt>Classificação</dt><dd><ClassificationBadge value={doc.classification} /></dd></div>
      <div><dt>Versão corrente</dt><dd>{doc.current_version ?? '—'}</dd></div>
      <div><dt>Criado em</dt><dd>{formatDateTime(doc.created_at)}</dd></div>
    </dl></section>
    <section className="card" aria-labelledby="doc-versions"><h2 id="doc-versions">Versões</h2>
      <DataTable rows={doc.versions ?? []} rowKey={(v) => v.public_id} columns={[
        { key: 'version', label: 'Versão', render: (v) => <span>{v.version}{v.is_current && <span className="badge badge--info files-current">Corrente</span>}</span> },
        { key: 'file', label: 'Ficheiro', render: (v) => <Link className="files-name" to={`/ficheiros/${v.file.public_id}`}>{v.file.original_name}</Link> },
        { key: 'classification', label: 'Classificação', render: (v) => <ClassificationBadge value={v.file.classification} /> },
        { key: 'issued', label: 'Emissão', render: (v) => (v.issued_on ? formatDate(v.issued_on) : '—') },
        { key: 'size', label: 'Tamanho', render: (v) => formatBytes(v.file.size_bytes) },
        { key: 'download', label: 'Conteúdo', render: (v) => (doc.actions?.download && v.file.status === 'AVAILABLE' ? <DownloadButton path={fl.versionContent(doc.public_id, v.public_id)} filename={v.file.original_name} sensitive={v.download_reason_required} label={`Descarregar v${v.version}`} /> : '—') },
      ]} />
    </section>
    <NewVersionDialog open={dialog === 'version'} document={doc} onClose={() => setDialog(null)} onDone={() => done('Nova versão criada. A anterior foi preservada.')} />
    <ReasonDialog open={dialog === 'archive'} title="Arquivar documento" message="O documento sai das listas comuns. As versões são preservadas." submitLabel="Arquivar" reasonRequired onClose={() => setDialog(null)} onConfirm={async (reason) => { await filesPost(fl.archive(doc.public_id), { reason, lock_version: doc.lock_version }); done('Documento arquivado.') }} />
    <ReasonDialog open={dialog === 'restore'} title="Restaurar documento" message="O documento volta às listas comuns." submitLabel="Restaurar" reasonRequired onClose={() => setDialog(null)} onConfirm={async (reason) => { await filesPost(fl.documentRestore(doc.public_id), { reason, lock_version: doc.lock_version }); done('Documento restaurado.') }} />
  </>
}
