// DTOs mirror the A3 read projections (AcademyReadResource / AcademyCollectionResource) after
// server-side sanitisation. Internal Person ids are never part of them; `id` fields exist only
// where the approved schema has no public_id and the id is a route parameter.

export type PublicId = string

export interface PageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface PageLinks {
  prev: string | null
  next: string | null
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
  links: PageLinks
}

/** Bounded, unpaginated collection (active instructors: at most 200). */
export interface BoundedList<T> {
  data: T[]
  meta: { count: number }
}

export interface Item<T> {
  data: T
}

export interface AcademicUnit {
  id: number
  code: string
  name: string
  status: string
  organizational_unit_public_id?: PublicId
}

export interface Program {
  public_id: PublicId
  code: string
  name: string
  status: string
  academic_unit_code: string
  academic_unit_name?: string
  lock_version?: number
}

export interface Curriculum {
  id: number
  version: number
  status: string
  published_at?: string
  lock_version: number
  program_public_id: PublicId
  program_code: string
  program_name?: string
  course_count?: number
}

export interface CurriculumCourse {
  public_id: PublicId
  code: string
  name: string
  status: string
  sequence: number
  required: boolean | number
}

export interface Course {
  public_id: PublicId
  code: string
  name: string
  status: string
  lock_version?: number
}

export interface CourseVersion {
  id: number
  version: number
  status: string
  lock_version: number
  course_public_id?: PublicId
  course_code?: string
  course_name?: string
  module_count?: number
  lesson_count?: number
  resource_count?: number
}

export interface CourseModule {
  id: number
  sequence: number
  name: string
}

export interface Lesson {
  id: number
  sequence: number
  name: string
  required: boolean | number
}

export interface LessonResource {
  public_id: PublicId
  sequence: number
  required: boolean | number
  kind: string
  provider?: string
  external_url?: string
  duration_seconds?: number
  status: string
}

export interface Cohort {
  public_id: PublicId
  code: string
  name: string
  starts_at?: string
  ends_at?: string
  status: string
  academic_unit_code: string
  lock_version?: number
}

export interface ClassSummary {
  public_id: PublicId
  code: string
  capacity?: number
  status: string
  lock_version: number
  academic_unit_code: string
  course_public_id: PublicId
  course_code: string
  course_name: string
  course_version: number
}

export interface ClassDetail extends ClassSummary {
  course_version_id: number
  academic_unit_name: string
  cohort_public_id?: PublicId
  cohort_name?: string
  location_public_id?: PublicId
  location_name?: string
}

/** Roster projection: opaque Person id + display name + enrolment facts only. */
export interface RosterRow {
  enrollment_public_id: PublicId
  enrollment_status: string
  enrolled_at?: string
  public_id: PublicId
  display_name: string
}

export interface PersonSearchResult {
  public_id: PublicId
  display_name: string
}

export interface EnrollmentDetail {
  public_id: PublicId
  status: string
  enrolled_at?: string
  lock_version: number
  person_public_id: PublicId
  display_name: string
}

export interface ClassInstructorAssignment {
  assignment_id: number
  person_public_id: PublicId
  display_name: string
  status: string
  starts_at: string
  ends_at?: string | null
  lock_version: number
}

export interface EligibleFile {
  public_id: PublicId
  file_name: string
  media_type: string
}

export interface AcademyContextPayload {
  permissions: string[]
  vocabulary: {
    transitions: Record<'enrollment' | 'session' | 'attempt', { from: string; to: string }[]>
    attendance_statuses: string[]
  }
}

export interface ClassSession {
  id: number
  starts_at: string
  ends_at: string
  status: string
  lock_version: number
  lesson_name?: string
}

export type LocationSource = 'EVENT_SESSION' | 'CLASS_DEFAULT' | 'NONE'

export interface EffectiveLocation {
  source: LocationSource
}

export interface AttendanceRecord {
  status: string
  recorded_at: string
  lock_version: number
}

export interface Assessment {
  public_id: PublicId
  name: string
  max_score: string | number
  pass_score?: string | number
  max_attempts: number
  weight: string | number
  status: string
  lock_version: number
  lesson_name?: string
}

export interface AssessmentAttempt {
  id: number
  attempt_number: number
  started_at?: string
  submitted_at?: string
  status: string
  lock_version: number
  enrollment_public_id: PublicId
  person_public_id: PublicId
  display_name: string
  assessment_public_id?: PublicId
  assessment_name?: string
}

export interface GradeVersion {
  version: number
  score: string | number
  status: string
  graded_at: string
  reason?: string | null
  lock_version: number
}

export interface ProgressPayload {
  lessons: { id?: number; name: string; completion_ratio?: string | number; completed_at?: string; status: string }[]
  resources: { public_id: PublicId; resource_kind: string; watched_seconds?: number; verified_at?: string; status: string }[]
}

export interface Certificate {
  public_id: PublicId
  version: number
  issued_at: string
  revoked_at?: string
  status: string
  lock_version: number
  enrollment_public_id: PublicId
  person_public_id: PublicId
  display_name: string
  class_public_id?: PublicId
  class_code?: string
}

export interface TranscriptSummary {
  public_id: PublicId
  version: number
  issued_at: string
  status: string
  lock_version: number
}

export interface TranscriptLine {
  enrollment_public_id: PublicId
  class_public_id: PublicId
  class_code: string
  final_score?: string | number
  result_status?: string
  grades?: { score?: string | number; version?: number }[]
}

export interface Transcript {
  public_id?: PublicId
  version?: number
  issued_at?: string
  status?: string
  lock_version?: number
  person: { public_id: PublicId; display_name: string }
  lines: TranscriptLine[]
}

/** Command outputs are sanitised server-side; only these optional facts are relied on. */
export interface ActionResult {
  outcome?: string
  version?: number
  lock_version?: number
  from?: string
  to?: string
  public_id?: PublicId
  class_session_id?: number
  attempt_id?: number
  curriculum_id?: number
  assignment_id?: number
}

export interface EnrollmentCreated {
  public_id?: PublicId
  outcome?: string
}

export type SortDirection = 'asc' | 'desc'

export interface ListQuery {
  page?: number
  per_page?: number
  search?: string
  sort?: string
  direction?: SortDirection
  [filter: string]: string | number | undefined
}
