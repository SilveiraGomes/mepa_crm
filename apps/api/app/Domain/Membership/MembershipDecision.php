<?php

declare(strict_types=1);

namespace App\Domain\Membership;

// One authority decision: $permission is held through the institutional scope that covers $unit (the Congregation of
// the membership's open period, or the origin/destination of a transfer). $unit is the audit unit (ADR 0020 D05/D11).
final class MembershipDecision
{
    public function __construct(public string $permission, public int $unit)
    {
    }
}
