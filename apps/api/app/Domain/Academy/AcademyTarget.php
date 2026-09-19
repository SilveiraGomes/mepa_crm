<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// The operation's target, derived by AcademyScopeResolver from persisted rows. Nothing in here
// ever comes from a request payload: unitIds are organizational units that must ALL be covered
// by the actor's institutional scope; classId is the class whose assignment provenance applies.
final class AcademyTarget
{
    public function __construct(public array $unitIds, public ?int $classId = null, public ?int $academicUnitId = null, public array $rows = [])
    {
        $this->unitIds = array_values(array_unique(array_map('intval', $unitIds)));
    }
}
