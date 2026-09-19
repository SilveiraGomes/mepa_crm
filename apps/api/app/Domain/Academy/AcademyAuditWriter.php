<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Audit sink for required Academy audit records. Behind an interface so a test can prove that a
// failing audit write leaves no partially committed business state (the write path always calls
// it inside the business transaction).
interface AcademyAuditWriter
{
    public function record(AcademyDecision $decision, int $unitId, string $action, string $entityType, int $entityId, ?array $before, ?array $after, ?string $reason): void;
}
