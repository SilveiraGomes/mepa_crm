<?php

declare(strict_types=1);

namespace App\Domain\Academy;

use App\Domain\Events\EventPolicy;
use App\Domain\WaveFour\DomainClock;
use Illuminate\Database\Connection;

// ADR-0015 Decision B authorization chain, derived only from persisted rows:
//   actor -> permission (role_permissions) -> institutional scope (user_role_scopes/scopes)
//         -> target organizational unit(s) -> [class_instructors assignment when required]
// The role and the scope of one grant are always evaluated jointly (never the role of one grant
// with the scope of another). Same tables and predicates as DomainAccess::authorizeMany, with:
//   - a provisional mode ($lock = false): plain reads, fail-fast, holds no lock;
//   - a final mode ($lock = true): FOR SHARE reads, run as the last statement before commit,
//     with one authoritative UTC_TIMESTAMP(6) sampled AFTER every lock wait;
//   - explicit ACADEMY_ADMIN override (never automatic) that must carry a reason.
// A final check re-verifies exactly the grounds of the provisional decision ($recheck): it can
// never silently switch from DIRECT to ADMIN_OVERRIDE, because the audit already recorded DIRECT.
final class AcademyAccess
{
    public function __construct(private Connection $db, private EventPolicy $accessPolicy, private AcademyPolicy $policy)
    {
    }

    public function authorize(AcademyOperation $op, int $actor, int $session, AcademyTarget $target, ?string $overrideReason = null, bool $lock = false, ?AcademyDecision $recheck = null): AcademyDecision
    {
        $override = $recheck !== null ? $recheck->isOverride() : $overrideReason !== null;
        $reason = $recheck !== null ? $recheck->overrideReason : $overrideReason;
        if ($override) {
            if (!$op->adminOverride) {
                throw new AcademyError(AcademyReason::OVERRIDE_NOT_ALLOWED, ['operation' => $op->key]);
            }
            if ($reason === null || trim($reason) === '') {
                throw new AcademyError(AcademyReason::ADMIN_REASON_REQUIRED, ['operation' => $op->key]);
            }
        }
        if ($target->unitIds === []) {
            throw new AcademyError(AcademyReason::SCOPE_UNRESOLVED, ['operation' => $op->key]);
        }
        $permission = $override ? AcademyOperation::ADMIN : $op->permission;
        $q = fn ($query) => $lock ? $query->sharedLock() : $query;

        $user = $q($this->db->table('users')->where('id', $actor))->first();
        $auth = $q($this->db->table('auth_sessions')->where('id', $session))->first();
        $links = $q($this->db->table('user_role_scopes')->where('user_id', $actor)->orderBy('id'))->get();
        $candidates = [];
        foreach ($links as $link) {
            $scope = $q($this->db->table('scopes')->where('id', $link->scope_id))->first();
            // Minimal explicit UNIT scope. Department-only grants never authorize Academy operations.
            if (!$scope || $scope->scope_kind !== 'UNIT' || $scope->department_instance_id !== null) {
                continue;
            }
            $covered = [];
            foreach ($target->unitIds as $unit) {
                if ($this->covers($scope, $unit, $q)) {
                    $covered[] = $unit;
                }
            }
            $role = $q($this->db->table('roles')->where('id', $link->role_id))->first();
            $granted = $q($this->db->table('role_permissions as rp')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $link->role_id)
                ->where('p.code', $permission)
                ->where('p.data_type', AcademyOperation::DATA_TYPE)
                ->whereColumn('p.action', 'p.code'))->first(['p.id']) !== null;
            $candidates[] = [$link, $role, $granted, $covered];
        }

        // Every read above may have waited on a lock: sample the decisive clock only now.
        $now = DomainClock::now($this->db);
        $t = $now->format('Y-m-d H:i:s.u');
        if (!$user || $user->archived_at !== null || !$this->accessPolicy->permits('users', (string) $user->status)
            || !$auth || (int) $auth->user_id !== $actor || $auth->revoked_at !== null || $auth->expires_at <= $t) {
            throw new AcademyError(AcademyReason::NOT_AUTHORIZED, ['operation' => $op->key]);
        }
        $permitted = false;
        $covering = [];
        foreach ($candidates as [$link, $role, $granted, $covered]) {
            $active = $role && (int) $role->is_active === 1 && $this->accessPolicy->permits('auth_grants', (string) $link->status)
                && $link->starts_at <= $t && ($link->ends_at === null || $link->ends_at > $t);
            if (!$active || !$granted) {
                continue;
            }
            $permitted = true;
            foreach ($covered as $unit) {
                $covering[$unit] = true;
            }
        }
        if (!$permitted) {
            throw new AcademyError(AcademyReason::NOT_AUTHORIZED, ['operation' => $op->key]);
        }
        foreach ($target->unitIds as $unit) {
            if (!isset($covering[$unit])) {
                throw new AcademyError(AcademyReason::OUT_OF_SCOPE, ['operation' => $op->key]);
            }
        }
        if ($op->classAssignment && !$override) {
            $this->requireAssignment($user, $target, $t, $q);
        }

        return new AcademyDecision($override ? AcademyDecision::ADMIN_OVERRIDE : AcademyDecision::DIRECT, $permission, $actor, $session, $now, $override ? $reason : null, $user->person_id === null ? null : (int) $user->person_id, $op->key);
    }

    private function covers(object $scope, int $unit, callable $q): bool
    {
        if ((int) $scope->unit_id === $unit) {
            return true;
        }
        if ((int) $scope->include_descendants !== 1) {
            return false;
        }
        $seen = [];
        $cursor = $unit;
        while ($cursor) {
            if (isset($seen[$cursor])) {
                throw new AcademyError(AcademyReason::CONTEXT_MISMATCH, ['reason' => 'unit_cycle']);
            }
            $seen[$cursor] = true;
            if ($cursor === (int) $scope->unit_id) {
                return true;
            }
            $cursor = (int) $q($this->db->table('organizational_units')->where('id', $cursor))->value('parent_id');
        }
        return false;
    }

    // Class-level provenance: an active class_instructors row for the actor's own Person.
    private function requireAssignment(object $user, AcademyTarget $target, string $t, callable $q): void
    {
        if ($target->classId === null || $user->person_id === null) {
            throw new AcademyError(AcademyReason::CLASS_ASSIGNMENT_REQUIRED);
        }
        $instructor = $q($this->db->table('instructors')->where('person_id', $user->person_id))->first();
        if (!$instructor || !$this->policy->inSet('instructors', 'active', (string) $instructor->status)) {
            throw new AcademyError(AcademyReason::CLASS_ASSIGNMENT_REQUIRED);
        }
        $rows = $q($this->db->table('class_instructors')->where('class_id', $target->classId)->where('instructor_id', $instructor->id))->get();
        foreach ($rows as $row) {
            if ($this->policy->inSet('class_instructors', 'active', (string) $row->status) && $row->starts_at <= $t && ($row->ends_at === null || $row->ends_at > $t)) {
                return;
            }
        }
        throw new AcademyError(AcademyReason::CLASS_ASSIGNMENT_REQUIRED);
    }
}
