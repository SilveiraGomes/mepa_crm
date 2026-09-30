<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use RuntimeException;
use Throwable;

/**
 * A refused finance operation. `reason` is a stable machine code (ADR 0021); `items` lists offending objects when useful
 * (public ids / codes only). `context` carries codes and internal ids for server logs only (never rendered), except a
 * `field` name used by the HTTP layer for 422 details.
 */
final class FinanceError extends RuntimeException
{
    /** @param list<string> $items */
    public function __construct(public readonly string $reason, public readonly array $items = [], public readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($reason . ($items === [] ? '' : ': ' . implode(', ', $items)), 0, $previous);
    }
}
