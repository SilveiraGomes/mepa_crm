<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Academic attendance lives in academic_attendance (never in event attendance) and is always
// contextualised by class_session + enrollment. UNIQUE(enrollment_id, class_session_id) makes a
// repeated request naturally idempotent (UNCHANGED), so no idempotency key is needed. A student does
// not record their own attendance: only ACADEMY_ATTENDANCE + scope + active class assignment (or an
// explicit, audited ACADEMY_ADMIN override) can. A minor may only be recorded while the Wave 4
// consent/guardian cover holds; that cover is re-evaluated at commit time, not inferred from history.
final class AcademicAttendanceService
{
    private const MAX_BULK = 200;

    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function record(int $actor, int $session, int $classSessionId, int $enrollmentId, string $status, ?string $overrideReason = null, ?int $expectedLockVersion = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'attendance.record',
            $actor,
            $session,
            fn () => $this->rt->scope->forSession($classSessionId, 'share'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($enrollmentId, $status, $expectedLockVersion) {
                $covers = [];
                $result = $this->apply($decision, $target, $enrollmentId, $status, $expectedLockVersion, $covers);
                $this->assertCovers($covers);
                return $result;
            },
            $overrideReason,
            $claimed
        );
    }

    // One atomic request: every entry succeeds or none is stored. Entries are processed in
    // enrollment-id order so concurrent bulk requests take their row locks in the same order.
    public function recordBulk(int $actor, int $session, int $classSessionId, array $entries, ?string $overrideReason = null, ?array $claimed = null): array
    {
        if ($entries === [] || count($entries) > self::MAX_BULK) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'entries']);
        }
        ksort($entries);
        return $this->rt->write(
            'attendance.record',
            $actor,
            $session,
            fn () => $this->rt->scope->forSession($classSessionId, 'share'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($entries) {
                $covers = [];
                $results = [];
                foreach ($entries as $enrollmentId => $status) {
                    $results[] = $this->apply($decision, $target, (int) $enrollmentId, (string) $status, null, $covers);
                }
                $this->assertCovers($covers);
                return $results;
            },
            $overrideReason,
            $claimed
        );
    }

    private function apply(AcademyDecision $decision, AcademyTarget $target, int $enrollmentId, string $status, ?int $expected, array &$covers): array
    {
        $session = $target->rows['session'];
        if (!$this->rt->policy->inSet('class_sessions', 'attendable', (string) $session->status)) {
            throw new AcademyError(AcademyReason::SESSION_NOT_ATTENDABLE, ['class_session_id' => (int) $session->id]);
        }
        if (!$this->rt->policy->inSet('academic_attendance', 'recordable', $status)) {
            throw new AcademyError(AcademyReason::ATTENDANCE_STATUS_INVALID);
        }
        $enrollment = $this->rt->db->table('enrollments')->where('id', $enrollmentId)->lockForUpdate()->first();
        if (!$enrollment || (int) $enrollment->class_id !== $target->classId) {
            throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'enrollments']);
        }
        if (!$this->rt->policy->inSet('enrollments', 'operational', (string) $enrollment->status)) {
            throw new AcademyError(AcademyReason::ENROLLMENT_NOT_ACTIVE, ['enrollment_id' => $enrollmentId]);
        }
        $covers[] = $this->rt->safety->lock((int) $enrollment->person_id);
        $now = AcademyRuntime::ts($this->rt->now());
        $row = $this->rt->db->table('academic_attendance')->where('enrollment_id', $enrollmentId)->where('class_session_id', $session->id)->lockForUpdate()->first();
        if (!$row) {
            $id = (int) $this->rt->db->table('academic_attendance')->insertGetId([
                'enrollment_id' => $enrollmentId, 'class_session_id' => $session->id, 'status' => $status, 'recorded_by' => $decision->actor, 'recorded_at' => $now, 'created_at' => $now,
            ]);
            $this->rt->audit($decision, $target, 'attendance.recorded', 'academic_attendance', $id, null, ['enrollment_id' => $enrollmentId, 'class_session_id' => (int) $session->id, 'status' => $status]);
            return ['enrollment_id' => $enrollmentId, 'attendance_id' => $id, 'outcome' => 'CREATED'];
        }
        if ($expected !== null && (int) $row->lock_version !== $expected) {
            throw new AcademyError(AcademyReason::STALE_WRITE, ['entity' => 'academic_attendance', 'current_lock_version' => (int) $row->lock_version]);
        }
        if ($row->status === $status) {
            return ['enrollment_id' => $enrollmentId, 'attendance_id' => (int) $row->id, 'outcome' => 'UNCHANGED'];
        }
        $this->rt->db->table('academic_attendance')->where('id', $row->id)->update(['status' => $status, 'recorded_by' => $decision->actor, 'recorded_at' => $now, 'lock_version' => (int) $row->lock_version + 1]);
        $this->rt->audit($decision, $target, 'attendance.updated', 'academic_attendance', (int) $row->id, ['status' => $row->status, 'recorded_by' => (int) $row->recorded_by], ['status' => $status, 'recorded_by' => $decision->actor]);
        return ['enrollment_id' => $enrollmentId, 'attendance_id' => (int) $row->id, 'outcome' => 'UPDATED'];
    }

    private function assertCovers(array $covers): void
    {
        $at = $this->rt->now();
        foreach ($covers as $cover) {
            $this->rt->safety->assertCover($cover, $at);
        }
    }

    public function listForSession(int $actor, int $session, int $classSessionId, int $page = 1, int $perPage = 100, ?array $claimed = null): array
    {
        [$page, $perPage] = AcademyInput::page($page, $perPage);
        return $this->rt->read(
            'attendance.view',
            $actor,
            $session,
            fn () => $this->rt->scope->forSession($classSessionId, null),
            function (AcademyTarget $target) use ($classSessionId, $page, $perPage) {
                $query = $this->rt->db->table('academic_attendance')->where('class_session_id', $classSessionId);
                $total = (clone $query)->count();
                $rows = $query->orderBy('id')->forPage($page, $perPage)->get(['id', 'enrollment_id', 'status', 'recorded_by', 'recorded_at', 'lock_version']);
                return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $rows->map(fn ($r) => (array) $r)->all()];
            },
            $claimed
        );
    }
}
