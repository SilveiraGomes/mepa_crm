// Path builders for the 62 Academy routes (docs/api/wave5_academy_http_contracts.json).
// Every segment is percent-encoded, so a tampered route parameter can never change the path.
// docs/ui/wave5_academy_ui_contracts.json references these keys; the UI validator checks both ways.

const seg = (value: string | number): string => encodeURIComponent(String(value))

export const ep = {
  academicUnits: () => 'academic-units',
  academicUnit: (id: string | number) => `academic-units/${seg(id)}`,
  programs: () => 'programs',
  program: (publicId: string) => `programs/${seg(publicId)}`,
  programCurriculaCreate: (publicId: string) => `programs/${seg(publicId)}/curricula`,
  curricula: () => 'curricula',
  curriculum: (id: string | number) => `curricula/${seg(id)}`,
  curriculumCourses: (id: string | number) => `curricula/${seg(id)}/courses`,
  curriculumPublish: (id: string | number) => `curricula/${seg(id)}/publish`,
  courses: () => 'courses',
  course: (publicId: string) => `courses/${seg(publicId)}`,
  courseVersions: (publicId: string) => `courses/${seg(publicId)}/versions`,
  courseVersion: (id: string | number) => `course-versions/${seg(id)}`,
  courseVersionModules: (id: string | number) => `course-versions/${seg(id)}/modules`,
  moduleLessons: (versionId: string | number, moduleId: string | number) => `course-versions/${seg(versionId)}/modules/${seg(moduleId)}/lessons`,
  lessonResources: (versionId: string | number, moduleId: string | number, lessonId: string | number) =>
    `course-versions/${seg(versionId)}/modules/${seg(moduleId)}/lessons/${seg(lessonId)}/resources`,
  assessmentCreate: (versionId: string | number) => `course-versions/${seg(versionId)}/assessments`,
  cohorts: () => 'cohorts',
  cohort: (publicId: string) => `cohorts/${seg(publicId)}`,
  classes: () => 'classes',
  classDetail: (publicId: string) => `classes/${seg(publicId)}`,
  classRoster: (publicId: string) => `classes/${seg(publicId)}/roster`,
  classPeopleSearch: (publicId: string) => `classes/${seg(publicId)}/people:search`,
  classEnrollments: (publicId: string) => `classes/${seg(publicId)}/enrollments`,
  classEnrollment: (classId: string, enrollmentId: string) => `classes/${seg(classId)}/enrollments/${seg(enrollmentId)}`,
  enrollmentTransition: (enrollmentId: string) => `enrollments/${seg(enrollmentId)}/transitions`,
  enrollmentComplete: (enrollmentId: string) => `enrollments/${seg(enrollmentId)}/complete`,
  enrollmentProgress: (enrollmentId: string) => `enrollments/${seg(enrollmentId)}/progress`,
  lessonProgress: (enrollmentId: string, lessonId: string | number) => `enrollments/${seg(enrollmentId)}/lessons/${seg(lessonId)}/progress`,
  resourceProgress: (enrollmentId: string, resourceId: string) => `enrollments/${seg(enrollmentId)}/resources/${seg(resourceId)}/progress`,
  classInstructors: (publicId: string) => `classes/${seg(publicId)}/instructors`,
  instructorEnd: (assignmentId: string | number) => `instructor-assignments/${seg(assignmentId)}/end`,
  classSessions: (publicId: string) => `classes/${seg(publicId)}/sessions`,
  classSession: (classId: string, sessionId: string | number) => `classes/${seg(classId)}/sessions/${seg(sessionId)}`,
  sessionTransition: (sessionId: string | number) => `sessions/${seg(sessionId)}/transitions`,
  sessionLocation: (sessionId: string | number) => `sessions/${seg(sessionId)}/effective-location`,
  sessionAttendance: (sessionId: string | number) => `sessions/${seg(sessionId)}/attendance`,
  sessionAttendanceRecord: (sessionId: string | number, enrollmentId: string) => `sessions/${seg(sessionId)}/attendance/${seg(enrollmentId)}`,
  sessionAttendanceBulk: (sessionId: string | number) => `sessions/${seg(sessionId)}/attendance:bulk`,
  classAssessments: (publicId: string) => `classes/${seg(publicId)}/assessments`,
  classAssessment: (classId: string, assessmentId: string) => `classes/${seg(classId)}/assessments/${seg(assessmentId)}`,
  assessmentUpdate: (assessmentId: string) => `assessments/${seg(assessmentId)}`,
  assessmentAttempts: (classId: string, assessmentId: string) => `classes/${seg(classId)}/assessments/${seg(assessmentId)}/attempts`,
  attemptStart: (assessmentId: string) => `assessments/${seg(assessmentId)}/attempts`,
  classAttempt: (classId: string, attemptId: string | number) => `classes/${seg(classId)}/attempts/${seg(attemptId)}`,
  attemptTransition: (attemptId: string | number) => `attempts/${seg(attemptId)}/transitions`,
  attemptGrades: (attemptId: string | number) => `attempts/${seg(attemptId)}/grades`,
  attemptGradeRevise: (attemptId: string | number) => `attempts/${seg(attemptId)}/grades/revisions`,
  attemptGradeFinalize: (attemptId: string | number) => `attempts/${seg(attemptId)}/grades/finalize`,
  classCertificates: (publicId: string) => `classes/${seg(publicId)}/certificates`,
  classCertificate: (classId: string, certificateId: string) => `classes/${seg(classId)}/certificates/${seg(certificateId)}`,
  certificateIssue: (enrollmentId: string) => `enrollments/${seg(enrollmentId)}/certificates`,
  certificateRevoke: (certificateId: string) => `certificates/${seg(certificateId)}/revoke`,
  transcripts: (curriculumId: string | number, personId: string) => `curricula/${seg(curriculumId)}/people/${seg(personId)}/transcripts`,
  transcript: (curriculumId: string | number, personId: string, transcriptId: string) =>
    `curricula/${seg(curriculumId)}/people/${seg(personId)}/transcripts/${seg(transcriptId)}`,
  transcriptPreview: (curriculumId: string | number, personId: string) => `curricula/${seg(curriculumId)}/people/${seg(personId)}/transcript-preview`,
  transcriptIssue: (curriculumId: string | number, personId: string) => `curricula/${seg(curriculumId)}/people/${seg(personId)}/transcripts`,
} as const

export const PUBLIC_ID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/

export const MAX_PAGE_SIZE = 100
export const DEFAULT_PAGE_SIZE = 50
export const MIN_SEARCH_LENGTH = 3
export const MAX_BULK_ATTENDANCE = 200
