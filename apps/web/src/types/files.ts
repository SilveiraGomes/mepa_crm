/** P0.8 Documents/Files API shapes (ADR 0019). Public ids only: no disk, storage key, checksum or numeric key. */
export interface CodeLabel { code: string; label: string }
export interface FilesUnit { public_id: string; name: string; permissions: string[]; clearance: string }
export interface FilesContext {
  permissions: string[]
  units: FilesUnit[]
  classifications: (CodeLabel & { rank: number })[]
  default_classification: string
  document_types: (CodeLabel & { requires_title: boolean })[]
  file_statuses: CodeLabel[]
  limits: { max_file_bytes: number; extensions: string[]; mime_types: string[] }
  inspection: string
}
export interface FileActions { download: boolean; download_reason_required: boolean; tombstone: boolean; restore: boolean; reclassify: boolean; transfer: boolean }
export interface StoredFile {
  public_id: string
  original_name: string
  mime_type: string
  size_bytes: number
  classification: string
  classification_label: string | null
  status: 'QUARANTINED' | 'AVAILABLE' | 'TOMBSTONE' | 'PURGED'
  status_label: string | null
  owner_unit: { public_id: string | null; name: string | null }
  inspection: string
  created_at: string
  deleted_at: string | null
  lock_version: number
  warnings?: string[]
  in_use?: boolean
  document?: { public_id: string; title: string; version: number | null; version_public_id: string | null } | null
  actions?: FileActions
}
export interface DocumentVersion {
  public_id: string
  version: number
  is_current: boolean
  issued_on: string | null
  supersedes_public_id: string | null
  created_at: string
  file: Pick<StoredFile, 'public_id' | 'original_name' | 'mime_type' | 'size_bytes' | 'classification' | 'classification_label' | 'status'>
  download_reason_required: boolean
}
export interface LegalDocument {
  public_id: string
  type: CodeLabel
  reference: string
  title: string
  status: 'ACTIVE' | 'ARCHIVED'
  status_label: string | null
  owner_unit: { public_id: string | null; name: string | null }
  classification: string
  classification_label?: string
  current_version: number | null
  created_at: string
  lock_version?: number
  versions?: DocumentVersion[]
  actions?: { download: boolean; new_version: boolean; edit: boolean; archive: boolean; restore: boolean; transfer: boolean }
}
