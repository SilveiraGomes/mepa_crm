<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

final class DomainError extends \RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct($reason);
    }
}
