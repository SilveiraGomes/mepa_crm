<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Permission matrix (ADR-0015 Decision B, refined by the P0.3.5-A2 mission). One row per operation:
// required permission, whether an active class_instructors assignment is required, whether an
// explicit ACADEMY_ADMIN override is allowed, and whether an audit_logs record is mandatory.
// Institutional scope (user_role_scopes) is required for every operation.
//
// Permission rows follow the convention already enforced by DomainAccess/EventAccess:
// permissions.code = permissions.action = <permission>, permissions.data_type = 'ACADEMY'.
final class AcademyOperation
{
    public const DATA_TYPE = 'ACADEMY';
    public const VIEW = 'ACADEMY_VIEW';
    public const MANAGE = 'ACADEMY_MANAGE';
    public const ENROLL = 'ACADEMY_ENROLL';
    public const TEACH = 'ACADEMY_TEACH';
    public const ATTENDANCE = 'ACADEMY_ATTENDANCE';
    public const ASSESS = 'ACADEMY_ASSESS';
    public const GRADES_VIEW = 'ACADEMY_GRADES_VIEW';
    public const CERTIFY = 'ACADEMY_CERTIFY';
    public const ADMIN = 'ACADEMY_ADMIN';

    public const PERMISSIONS = [self::VIEW, self::MANAGE, self::ENROLL, self::TEACH, self::ATTENDANCE, self::ASSESS, self::GRADES_VIEW, self::CERTIFY, self::ADMIN];

    // key => [permission, class assignment required, admin override allowed, audit required]
    private const MATRIX = [
        'catalog.view' => [self::VIEW, false, false, false],
        'class.view' => [self::VIEW, false, false, false],
        'assessment.view' => [self::VIEW, false, false, false],
        'attempt.view' => [self::GRADES_VIEW, false, false, false],
        'certificate.view' => [self::VIEW, false, false, false],
        'transcript.view' => [self::GRADES_VIEW, false, false, false],
        'progress.view' => [self::VIEW, false, false, false],
        // Contextual candidate lookup is part of the enrollment workflow, not a general directory read.
        'person.search' => [self::ENROLL, false, false, false],
        'instructor.candidate.search' => [self::MANAGE, false, false, false],
        'certificate.file.select' => [self::CERTIFY, false, false, false],
        'transcript.file.select' => [self::CERTIFY, false, false, false],
        'structure.manage' => [self::MANAGE, false, false, true],
        'enrollment.view' => [self::VIEW, false, false, false],
        'enrollment.create' => [self::ENROLL, false, false, true],
        'enrollment.transition' => [self::ENROLL, false, false, true],
        'instructor.assign' => [self::MANAGE, false, true, true],
        'instructor.end' => [self::MANAGE, false, true, true],
        'instructor.view' => [self::VIEW, false, false, false],
        'session.manage' => [self::TEACH, true, true, true],
        'session.view' => [self::VIEW, false, false, false],
        'attendance.record' => [self::ATTENDANCE, true, true, true],
        'attendance.view' => [self::VIEW, false, false, false],
        'attempt.record' => [self::ASSESS, true, true, true],
        'attempt.transition' => [self::ASSESS, true, true, true],
        'grade.record' => [self::ASSESS, true, true, true],
        'grade.revise' => [self::ASSESS, true, true, true],
        'grade.finalize' => [self::ASSESS, true, true, true],
        'grade.view' => [self::GRADES_VIEW, false, false, false],
        'progress.record' => [self::TEACH, true, true, false],
        'enrollment.complete' => [self::ASSESS, true, true, true],
        'certificate.issue' => [self::CERTIFY, false, true, true],
        'certificate.revoke' => [self::CERTIFY, false, true, true],
        // A transcript carries grades, so compiling one needs the grade-visibility permission.
        'transcript.compile' => [self::GRADES_VIEW, false, false, false],
        'transcript.issue' => [self::CERTIFY, false, true, true],
    ];

    private function __construct(
        public string $key,
        public string $permission,
        public bool $classAssignment,
        public bool $adminOverride,
        public bool $audit
    ) {
    }

    public static function get(string $key): self
    {
        if (!isset(self::MATRIX[$key])) {
            throw new AcademyError(AcademyReason::NOT_AUTHORIZED, ['operation' => 'unknown']);
        }
        [$permission, $assignment, $override, $audit] = self::MATRIX[$key];
        return new self($key, $permission, $assignment, $override, $audit);
    }

    public static function keys(): array
    {
        return array_keys(self::MATRIX);
    }

    public static function matrix(): array
    {
        return self::MATRIX;
    }
}
