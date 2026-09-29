<?php

declare(strict_types=1);

namespace App\Domain\People;

// Outcome of one contextual authority check: the permission, the Person it was checked against and
// the persisted context that authorized it. `unitId` is the unit written to audit_logs.unit_id; it is
// never a property of the Person. `otherUnits` lists the remaining eligible units (audit metadata).
final class PeopleDecision
{
    public function __construct(
        public string $permission,
        public int $personId,
        public int $unitId,
        public string $contextSource,
        public int $contextId,
        public string $contextKind,
        public string $tier,
        public array $otherUnits = []
    ) {
    }

    public function metadata(): array
    {
        return [
            'permission' => $this->permission,
            'context_source' => $this->contextSource,
            'context_id' => $this->contextId,
            'context_kind' => $this->contextKind,
            'other_units' => array_values($this->otherUnits),
        ];
    }
}
