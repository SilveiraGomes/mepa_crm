<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

use RuntimeException;
use Throwable;

final class TerritorialError extends RuntimeException
{
    public function __construct(public string $reason, public array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
