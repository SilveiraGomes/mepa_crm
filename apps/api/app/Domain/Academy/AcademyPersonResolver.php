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

    public function resolve(int|string $reference): int
    {
        if (is_int($reference) && $reference > 0) {
            return $reference;
        }
        if (is_string($reference) && ctype_digit($reference) && $reference !== '' && (int) $reference > 0) {
            return (int) $reference;
        }
        if (is_string($reference) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $reference) === 1) {
            $id = $this->db->table('people')->where('public_id', $reference)->value('id');
            if ($id === null) {
                throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => 'people']);
            }
            return (int) $id;
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
