const seg = (value: string) => encodeURIComponent(value)
const membership = (id: string) => `memberships/${seg(id)}`
const transfer = (id: string) => `memberships/transfers/${seg(id)}`

/** Membership API paths. Targets are public ids; legacy identifiers and milestones use a natural key under their membership. */
export const mb = {
  context: () => 'memberships/context',
  list: () => 'memberships',
  submit: () => 'memberships/admissions',
  collective: () => 'memberships/admissions/collective-approval',
  membership,
  action: (id: string, action: 'validate' | 'withdraw' | 'reject' | 'approve' | 'inactivate' | 'reactivate' | 'end' | 'readmit') => `${membership(id)}/${action}`,
  periods: (id: string) => `${membership(id)}/periods`,
  transfersOf: (id: string) => `${membership(id)}/transfers`,
  legacy: (id: string) => `${membership(id)}/legacy-identifiers`,
  legacyRevoke: (id: string) => `${membership(id)}/legacy-identifiers/revoke`,
  milestones: (id: string) => `${membership(id)}/milestones`,
  milestoneCorrect: (id: string, type: string) => `${membership(id)}/milestones/${seg(type)}/correct`,
  transfers: () => 'memberships/transfers',
  transfer,
  transferStage: (id: string, stage: 'validate-origin' | 'accept' | 'reject' | 'complete' | 'cancel') => `${transfer(id)}/${stage}`,
}
