const dateTime = new Intl.DateTimeFormat('pt-AO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Luanda' })
const dateOnly = new Intl.DateTimeFormat('pt-AO', { dateStyle: 'medium', timeZone: 'Africa/Luanda' })

// The API stores UTC as "YYYY-MM-DD HH:MM:SS(.ffffff)" without a zone marker.
function parse(value: string): Date | null {
  const iso = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00Z` : `${value.replace(' ', 'T')}${/[zZ]|[+-]\d{2}:?\d{2}$/.test(value) ? '' : 'Z'}`
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? null : date
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = parse(value)
  return date ? dateTime.format(date) : '—'
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = parse(value)
  return date ? dateOnly.format(date) : '—'
}

export function formatNumber(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  return Number.isFinite(number) ? new Intl.NumberFormat('pt-AO', { maximumFractionDigits: 2 }).format(number) : '—'
}

/** Datetime-local input value (browser local time) -> ISO 8601 UTC for the API. */
export function localInputToIso(value: string): string {
  return new Date(value).toISOString()
}

/** Status codes are institutional data returned by the API; they are shown as stored, only made readable. */
export function readableCode(code: string | null | undefined): string {
  if (!code) return '—'
  const words = code.replace(/[_-]+/g, ' ').trim().toLowerCase()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** External resource links are rendered only for http(s); anything else (javascript:, data:) stays plain text. */
export function safeHttpUrl(value: string | null | undefined): string | null {
  if (!value) return null
  try {
    const url = new URL(value)
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.toString() : null
  } catch {
    return null
  }
}
