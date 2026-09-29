/** P0.7 Physical Locations / Properties / Temples / Unit links (ADR-0018). Only public ids and opaque refs. */
export type PhysicalStatus = 'DRAFT' | 'ACTIVE' | 'CLOSED'
export type Visibility = 'PRIVATE' | 'APPROVED_PUBLIC'

export interface NamedRef { public_id: string; name: string }

export interface PublicProjection { public_id: string; name: string; latitude: number; longitude: number }

export interface PhysicalLocation {
  public_id: string
  name: string
  status: PhysicalStatus
  status_label: string
  public_visibility: Visibility
  latitude: number | null
  longitude: number | null
  country_code: string | null
  created_at: string
  lock_version: number
  unit: (NamedRef & { is_primary: boolean }) | null
  active_links?: number
  public_projection?: PublicProjection | null
  can?: string[]
}

export interface LocationAddress { country_code: string; line1: string; locality: string | null }

export interface PhysicalLink {
  ref: string
  unit: NamedRef | null
  location: NamedRef | null
  property: { public_id: string; code: string } | null
  occupation_type: { code: string; label: string } | null
  is_primary: boolean
  status: 'ACTIVE' | 'ENDED'
  starts_at: string
  ends_at: string | null
  reason: string | null
  lock_version: number
}

export interface PropertyOwner { kind: 'PERSON' | 'EXTERNAL' | 'NONE'; person: { public_id: string; display_name: string } | null }

export interface PhysicalProperty {
  public_id: string
  code: string
  location: NamedRef
  ownership_status: string
  ownership_status_label: string
  status: PhysicalStatus
  status_label: string
  owner: PropertyOwner
  lock_version: number
  can?: string[]
}

export interface Temple {
  public_id: string
  name: string
  capacity: number | null
  location: NamedRef
  status: PhysicalStatus
  status_label: string
  created_at: string
  lock_version: number
  can?: string[]
}

export interface PhysicalUnit extends NamedRef { status: string; permissions: string[] }
export interface CodeLabel { code: string; label: string }

export interface PhysicalContext {
  permissions: string[]
  units: PhysicalUnit[]
  occupation_types: (CodeLabel & { requires_reason: boolean })[]
  ownership_statuses: CodeLabel[]
  statuses: CodeLabel[]
}
