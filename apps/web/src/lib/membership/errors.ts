import { ApiError } from '../../services/api'
import { toUiError, type UiError } from '../academy/errors'

const MEMBERSHIP_CONFLICTS: Record<string, string> = {
  STALE_WRITE: 'Este registo foi alterado por outro utilizador. Actualize os dados antes de tentar novamente.',
  TRANSITION_NOT_ALLOWED: 'Esta alteração não é permitida no estado actual da membresia.',
  MEMBERSHIP_EXISTS: 'Esta pessoa já tem uma membresia ou candidatura em curso.',
  MEMBERSHIP_NOT_ACTIVE: 'Só um membro activo pode ser transferido.',
  PERSON_DECEASED: 'A pessoa está registada como falecida. Só é possível terminar a membresia.',
  PERSON_NOT_OPERATIONAL: 'A pessoa está arquivada ou fundida noutra ficha. Nenhuma alteração é permitida.',
  CONGREGATION_NOT_ACTIVE: 'A Congregação indicada não está activa.',
  TRANSFER_IN_PROGRESS: 'Existe uma transferência em curso. Conclua-a ou cancele-a antes desta operação.',
  LEGACY_ID_EXISTS: 'Este identificador já está registado nesta membresia.',
  LEGACY_ID_REQUIRED: 'Uma regularização de membro histórico exige pelo menos um identificador anterior.',
  MILESTONE_EXISTS: 'Este marco já está registado para esta pessoa. Utilize a correcção.',
}

const ITEM_MESSAGES: Record<string, string> = {
  RESOURCE_NOT_FOUND: 'indisponível',
  TRANSITION_NOT_ALLOWED: 'não está validada',
  STALE_WRITE: 'foi alterada entretanto',
  PERSON_DECEASED: 'pessoa falecida',
  PERSON_NOT_OPERATIONAL: 'pessoa arquivada',
  CONGREGATION_NOT_ACTIVE: 'Congregação inactiva',
  LEGACY_ID_REQUIRED: 'falta identificador anterior',
}

export interface CollectiveItemError { index: number; public_id: string; code: string }

/** Per-item errors of a rejected collective approval (nothing was approved). */
export function collectiveItems(error: unknown): CollectiveItemError[] {
  if (error instanceof ApiError && error.code === 'COLLECTIVE_APPROVAL_REJECTED') {
    return (error.details?.items ?? []) as CollectiveItemError[]
  }
  return []
}

export const itemMessage = (code: string) => ITEM_MESSAGES[code] ?? 'recusada'

/**
 * Membership errors reuse the shared translation (F-06: every 404 is the same message) and add the Membership codes.
 * Raw codes and server messages are never shown.
 */
export function toMembershipError(error: unknown): UiError {
  if (error instanceof ApiError) {
    if (error.status === 422 && error.code === 'REASON_REQUIRED') {
      return { kind: 'validation', title: 'Motivo obrigatório', message: 'Indique o motivo desta operação (mínimo 3 caracteres).', fields: { reason: 'Indique o motivo.' }, retryable: false }
    }
    if (error.status === 409 && error.code === 'COLLECTIVE_APPROVAL_REJECTED') {
      return { kind: 'conflict', title: 'Nenhuma aprovação registada', message: 'A lista contém candidaturas que não podem ser aprovadas. Nada foi aprovado: corrija a lista e tente novamente.', fields: {}, retryable: false }
    }
    if (error.status === 503) {
      return { kind: 'server', title: 'Número indisponível', message: 'Não foi possível emitir o número de membro neste momento. Nada foi alterado.', fields: {}, retryable: true }
    }
    if (error.status === 409 && MEMBERSHIP_CONFLICTS[error.code]) {
      return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: error.code === 'STALE_WRITE' ? 'Registo desactualizado' : 'Operação recusada', message: MEMBERSHIP_CONFLICTS[error.code], fields: {}, retryable: false }
    }
  }
  return toUiError(error)
}
