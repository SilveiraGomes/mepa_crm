<?php

declare(strict_types=1);

namespace App\Domain\People;

final class PeopleActor
{
    public function __construct(public int $user, public int $session, public ?int $personId)
    {
    }
}
