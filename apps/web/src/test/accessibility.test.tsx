import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import axe from 'axe-core'
import { MemoryRouter } from 'react-router-dom'
import { PageHeader, Pagination } from '../components/ui'

describe('acessibilidade programática', () => {
  it('não encontra violações no cabeçalho e paginação base', async () => {
    const { container } = render(<MemoryRouter><main><PageHeader title="Turmas" description="Lista paginada" /><Pagination meta={{ current_page: 1, per_page: 50, total: 0, last_page: 1 }} onPage={() => undefined} /></main></MemoryRouter>)
    const result = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } })
    expect(result.violations).toEqual([])
  })
})
