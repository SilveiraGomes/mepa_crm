<?php

declare (strict_types=1);
namespace App\Domain\Events;

use RuntimeException;
final class EventError extends RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct($reason);
    }
}
