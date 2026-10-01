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
  // P0.10-F1C: explicit transition endpoints only; collections are paginated by the server.
  accounts: () => 'finance/accounts',
  account: (id: string) => `finance/accounts/${seg(id)}`,
  accountHistory: (id: string) => `finance/accounts/${seg(id)}/history`,
  accountClose: (id: string) => `finance/accounts/${seg(id)}/close`,
  subledgers: (kind: SubledgerKind) => `finance/${kind}`,
  subledger: (kind: SubledgerKind, id: string) => `finance/${kind}/${seg(id)}`,
  subledgerStep: (kind: SubledgerKind, id: string, step: 'settlements' | 'cancel') => `finance/${kind}/${seg(id)}/${step}`,
  settlement: (id: string) => `finance/settlements/${seg(id)}`,
  settlementCancel: (id: string) => `finance/settlements/${seg(id)}/cancel`,
  statements: () => 'finance/bank-statements',
  statement: (id: string) => `finance/bank-statements/${seg(id)}`,
  reconciliations: () => 'finance/reconciliations',
  reconciliation: (id: string) => `finance/reconciliations/${seg(id)}`,
  reconciliationStep: (id: string, step: 'matches' | 'unmatch' | 'close' | 'adjustments') => `finance/reconciliations/${seg(id)}/${step}`,
  budgets: () => 'finance/budgets',
  budget: (id: string) => `finance/budgets/${seg(id)}`,
  budgetStep: (id: string, step: 'lines' | 'submit' | 'return' | 'review' | 'approve' | 'cancel' | 'revise') => `finance/budgets/${seg(id)}/${step}`,
  actualVsBudget: (id: string) => `finance/budgets/${seg(id)}/actual-vs-budget`,
  periods: () => 'finance/periods',
  periodStep: (code: string, step: 'close' | 'reopen' | 'national-close') => `finance/periods/${seg(code)}/${step}`,
}

export type SubledgerKind = 'receivables' | 'payables'
