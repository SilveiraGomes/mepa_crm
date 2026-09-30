const seg = (value: string) => encodeURIComponent(value)
const transfer = (id: string) => `finance/transfers/${seg(id)}`

/** Finance API paths (P0.10-F1B). Targets are public ids; explicit stage endpoints only. */
export const fin = {
  context: () => 'finance/context',
  units: () => 'finance/units',
  transfers: () => 'finance/transfers',
  transfer,
  stage: (id: string, stage: 'send' | 'receive' | 'cancel' | 'reverse-send' | 'reconcile') => `${transfer(id)}/${stage}`,
  custody: (unit: string) => `finance/units/${seg(unit)}/custody`,
  subtree: (unit: string) => `finance/units/${seg(unit)}/subtree-transfers`,
}
