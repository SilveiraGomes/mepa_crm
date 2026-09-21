import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ApiError, NetworkError, buildUrl } from '../services/api'
import { toUiError, UNAVAILABLE_MESSAGE } from '../lib/academy/errors'
import { capabilitiesFor } from '../lib/academy/permissions'
import { Pagination } from '../components/ui'
import { safeRedirect } from '../lib/auth/session'
import { safeHttpUrl } from '../lib/format'

describe('contratos de segurança da interface Academy', () => {
  it('oculta qualquer 404 com a mesma mensagem', () => {
    expect(toUiError(new ApiError(404, 'RESOURCE_NOT_FOUND')).message).toBe(UNAVAILABLE_MESSAGE)
    expect(toUiError(new ApiError(404, 'OUT_OF_SCOPE')).message).toBe(UNAVAILABLE_MESSAGE)
  })

  it('não revela a causa interna de segurança infantil', () => {
    const result = toUiError(new ApiError(422, 'PARTICIPATION_REQUIREMENTS_NOT_MET'))
    expect(result.message).not.toMatch(/guardian|responsável|consentimento|revog/i)
  })

  it('distingue conflito de escrita obsoleta', () => {
    const result = toUiError(new ApiError(409, 'STALE_WRITE'))
    expect(result.kind).toBe('stale')
    expect(result.message).toMatch(/alterado por outro utilizador/i)
  })

  it('mapeia falta de política e ausência de rede', () => {
    expect(toUiError(new ApiError(422, 'ACADEMIC_POLICY_NOT_CONFIGURED')).kind).toBe('policy')
    expect(toUiError(new NetworkError()).kind).toBe('offline')
  })

  it('oculta acções quando as permissões são conhecidas', () => {
    const caps = capabilitiesFor({ permissions: ['ACADEMY_VIEW'] })
    expect(caps.can('canView')).toBe(true)
    expect(caps.can('canAssess')).toBe(false)
    expect(caps.can('canManage')).toBe(false)
    expect(caps.can('canCertify')).toBe(false)
  })

  it('oculta acções enquanto as permissões efectivas não chegam', () => {
    const caps = capabilitiesFor({})
    expect(caps.known).toBe(false)
    expect(caps.can('canAssess')).toBe(false)
  })

  it('mostra apenas a acção autorizada pela permissão efectiva', () => {
    const caps = capabilitiesFor({ permissions: ['ACADEMY_CERTIFY'] })
    expect(caps.can('canCertify')).toBe(true)
    expect(caps.can('canManage')).toBe(false)
  })

  it('codifica query string e bloqueia redirects externos', () => {
    expect(buildUrl('academy/classes', { search: 'A&B' })).toContain('search=A%26B')
    expect(safeRedirect('//evil.example')).toBe('/academia')
    expect(safeRedirect('/academia/turmas')).toBe('/academia/turmas')
  })

  it('aceita apenas ligações http e https', () => {
    expect(safeHttpUrl('javascript:alert(1)')).toBeNull()
    expect(safeHttpUrl('https://example.test/file')).toBe('https://example.test/file')
  })
})

describe('paginação', () => {
  it('desactiva limites e pede a página seguinte', () => {
    let selected = 0
    render(<Pagination meta={{ current_page: 1, per_page: 50, total: 75, last_page: 2 }} onPage={(page) => { selected = page }} />)
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Seguinte' }))
    expect(selected).toBe(2)
  })
})
