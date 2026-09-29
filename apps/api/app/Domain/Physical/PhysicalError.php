<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use RuntimeException;
use Throwable;

// `reason` is one of PhysicalReason. `context` carries codes and internal ids for server logs only; it never
// carries address lines, owner names, keys or ciphertext, and it never leaves the server.
final class PhysicalError extends RuntimeException
{
    public function __construct(public string $reason, public array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
