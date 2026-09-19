<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use Illuminate\Support\Str;

// Enrollment != Membership: the only identity used is people.id, an external student without a
// memberships row enrolls normally. Idempotency is natural: UNIQUE(person_id, class_id) is the
// logical key, so a repeated or racing request converges on ALREADY_ENROLLED (no extra
// idempotency-key infrastructure). Concurrency: the Person row is locked exclusively, which
// serializes every enrollment of that Person; the UNIQUE index is the last line of defence and its
// violation is translated to the same ALREADY_ENROLLED.
final class EnrollmentService
{
    public function __construct(private AcademyRuntime $rt)
    {
    }

    public function enroll(int $actor, int $session, int $classId, int|string $personRef, ?array $claimed = null): array
    {
        return $this->rt->write(
            'enrollment.create',
            $actor,
            $session,
            fn () => $this->rt->scope->forClass($classId, 'share'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($personRef) {
                $person = $this->rt->people->resolve($personRef);
                $this->rt->db->table('people')->where('id', $person)->lockForUpdate()->first();
                $this->rt->people->assertEligible($person);
                if (!$this->rt->policy->inSet('classes', 'enrollable', (string) $target->rows['class']->status)) {
                    throw new AcademyError(AcademyReason::CLASS_NOT_ENROLLABLE, ['class_id' => $target->classId]);
                }
                $cover = $this->rt->safety->lock($person);
                $existing = $this->rt->db->table('enrollments')->where('person_id', $person)->where('class_id', $target->classId)->lockForUpdate()->first();
                if ($existing) {
                    throw new AcademyError(AcademyReason::ALREADY_ENROLLED, ['enrollment_id' => (int) $existing->id]);
                }
                $now = AcademyRuntime::ts($this->rt->now());
                $publicId = (string) Str::ulid();
                $status = $this->rt->policy->initial('enrollments');
                $id = (int) $this->rt->db->table('enrollments')->insertGetId([
                    'public_id' => $publicId, 'person_id' => $person, 'class_id' => $target->classId, 'enrolled_at' => $now,
                    'status' => $status, 'approved_by' => null, 'created_at' => $now,
                ]);
                $this->rt->audit($decision, $target, 'enrollment.created', 'enrollments', $id, null, ['person_id' => $person, 'class_id' => $target->classId, 'status' => $status]);
                // Commit-time safety: the minor's consent/guardian cover is evaluated against a fresh clock.
                $this->rt->safety->assertCover($cover, $this->rt->now());
                return ['enrollment_id' => $id, 'public_id' => $publicId, 'person_id' => $person, 'outcome' => 'CREATED'];
            },
            null,
            $claimed,
            AcademyReason::ALREADY_ENROLLED
        );
    }

    // approve / activate / withdraw / cancel / reject: any status change goes through the state
    // machine, so only transitions the policy has APPROVED can execute. The policy also declares what
    // a transition DOES: one that produces a completion (EFFECT_COMPLETION) runs the same
    // CompletionEligibilityGuard as CompletionService (completion authority + criteria) before the
    // status changes; an undeclared effect is POLICY_NOT_CONFIGURED. ACADEMY_ENROLL alone never completes.
    public function transition(int $actor, int $session, int $enrollmentId, string $toState, ?string $reason = null, ?int $expectedLockVersion = null, ?array $claimed = null): array
    {
        return $this->rt->write(
            'enrollment.transition',
            $actor,
            $session,
            fn () => $this->rt->scope->forEnrollment($enrollmentId, 'update'),
            function (AcademyDecision $decision, AcademyTarget $target) use ($enrollmentId, $toState, $reason, $expectedLockVersion) {
                $enrollment = $target->rows['enrollment'];
                $effect = $this->rt->machine->approvedEffect('enrollments', (string) $enrollment->status, $toState);
                $criteria = $effect === AcademyPolicy::EFFECT_COMPLETION ? $this->rt->completion->assertEligible($decision, $target) : null;
                $approval = $this->rt->policy->inOptionalSet('enrollments', 'approval_targets', $toState);
                $cover = $approval ? $this->rt->safety->lock((int) $enrollment->person_id) : null;
                $extra = $approval ? ['approved_by' => $decision->actor] : [];
                $moved = $this->rt->machine->move('enrollments', 'enrollments', $enrollmentId, $toState, $expectedLockVersion, $extra, 'status', $effect);
                if ($criteria !== null) {
                    $this->rt->audit($decision, $target, 'enrollment.completed', 'enrollments', $enrollmentId, ['status' => $moved['from']], ['status' => $moved['to'], 'criteria' => array_column($criteria, 'type'), 'via' => 'enrollment.transition'], $reason);
                } else {
                    $this->rt->audit($decision, $target, 'enrollment.transitioned', 'enrollments', $enrollmentId, ['status' => $moved['from']], ['status' => $moved['to']], $reason);
                }
                $this->rt->safety->assertCover($cover, $this->rt->now());
                return ['enrollment_id' => $enrollmentId, 'from' => $moved['from'], 'to' => $moved['to'], 'lock_version' => $moved['lock_version']];
            },
            null,
            $claimed
        );
    }

    // Contextual, bounded listing: scope derived from the class; filter + pagination; no PII columns.
    public function listForClass(int $actor, int $session, int $classId, ?string $status = null, int $page = 1, int $perPage = 50, ?array $claimed = null): array
    {
        [$page, $perPage] = AcademyInput::page($page, $perPage);
        return $this->rt->read(
            'enrollment.view',
            $actor,
            $session,
            fn () => $this->rt->scope->forClass($classId, null),
            function (AcademyTarget $target) use ($classId, $status, $page, $perPage) {
                $query = $this->rt->db->table('enrollments')->where('class_id', $classId);
                if ($status !== null) {
                    $query->where('status', $status);
                }
                $total = (clone $query)->count();
                $rows = $query->orderBy('id')->forPage($page, $perPage)->get(['id', 'public_id', 'person_id', 'status', 'enrolled_at', 'lock_version']);
                return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $rows->map(fn ($r) => (array) $r)->all()];
            },
            $claimed
        );
    }
}
