<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use ReflectionClass;

// Domain error contract for the Academy application layer (P0.3.5-A2).
final class AcademyReason
{
    // authorization
    public const NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const CLASS_ASSIGNMENT_REQUIRED = 'CLASS_ASSIGNMENT_REQUIRED';
    public const OVERRIDE_NOT_ALLOWED = 'OVERRIDE_NOT_ALLOWED';
    public const ADMIN_REASON_REQUIRED = 'ADMIN_REASON_REQUIRED';
    public const SCOPE_UNRESOLVED = 'SCOPE_UNRESOLVED';
    // targets / context
    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';
    public const CONTEXT_MISMATCH = 'CONTEXT_MISMATCH';
    public const PERSON_NOT_ELIGIBLE = 'PERSON_NOT_ELIGIBLE';
    public const INVALID_PERSON_REFERENCE = 'INVALID_PERSON_REFERENCE';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const REASON_REQUIRED = 'REASON_REQUIRED';
    // policy / state (D-09, D-11)
    public const POLICY_NOT_CONFIGURED = 'POLICY_NOT_CONFIGURED';
    public const STATE_POLICY_PENDING = 'STATE_POLICY_PENDING';
    public const INVALID_TRANSITION = 'INVALID_TRANSITION';
    // child safety (Wave 4 gate)
    public const CONSENT_REQUIRED = 'CONSENT_REQUIRED';
    public const GUARDIAN_AUTHORIZATION_INVALID = 'GUARDIAN_AUTHORIZATION_INVALID';
    public const CHILD_NOT_ELIGIBLE = 'CHILD_NOT_ELIGIBLE';
    public const CHILD_SAFETY_DENIED = 'CHILD_SAFETY_DENIED';
    // concurrency / uniqueness
    public const ALREADY_ENROLLED = 'ALREADY_ENROLLED';
    public const ALREADY_ASSIGNED = 'ALREADY_ASSIGNED';
    public const STALE_WRITE = 'STALE_WRITE';
    public const ATTEMPT_ALREADY_EXISTS = 'ATTEMPT_ALREADY_EXISTS';
    public const ATTEMPT_NUMBER_CONFLICT = 'ATTEMPT_NUMBER_CONFLICT';
    public const GRADE_ALREADY_RECORDED = 'GRADE_ALREADY_RECORDED';
    public const GRADE_NOT_FOUND = 'GRADE_NOT_FOUND';
    public const CERTIFICATE_ALREADY_EXISTS = 'CERTIFICATE_ALREADY_EXISTS';
    public const CERTIFICATE_ALREADY_REVOKED = 'CERTIFICATE_ALREADY_REVOKED';
    public const CURRICULUM_PUBLISHED_IMMUTABLE = 'CURRICULUM_PUBLISHED_IMMUTABLE';
    public const CURRICULUM_ALREADY_PUBLISHED = 'CURRICULUM_ALREADY_PUBLISHED';
    // lifecycle gates
    public const CLASS_NOT_ENROLLABLE = 'CLASS_NOT_ENROLLABLE';
    public const CLASS_NOT_TEACHABLE = 'CLASS_NOT_TEACHABLE';
    public const SESSION_NOT_ATTENDABLE = 'SESSION_NOT_ATTENDABLE';
    public const ENROLLMENT_NOT_ACTIVE = 'ENROLLMENT_NOT_ACTIVE';
    public const ENROLLMENT_NOT_COMPLETED = 'ENROLLMENT_NOT_COMPLETED';
    public const ATTENDANCE_STATUS_INVALID = 'ATTENDANCE_STATUS_INVALID';
    public const ASSESSMENT_NOT_OPEN = 'ASSESSMENT_NOT_OPEN';
    public const ASSESSMENT_IN_USE = 'ASSESSMENT_IN_USE';
    public const ATTEMPT_NOT_GRADABLE = 'ATTEMPT_NOT_GRADABLE';
    public const INSTRUCTOR_NOT_ACTIVE = 'INSTRUCTOR_NOT_ACTIVE';
    public const COMPLETION_CRITERIA_NOT_MET = 'COMPLETION_CRITERIA_NOT_MET';
    public const FILE_NOT_AVAILABLE = 'FILE_NOT_AVAILABLE';
    public const TRANSCRIPT_EMPTY = 'TRANSCRIPT_EMPTY';
    // storage (database is the last line of defence; original error stays in getPrevious())
    public const STORAGE_CONFLICT = 'STORAGE_CONFLICT';
    public const REFERENCE_NOT_FOUND = 'REFERENCE_NOT_FOUND';
    public const INVARIANT_VIOLATION = 'INVARIANT_VIOLATION';

    public static function all(): array
    {
        return array_values((new ReflectionClass(self::class))->getConstants());
    }
}
