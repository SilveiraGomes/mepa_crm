<?php

declare(strict_types=1);

namespace App\Domain\Membership;

// Deterministic reasons of the Membership domain (ADR 0020). TARGET_NOT_FOUND and OUT_OF_SCOPE are rendered as the
// same 404 (F-06): an unknown, malformed or out-of-scope membership, transfer, candidacy, milestone, legacy identifier
// or source document is indistinguishable.
final class MembershipReason
{
    public const NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const REASON_REQUIRED = 'REASON_REQUIRED';
    public const STALE_WRITE = 'STALE_WRITE';
    public const TRANSITION_NOT_ALLOWED = 'TRANSITION_NOT_ALLOWED';
    public const MEMBERSHIP_EXISTS = 'MEMBERSHIP_EXISTS';
    public const MEMBERSHIP_NOT_ACTIVE = 'MEMBERSHIP_NOT_ACTIVE';
    public const PERSON_DECEASED = 'PERSON_DECEASED';
    public const PERSON_NOT_OPERATIONAL = 'PERSON_NOT_OPERATIONAL';
    public const CONGREGATION_NOT_ACTIVE = 'CONGREGATION_NOT_ACTIVE';
    public const TRANSFER_IN_PROGRESS = 'TRANSFER_IN_PROGRESS';
    public const LEGACY_ID_EXISTS = 'LEGACY_ID_EXISTS';
    public const LEGACY_ID_REQUIRED = 'LEGACY_ID_REQUIRED';
    public const MILESTONE_EXISTS = 'MILESTONE_EXISTS';
    public const COLLECTIVE_REJECTED = 'COLLECTIVE_REJECTED';
    public const NUMBER_UNAVAILABLE = 'NUMBER_UNAVAILABLE';
    public const CONFIG_MISSING = 'CONFIG_MISSING';
    public const INVARIANT_VIOLATION = 'INVARIANT_VIOLATION';
    public const STORAGE_CONFLICT = 'STORAGE_CONFLICT';

    /** Rendered as 409 with the reason as the error code. */
    public const CONFLICTS = [
        self::STALE_WRITE, self::TRANSITION_NOT_ALLOWED, self::MEMBERSHIP_EXISTS, self::MEMBERSHIP_NOT_ACTIVE,
        self::PERSON_DECEASED, self::PERSON_NOT_OPERATIONAL, self::CONGREGATION_NOT_ACTIVE, self::TRANSFER_IN_PROGRESS,
        self::LEGACY_ID_EXISTS, self::LEGACY_ID_REQUIRED, self::MILESTONE_EXISTS,
    ];
}
