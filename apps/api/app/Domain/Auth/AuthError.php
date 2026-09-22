<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;

final class AuthError extends RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct($reason);
    }
}
