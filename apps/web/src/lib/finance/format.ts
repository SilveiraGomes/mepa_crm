const GROUP = ' ' // narrow no-break space between thousands
const BEFORE_UNIT = ' ' // no-break space before "Kz"

/** "1234567.50" -> "1 234 567,50 Kz" without ever parsing the amount to a float. */
export function formatKz(amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === '') return '—'
  const negative = amount.startsWith('-')
  const [integer, fraction = '00'] = (negative ? amount.slice(1) : amount).split('.')
  const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, GROUP)
  return `${negative ? '−' : ''}${grouped},${fraction.padEnd(2, '0').slice(0, 2)}${BEFORE_UNIT}Kz`
}
