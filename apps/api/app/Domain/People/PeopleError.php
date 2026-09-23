<?php

declare(strict_types=1);

namespace App\Domain\People;

use RuntimeException;
use Throwable;

// Deterministic People domain error. `reason` is one of PeopleReason. `context` carries codes and
// internal ids only for server logs; it never carries names, contact values, addresses, keys,
// ciphertext or blind indexes, and it never leaves the server.
final class PeopleError extends RuntimeException
{
    public function __construct(public string $reason, public array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
