import type { AgeBand, Birth, BirthPrecision, PersonStatus } from '../../types/people'

export const STATUS_LABEL: Record<PersonStatus, string> = { ACTIVE: 'Activa', INACTIVE: 'Inactiva', DECEASED: 'Falecida' }
export const HOUSEHOLD_STATUS_LABEL: Record<string, string> = { ACTIVE: 'Activa', INACTIVE: 'Inactiva', ARCHIVED: 'Arquivada' }
export const PRECISION_LABEL: Record<BirthPrecision, string> = { EXACT: 'Data completa', MONTH: 'Mês e ano', YEAR: 'Apenas o ano', UNKNOWN: 'Desconhecida' }
export const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro']
const dateOnly = new Intl.DateTimeFormat('pt-AO', { dateStyle: 'long', timeZone: 'UTC' })

export function ageBandLabel(band: AgeBand): string {
  if (band === 'MINOR' || band === 'UNCERTAIN') return 'Menor'
  if (band === 'ADULT') return 'Adulto'
  return 'Não determinada'
}

/** Renders only the components that exist: never a day for MONTH, never a month for YEAR. */
export function formatBirth(birth: Birth | undefined): string {
  if (!birth) return 'Oculto'
  switch (birth.precision) {
    case 'EXACT': return birth.date ? dateOnly.format(new Date(`${birth.date}T00:00:00Z`)) : '—'
    case 'MONTH': return birth.month && birth.year ? `${MONTHS[birth.month - 1]} de ${birth.year}` : '—'
    case 'YEAR': return birth.year ? String(birth.year) : '—'
    default: return 'Desconhecida'
  }
}

export interface BirthValue { precision: BirthPrecision; date: string; year: string; month: string }
export const EMPTY_BIRTH: BirthValue = { precision: 'UNKNOWN', date: '', year: '', month: '' }

export function birthFromDetail(birth: Birth | undefined, precision: BirthPrecision): BirthValue {
  return { precision, date: birth?.date ?? '', year: birth?.year ? String(birth.year) : '', month: birth?.month ? String(birth.month) : '' }
}

/** Request body for a precision: fields that do not belong to it are sent as null, never invented. */
export function birthPayload(value: BirthValue): Record<string, string | number | null> {
  switch (value.precision) {
    case 'EXACT': return { birth_precision: 'EXACT', birth_date: value.date || null, birth_year: null, birth_month: null }
    case 'MONTH': return { birth_precision: 'MONTH', birth_date: null, birth_year: value.year ? Number(value.year) : null, birth_month: value.month ? Number(value.month) : null }
    case 'YEAR': return { birth_precision: 'YEAR', birth_date: null, birth_year: value.year ? Number(value.year) : null, birth_month: null }
    default: return { birth_precision: 'UNKNOWN', birth_date: null, birth_year: null, birth_month: null }
  }
}

