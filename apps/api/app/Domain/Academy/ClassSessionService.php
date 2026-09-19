<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use DateTimeImmutable;

// Class != Session. class_sessions.event_session_id is nullable: an academic session exists
// without any Event. When linked, the event session must belong to an event owned by the same
// organizational unit as the class's academic unit (contextual coherence the schema cannot express).
// The concrete session location is never copied from the class default; it is resolved on demand
// by AcademicSessionLocationResolver (explicit event location prevails, else classes.location_id).
final class ClassSessionService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function schedule(int $actor, int $session, int $classId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $lessonId = null, ?int $eventSessionId = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'session.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forClass($classId, 'share'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($startsAt, $endsAt, $lessonId, $eventSessionId) {
                if ($endsAt <= $startsAt) {
                    throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'period']);
                }
                $class = $target->rows['class'];
                if (!$this->rt->policy->inSet('classes', 'teachable', (string) $class->status)) {
                    throw new AcademyError(AcademyReason::CLASS_NOT_TEACHABLE, ['class_id' => $target->classId]);
                }
                if ($lessonId !== null) {
                    $version = $this->rt->db->table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.module_id')->where('l.id', $lessonId)->sharedLock()->value('m.course_version_id');
                    if ($version === null || (int) $version !== (int) $class->course_version_id) {
                        throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'lessons']);
                    }
                }
                if ($eventSessionId !== null) {
                    $owner = $this->rt->db->table('event_sessions as es')->join('events as e', 'e.id', '=', 'es.event_id')->where('es.id', $eventSessionId)->sharedLock()->value('e.owner_unit_id');
                    if ($owner === null || (int) $owner !== (int) $target->rows['academic_unit']->unit_id) {
                        throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'event_sessions']);
                    }
                }
                $now = AcademyRuntime::ts($this->rt->now());
                $status = $this->rt->policy->initial('class_sessions');
                $id = (int) $this->rt->db->table('class_sessions')->insertGetId([
                    'class_id' => $target->classId, 'lesson_id' => $lessonId, 'starts_at' => AcademyRuntime::ts($startsAt), 'ends_at' => AcademyRuntime::ts($endsAt),
                    'event_session_id' => $eventSessionId, 'status' => $status, 'created_at' => $now,
                ]);
                $this->rt->audit($decision, $target, 'class_session.scheduled', 'class_sessions', $id, null, ['class_id' => $target->classId, 'lesson_id' => $lessonId, 'event_session_id' => $eventSessionId, 'status' => $status]);
                return ['class_session_id' => $id];
            },
            $overrideReason,
            $claimed
        );
    }

    // cancel / complete / reopen: policy-approved transitions only.
    public function transition(int $actor, int $session, int $classSessionId, string $toState, ?string $reason = null, ?int $expectedLockVersion = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'session.manage',
            $actor,
            $session,
            fn () => $this->rt->scope->forSession($classSessionId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($classSessionId, $toState, $reason, $expectedLockVersion) {
                $moved = $this->rt->machine->move('class_sessions', 'class_sessions', $classSessionId, $toState, $expectedLockVersion);
                $this->rt->audit($decision, $target, 'class_session.transitioned', 'class_sessions', $classSessionId, ['status' => $moved['from']], ['status' => $moved['to']], $reason);
                return ['class_session_id' => $classSessionId, 'from' => $moved['from'], 'to' => $moved['to']];
            },
            $overrideReason,
            $claimed
        );
    }

    public function effectiveLocation(int $actor, int $session, int $classSessionId, ?array $claimed = null): array
    {
        return $this->rt->read(
            'session.view',
            $actor,
            $session,
            fn () => $this->rt->scope->forSession($classSessionId, null),
            fn (AcademyTarget $target) => (new AcademicSessionLocationResolver($this->rt->db))->effectiveAcademicSessionLocation($classSessionId),
            $claimed
        );
    }
}
