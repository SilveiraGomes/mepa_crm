<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use RuntimeException;
use Throwable;

// Deterministic domain error. `reason` is one of AcademyReason. `context` carries ids only
// (never names, scores, tokens or other personal data). The original database error, when
// there is one, stays reachable through getPrevious().
final class AcademyError extends RuntimeException
{
    public function __construct(public string $reason, public array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
