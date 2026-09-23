// Path builders for /api/v1/people (docs/contracts/people_families_contracts.json). Every segment is
// percent-encoded, so a tampered parameter can never change the path. Only public_id / opaque refs.

const seg = (value: string): string => encodeURIComponent(value)
const person = (id: string) => `people/${seg(id)}`
const household = (id: string) => `people/households/${seg(id)}`

export const pe = {
  context: () => 'people/context',
  catalogs: () => 'people/catalogs',
  selector: () => 'people/selector',
  exports: () => 'people/exports',
  people: () => 'people',
  person,
  personAction: (id: string, action: 'inactivate' | 'reactivate' | 'mark-deceased') => `${person(id)}/${action}`,
  contacts: (id: string) => `${person(id)}/contacts`,
  contact: (id: string, ref: string) => `${person(id)}/contacts/${seg(ref)}`,
  contactEnd: (id: string, ref: string) => `${person(id)}/contacts/${seg(ref)}/end`,
  addresses: (id: string) => `${person(id)}/addresses`,
  address: (id: string, ref: string) => `${person(id)}/addresses/${seg(ref)}`,
  addressEnd: (id: string, ref: string) => `${person(id)}/addresses/${seg(ref)}/end`,
  personHouseholds: (id: string) => `${person(id)}/households`,
  relationships: (id: string) => `${person(id)}/relationships`,
  relationshipEnd: (id: string, ref: string) => `${person(id)}/relationships/${seg(ref)}/end`,
  households: () => 'people/households',
  household,
  householdAction: (id: string, action: 'inactivate' | 'reactivate' | 'archive' | 'restore') => `${household(id)}/${action}`,
  householdMembers: (id: string) => `${household(id)}/members`,
  householdMemberEnd: (id: string, ref: string) => `${household(id)}/members/${seg(ref)}/end`,
}

export const PEOPLE_PAGE_SIZE = 50
export const PEOPLE_MIN_SEARCH = 2
