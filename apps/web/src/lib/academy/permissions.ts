import type { AuthSession } from '../auth/session'

export const ACADEMY_PERMISSIONS = [
  'ACADEMY_VIEW',
  'ACADEMY_MANAGE',
  'ACADEMY_ENROLL',
  'ACADEMY_TEACH',
  'ACADEMY_ATTENDANCE',
  'ACADEMY_ASSESS',
  'ACADEMY_GRADES_VIEW',
  'ACADEMY_CERTIFY',
] as const

export type AcademyPermission = (typeof ACADEMY_PERMISSIONS)[number]

/** Named questions the screens ask. Screens never compare roles or permission strings themselves. */
export type Capability =
  | 'canView'
  | 'canManage'
  | 'canEnroll'
  | 'canTeach'
  | 'canRecordAttendance'
  | 'canAssess'
  | 'canViewGrades'
  | 'canCertify'

// Permission per capability, taken from the "Permission" column of the A3 route inventory.
// ACADEMY_MANAGE (not ACADEMY_TEACH) governs instructor assignment (A2-DEV-01).
export const CAPABILITY_PERMISSION: Record<Capability, AcademyPermission> = {
  canView: 'ACADEMY_VIEW',
  canManage: 'ACADEMY_MANAGE',
  canEnroll: 'ACADEMY_ENROLL',
  canTeach: 'ACADEMY_TEACH',
  canRecordAttendance: 'ACADEMY_ATTENDANCE',
  canAssess: 'ACADEMY_ASSESS',
  canViewGrades: 'ACADEMY_GRADES_VIEW',
  canCertify: 'ACADEMY_CERTIFY',
}

export interface Capabilities {
  /** False while the API cannot tell the UI what the actor holds (A4_API_CONTRACT_GAP-02). */
  known: boolean
  can: (capability: Capability) => boolean
}

/**
 * Presentation only. When the permission set is known, actions the actor lacks are hidden. When it
 * is not, actions stay visible and the backend — which always decides — refuses them. It never
 * grants anything: a hidden button is not protection, and a visible one is not authority.
 */
export function capabilitiesFor(session: Pick<AuthSession, 'permissions'> | null): Capabilities {
  const permissions = session?.permissions
  if (!permissions) return { known: false, can: () => session !== null }
  const held = new Set(permissions)
  return { known: true, can: (capability) => held.has(CAPABILITY_PERMISSION[capability]) }
}
