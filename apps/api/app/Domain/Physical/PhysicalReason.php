<?php

declare(strict_types=1);

namespace App\Domain\Physical;

// Deterministic reasons of the Physical domain. TARGET_NOT_FOUND and OUT_OF_SCOPE are rendered as the same
// 404 (F-06): an unknown, malformed, out-of-scope or unlinked (no active vigente link) target is indistinguishable.
final class PhysicalReason
{
    public const NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const REASON_REQUIRED = 'REASON_REQUIRED';
    public const STALE_WRITE = 'STALE_WRITE';
    public const TRANSITION_NOT_ALLOWED = 'TRANSITION_NOT_ALLOWED';
    public const ACTIVE_DEPENDENCIES = 'ACTIVE_DEPENDENCIES';
    public const LAST_ACTIVE_LINK_REQUIRED = 'LAST_ACTIVE_LINK_REQUIRED';
    public const LINK_EXISTS = 'LINK_EXISTS';
    public const LOCATION_CLOSED = 'LOCATION_CLOSED';
    public const LOCATION_NOT_ACTIVE = 'LOCATION_NOT_ACTIVE';
    public const COORDINATES_REQUIRED = 'COORDINATES_REQUIRED';
    public const UNIT_NOT_OPERATIONAL = 'UNIT_NOT_OPERATIONAL';
    public const CODE_EXISTS = 'CODE_EXISTS';
    public const CRYPTO_UNAVAILABLE = 'CRYPTO_UNAVAILABLE';
    public const CONFIG_MISSING = 'CONFIG_MISSING';
    public const INVARIANT_VIOLATION = 'INVARIANT_VIOLATION';
    public const STORAGE_CONFLICT = 'STORAGE_CONFLICT';
}
