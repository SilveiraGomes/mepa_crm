import { ApiError } from '../../services/api'
import { toUiError, type UiError } from '../academy/errors'

const REJECTIONS: Record<string, string> = {
  FILE_TYPE_NOT_ALLOWED: 'Tipo de ficheiro não permitido. Aceites: PDF, JPEG, PNG e WebP. O ficheiro foi destruído e a rejeição ficou registada.',
  FILE_CONTENT_REJECTED: 'O conteúdo do ficheiro foi recusado pela verificação estrutural (por exemplo, PDF cifrado ou com conteúdo activo). O ficheiro foi destruído.',
  FILE_TOO_LARGE: 'O ficheiro excede o tamanho máximo permitido.',
  FILE_EMPTY: 'O ficheiro está vazio.',
  FILE_NAME_INVALID: 'O nome do ficheiro não é válido.',
  FILES_QUOTA_EXCEEDED: 'A quota de armazenamento da unidade não permite este ficheiro.',
  CLASSIFICATION_INVALID: 'A classificação indicada não é válida.',
}
const CONFLICTS: Record<string, string> = {
  STALE_WRITE: 'Este registo foi alterado por outro utilizador. Actualize os dados antes de tentar novamente.',
  TRANSITION_NOT_ALLOWED: 'Esta alteração não é permitida no estado actual do registo.',
  FILE_IN_USE: 'O ficheiro está a ser usado (por exemplo, numa versão de documento) e não pode ser retirado nem mudar de unidade.',
  DOCUMENT_ARCHIVED: 'O documento está arquivado. Restaure-o antes de adicionar versões.',
  CLASSIFICATION_BELOW_FLOOR: 'A classificação não pode ficar abaixo do mínimo exigido (versão anterior ou utilização).',
}

/** Files errors reuse the shared translation (F-06: every 404 is the same message) plus the ADR-0019 codes. */
export function toFilesError(error: unknown): UiError {
  if (error instanceof ApiError) {
    if (error.status === 422 && error.code === 'REASON_REQUIRED') {
      return { kind: 'validation', title: 'Motivo obrigatório', message: 'Indique o motivo desta operação (mínimo 3 caracteres).', fields: { reason: 'Indique o motivo.' }, retryable: false }
    }
    if (REJECTIONS[error.code]) return { kind: 'validation', title: 'Ficheiro não aceite', message: REJECTIONS[error.code], fields: {}, retryable: false }
    if (error.status === 403 && error.code === 'CLEARANCE_REQUIRED') return { kind: 'forbidden', title: 'Classificação não autorizada', message: 'A sua habilitação não permite esta classificação.', fields: {}, retryable: false }
    if (error.status === 503) {
      return { kind: 'server', title: 'Conteúdo indisponível', message: 'O conteúdo protegido está temporariamente indisponível. Nenhum dado foi exposto nem criado.', fields: {}, retryable: true }
    }
    if (error.status === 409 && CONFLICTS[error.code]) {
      return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: error.code === 'STALE_WRITE' ? 'Registo desactualizado' : 'Operação recusada', message: CONFLICTS[error.code], fields: {}, retryable: false }
    }
  }
  return toUiError(error)
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1).replace('.', ',')} KiB`
  return `${(bytes / 1048576).toFixed(1).replace('.', ',')} MiB`
}
