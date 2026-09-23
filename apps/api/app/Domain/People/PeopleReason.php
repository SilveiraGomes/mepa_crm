<?php

declare(strict_types=1);

namespace App\Domain\People;

use ReflectionClass;

// Domain error contract for People / Families (P0.5-I).
final class PeopleReason
{
    // authorization (ADR-0017 D01: permission + institutional scope + persisted context)
    public const NOT_AUTHORIZED = 'NOT_AUTHORIZED';          // the actor holds the permission nowhere
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';              // no compatible persisted context inside the actor's scope
    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';      // unknown, malformed or concealed target (F-06)
    public const FORBIDDEN = 'FORBIDDEN';                    // target visible to the actor, operation permission missing
    public const SENSITIVE_RESTRICTED = 'SENSITIVE_RESTRICTED';
    public const MINOR_PROTECTED = 'MINOR_PROTECTED';
    public const CONTEXT_UNIT_INVALID = 'CONTEXT_UNIT_INVALID';
    // input / lifecycle
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const REASON_REQUIRED = 'REASON_REQUIRED';
    public const STALE_WRITE = 'STALE_WRITE';
    public const TRANSITION_NOT_ALLOWED = 'TRANSITION_NOT_ALLOWED';
    public const PERSON_DECEASED = 'PERSON_DECEASED';
    public const CONTACT_EXISTS = 'CONTACT_EXISTS';
    public const ALREADY_MEMBER = 'ALREADY_MEMBER';
    public const HOUSEHOLD_NOT_ACTIVE = 'HOUSEHOLD_NOT_ACTIVE';
    public const RELATIONSHIP_EXISTS = 'RELATIONSHIP_EXISTS';
    public const RELATIONSHIP_CONFLICT = 'RELATIONSHIP_CONFLICT';
    public const EXPORT_TOO_LARGE = 'EXPORT_TOO_LARGE';
    // configuration (fail closed)
    public const CATALOG_INVALID = 'CATALOG_INVALID';
    public const CRYPTO_UNAVAILABLE = 'CRYPTO_UNAVAILABLE';
    public const CONFIG_MISSING = 'CONFIG_MISSING';
    // storage
    public const STORAGE_CONFLICT = 'STORAGE_CONFLICT';
    public const REFERENCE_NOT_FOUND = 'REFERENCE_NOT_FOUND';
    public const INVARIANT_VIOLATION = 'INVARIANT_VIOLATION';

    public static function all(): array
    {
        return array_values((new ReflectionClass(self::class))->getConstants());
    }
}
