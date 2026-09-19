<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use App\Domain\WaveFour\ChildParticipationSafetyGate;
use App\Domain\WaveFour\DomainError;
use Illuminate\Database\Connection;

// Person resolution for every Academy service. A Person is referenced by people.id or by the
// people.public_id ULID. Never by member number (a member number is not an academic identifier),
// and never through Membership: an external student or instructor without membership resolves.
final class AcademyPersonResolver
{
    public function __construct(private Connection $db, private ChildParticipationSafetyGate $gate)
    {
    }

    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    // Non-throwing lookup used while an operation derives its authorization target: an unknown or
    // malformed reference simply yields null, and resolve() reports it only AFTER the caller has been
    // authorized, so the existence of a Person is never revealed to an unauthorized caller.
    public function find(int|string $reference): ?int
    {
        if (is_int($reference)) {
            return $reference > 0 ? $reference : null;
        }
        if (ctype_digit($reference) && (int) $reference > 0) {
            return (int) $reference;
        }
        if (preg_match(self::ULID, $reference) === 1) {
            $id = $this->db->table('people')->where('public_id', $reference)->value('id');
            return $id === null ? null : (int) $id;
        }
        return null;
    }

    public function resolve(int|string $reference): int
    {
        $id = $this->find($reference);
        if ($id !== null) {
            return $id;
        }
        if (is_string($reference) && preg_match(self::ULID, $reference) === 1) {
            throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => 'people']);
        }
        throw new AcademyError(AcademyReason::INVALID_PERSON_REFERENCE);
    }

    // Canonical eligibility (active status, not archived, not merged) lives in the Wave 4 gate.
    public function assertEligible(int $person): void
    {
        try {
            $this->gate->person($person);
        } catch (DomainError) {
            throw new AcademyError(AcademyReason::PERSON_NOT_ELIGIBLE);
        }
    }
}
