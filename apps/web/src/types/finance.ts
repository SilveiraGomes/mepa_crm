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

export interface FinanceContext {
  permissions: string[]
  units: FinanceUnit[]
  accounts: FinanceAccount[]
  purposes: Purpose[]
  contribution_categories: { code: string; label: string }[]
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
