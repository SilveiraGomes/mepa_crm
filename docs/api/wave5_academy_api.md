# Wave 5 Academy HTTP API

Status: P0.3.5-A3.1 read-model completion inventory. Base URI: `/api/v1/academy`. Authentication uses the existing opaque `auth_sessions` bearer token. Contextual authorization remains in the Academy application layer.

## A3.1 read surface

A3.1 adds 28 GET contracts (58 Academy routes total): list/detail for academic units, programs, curricula, courses and versions, cohorts and classes; explicit class roster and scoped Person search; enrollment/session/assessment/attempt/certificate/transcript reads; and enrollment progress. The executable inventory, including permission, scope provenance, concealment, filter and sort allowlists, is `wave5_academy_http_contracts.json`.

Collections are restricted in SQL to authorized academic-unit IDs or to an already-authorized parent. Details return concealed 404 for absent, unauthorized, out-of-scope, or nested-parent mismatch targets. Default pagination is 50 and maximum 100.

Person search is deliberately not a national directory: it returns only People with an existing academic relationship in the target class's academic unit. External/non-member enrollment remains supported by the existing opaque Person `public_id` command contract; creating/searching a new national Person is outside Academy CRUD.

Modules, lessons and learning resources are not independent public CRUD roots. They are projected as a bounded content tree in course-version detail. Their write lifecycle remains intentionally outside this phase.

## Route inventory

| Method | URI | Controller action | A2 service operation | Permission | Concealment | Request | Resource | Pagination | Audit |
|---|---|---|---|---|---|---|---|---|---|
| POST | `/programs/{program}/curricula` | `CurriculumController@store` | `CurriculumService::createVersion` | ACADEMY_MANAGE | scoped 404 | `ContextRequest` | `AcademyActionResource` | no | service |
| POST | `/curricula/{curriculum}/publish` | `CurriculumController@publish` | `CurriculumService::publish` | ACADEMY_MANAGE | scoped 404 | `VersionedActionRequest` | `AcademyActionResource` | no | service |
| POST | `/curricula/{curriculum}/courses` | `CurriculumController@addCourse` | `CurriculumService::addCourse` | ACADEMY_MANAGE | scoped 404 | `CurriculumCourseRequest` | `AcademyActionResource` | no | service |
| POST | `/classes/{class}/enrollments` | `EnrollmentController@store` | `EnrollmentService::enroll` | ACADEMY_ENROLL | scoped 404 | `EnrollmentStoreRequest` | `EnrollmentResource` | no | service |
| GET | `/classes/{class}/enrollments` | `EnrollmentController@index` | `EnrollmentService::listForClass` | ACADEMY_VIEW | scoped 404 | `AcademyListRequest` | `AcademyCollectionResource` | yes | no |
| POST | `/enrollments/{enrollment}/transitions` | `EnrollmentController@transition` | `EnrollmentService::transition` | ACADEMY_ENROLL | scoped 404 | `TransitionRequest` | `AcademyActionResource` | no | service |
| POST | `/enrollments/{enrollment}/complete` | `EnrollmentController@complete` | `CompletionService::complete` | ACADEMY_ASSESS | scoped 404 | `VersionedActionRequest` | `AcademyActionResource` | no | service |
| POST | `/classes/{class}/instructors` | `InstructorController@store` | `InstructorAssignmentService::assign` | ACADEMY_MANAGE | scoped 404 | `InstructorAssignRequest` | `AcademyActionResource` | no | service |
| GET | `/classes/{class}/instructors` | `InstructorController@index` | `InstructorAssignmentService::activeAssignments` | ACADEMY_VIEW | scoped 404 | `ContextRequest` | `AcademyCollectionResource` | bounded 200 | no |
| POST | `/instructor-assignments/{assignment}/end` | `InstructorController@end` | `InstructorAssignmentService::end` | ACADEMY_MANAGE | scoped 404 | `VersionedActionRequest` | `AcademyActionResource` | no | service |
| POST | `/classes/{class}/sessions` | `SessionController@store` | `ClassSessionService::schedule` | ACADEMY_TEACH | scoped 404 | `SessionStoreRequest` | `AcademyActionResource` | no | service |
| POST | `/sessions/{session}/transitions` | `SessionController@transition` | `ClassSessionService::transition` | ACADEMY_TEACH | scoped 404 | `TransitionRequest` | `AcademyActionResource` | no | service |
| GET | `/sessions/{session}/effective-location` | `SessionController@location` | `ClassSessionService::effectiveLocation` | ACADEMY_VIEW | scoped 404 | `ContextRequest` | `AcademyActionResource` | no | no |
| PUT | `/sessions/{session}/attendance/{enrollment}` | `AttendanceController@store` | `AcademicAttendanceService::record` | ACADEMY_ATTENDANCE | scoped 404 | `AttendanceRecordRequest` | `AcademyActionResource` | no | service |
| POST | `/sessions/{session}/attendance:bulk` | `AttendanceController@bulk` | `AcademicAttendanceService::recordBulk` | ACADEMY_ATTENDANCE | scoped 404 | `AttendanceBulkRequest` | `AcademyCollectionResource` | max 200 | service |
| GET | `/sessions/{session}/attendance` | `AttendanceController@index` | `AcademicAttendanceService::listForSession` | ACADEMY_VIEW | scoped 404 | `AcademyListRequest` | `AcademyCollectionResource` | yes | no |
| POST | `/course-versions/{courseVersion}/assessments` | `AssessmentController@store` | `AssessmentService::create` | ACADEMY_MANAGE | scoped 404 | `AssessmentStoreRequest` | `AcademyActionResource` | no | service |
| PATCH | `/assessments/{assessment}` | `AssessmentController@update` | `AssessmentService::update` | ACADEMY_MANAGE | scoped 404 | `AssessmentUpdateRequest` | `AcademyActionResource` | no | service |
| POST | `/assessments/{assessment}/attempts` | `AttemptController@store` | `AssessmentAttemptService::start` | ACADEMY_ASSESS | scoped 404 | `AttemptStoreRequest` | `AcademyActionResource` | no | service |
| POST | `/attempts/{attempt}/transitions` | `AttemptController@transition` | `AssessmentAttemptService::transition` | ACADEMY_ASSESS | scoped 404 | `TransitionRequest` | `AcademyActionResource` | no | service |
| POST | `/attempts/{attempt}/grades` | `GradeController@store` | `GradeService::record` | ACADEMY_ASSESS | scoped 404 | `GradeStoreRequest` | `AcademyActionResource` | no | service |
| POST | `/attempts/{attempt}/grades/revisions` | `GradeController@revise` | `GradeService::revise` | ACADEMY_ASSESS | scoped 404 | `GradeRevisionRequest` | `AcademyActionResource` | no | service |
| POST | `/attempts/{attempt}/grades/finalize` | `GradeController@finalize` | `GradeService::finalize` | ACADEMY_ASSESS | scoped 404 | `VersionedActionRequest` | `AcademyActionResource` | no | service |
| GET | `/attempts/{attempt}/grades` | `GradeController@index` | `GradeService::history` | ACADEMY_GRADES_VIEW | scoped 404 | `ContextRequest` | `AcademyCollectionResource` | bounded by attempt | no |
| PUT | `/enrollments/{enrollment}/lessons/{lesson}/progress` | `ProgressController@lesson` | `ProgressService::recordLesson` | ACADEMY_TEACH | scoped 404 | `LessonProgressRequest` | `AcademyActionResource` | no | service |
| PUT | `/enrollments/{enrollment}/resources/{resource}/progress` | `ProgressController@resource` | `ProgressService::recordResource` | ACADEMY_TEACH | scoped 404 | `ResourceProgressRequest` | `AcademyActionResource` | no | service |
| POST | `/enrollments/{enrollment}/certificates` | `CertificateController@store` | `CertificateService::issue` | ACADEMY_CERTIFY | scoped 404 | `CertificateIssueRequest` | `CertificateResource` | no | service |
| POST | `/certificates/{certificate}/revoke` | `CertificateController@revoke` | `CertificateService::revoke` | ACADEMY_CERTIFY | scoped 404 | `VersionedActionRequest` | `AcademyActionResource` | no | service |
| POST | `/curricula/{curriculum}/people/{person}/transcript-preview` | `TranscriptController@preview` | `TranscriptService::compile` | ACADEMY_GRADES_VIEW | scoped 404/all-or-nothing | `ContextRequest` | `TranscriptResource` | no | no |
| POST | `/curricula/{curriculum}/people/{person}/transcripts` | `TranscriptController@store` | `TranscriptService::issue` | ACADEMY_CERTIFY | scoped 404/all-or-nothing | `TranscriptIssueRequest` | `TranscriptResource` | no | service |

## A3.1 resolution of A3-PRODUCT-01

The missing read contracts are now explicit application services and HTTP contracts. No command behaviour, migration or schema object changed. The prior `A3_APPLICATION_CONTRACT_CONFLICT` list is empty; the final CRUD classification and performance evidence are in `docs/reviews/P0.3.5_A3_1_crud_read_completion.md`.

## Common contract

- JSON only. Success payloads use `data`; paginated payloads use `data`, `meta`, and `links`.
- `page` defaults to 1. `per_page` defaults to 50 and is capped at 100 (bulk attendance is capped at 200 items by both request and service).
- Only documented fields are accepted. Unknown or protected fields such as `created_by`, `issued_by`, `status`, arbitrary unit claims, and internal flags fail validation.
- `academic_unit_id`, `unit_id`, and `class_id`, when accepted as optional context claims, can only narrow/check context; A2 derives authority from persisted rows.
- Public IDs are used where the schema provides them. Numeric route IDs remain only for approved tables without `public_id`.
- No destructive DELETE routes exist.

## Concealment and error matrix

| Internal domain error | HTTP | External code | Message exposed | Existence exposed | Internal log |
|---|---:|---|---|---|---|
| TARGET_NOT_FOUND | 404 | RESOURCE_NOT_FOUND | generic | no | yes |
| NOT_AUTHORIZED | 404 on Academy target routes | RESOURCE_NOT_FOUND | generic | no | yes |
| OUT_OF_SCOPE | 404 | RESOURCE_NOT_FOUND | generic | no | yes |
| CLASS_ASSIGNMENT_REQUIRED | 404 | RESOURCE_NOT_FOUND | generic | no | yes |
| CONTEXT_MISMATCH | 404 | RESOURCE_NOT_FOUND | generic | no | yes |
| ALREADY_ENROLLED | 409 | ALREADY_ENROLLED | safe | yes, after authorised parent | yes |
| STALE_WRITE | 409 | STALE_WRITE | safe | yes | yes |
| CERTIFICATE_ALREADY_EXISTS | 409 | CERTIFICATE_ALREADY_EXISTS | safe | yes, after authorised enrollment | yes |
| POLICY_NOT_CONFIGURED | 422 | ACADEMIC_POLICY_NOT_CONFIGURED | generic | no | yes |
| STATE_POLICY_PENDING | 422 | STATE_POLICY_PENDING | generic | no | yes |
| CONSENT_REQUIRED | 422 | PARTICIPATION_REQUIREMENTS_NOT_MET | generic | no | yes |
| VALIDATION_ERROR / INVALID_INPUT | 422 | VALIDATION_ERROR | safe field map only | no | optional |
| other deterministic conflicts | 409 | CONFLICT | generic | no | yes |

Unauthenticated requests return 401 `UNAUTHENTICATED` before target resolution. Responses never include SQL, stack traces, domain context IDs, policy internals, guardian details, file metadata, or classification values.

## Filters, sorting and search

A3.1 exposes endpoint-specific allowlists recorded in the HTTP manifest: scoped search for academic units, programs, courses, cohorts, classes, roster and assessments; factual status filters where stored; relationship filters using approved public IDs or numeric identifiers only where no `public_id` exists; and bounded date filters for sessions. Sort is limited to documented logical keys and translated to fixed SQL columns. No arbitrary sort/filter column or national Person search is accepted.

## A4 consumption notes

The future PWA may build action visibility from the permission named in the route inventory. It must treat 404 as an opaque unavailable resource, use `lock_version` for versioned commands, confirm transition/revoke/finalize actions, display validation errors by field, and never infer absence versus lack of scope. Catalogue screens and person-bearing rosters can consume the A3.1 GET contracts. Person search remains intentionally contextual and is not a general identity directory.
