import { describe, expect, it } from 'vitest'
import { fin } from '../lib/finance/endpoints'
import { toFinanceError } from '../lib/finance/errors'
import { formatKz } from '../lib/finance/format'
import { UNAVAILABLE_MESSAGE } from '../lib/academy/errors'
import { ApiError } from '../services/api'

describe('Finance API paths (P0.10-F1B)', () => {
  it('addresses targets by public id only, percent-encoded, with explicit stage endpoints (no generic status)', () => {
    expect(fin.transfer('01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe('finance/transfers/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(fin.stage('../x', 'send')).toBe('finance/transfers/..%2Fx/send')
    expect(fin.stage('T', 'reverse-send')).toBe('finance/transfers/T/reverse-send')
    expect(fin.custody('U/1')).toBe('finance/units/U%2F1/custody')
    const all = [fin.context(), fin.units(), fin.transfers(), fin.subtree('X')].join('\n')
    expect(all).not.toMatch(/status|_id|delete/)
  })
})

describe('Money display', () => {
  it('formats decimal strings without floats (no rounding drift)', () => {
    // Thousands: narrow no-break space (U+202F); before the unit: no-break space (U+00A0); minus sign U+2212.
    expect(formatKz('1234567.50')).toBe('1 234 567,50 Kz')
    expect(formatKz('0.10')).toBe('0,10 Kz')
    expect(formatKz('100000.00')).toBe('100 000,00 Kz')
    expect(formatKz('-5.00')).toBe('−5,00 Kz')
    expect(formatKz(null)).toBe('—')
  })
})

describe('Finance error translation', () => {
  it('keeps every 404 on the single F-06 message', () => {
    const error = toFinanceError(new ApiError(404, 'RESOURCE_NOT_FOUND'))
    expect(error.kind).toBe('unavailable')
    expect(error.message).toBe(UNAVAILABLE_MESSAGE)
  })

  it('explains transfer conflicts in Portuguese without raw codes', () => {
    for (const code of ['TRANSITION_NOT_ALLOWED', 'INSUFFICIENT_FUNDS', 'AMOUNT_MISMATCH', 'ALREADY_RECEIVED', 'PERIOD_CLOSED', 'RECONCILIATION_MISMATCH']) {
      const error = toFinanceError(new ApiError(409, code))
      expect(error.kind).toBe('conflict')
      expect(error.message).not.toContain(code)
    }
    expect(toFinanceError(new ApiError(422, 'AMOUNT_SCALE')).message).toContain('2 casas decimais')
  })
})

describe('Finance Core API paths (P0.10-F1C)', () => {
  it('uses public ids and explicit transition endpoints only', () => {
    expect(fin.subledgerStep('receivables', 'R/1', 'settlements')).toBe('finance/receivables/R%2F1/settlements')
    expect(fin.settlementCancel('S')).toBe('finance/settlements/S/cancel')
    expect(fin.reconciliationStep('X', 'matches')).toBe('finance/reconciliations/X/matches')
    expect(fin.budgetStep('B', 'approve')).toBe('finance/budgets/B/approve')
    expect(fin.periodStep('2026-08', 'national-close')).toBe('finance/periods/2026-08/national-close')
    const all = [fin.accounts(), fin.account('A'), fin.accountClose('A'), fin.subledgers('payables'), fin.statements(), fin.reconciliations(), fin.budgets(), fin.actualVsBudget('B'), fin.periods()].join('|')
    expect(all).not.toMatch(/status|_id|delete|patch/i)
  })
})

describe('Finance Core error translation (P0.10-F1C)', () => {
  it('explains accrual, reconciliation, budget and close conflicts without raw codes', () => {
    for (const code of ['OVER_SETTLEMENT', 'ALREADY_SETTLED', 'MATCH_EXCEEDS_STATEMENT_LINE', 'MATCH_EXCEEDS_LEDGER_LINE', 'SEGREGATION_REQUIRED', 'BUDGET_VERSION_OUTDATED', 'UNITS_NOT_CLOSED', 'ACCOUNT_BALANCE_NOT_ZERO']) {
      const error = toFinanceError(new ApiError(409, code))
      expect(error.kind).toBe('conflict')
      expect(error.message).not.toContain(code)
    }
  })

  it('attaches validation messages to the right field', () => {
    expect(toFinanceError(new ApiError(422, 'PAYABLE_DOCUMENT_REQUIRED')).fields.document).toContain('documento')
    expect(toFinanceError(new ApiError(422, 'CATEGORY_NOT_RECEIVABLE')).fields.category).toContain('Dízimos')
    expect(toFinanceError(new ApiError(422, 'STATEMENT_UNBALANCED')).fields.closing_balance).toContain('saldo final')
    expect(toFinanceError(new ApiError(422, 'REASON_REQUIRED')).fields.reason).toBe('Indique o motivo.')
  })
})
