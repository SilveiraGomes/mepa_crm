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
