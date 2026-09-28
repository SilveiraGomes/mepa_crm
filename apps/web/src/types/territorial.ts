export interface TerritorialUnit {
  public_id: string
  parent_public_id: string | null
  code: string
  name: string
  type: string
  type_label: string
  status: 'DRAFT' | 'ACTIVE' | 'CLOSED'
  opened_on: string | null
  closed_on: string | null
  lock_version: number
  path?: TerritorialUnit[]
}
export interface TerritorialContext {
  permissions: string[]
  working_units: TerritorialUnit[]
  types: { code: string; label: string }[]
  statuses: string[]
}
