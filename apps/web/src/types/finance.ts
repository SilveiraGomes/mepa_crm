// DTOs of the P0.10-F1B Finance API (ADR 0021 + D-04A). Only public ids; money is a decimal STRING with 2 places
// ("1234.50"), never a float. Transfer status is the ADR's (DRAFT/SENT/RECEIVED/CANCELLED); the reconciliation state
// is derived by the server.

export type TransferStatus = 'DRAFT' | 'SENT' | 'RECEIVED' | 'CANCELLED'
export type ReconciliationState = 'NOT_APPLICABLE' | 'IN_TRANSIT' | 'AWAITING_RECONCILIATION' | 'RECONCILED' | 'RETURNED'
export type TransferAction = 'send' | 'cancel' | 'receive' | 'reverse_send' | 'reconcile'
export type PerimeterClass = 'INTERNAL_TO_PERIMETER' | 'OUT_OF_PERIMETER' | 'INTO_PERIMETER'

export interface UnitRef { public_id: string; name: string }
export interface Purpose { code: string; label: string; regular: boolean }

export interface FinanceUnit extends UnitRef { type: string; permissions: string[] }
export interface FinanceAccount { public_id: string; name: string; kind: 'CASH' | 'BANK'; unit: string }

export interface Rubric { code: string; label: string; nature: string; capitalized?: boolean }

export interface FinanceContext {
  permissions: string[]
  units: FinanceUnit[]
  accounts: FinanceAccount[]
  purposes: Purpose[]
  contribution_categories: { code: string; label: string }[]
  receivable_categories: Rubric[]
  payable_categories: Rubric[]
  budget_categories: Rubric[]
  years: string[]
  currency: string
  today: string
}

export interface TransferSummary {
  public_id: string
  origin: UnitRef
  destination: UnitRef
  amount: string
  currency: string
  purpose: Purpose
  status: TransferStatus
  reconciliation_state: ReconciliationState
  created_at: string
  sent_at: string | null
  received_at: string | null
  reconciled_at: string | null
  age_days: number | null
  economic_effect: string
  perimeter_class?: PerimeterClass
}

export interface TransferStage { stage: 'SEND' | 'RECEIVE' | 'REVERSE_SEND'; entry: string | null; entry_date: string; posted_at: string }

export interface TransferDetail extends TransferSummary {
  side: 'ORIGIN' | 'DESTINATION' | 'BOTH'
  lock_version: number
  origin_account: { public_id: string; name: string; kind: string } | null
  destination_account: { public_id: string; name: string; kind: string } | null
  cancel_reason: string | null
  document: { public_id: string | null } | null
  stages: TransferStage[]
  pairing: string[] | null
  actions: TransferAction[]
}

export interface CustodyGroup { origin?: UnitRef; destination?: UnitRef; purpose: { code: string; label: string }; transfers: number; amount: string }
export interface TransitItem { transfer: string; counterpart: UnitRef; amount: string; sent_at: string; age_days: number }

export interface CustodyPosition {
  unit: UnitRef
  period: { from: string; to: string }
  opening_balance: string
  external_funds_received: string
  internal_funds_received: string
  external_applications: string
  internal_funds_sent: string
  closing_balance: string
  balanced: boolean
  received_by_origin: CustodyGroup[]
  sent_by_destination: CustodyGroup[]
  returned_by_destination: CustodyGroup[]
  in_transit_outgoing: TransitItem[]
  in_transit_incoming: TransitItem[]
  in_transit_outgoing_total: string
  economic_result: { income: string; expense: string; result: string; note: string }
}

// ---- P0.10-F1C: financial accounts, accrual subledgers, bank reconciliation, budget, closes ---------------------------
// States are the server's (CHECK vocabularies); match states, outstanding amounts and balances are DERIVED by the server.

export type AccountKind = 'CASH' | 'BANK'
export interface AccountSummary {
  public_id: string
  unit: UnitRef
  code: string
  name: string
  kind: AccountKind
  status: 'OPEN' | 'CLOSED'
  opened_on: string
  closed_on: string | null
  currency: string
  balance: string
}
export interface AccountDetail extends AccountSummary {
  lock_version: number
  balance_source: string
  bank?: { bank_name: string; account_number: string | null } | null
  cash_register?: { status: string; custodian: string | null } | null
  opening_entry: { entry: string; amount: string } | null
  actions: 'close'[]
}
export interface AccountMovement { entry: string; entry_line: number; kind: string; entry_date: string; description: string; debit: string; credit: string; posted_at: string }

export type SubledgerStatus = 'PENDING' | 'RECOGNIZED' | 'SETTLED' | 'CANCELLED'
export interface Party { kind: string; name: string | null; person: string | null }
export interface CategoryRef { code: string; label: string; nature: string }
export interface SubledgerSummary {
  public_id: string
  kind: 'RECEIVABLE' | 'PAYABLE'
  unit: UnitRef
  status: SubledgerStatus
  amount: string
  settled: string
  outstanding: string
  currency: string
  due_on: string
  recognized_on: string
  description: string
  category: CategoryRef | null
  economic_account: string | null
  capitalized: boolean
  party: Party
  recognition_entry: string
  created_at: string
}
export interface SettlementRow {
  public_id: string
  amount: string
  status: 'POSTED' | 'CANCELLED'
  direction: 'RECEIPT' | 'PAYMENT'
  settled_at: string
  account: { public_id: string; name: string }
  entry: string
  cancellation_entry: string | null
}
export interface SubledgerDetail extends SubledgerSummary {
  lock_version: number
  document: { public_id: string | null } | null
  settlements: SettlementRow[]
  cancellation_entry: string | null
  actions: ('settle' | 'cancel')[]
}
export interface SettlementDetail extends SettlementRow {
  cancel_reason: string | null
  receivable: string | null
  payable: string | null
  lock_version: number
  actions: 'cancel'[]
}

export type MatchState = 'UNMATCHED' | 'PARTIALLY_MATCHED' | 'MATCHED'
export interface StatementLine {
  line_number: number
  occurred_on: string
  amount: string
  direction: 'IN' | 'OUT'
  description: string
  reference: string | null
  matched_total: string
  state: MatchState
  matched_here?: string
}
export interface StatementSummary {
  public_id: string
  account: { public_id: string; name: string }
  starts_on: string
  ends_on: string
  opening_balance: string
  closing_balance: string
  lines_count: number
  created_at: string
  origin: string
}
export interface StatementDetail extends StatementSummary { lines: StatementLine[]; document: { public_id: string | null } | null; source_hash: string }

export interface ReconciliationSummary {
  public_id: string
  account: { public_id: string; name: string }
  period: string
  version: number
  status: 'OPEN' | 'CLOSED'
  statement: string | null
  closed_at: string | null
  created_at: string
}
export interface LedgerBankLine {
  entry: string
  entry_line: number
  entry_date: string
  kind: string
  description: string
  direction: 'IN' | 'OUT'
  amount: string
  matched_total: string
  matched_here: string
  state: MatchState
}
export interface ReconciliationSummaryFigures {
  statement_lines: number
  matched: number
  partially_matched: number
  unmatched: number
  statement_closing_balance: string
  ledger_balance_at_period_end: string
  difference: string
}
export interface ReconciliationDetail extends ReconciliationSummary {
  lock_version: number
  statement_lines: StatementLine[]
  ledger_lines: LedgerBankLine[]
  matches: { statement_line: number; entry: string; entry_line: number; amount: string }[]
  summary: ReconciliationSummaryFigures
  actions: ('match' | 'unmatch' | 'close' | 'adjust')[]
}

export type BudgetStatus = 'DRAFT' | 'SUBMITTED' | 'REVIEWED' | 'APPROVED' | 'SUPERSEDED' | 'CLOSED' | 'CANCELLED'
export type BudgetAction = 'edit_lines' | 'submit' | 'cancel' | 'review' | 'return' | 'approve' | 'revise'
export interface BudgetSummary {
  public_id: string
  unit: UnitRef
  year: string
  fund: string
  version: number
  status: BudgetStatus
  in_execution: boolean
  requested_total: string
  approved_total: string
  approved_at: string | null
  created_at: string
}
export interface BudgetLine { category: CategoryRef; requested_amount: string; approved_amount: string }
export interface BudgetDetail extends BudgetSummary {
  lines: BudgetLine[]
  versions: { public_id: string; version: number; status: BudgetStatus }[]
  lock_version: number
  submitted_by_me: boolean
  actions: BudgetAction[]
}
export interface ActualRow { category: CategoryRef; side: 'REVENUE' | 'COST'; budgeted: boolean; budget_amount: string; actual_amount: string; variance: string; variance_percent: string | null }
export interface ActualTotals { budget_amount: string; actual_amount: string; variance: string; variance_percent: string | null }
export interface ActualVsBudget {
  budget: BudgetSummary
  interval: { from: string; to: string }
  fund: string
  source: string
  basis: string
  rows: ActualRow[]
  totals: { REVENUE: ActualTotals; COST: ActualTotals }
}

export type PeriodUnitStatus = 'OPEN' | 'CLOSED' | 'REOPENED' | 'NATIONALLY_CLOSED'
export interface PeriodMonth {
  code: string
  starts_on: string
  ends_on: string
  national_status: 'OPEN' | 'CLOSED'
  national_closed_at: string | null
  unit_status: PeriodUnitStatus
  closed_at: string | null
  closed_by_me: boolean
  reopened_at: string | null
  reopen_reason: string | null
  pending_entries: number
  actions: ('close' | 'reopen' | 'national_close')[]
}
export interface PeriodList { unit: UnitRef; year: string; national_closer: boolean; items: PeriodMonth[] }

// ---- P0.10-F1D reporting/consolidation -------------------------------------------------------------------------------
export type ReportView = 'OWN' | 'CONSOLIDATED'
export type ReportPeriodKind = 'MONTH' | 'QUARTER' | 'SEMESTER' | 'YEAR'
export interface ContributionSummary { public_id: string; origin: 'EXTERNAL'; unit: UnitRef; kind: 'MONETARY' | 'IN_KIND'; identification: string; category: { code: string; label: string }; amount: string | null; valuation_status: string | null; valuation_amount: string | null; description: string | null; received_at: string; status: string; entry: string | null; party: { kind: string; person: string | null; name: string | null } | null }
export interface ContributionDetail extends ContributionSummary { lock_version: number; document: { public_id: string | null } | null }
export interface DreReport { basis: string; revenue: string; expenses: string; economic_result: string; investments_consumed: string; investments_capitalized: string; investment_returns: string; internal_transfer_effect: string; revenue_lines: { category: CategoryRef; amount: string }[]; expense_lines: { category: CategoryRef; amount: string }[] }
export interface DoafReport { basis: string; opening_balance: string; external_funds_received: string; internal_funds_received: string; external_applications: string; internal_funds_sent: string; funds_in_transit_under_custody: string; treasury_closing: string; closing_balance: string; total_origins: string; total_applications: string; balanced: boolean; identity: string; internal_transfers_eliminated: boolean }
export interface FinanceReport { institution: string; report_type: string; report_name: string; view: ReportView; unit: UnitRef; period: { kind: string; label: string; from: string; to: string }; generated_at: string; perimeter_units: number; parameters_hash: string; dre?: DreReport; doaf?: DoafReport; dashboard?: { revenue: string; expenses: string; economic_result: string; internal_received: string; internal_sent: string; in_transit: string; cash_bank_position: string; receivables: string; payables: string; budget_execution: { approved: string; actual: string } }; drill_down?: { unit: UnitRef; own_result: string; funds_received: string; funds_sent: string; closing_balance: string }[]; summary?: { category: CategoryRef; amount: string }[]; transfers?: ReportTransfer[]; reconciliation?: ReportTransfer[]; position?: { sent: string; received: string; in_transit: string; net_control_position: string; account_class: string; transfers: string[] }; movements?: { entry: string; date: string; kind: string; description: string; account: UnitRef; unit: UnitRef; debit: string; credit: string }[]; treasury?: { account: { public_id: string; code: string; name: string; kind: string }; unit: UnitRef; opening: string; inflows: string; outflows: string; closing: string }[]; budget_vs_actual?: { year: string; basis: string; approved_budget: string; actual: string; variance: string; variance_percent: string | null; totals: { approved: string; actual: string } }; receivables?: ReportOpenItem[]; payables?: ReportOpenItem[] }
export interface ReportTransfer { transfer: string; origin: UnitRef; destination: UnitRef; amount: string; purpose?: { code: string; label: string }; status: string; sent_at: string | null; received_at: string | null; perimeter_class?: string | null; reconciled?: boolean }
export interface ReportOpenItem { public_id: string; unit: UnitRef; due_on: string; status: string; amount: string; outstanding: string }
