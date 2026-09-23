import { describe, expect, it } from 'vitest'
import { birthPayload, formatBirth } from '../lib/people/format'
import { pe } from '../lib/people/endpoints'
import { toPeopleError } from '../lib/people/errors'
import { ApiError } from '../services/api'

describe('People birth precision (ADR-0017 D-10)', () => {
  it('never renders or sends invented components', () => {
    expect(formatBirth({ precision: 'MONTH', year: 1990, month: 3 })).toBe('Março de 1990')
    expect(formatBirth({ precision: 'YEAR', year: 1970 })).toBe('1970')
    expect(formatBirth({ precision: 'UNKNOWN' })).toBe('Desconhecida')
    expect(formatBirth(undefined)).toBe('Oculto')
    expect(birthPayload({ precision: 'MONTH', date: '1990-03-01', year: '1990', month: '3' })).toEqual({ birth_precision: 'MONTH', birth_date: null, birth_year: 1990, birth_month: 3 })
    expect(birthPayload({ precision: 'YEAR', date: '', year: '1970', month: '5' })).toEqual({ birth_precision: 'YEAR', birth_date: null, birth_year: 1970, birth_month: null })
    expect(birthPayload({ precision: 'UNKNOWN', date: '2000-01-01', year: '2000', month: '1' })).toEqual({ birth_precision: 'UNKNOWN', birth_date: null, birth_year: null, birth_month: null })
  })
})

describe('People API paths and errors', () => {
  it('percent-encodes every public identifier and nested reference', () => {
    expect(pe.person('01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe('people/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(pe.contact('../x', 'a/b')).toBe('people/..%2Fx/contacts/a%2Fb')
    expect(pe.householdMemberEnd('H', 'r')).toBe('people/households/H/members/r/end')
  })

  it('keeps F-06: every 404 reads the same, sensitive and minor restrictions are explicit', () => {
    expect(toPeopleError(new ApiError(404, 'RESOURCE_NOT_FOUND')).message).toBe('Recurso não encontrado ou indisponível.')
    expect(toPeopleError(new ApiError(403, 'SENSITIVE_DATA_RESTRICTED')).title).toBe('Dados sensíveis ocultos')
    expect(toPeopleError(new ApiError(403, 'MINOR_PROTECTED')).title).toBe('Dados de menor protegidos')
    expect(toPeopleError(new ApiError(409, 'STALE_WRITE')).kind).toBe('stale')
    expect(toPeopleError(new ApiError(503, 'PEOPLE_CRYPTO_UNAVAILABLE')).message).toMatch(/Nenhum dado foi exposto/)
  })
})
