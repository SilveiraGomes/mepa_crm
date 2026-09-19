<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use DateTimeImmutable;

// Outcome of an authorization check. `mode` is DIRECT (permission + institutional scope
// [+ active class assignment]) or ADMIN_OVERRIDE (explicit ACADEMY_ADMIN in scope, with reason).
//
// `operation` is the matrix key that was authorized. An operation whose business effect belongs to
// another operation (a generic enrollment transition that completes an enrollment) must also hold
// THAT operation's authority: attach() records the extra decision, and AcademyRuntime::write
// re-verifies every attached decision with the final (locking) check, exactly like the primary one.
final class AcademyDecision
{
    public const DIRECT = 'DIRECT';
    public const ADMIN_OVERRIDE = 'ADMIN_OVERRIDE';

    /** @var array<int, array{0: AcademyOperation, 1: AcademyDecision}> */
    private array $attached = [];

    public function __construct(
        public string $mode,
        public string $permission,
        public int $actor,
        public int $session,
        public DateTimeImmutable $at,
        public ?string $overrideReason,
        public ?int $actorPersonId,
        public string $operation = ''
    ) {
    }

    public function attach(AcademyOperation $op, AcademyDecision $decision): void
    {
        $this->attached[] = [$op, $decision];
    }

    public function attached(): array
    {
        return $this->attached;
    }

    public function isOverride(): bool
    {
        return $this->mode === self::ADMIN_OVERRIDE;
    }
}
