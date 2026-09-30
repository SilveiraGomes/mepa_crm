<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use DateTimeImmutable;

// Instructor = a Person with an instructors profile (no membership, no new Person type, no
// ecclesiastical office). Assignments live in class_instructors, the class-level provenance of
// ADR-0015 Decision B. There is no UNIQUE index on (class, instructor), so the aggregate invariant
// "no overlapping active assignment" is enforced under an exclusive lock on the class row (the
// preexisting anchor), which makes concurrent duplicate assignments converge on ALREADY_ASSIGNED.
// Assigning needs ACADEMY_MANAGE (or an explicit, audited ACADEMY_ADMIN override): a teacher
// cannot assign themselves.
final class InstructorAssignmentService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function assign(int $actor, int $session, int $classId, int|string $personRef, ?DateTimeImmutable $startsAt = null, ?DateTimeImmutable $endsAt = null, ?string $reason = null, ?string $sourceDocument = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'instructor.assign',
            $actor,
            $session,
            fn () => $this->rt->scope->forClass($classId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($personRef, $startsAt, $endsAt, $reason, $sourceDocument) {
                $person = $this->rt->people->resolve($personRef);
                $this->rt->db->table('people')->where('id', $person)->lockForUpdate()->first();
                $this->rt->people->assertEligible($person);
                $start = $startsAt ?? $this->rt->now();
                if ($endsAt !== null && $endsAt <= $start) {
                    throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'period']);
                }
                // P08-D-F01: resolved from its public_id only now, after the class authority was decided.
                $sourceDocumentId = $sourceDocument === null ? null : (int) $this->rt->resources->legalDocument($target, $sourceDocument)->id;
                $instructor = $this->rt->db->table('instructors')->where('person_id', $person)->lockForUpdate()->first();
                $now = AcademyRuntime::ts($this->rt->now());
                if (!$instructor) {
                    $instructorId = (int) $this->rt->db->table('instructors')->insertGetId(['person_id' => $person, 'status' => $this->rt->policy->initial('instructors'), 'created_at' => $now]);
                    $instructor = $this->rt->db->table('instructors')->where('id', $instructorId)->lockForUpdate()->first();
                }
                if (!$this->rt->policy->inSet('instructors', 'active', (string) $instructor->status)) {
                    throw new AcademyError(AcademyReason::INSTRUCTOR_NOT_ACTIVE, ['instructor_id' => (int) $instructor->id]);
                }
                $startS = AcademyRuntime::ts($start);
                $endS = $endsAt === null ? null : AcademyRuntime::ts($endsAt);
                $active = $this->rt->policy->set('class_instructors', 'active');
                $overlap = $this->rt->db->table('class_instructors')->where('class_id', $target->classId)->where('instructor_id', $instructor->id)->whereIn('status', $active)
                    ->where(function ($q) use ($endS) {
                        if ($endS !== null) {
                            $q->where('starts_at', '<', $endS);
                        }
                    })
                    ->where(function ($q) use ($startS) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>', $startS);
                    })->lockForUpdate()->first();
                if ($overlap) {
                    throw new AcademyError(AcademyReason::ALREADY_ASSIGNED, ['assignment_id' => (int) $overlap->id]);
                }
                $status = $this->rt->policy->initial('class_instructors');
                $id = (int) $this->rt->db->table('class_instructors')->insertGetId([
                    'class_id' => $target->classId, 'instructor_id' => $instructor->id, 'status' => $status, 'starts_at' => $startS, 'ends_at' => $endS,
                    'reason' => $reason, 'source_document_id' => $sourceDocumentId, 'created_at' => $now,
                ]);
                $this->rt->audit($decision, $target, 'class_instructor.assigned', 'class_instructors', $id, null, ['class_id' => $target->classId, 'instructor_id' => (int) $instructor->id, 'starts_at' => $startS, 'ends_at' => $endS], $reason);
                return ['assignment_id' => $id, 'instructor_id' => (int) $instructor->id, 'outcome' => 'ASSIGNED'];
            },
            $overrideReason,
            $claimed
        );
    }

    // Ends an assignment now (never deletes it). The status change is a policy-approved transition.
    public function end(int $actor, int $session, int $assignmentId, ?string $reason = null, ?int $expectedLockVersion = null, ?string $overrideReason = null, ?array $claimed = null): array
    {
        $classId = $this->rt->db->table('class_instructors')->where('id', $assignmentId)->value('class_id');
        if ($classId === null) {
            throw new AcademyError(AcademyReason::TARGET_NOT_FOUND, ['entity' => 'class_instructors']);
        }
        return $this->rt->write(
            'instructor.end',
            $actor,
            $session,
            fn () => $this->rt->scope->forClass((int) $classId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($assignmentId, $reason, $expectedLockVersion) {
                $row = $this->rt->db->table('class_instructors')->where('id', $assignmentId)->lockForUpdate()->first();
                if (!$row || (int) $row->class_id !== $target->classId) {
                    throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['entity' => 'class_instructors']);
                }
                $now = $this->rt->now();
                $minimum = (new DateTimeImmutable($row->starts_at, new \DateTimeZone('UTC')))->modify('+1 microsecond');
                $endsAt = AcademyRuntime::ts(max($now, $minimum));
                $extra = ['ends_at' => $row->ends_at === null ? $endsAt : min($row->ends_at, $endsAt)];
                $moved = $this->rt->machine->move('class_instructors', 'class_instructors', $assignmentId, $this->rt->policy->target('class_instructors', 'ended'), $expectedLockVersion, $extra);
                $this->rt->audit($decision, $target, 'class_instructor.removed', 'class_instructors', $assignmentId, ['status' => $moved['from'], 'ends_at' => $row->ends_at], ['status' => $moved['to'], 'ends_at' => $extra['ends_at']], $reason);
                return ['assignment_id' => $assignmentId, 'from' => $moved['from'], 'to' => $moved['to']];
            },
            $overrideReason,
            $claimed
        );
    }

    public function activeAssignments(int $actor, int $session, int $classId, ?array $claimed = null): array
    {
        return $this->rt->read(
            'instructor.view',
            $actor,
            $session,
            fn () => $this->rt->scope->forClass($classId, null),
            function (AcademyTarget $target) {
                $t = AcademyRuntime::ts($this->rt->now());
                return $this->rt->db->table('class_instructors as ci')->join('instructors as i', 'i.id', '=', 'ci.instructor_id')->join('people as p', 'p.id', '=', 'i.person_id')
                    ->where('ci.class_id', $target->classId)->whereIn('ci.status', $this->rt->policy->set('class_instructors', 'active'))
                    ->where('starts_at', '<=', $t)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $t))
                    ->orderBy('ci.id')->limit(200)->get(['ci.id as assignment_id', 'ci.status', 'ci.starts_at', 'ci.ends_at', 'ci.lock_version', 'p.public_id as person_public_id', 'p.full_name as display_name'])->map(fn ($r) => (array) $r)->all();
            },
            $claimed
        );
    }
}
