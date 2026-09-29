import { describe, expect, it } from 'vitest'
import { ph } from '../lib/physical/endpoints'
import { toPhysicalError } from '../lib/physical/errors'
import { UNAVAILABLE_MESSAGE } from '../lib/academy/errors'
import { ApiError } from '../services/api'

describe('Physical API paths (P0.7)', () => {
  it('addresses targets by public id / opaque ref, percent-encoded', () => {
    expect(ph.location('01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe('physical/locations/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(ph.transfer('../x', 'a/b')).toBe('physical/locations/..%2Fx/links/a%2Fb/transfer')
    expect(ph.setPrimary('L', 'r')).toBe('physical/locations/L/links/r/set-primary')
    expect(ph.externalOwner('P')).toBe('physical/properties/P/external-owner')
    expect(Object.values(ph).map((build) => build('X', 'Y')).join('\n')).not.toMatch(/\/status$|visibility|source_document/m)
  })
})

describe('Physical error translation', () => {
  it('keeps every 404 on the single F-06 message', () => {
    const error = toPhysicalError(new ApiError(404, 'RESOURCE_NOT_FOUND'))
    expect(error.kind).toBe('unavailable')
    expect(error.message).toBe(UNAVAILABLE_MESSAGE)
  })

  it('explains the ADR-0018 conflicts in Portuguese without raw codes', () => {
    const last = toPhysicalError(new ApiError(409, 'LAST_ACTIVE_LINK_REQUIRED'))
    expect(last.message).toContain('último vínculo activo')
    expect(last.message).not.toContain('LAST_ACTIVE_LINK_REQUIRED')
    expect(toPhysicalError(new ApiError(409, 'STALE_WRITE')).kind).toBe('stale')
    expect(toPhysicalError(new ApiError(409, 'COORDINATES_REQUIRED')).message).toContain('latitude')
    expect(toPhysicalError(new ApiError(422, 'REASON_REQUIRED')).fields.reason).toBeTruthy()
  })

  it('never suggests that protected data was exposed when crypto fails closed', () => {
    const error = toPhysicalError(new ApiError(503, 'PHYSICAL_CRYPTO_UNAVAILABLE'))
    expect(error.message).toContain('Nenhum dado foi exposto')
    expect(error.retryable).toBe(true)
  })
})
