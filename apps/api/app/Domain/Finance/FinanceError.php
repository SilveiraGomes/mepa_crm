<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use RuntimeException;

/** A refused finance operation. `reason` is a stable machine code (ADR 0021); `items` lists offending objects when useful. */
final class FinanceError extends RuntimeException
{
    /** @param list<string> $items */
    public function __construct(public readonly string $reason, public readonly array $items = [])
    {
        parent::__construct($reason . ($items === [] ? '' : ': ' . implode(', ', $items)));
    }
}
