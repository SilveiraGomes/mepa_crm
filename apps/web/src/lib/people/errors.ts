import { ApiError } from '../../services/api'
import { toUiError, type UiError } from '../academy/errors'

const PEOPLE_CONFLICTS: Record<string, string> = {
  STALE_WRITE: 'Este registo foi alterado por outro utilizador. Actualize os dados antes de tentar novamente.',
  TRANSITION_NOT_ALLOWED: 'Esta alteração de estado não é permitida no estado actual.',
  PERSON_DECEASED: 'Esta pessoa está registada como falecida. Os dados ficam preservados e não podem ser alterados.',
  CONTACT_EXISTS: 'Este contacto já está registado para esta pessoa.',
  ALREADY_MEMBER: 'Esta pessoa já é membro activo desta família.',
  HOUSEHOLD_NOT_ACTIVE: 'Só é possível alterar membros de uma família activa.',
  RELATIONSHIP_EXISTS: 'Esta relação já está registada.',
  RELATIONSHIP_CONFLICT: 'Esta relação contradiz uma relação já registada entre as duas pessoas.',
}

/**
 * People errors reuse the shared translation (F-06: every 404 is the same message) and add the People
 * specific codes. Raw codes and server messages are never shown.
 */
export function toPeopleError(error: unknown): UiError {
  if (error instanceof ApiError) {
    if (error.status === 403 && error.code === 'SENSITIVE_DATA_RESTRICTED') {
      return { kind: 'forbidden', title: 'Dados sensíveis ocultos', message: 'Estes dados exigem uma permissão específica de consulta de dados sensíveis.', fields: {}, retryable: false }
    }
    if (error.status === 403 && error.code === 'MINOR_PROTECTED') {
      return { kind: 'forbidden', title: 'Dados de menor protegidos', message: 'Os dados desta pessoa não estão disponíveis nesta área. Utilize o fluxo próprio do domínio responsável.', fields: {}, retryable: false }
    }
    if (error.status === 422 && error.code === 'REASON_REQUIRED') {
      return { kind: 'validation', title: 'Motivo obrigatório', message: 'Indique o motivo desta operação.', fields: { reason: 'Indique o motivo.' }, retryable: false }
    }
    if (error.status === 422 && error.code === 'EXPORT_TOO_LARGE') {
      return { kind: 'validation', title: 'Exportação demasiado grande', message: 'Restrinja os filtros para exportar menos registos.', fields: {}, retryable: false }
    }
    if (error.status === 503) {
      return { kind: 'server', title: 'Dados protegidos indisponíveis', message: 'Os dados protegidos estão temporariamente indisponíveis. Nenhum dado foi exposto ou alterado.', fields: {}, retryable: true }
    }
    if (error.status === 409 && PEOPLE_CONFLICTS[error.code]) {
      return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: error.code === 'STALE_WRITE' ? 'Registo desactualizado' : 'Conflito', message: PEOPLE_CONFLICTS[error.code], fields: {}, retryable: false }
    }
  }
  return toUiError(error)
}
