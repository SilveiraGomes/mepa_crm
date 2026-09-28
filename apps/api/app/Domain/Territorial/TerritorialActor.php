<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

final class TerritorialActor
{
    public function __construct(public int $user, public int $session)
    {
    }
}
