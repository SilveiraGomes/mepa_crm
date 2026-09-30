<?php

declare(strict_types=1);

namespace App\Domain\Finance;

/** One authority decision: $permission is held through the institutional scope that covers $unit (the audit unit). */
final class FinanceDecision
{
    public function __construct(public string $permission, public int $unit)
    {
    }
}
