<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use App\Domain\Events\EventPolicy;
use App\Domain\WaveFour\ChildParticipationSafetyGate;
use App\Domain\WaveFour\DomainClock;
use App\Domain\WaveFour\DomainPolicy;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

// Collaborator bundle + the one transactional boundary every Academy service uses.
//
// write(): business transaction (deadlock retry 5x, like the Wave 4 services)
//   1. derive the target from the database (root row locked as the service asked)
//   2. provisional authorization (plain reads, fail-fast)
//   3. claimed client context, if any, must equal the derived target (it is never authority)
//   4. business writes + required audit (same transaction: a failed audit rolls everything back)
//   5. FINAL authorization (FOR SHARE reads, decisive clock sampled after the locks) as the last
//      statement before commit, so a scope/assignment/session that lapsed or was revoked while the
//      operation waited on locks cannot commit. Authority the work attached to the decision (a
//      generic transition that produces a completion also needs the completion authority) is
//      re-verified the same way.
// Database errors are translated to domain errors after rollback; the original stays in getPrevious().
final class AcademyRuntime
{
    public function __construct(
        public Connection $db,
        public AcademyPolicy $policy,
        public AcademyAccess $access,
        public AcademyAuditWriter $auditor,
        public AcademyScopeResolver $scope,
        public AcademyStateMachine $machine,
        public AcademicPolicyResolver $resolver,
        public AcademyChildSafety $safety,
        public AcademyPersonResolver $people,
        public CompletionEligibilityGuard $completion,
        public AcademyResourceGuard $resources
    ) {
    }

    public static function make(Connection $db, AcademyPolicy $policy, EventPolicy $eventPolicy, DomainPolicy $childPolicy, ?AcademyAuditWriter $auditor = null): self
    {
        $gate = new ChildParticipationSafetyGate($db, $childPolicy, $eventPolicy);
        $access = new AcademyAccess($db, $eventPolicy, $policy);
        $resolver = new AcademicPolicyResolver($db, $policy);
        return new self(
            $db,
            $policy,
            $access,
            $auditor ?? new DatabaseAcademyAudit($db),
            new AcademyScopeResolver($db),
            new AcademyStateMachine($db, $policy),
            $resolver,
            new AcademyChildSafety($gate),
            new AcademyPersonResolver($db, $gate),
            new CompletionEligibilityGuard($access, $resolver),
            new AcademyResourceGuard($db)
        );
    }

    public function now(): DateTimeImmutable
    {
        return DomainClock::now($this->db);
    }

    public static function ts(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }

    public function write(string $operation, int $actor, int $session, callable $target, callable $work, ?string $overrideReason = null, ?array $claimed = null, ?string $duplicateAs = null): mixed
    {
        $op = AcademyOperation::get($operation);
        try {
            return $this->db->transaction(function () use ($op, $actor, $session, $target, $work, $overrideReason, $claimed) {
                $resolved = $target();
                $decision = $this->access->authorize($op, $actor, $session, $resolved, $overrideReason);
                $this->assertClaimed($resolved, $claimed);
                $result = $work($decision, $resolved);
                $this->access->authorize($op, $actor, $session, $resolved, null, true, $decision);
                foreach ($decision->attached() as [$attachedOp, $attachedDecision]) {
                    $this->access->authorize($attachedOp, $actor, $session, $resolved, null, true, $attachedDecision);
                }
                return $result;
            }, 5);
        } catch (QueryException $e) {
            throw $this->translate($e, $duplicateAs);
        }
    }

    // Read-only path: one authorization decision, no transaction, no lock held.
    public function read(string $operation, int $actor, int $session, callable $target, callable $work, ?array $claimed = null): mixed
    {
        $op = AcademyOperation::get($operation);
        try {
            $resolved = $target();
            $this->access->authorize($op, $actor, $session, $resolved);
            $this->assertClaimed($resolved, $claimed);
            return $work($resolved);
        } catch (QueryException $e) {
            throw $this->translate($e, null);
        }
    }

    // audit_logs.unit_id is one organizational unit: the first derived unit of the target.
    public function audit(AcademyDecision $decision, AcademyTarget $target, string $action, string $entity, int $id, ?array $before, ?array $after, ?string $reason = null): void
    {
        $this->auditor->record($decision, $target->unitIds[0], $action, $entity, $id, $before, $after, $reason);
    }

    // The client may say which class/unit it believes it is acting on. That claim is checked against
    // the derived target after authorization and can only ever cause a denial, never grant anything.
    private function assertClaimed(AcademyTarget $target, ?array $claimed): void
    {
        if ($claimed === null) {
            return;
        }
        $mismatch = (isset($claimed['class_id']) && (int) $claimed['class_id'] !== $target->classId)
            || (isset($claimed['academic_unit_id']) && (int) $claimed['academic_unit_id'] !== $target->academicUnitId)
            || (isset($claimed['unit_id']) && !in_array((int) $claimed['unit_id'], $target->unitIds, true));
        if ($mismatch) {
            throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['reason' => 'claimed_context']);
        }
    }

    private function translate(QueryException $e, ?string $duplicateAs): AcademyError
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $reason = match (true) {
            $code === 1062 => $duplicateAs ?? AcademyReason::STORAGE_CONFLICT,
            $code === 1452 => AcademyReason::REFERENCE_NOT_FOUND,
            in_array($code, [3819, 4025], true) => AcademyReason::INVARIANT_VIOLATION,
            default => AcademyReason::STORAGE_CONFLICT,
        };
        return new AcademyError($reason, ['db_error_code' => $code], $e);
    }
}
