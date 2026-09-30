import { ApiError } from '../../services/api'
import { toUiError, type UiError } from '../academy/errors'

const FINANCE_CONFLICTS: Record<string, string> = {
  TRANSITION_NOT_ALLOWED: 'Esta operação não é permitida no estado actual da transferência.',
  STALE_WRITE: 'A transferência foi alterada por outro utilizador. Actualize os dados antes de tentar novamente.',
  INSUFFICIENT_FUNDS: 'A conta de origem não tem saldo suficiente para este envio.',
  AMOUNT_MISMATCH: 'O valor recebido tem de ser exactamente igual ao valor enviado. Tarifas bancárias são registadas à parte.',
  ALREADY_RECEIVED: 'A transferência já foi recebida pelo destino e não pode ser devolvida isoladamente.',
  PERIOD_CLOSED: 'O período contabilístico desta data está fechado para a unidade.',
  PERIOD_NOT_FOUND: 'Não existe período contabilístico aberto para esta data.',
  ACCOUNT_NOT_OPEN: 'A conta financeira não está aberta.',
  DESTINATION_NOT_ACTIVE: 'A unidade de destino não está activa.',
  RECONCILIATION_MISMATCH: 'O envio e a recepção não correspondem. A transferência não foi reconciliada.',
  IDEMPOTENCY_CONFLICT: 'Este pedido já foi submetido com outros dados. Recarregue o formulário.',
  CURRENCY_NOT_SUPPORTED: 'Só são aceites valores em Kwanzas (AOA).',
}

const FINANCE_VALIDATION: Record<string, string> = {
  AMOUNT_SCALE: 'O valor aceita no máximo 2 casas decimais.',
  AMOUNT_INVALID: 'Indique um valor válido, por exemplo 1500.50.',
  AMOUNT_NOT_POSITIVE: 'O valor tem de ser maior do que zero.',
  AMOUNT_LIMIT: 'O valor excede o limite permitido.',
  ENTRY_DATE_IN_FUTURE: 'A data não pode ser futura.',
  REASON_REQUIRED: 'Indique o motivo desta operação (mínimo 3 caracteres).',
}

/** Finance errors reuse the shared translation (F-06: every 404 is the same message). Raw codes are never shown. */
export function toFinanceError(error: unknown): UiError {
  if (error instanceof ApiError) {
    if (error.status === 422 && FINANCE_VALIDATION[error.code]) {
      return { kind: 'validation', title: 'Dados inválidos', message: FINANCE_VALIDATION[error.code], fields: error.code === 'REASON_REQUIRED' ? { reason: 'Indique o motivo.' } : { amount: FINANCE_VALIDATION[error.code] }, retryable: false }
    }
    if (error.status === 409 && FINANCE_CONFLICTS[error.code]) {
      return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: 'Operação recusada', message: FINANCE_CONFLICTS[error.code], fields: {}, retryable: false }
    }
  }
  return toUiError(error)
}
