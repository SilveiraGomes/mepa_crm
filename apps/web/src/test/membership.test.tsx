import { describe, expect, it } from 'vitest'
import { mb } from '../lib/membership/endpoints'
import { collectiveItems, itemMessage, toMembershipError } from '../lib/membership/errors'
import { UNAVAILABLE_MESSAGE } from '../lib/academy/errors'
import { ApiError } from '../services/api'

describe('Membership API paths (P0.9)', () => {
  it('addresses targets by public id only, percent-encoded; the official number is never a path key', () => {
    expect(mb.membership('01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe('memberships/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(mb.action('../x', 'approve')).toBe('memberships/..%2Fx/approve')
    expect(mb.milestoneCorrect('M', 'BAPTISM/../x')).toBe('memberships/M/milestones/BAPTISM%2F..%2Fx/correct')
    expect(mb.transferStage('T', 'complete')).toBe('memberships/transfers/T/complete')
    expect(mb.collective()).toBe('memberships/admissions/collective-approval')
    const all = [mb.list(), mb.context(), mb.submit(), mb.periods('X'), mb.legacy('X'), mb.legacyRevoke('X'), mb.milestones('X'), mb.transfers(), mb.transfersOf('X')].join('\n')
    expect(all).not.toMatch(/MEPA\d|number|delete|_id/)
  })
})

describe('Membership error translation', () => {
  it('keeps every 404 on the single F-06 message', () => {
    const error = toMembershipError(new ApiError(404, 'RESOURCE_NOT_FOUND'))
    expect(error.kind).toBe('unavailable')
    expect(error.message).toBe(UNAVAILABLE_MESSAGE)
  })

  it('explains the domain conflicts in Portuguese without raw codes', () => {
    for (const code of ['TRANSFER_IN_PROGRESS', 'PERSON_DECEASED', 'MEMBERSHIP_EXISTS', 'MILESTONE_EXISTS', 'LEGACY_ID_EXISTS', 'TRANSITION_NOT_ALLOWED']) {
      const error = toMembershipError(new ApiError(409, code))
      expect(error.kind).toBe('conflict')
      expect(error.message).not.toContain(code)
    }
    expect(toMembershipError(new ApiError(409, 'STALE_WRITE')).kind).toBe('stale')
    expect(toMembershipError(new ApiError(422, 'REASON_REQUIRED')).fields.reason).toBeTruthy()
  })

  it('reports a rejected collective approval as nothing approved, with per-item reasons', () => {
    const failure = new ApiError(409, 'COLLECTIVE_APPROVAL_REJECTED', {}, null, { items: [{ index: 2, public_id: 'X', code: 'TRANSITION_NOT_ALLOWED' }, { index: 4, public_id: 'Y', code: 'RESOURCE_NOT_FOUND' }] })
    expect(toMembershipError(failure).message).toContain('Nada foi aprovado')
    expect(collectiveItems(failure).map((item) => item.index)).toEqual([2, 4])
    expect(itemMessage('RESOURCE_NOT_FOUND')).toBe('indisponível')
    expect(collectiveItems(new ApiError(409, 'STALE_WRITE'))).toEqual([])
  })

  it('never suggests a number was issued when issuance fails closed', () => {
    const error = toMembershipError(new ApiError(503, 'MEMBER_NUMBER_UNAVAILABLE'))
    expect(error.message).toContain('Nada foi alterado')
    expect(error.retryable).toBe(true)
  })
})
