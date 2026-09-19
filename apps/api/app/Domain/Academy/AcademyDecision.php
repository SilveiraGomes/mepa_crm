<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use DateTimeImmutable;

// Outcome of an authorization check. `mode` is DIRECT (permission + institutional scope
// [+ active class assignment]) or ADMIN_OVERRIDE (explicit ACADEMY_ADMIN in scope, with reason).
final class AcademyDecision
{
    public const DIRECT = 'DIRECT';
    public const ADMIN_OVERRIDE = 'ADMIN_OVERRIDE';

    public function __construct(
        public string $mode,
        public string $permission,
        public int $actor,
        public int $session,
        public DateTimeImmutable $at,
        public ?string $overrideReason,
        public ?int $actorPersonId
    ) {
    }

    public function isOverride(): bool
    {
        return $this->mode === self::ADMIN_OVERRIDE;
    }
}
