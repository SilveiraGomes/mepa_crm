/** P0.9 Membership (ADR-0020). Only public ids; the official number is a value, never a key. */
export type MembershipStatus = 'SUBMITTED' | 'VALIDATED' | 'REJECTED' | 'WITHDRAWN' | 'ACTIVE' | 'INACTIVE' | 'ENDED'
export type Precision = 'EXACT' | 'MONTH' | 'YEAR' | 'UNKNOWN'
export type TransferStatus = 'REQUESTED' | 'ORIGIN_VALIDATED' | 'DESTINATION_ACCEPTED' | 'COMPLETED' | 'REJECTED' | 'CANCELLED'
export type TransferAction = 'validate_origin' | 'accept' | 'reject' | 'complete' | 'cancel'

export interface CodeLabel { code: string; label: string }
export interface UnitRef { public_id: string; name: string }
export interface DocumentRef { has_document: boolean; source_document: { public_id: string } | null }

export interface MembershipPerson {
  public_id: string
  display_name: string
  status: string
  deceased: boolean
  protected_minor?: boolean
  age_band?: string | null
}

export interface MembershipSummary {
  public_id: string
  status: MembershipStatus
  status_label: string
  is_member: boolean
  member_number: string | null
  origin: 'ADMISSION' | 'LEGACY_IMPORT'
  admitted_on: string | null
  admitted_on_precision: Precision
  approved_at: string | null
  congregation: UnitRef | null
  person: MembershipPerson
  deceased: boolean
  lock_version: number
}

export interface MembershipDetail extends MembershipSummary, DocumentRef {
  open_transfer: MembershipTransfer | null
  period_started_at: string
  legacy_identifiers: number
  legacy_conflicts: number
  can: string[]
}

export interface MembershipPeriod extends DocumentRef {
  status: MembershipStatus
  status_label: string
  congregation: UnitRef
  starts_at: string
  ends_at: string | null
  open: boolean
  reason: string | null
}

export interface MembershipTransfer extends DocumentRef {
  public_id: string
  membership: { public_id: string }
  person?: { public_id: string; display_name: string }
  status: TransferStatus
  status_label: string
  open: boolean
  origin: UnitRef | null
  destination: UnitRef | null
  requested_at: string
  effective_at: string | null
  closed_at: string | null
  lock_version: number
  side?: 'ORIGIN' | 'DESTINATION' | 'BOTH'
  actions?: TransferAction[]
}

export interface LegacyIdentifier {
  source_system: string
  raw_number: string
  normalized_number: string
  status: 'ACTIVE' | 'CONFLICT' | 'REVOKED'
  status_label: string
  conflict: { detail_visible: boolean; memberships: string[] } | null
  registered_at: string
  lock_version: number
}

export interface Milestone extends DocumentRef {
  type: 'CONVERSION' | 'BAPTISM'
  type_label: string
  occurred_on: string | null
  date_precision: Precision
  unit: UnitRef | null
  lock_version: number
}

export interface MembershipCongregation { public_id: string; name: string; permissions: string[] }

export interface MembershipContext {
  permissions: string[]
  congregations: MembershipCongregation[]
  statuses: CodeLabel[]
  transfer_statuses: CodeLabel[]
  legacy_sources: string[]
  legacy_statuses: CodeLabel[]
  milestone_types: CodeLabel[]
  date_precisions: Precision[]
  collective_max: number
  counts: { active_members: number }
  pagination: { default: number; max: number }
}

export interface CollectiveResult { approved: { public_id: string; member_number: string }[]; count: number }
