<?php

declare(strict_types=1);

namespace App\Domain\Physical;

// One authority decision: $permission is held through the institutional scope of $unit. When $location is set,
// the unit reached the location through the active vigente link $link (ADR 0018 D03). $unit is the audit unit.
final class PhysicalDecision
{
    public function __construct(
        public string $permission,
        public int $unit,
        public ?int $location = null,
        public ?int $link = null
    ) {
    }
}
