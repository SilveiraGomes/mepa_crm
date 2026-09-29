<?php

declare(strict_types=1);

namespace App\Domain\Physical;

// unit_location_links has no public_id (model catalog) and is reached only through its location. Its external
// handle is an HMAC of the internal key under a server secret: stable, not reversible, not a primary key and
// meaningless outside its location. Resolution compares against the location's own link rows only.
final class PhysicalRef
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new PhysicalError(PhysicalReason::CONFIG_MISSING, ['reason' => 'ref_secret']);
        }
    }

    public function for(string $kind, int $id): string
    {
        return substr(hash_hmac('sha256', 'mepa.physical.ref|' . $kind . '|' . $id, $this->secret), 0, 32);
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
