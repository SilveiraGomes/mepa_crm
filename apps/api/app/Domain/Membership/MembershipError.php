<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use RuntimeException;
use Throwable;

// `reason` is one of MembershipReason. `context` carries codes and internal ids for server logs only; it never carries
// People data, document titles or keys, and it never leaves the server. `items` is the only client-visible payload:
// the per-item result of a rejected collective approval (index, public_id as sent, error code).
final class MembershipError extends RuntimeException
{
    /** @param list<array{index: int, public_id: string, code: string}> $items */
    public function __construct(public string $reason, public array $context = [], ?Throwable $previous = null, public array $items = [])
    {
        parent::__construct($reason, 0, $previous);
    }
}
