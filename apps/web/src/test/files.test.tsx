import { describe, expect, it } from 'vitest'
import { fl } from '../lib/files/endpoints'
import { formatBytes, toFilesError } from '../lib/files/errors'
import { UNAVAILABLE_MESSAGE } from '../lib/academy/errors'
import { ApiError } from '../services/api'

describe('Documents/Files API paths (P0.8)', () => {
  it('addresses targets by public id only, percent-encoded, content only through /content', () => {
    expect(fl.file('01ARZ3NDEKTSV4RRFFQ69G5FAV')).toBe('files/01ARZ3NDEKTSV4RRFFQ69G5FAV')
    expect(fl.content('../x')).toBe('files/..%2Fx/content')
    expect(fl.versionContent('D', 'a/b')).toBe('documents/D/versions/a%2Fb/content')
    const all = Object.values(fl).map((build) => build('X', 'Y')).join('\n')
    expect(all).not.toMatch(/storage|signed|url|purge|delete|v1\//)
  })
})

describe('Documents/Files error translation', () => {
  it('keeps every 404 on the single F-06 message', () => {
    const error = toFilesError(new ApiError(404, 'RESOURCE_NOT_FOUND'))
    expect(error.kind).toBe('unavailable')
    expect(error.message).toBe(UNAVAILABLE_MESSAGE)
  })

  it('explains rejections, quota and conflicts in Portuguese without raw codes', () => {
    expect(toFilesError(new ApiError(422, 'FILE_TYPE_NOT_ALLOWED')).message).toContain('PDF, JPEG, PNG e WebP')
    expect(toFilesError(new ApiError(422, 'FILES_QUOTA_EXCEEDED')).message).toContain('quota')
    expect(toFilesError(new ApiError(409, 'FILE_IN_USE')).message).not.toContain('FILE_IN_USE')
    expect(toFilesError(new ApiError(422, 'REASON_REQUIRED')).fields.reason).toBeTruthy()
  })

  it('never suggests that protected content was exposed when storage or crypto fails closed', () => {
    const error = toFilesError(new ApiError(503, 'FILE_CONTENT_UNAVAILABLE'))
    expect(error.message).toContain('Nenhum dado foi exposto')
    expect(error.retryable).toBe(true)
  })

  it('formats sizes', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2,0 KiB')
    expect(formatBytes(3 * 1048576)).toBe('3,0 MiB')
  })
})
