import { ApiError, NetworkError } from '../../services/api'

export type UiErrorKind =
  | 'unavailable'
  | 'unauthenticated'
  | 'forbidden'
  | 'stale'
  | 'conflict'
  | 'policy'
  | 'state-policy'
  | 'participation'
  | 'validation'
  | 'throttled'
  | 'offline'
  | 'server'
  | 'unknown'

export interface UiError {
  kind: UiErrorKind
  title: string
  message: string
  /** Field-level problems, keyed by the request field name. Values are user-facing Portuguese. */
  fields: Record<string, string>
  retryable: boolean
}

export const UNAVAILABLE_MESSAGE = 'Recurso não encontrado ou indisponível.'

const CONFLICT_MESSAGES: Record<string, string> = {
  STALE_WRITE: 'Este registo foi alterado por outro utilizador. Actualize os dados antes de tentar novamente.',
  ALREADY_ENROLLED: 'Esta pessoa já está matriculada nesta turma.',
  ALREADY_ASSIGNED: 'Esta pessoa já é instrutor activo desta turma.',
  CERTIFICATE_ALREADY_EXISTS: 'Já existe um certificado emitido para esta matrícula.',
  CERTIFICATE_ALREADY_REVOKED: 'Este certificado já foi revogado.',
  GRADE_ALREADY_RECORDED: 'Já existe uma nota registada para esta tentativa. Utilize a revisão para a alterar.',
  CURRICULUM_ALREADY_PUBLISHED: 'Este currículo já foi publicado.',
  CURRICULUM_PUBLISHED_IMMUTABLE: 'Um currículo publicado não pode ser alterado.',
  CONFLICT: 'A operação não pôde ser concluída no estado actual do registo.',
}

/**
 * Single translation point from an API failure to what a user may read. Raw codes and English
 * server messages are never shown. Any 404 collapses into one message: the UI must not tell
 * "does not exist" from "not allowed" from "out of scope" (F-06).
 */
export function toUiError(error: unknown): UiError {
  if (error instanceof NetworkError) {
    return { kind: 'offline', title: 'Sem ligação', message: 'Sem ligação ao servidor. Verifique a sua ligação e tente novamente.', fields: {}, retryable: true }
  }
  if (!(error instanceof ApiError)) {
    return { kind: 'unknown', title: 'Erro inesperado', message: 'Não foi possível concluir a operação.', fields: {}, retryable: true }
  }

  if (error.status === 404) {
    return { kind: 'unavailable', title: 'Indisponível', message: UNAVAILABLE_MESSAGE, fields: {}, retryable: false }
  }
  if (error.status === 401) {
    return { kind: 'unauthenticated', title: 'Sessão terminada', message: 'A sua sessão terminou. Inicie sessão novamente.', fields: {}, retryable: false }
  }
  if (error.status === 403) {
    return { kind: 'forbidden', title: 'Sem autorização', message: 'Não tem autorização para esta operação.', fields: {}, retryable: false }
  }
  if (error.status === 429) {
    const wait = error.retryAfterSeconds ? ` Tente novamente dentro de ${error.retryAfterSeconds} segundos.` : ''
    return { kind: 'throttled', title: 'Demasiados pedidos', message: `Foram feitos demasiados pedidos seguidos.${wait}`, fields: {}, retryable: true }
  }
  if (error.status >= 500) {
    return { kind: 'server', title: 'Erro no servidor', message: 'Ocorreu um erro no servidor. Tente novamente dentro de instantes.', fields: {}, retryable: true }
  }

  switch (error.code) {
    case 'ACADEMIC_POLICY_NOT_CONFIGURED':
      return { kind: 'policy', title: 'Política não configurada', message: 'Esta operação ainda não está disponível porque a política académica correspondente não foi configurada.', fields: {}, retryable: false }
    case 'STATE_POLICY_PENDING':
      return { kind: 'state-policy', title: 'Política de estados pendente', message: 'Esta alteração ainda não está disponível porque a política académica correspondente não foi definida.', fields: {}, retryable: false }
    case 'PARTICIPATION_REQUIREMENTS_NOT_MET':
      return { kind: 'participation', title: 'Requisitos não cumpridos', message: 'Não foi possível concluir a operação porque os requisitos de participação não estão cumpridos.', fields: {}, retryable: false }
    case 'VALIDATION_ERROR': {
      const fields: Record<string, string> = {}
      for (const key of Object.keys(error.fields)) fields[key] = 'Verifique este campo.'
      return { kind: 'validation', title: 'Dados inválidos', message: 'Verifique os campos assinalados e tente novamente.', fields, retryable: false }
    }
    default:
      if (error.status === 409) {
        const known = CONFLICT_MESSAGES[error.code]
        return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: error.code === 'STALE_WRITE' ? 'Registo desactualizado' : 'Conflito', message: known ?? CONFLICT_MESSAGES.CONFLICT, fields: {}, retryable: false }
      }
      return { kind: 'unknown', title: 'Erro', message: 'Não foi possível concluir a operação.', fields: {}, retryable: false }
  }
}

export function isAbort(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}
