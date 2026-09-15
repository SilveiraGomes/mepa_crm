<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

use Illuminate\Database\Connection;
final class DiscipleshipEnrollmentScope
{
    public function __construct(private Connection $db)
    {
    }
    public function resolve(int $enrollment): int
    {
        // The approved enrollment has no unit column. Its atomic creation audit is the provenance.
        $rows = $this->db->table('audit_logs')->where('source', 'WAVE4_DOMAIN')->where('action', 'OUTREACH_RECORD_CREATED')->where('entity_type', 'discipleship_enrollments')->where('entity_id', $enrollment)->sharedLock()->get();
        $units = [];
        foreach ($rows as $row) {
            if ($row->unit_id === null) {
                throw new DomainError('ENROLLMENT_SCOPE_UNRESOLVED');
            }
            $units[(int) $row->unit_id] = true;
        }
        if (count($units) !== 1) {
            throw new DomainError('ENROLLMENT_SCOPE_UNRESOLVED');
        }
        return (int) array_key_first($units);
    }
}
