<?php

declare(strict_types=1);

namespace App\Domain\People;

// Contacts, addresses, household members and relationships have no public_id and are reached only
// through their parent resource (ADR-0009 / P0.5 contract). Their external handle is an HMAC of the
// internal key under a server secret: stable, not reversible, not a primary key and meaningless
// outside its parent. Resolution compares the handle against the parent's own (bounded) child rows.
final class OpaqueRef
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new PeopleError(PeopleReason::CONFIG_MISSING, ['reason' => 'ref_secret']);
        }
    }

    public function for(string $kind, int $id): string
    {
        return substr(hash_hmac('sha256', 'mepa.people.ref|' . $kind . '|' . $id, $this->secret), 0, 32);
    }

    /** @param iterable<int> $candidates */
    public function resolve(string $kind, string $ref, iterable $candidates): ?int
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $ref) !== 1) {
            return null;
        }
        foreach ($candidates as $id) {
            if (hash_equals($this->for($kind, (int) $id), $ref)) {
                return (int) $id;
            }
        }
        return null;
    }
}
