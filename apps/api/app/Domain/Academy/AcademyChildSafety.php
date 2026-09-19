<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use App\Domain\WaveFour\ChildParticipationSafetyGate;
use App\Domain\WaveFour\DomainError;
use DateTimeImmutable;

// Thin Academy adapter over the canonical Wave 4 ChildParticipationSafetyGate (not a parallel
// safety service): it only translates the gate's DomainError into the Academy error contract.
// lock() runs early in the business transaction; assertCover() runs late, against the decisive
// clock, immediately before the final authorization check. Fail closed: anything other than a
// positive cover is a deny, and no exception is swallowed into an allow.
final class AcademyChildSafety
{
    public function __construct(private ChildParticipationSafetyGate $gate)
    {
    }

    // null = the Person has no child profile (adult / non-child: the gate does not apply).
    public function lock(int $person): ?array
    {
        try {
            return $this->gate->lockParticipationCover($person);
        } catch (DomainError $e) {
            throw new AcademyError(self::map($e->reason), [], $e);
        }
    }

    public function assertCover(?array $cover, DateTimeImmutable $at): void
    {
        if ($cover === null) {
            return;
        }
        try {
            $this->gate->assertParticipationCover($cover, $at);
        } catch (DomainError $e) {
            throw new AcademyError(self::map($e->reason), [], $e);
        }
    }

    private static function map(string $reason): string
    {
        return match ($reason) {
            'CONSENT_REQUIRED' => AcademyReason::CONSENT_REQUIRED,
            'GUARDIAN_AUTHORIZATION_INVALID' => AcademyReason::GUARDIAN_AUTHORIZATION_INVALID,
            'CHILD_NOT_ELIGIBLE' => AcademyReason::CHILD_NOT_ELIGIBLE,
            'PERSON_NOT_ELIGIBLE' => AcademyReason::PERSON_NOT_ELIGIBLE,
            default => AcademyReason::CHILD_SAFETY_DENIED,
        };
    }
}
