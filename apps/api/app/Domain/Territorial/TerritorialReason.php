<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

final class TerritorialReason
{
    public const NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const INVALID_PARENT_TYPE = 'INVALID_PARENT_TYPE';
    public const CYCLE_DETECTED = 'CYCLE_DETECTED';
    public const ROOT_MOVE_FORBIDDEN = 'ROOT_MOVE_FORBIDDEN';
    public const TRANSITION_NOT_ALLOWED = 'TRANSITION_NOT_ALLOWED';
    public const ACTIVE_DEPENDENCIES = 'ACTIVE_DEPENDENCIES';
    public const MUNICIPAL_CENTER_REQUIRED = 'MUNICIPAL_CENTER_REQUIRED';
    public const GENERAL_CENTER_EXISTS = 'GENERAL_CENTER_EXISTS';
    public const STALE_WRITE = 'STALE_WRITE';
    public const INVARIANT_VIOLATION = 'INVARIANT_VIOLATION';
}
