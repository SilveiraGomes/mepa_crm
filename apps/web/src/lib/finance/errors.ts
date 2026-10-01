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
  // P0.10-F1C
  OVER_SETTLEMENT: 'O valor excede o montante em aberto. Nada foi registado.',
  ALREADY_SETTLED: 'Este valor já está totalmente liquidado.',
  HAS_SETTLEMENTS: 'Existem liquidações registadas. Anule-as primeiro.',
  ACCOUNT_BALANCE_NOT_ZERO: 'A conta só pode ser fechada com saldo zero.',
  ACCOUNT_HAS_PENDING_ENTRIES: 'Existem lançamentos por publicar nesta conta.',
  ACCOUNT_CODE_TAKEN: 'Já existe uma conta com este código na unidade.',
  ACCOUNT_NOT_BANK: 'A operação exige uma conta bancária.',
  FINANCIAL_ACCOUNT_CLOSED: 'A conta financeira está fechada e não aceita movimentos.',
  STATEMENT_ALREADY_IMPORTED: 'Este extracto já foi importado para a conta.',
  STATEMENT_OUTSIDE_PERIOD: 'O extracto não cobre o mês da reconciliação.',
  RECONCILIATION_ALREADY_OPEN: 'Já existe uma reconciliação aberta para esta conta e mês.',
  RECONCILIATION_CLOSED: 'A reconciliação está fechada e não pode ser alterada.',
  MATCH_DIRECTION_MISMATCH: 'Uma entrada no banco só corresponde a uma entrada no razão (e uma saída a uma saída).',
  MATCH_EXCEEDS_STATEMENT_LINE: 'O valor excede o que falta reconciliar da linha do extracto.',
  MATCH_EXCEEDS_LEDGER_LINE: 'O valor excede o que falta reconciliar do movimento contabilístico.',
  MATCH_DATE_INCOMPATIBLE: 'O movimento contabilístico é posterior ao fim do mês reconciliado.',
  ALREADY_MATCHED: 'Esta correspondência já existe nesta reconciliação.',
  MATCH_NOT_FOUND: 'Esta correspondência não existe.',
  ENTRY_NOT_POSTED: 'Só movimentos publicados podem ser reconciliados.',
  BUDGET_EMPTY: 'O orçamento não tem linhas.',
  BUDGET_NOT_EDITABLE: 'Só um orçamento em rascunho pode ser alterado. Crie uma revisão.',
  BUDGET_VERSION_OUTDATED: 'Já existe uma versão mais recente aprovada.',
  SEGREGATION_REQUIRED: 'Esta operação tem de ser feita por outra pessoa (segregação de funções).',
  PERIOD_ALREADY_CLOSED: 'O mês já está fechado para esta unidade.',
  PERIOD_HAS_PENDING_ENTRIES: 'Existem lançamentos por publicar neste mês.',
  PERIOD_NOT_CLOSED: 'O mês não está fechado para esta unidade.',
  UNITS_NOT_CLOSED: 'Há unidades com movimentos que ainda não fecharam o mês.',
}

const FINANCE_VALIDATION: Record<string, string> = {
  AMOUNT_SCALE: 'O valor aceita no máximo 2 casas decimais.',
  AMOUNT_INVALID: 'Indique um valor válido, por exemplo 1500.50.',
  AMOUNT_NOT_POSITIVE: 'O valor tem de ser maior do que zero.',
  AMOUNT_LIMIT: 'O valor excede o limite permitido.',
  ENTRY_DATE_IN_FUTURE: 'A data não pode ser futura.',
  REASON_REQUIRED: 'Indique o motivo desta operação (mínimo 3 caracteres).',
  CATEGORY_NOT_RECEIVABLE: 'Dízimos, ofertas e doações reconhecem-se quando recebidos: não são valores a receber.',
  PAYABLE_DOCUMENT_REQUIRED: 'Um valor a pagar exige o documento de suporte (factura, recibo ou documento de despesa).',
  STATEMENT_DOCUMENT_REQUIRED: 'O extracto exige o ficheiro do extracto bancário.',
  STATEMENT_UNBALANCED: 'Saldo inicial + linhas tem de ser igual ao saldo final.',
  INTERNAL_COUNTERPARTY: 'Uma unidade MEPA não é cliente nem fornecedor: use uma transferência interna.',
}

/** Form field a validation code belongs to (the message is shown next to it). */
const FIELD_OF: Record<string, string> = {
  REASON_REQUIRED: 'reason', CATEGORY_NOT_RECEIVABLE: 'category', INTERNAL_COUNTERPARTY: 'category', PAYABLE_DOCUMENT_REQUIRED: 'document',
  STATEMENT_DOCUMENT_REQUIRED: 'document', STATEMENT_UNBALANCED: 'closing_balance',
}

/** Finance errors reuse the shared translation (F-06: every 404 is the same message). Raw codes are never shown. */
export function toFinanceError(error: unknown): UiError {
  if (error instanceof ApiError) {
    if (error.status === 422 && FINANCE_VALIDATION[error.code]) {
      return { kind: 'validation', title: 'Dados inválidos', message: FINANCE_VALIDATION[error.code], fields: { [FIELD_OF[error.code] ?? 'amount']: error.code === 'REASON_REQUIRED' ? 'Indique o motivo.' : FINANCE_VALIDATION[error.code] }, retryable: false }
    }
    if (error.status === 409 && FINANCE_CONFLICTS[error.code]) {
      return { kind: error.code === 'STALE_WRITE' ? 'stale' : 'conflict', title: 'Operação recusada', message: FINANCE_CONFLICTS[error.code], fields: {}, retryable: false }
    }
  }
  return toUiError(error)
}
