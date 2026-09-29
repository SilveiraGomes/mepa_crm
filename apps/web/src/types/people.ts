// DTOs of the P0.5-I People / Families API (docs/contracts/people_families_contracts.json).
// Only public identifiers exist here: Person and Household use public_id; nested rows (contacts,
// addresses, household members, relationships) use an opaque `ref` valid only under their parent.

export type PublicId = string
export type BirthPrecision = 'EXACT' | 'MONTH' | 'YEAR' | 'UNKNOWN'
export type AgeBand = 'MINOR' | 'ADULT' | 'UNCERTAIN' | null
export type PersonStatus = 'ACTIVE' | 'INACTIVE' | 'DECEASED'

export interface Birth {
  precision: BirthPrecision
  date?: string
  year?: number
  month?: number
}

export interface PersonSummary {
  public_id: PublicId
  display_name: string
  birth_precision: BirthPrecision
  status: PersonStatus
  age_band: AgeBand
  protected_minor: boolean
  lock_version: number
}

export interface PersonCapabilities {
  can_edit: boolean
  can_view_contacts: boolean
  can_manage_contacts: boolean
  can_view_addresses: boolean
  can_manage_addresses: boolean
  can_view_households: boolean
  can_manage_households: boolean
  can_view_relationships: boolean
  can_manage_relationships: boolean
}

export interface PersonDetail extends PersonSummary {
  birth?: Birth
  created_at?: string
  projection: 'COMMON' | 'MINIMAL'
  restricted: string[]
  capabilities: PersonCapabilities
}

export interface WorkingUnit { public_id: PublicId; code: string; name: string }
export interface PeopleContext { permissions: string[]; working_units: WorkingUnit[] }

export interface CodeName { code: string; name: string }
export interface TerritorialArea { code: string; name: string; parent_code: string | null }
export interface PeopleCatalogs {
  person_statuses: CodeName[]
  birth_precisions: BirthPrecision[]
  contact_types: CodeName[]
  household_roles: CodeName[]
  household_statuses: string[]
  relationship_types: (CodeName & { semantics: 'SYMMETRIC' | 'INVERSE_PAIRED' })[]
  territorial_areas: TerritorialArea[]
}

export interface SelectorPerson { public_id: PublicId; display_name: string; age_band: AgeBand; status: PersonStatus }

export interface Contact {
  ref: string
  type: string
  type_name: string
  value: string
  is_primary: boolean
  verified_at: string | null
  status: 'ACTIVE' | 'INACTIVE'
  created_at: string
  lock_version: number
}

export interface Address {
  ref: string
  country_code: string
  province: CodeName | null
  municipality: CodeName | null
  line1: string
  locality: string | null
  status: 'ACTIVE' | 'INACTIVE'
  starts_at: string
  ends_at: string | null
  lock_version: number
}

export interface HouseholdSummary {
  public_id: PublicId
  code: string
  name: string | null
  status: 'ACTIVE' | 'INACTIVE' | 'ARCHIVED'
  lock_version: number
  role?: string
  role_name?: string
}

export interface HouseholdMember {
  ref: string
  person: { public_id: PublicId; display_name: string; age_band: AgeBand; protected_minor: boolean }
  role: string
  role_name: string
  starts_at: string
}

export interface HouseholdDetail extends HouseholdSummary {
  members: HouseholdMember[]
  capabilities: { can_manage: boolean }
}

export interface Relationship {
  ref: string
  type: string
  type_name: string
  semantics: 'SYMMETRIC' | 'INVERSE_PAIRED'
  person: { public_id: PublicId; display_name: string; age_band: AgeBand }
  status: 'ACTIVE' | 'INACTIVE'
  starts_at: string
  ends_at: string | null
}

export interface ListMeta { hidden?: string | null }
export interface ItemList<T> { data: T[]; meta?: ListMeta }

export interface ExportResult { filename: string; content_type: string; row_count: number; csv: string }
