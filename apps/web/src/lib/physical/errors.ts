import { ApiError } from '../../services/api'
import { toUiError, type UiError } from '../academy/errors'

const PHYSICAL_CONFLICTS: Record<string, string> = {
  STALE_WRITE: 'Este registo foi alterado por outro utilizador. Actualize os dados antes de tentar novamente.',
  TRANSITION_NOT_ALLOWED: 'Esta alteração não é permitida no estado actual do registo.',
  ACTIVE_DEPENDENCIES: 'Existem imóveis ou templos activos neste local. Encerre-os antes de encerrar o local.',
  LAST_ACTIVE_LINK_REQUIRED: 'Este é o último vínculo activo do local. Transfira-o para outra unidade ou encerre o local na mesma operação.',
  LINK_EXISTS: 'Esta unidade já tem um vínculo activo com este local.',
  LOCATION_CLOSED: 'O local está encerrado e não aceita novos registos.',
  LOCATION_NOT_ACTIVE: 'O local tem de estar activo para esta operação.',
  COORDINATES_REQUIRED: 'Indique a latitude e a longitude do local antes de o publicar.',
  UNIT_NOT_OPERATIONAL: 'A unidade indicada está encerrada e não pode receber locais.',
  CODE_EXISTS: 'Já existe um imóvel com este código.',
}

/**
 * Physical errors reuse the shared translation (F-06: every 404 is the same message) and add the Physical
 * specific codes. Raw codes and server messages are never shown.
 */
export function toPhysicalError(error: unknown): UiError {
  if (error instanceof ApiError) {
    if (error.status === 422 && error.code === 'REASON_REQUIRED') {
      return { kind: 'validation', title: 'Motivo obrigatório', message: 'Indique o motivo desta operação (mínimo 3 caracteres).', fields: { reason: 'Indique o motivo.' }, retryable: false }
    }
    if (error.status === 503) {
      return { kind: 'server', title: 'Dados protegidos indisponíveis', message: 'A morada protegida está temporariamente indisponível. Nenhum dado foi exposto ou alterado.', fields: {}, retryable: true }
    }
    if (error.status === 409 && PHYSICAL_CONFLICTS[error.code]) {
      return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: error.code === 'STALE_WRITE' ? 'Registo desactualizado' : 'Operação recusada', message: PHYSICAL_CONFLICTS[error.code], fields: {}, retryable: false }
    }
  }
  return toUiError(error)
}
