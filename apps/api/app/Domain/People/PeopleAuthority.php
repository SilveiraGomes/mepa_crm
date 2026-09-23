<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\WaveFour\DomainClock;
use Illuminate\Database\Connection;

// Contextual authority resolver for People / Families (ADR-0017 D01).
//
//   permission (role_permissions, data_type PEOPLE, action = code)
//   + active institutional scope (user_role_scopes -> scopes, UNIT kind, optional descendants)
//   + persisted, currently valid context linking the Person to a unit inside that scope
//   = authority decision, whose context unit is the audit unit.
//
// The role and the scope of one grant are always evaluated jointly. A client-supplied unit is only
// a preference among contexts the server already found; it never creates authority. The Person has
// no unit: every unit below is read from domain records.
//
// Context tiers (ADR-0017 scope matrix):
//   GENERAL  - direct institutional contexts: person_unit_contexts, membership periods, ministerial
//              and function assignments, department memberships/appointments, governance bodies,
//              event registrations. Full People authority, subject to the permission.
//   ACADEMY  - Academy enrollments: contextual minimal projection only (edits stay in Academy).
//   CHILDREN - child profiles: minimal projection only (child data stays behind the Children gate).
// Temporal sources count only while [starts_at, ends_at) contains the database clock.
final class PeopleAuthority
{
    public const GENERAL = 'GENERAL';
    public const ACADEMY = 'ACADEMY';
    public const CHILDREN = 'CHILDREN';

    public function __construct(private Connection $db, private array $activeUserStatuses, private array $activeGrantStatuses)
    {
    }

    public function now(): string
    {
        return DomainClock::now($this->db)->format('Y-m-d H:i:s.u');
    }

    public function actor(int $user, int $session, bool $lock = false): PeopleActor
    {
        $q = fn ($query) => $lock ? $query->sharedLock() : $query;
        $row = $q($this->db->table('users')->where('id', $user))->first();
        $auth = $q($this->db->table('auth_sessions')->where('id', $session))->first();
        $now = $this->now();
        if (!$row || $row->archived_at !== null || !in_array((string) $row->status, $this->activeUserStatuses, true)
            || !$auth || (int) $auth->user_id !== $user || $auth->revoked_at !== null || (string) $auth->expires_at <= $now) {
            throw new PeopleError(PeopleReason::NOT_AUTHORIZED, ['reason' => 'actor']);
        }
        return new PeopleActor($user, $session, $row->person_id === null ? null : (int) $row->person_id);
    }

    /**
     * Scope roots of the actor's active grants carrying $permission: unit id => include_descendants.
     * @return array<int, int>
     */
    public function scopeRoots(PeopleActor $actor, string $permission, bool $lock = false): array
    {
        if ($this->activeGrantStatuses === []) {
            return [];
        }
        $query = $this->db->table('user_role_scopes as urs')
            ->join('roles as r', 'r.id', '=', 'urs.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('scopes as s', 's.id', '=', 'urs.scope_id')
            ->where('urs.user_id', $actor->user)->where('r.is_active', 1)
            ->where('p.code', $permission)->where('p.data_type', PeopleCatalog::DATA_TYPE)->whereColumn('p.action', 'p.code')
            ->where('s.scope_kind', 'UNIT')->whereNull('s.department_instance_id')->whereNotNull('s.unit_id');
        if ($lock) {
            $query->sharedLock();
        }
        $grants = $query->get(['urs.status', 'urs.starts_at', 'urs.ends_at', 's.unit_id', 's.include_descendants']);
        $now = $this->now();
        $roots = [];
        foreach ($grants as $grant) {
            if (in_array((string) $grant->status, $this->activeGrantStatuses, true)
                && (string) $grant->starts_at <= $now && ($grant->ends_at === null || (string) $grant->ends_at > $now)) {
                $roots[(int) $grant->unit_id] = max($roots[(int) $grant->unit_id] ?? 0, (int) $grant->include_descendants);
            }
        }
        ksort($roots);
        return $roots;
    }

    /**
     * Every organizational unit covered by the actor's grants of $permission.
     * @return array<int, true>
     */
    public function coveredUnits(PeopleActor $actor, string $permission, bool $lock = false): array
    {
        $roots = $this->scopeRoots($actor, $permission, $lock);
        if ($roots === []) {
            return [];
        }
        $seeds = [];
        $bindings = [];
        foreach ($roots as $unit => $expand) {
            $seeds[] = 'SELECT ? AS id, ? AS expand, 0 AS depth';
            $bindings[] = $unit;
            $bindings[] = $expand;
        }
        // depth guard: a corrupted parent cycle cannot recurse forever.
        $sql = 'WITH RECURSIVE covered AS (' . implode(' UNION ALL ', $seeds)
            . ' UNION ALL SELECT ou.id, covered.expand, covered.depth + 1 FROM organizational_units ou JOIN covered ON ou.parent_id = covered.id WHERE covered.expand = 1 AND covered.depth < 64)'
            . ' SELECT DISTINCT id FROM covered';
        $units = [];
        foreach ($this->db->select($sql, $bindings) as $row) {
            $units[(int) $row->id] = true;
        }
        return $units;
    }

    /** Units the actor may choose as working context for $permission (for UI selectors). */
    public function workingUnits(PeopleActor $actor, string $permission, int $limit = 200): array
    {
        $covered = array_keys($this->coveredUnits($actor, $permission));
        if ($covered === []) {
            return [];
        }
        return $this->db->table('organizational_units')->whereIn('id', $covered)->orderBy('name')->orderBy('id')->limit($limit)
            ->get(['id', 'public_id', 'code', 'name'])->all();
    }

    /**
     * Currently valid persisted contexts of one Person, in priority order.
     * @return list<array{source: string, id: int, unit: int, kind: string, tier: string}>
     */
    public function contexts(int $personId, bool $lock = false): array
    {
        $now = $this->now();
        $out = [];
        foreach ($this->sources() as [$source, $tier, $idColumn, $unitColumn, $kind, $from, $personColumn, $temporal]) {
            $sql = "SELECT {$idColumn} AS source_id, {$unitColumn} AS unit_id, {$kind} AS kind {$from} AND {$personColumn} = ?";
            $bindings = [$personId];
            if ($temporal !== null) {
                $sql .= " AND {$temporal}.starts_at <= ? AND ({$temporal}.ends_at IS NULL OR {$temporal}.ends_at > ?)";
                $bindings[] = $now;
                $bindings[] = $now;
            }
            $sql .= ' ORDER BY ' . $unitColumn . ', ' . $idColumn . ($lock ? ' FOR SHARE' : '');
            foreach ($this->db->select($sql, $bindings) as $row) {
                $out[] = ['source' => $source, 'id' => (int) $row->source_id, 'unit' => (int) $row->unit_id, 'kind' => (string) $row->kind, 'tier' => $tier];
            }
        }
        return $out;
    }

    /**
     * SQL predicate (for list/search/export) true when the Person aliased $alias has a currently valid
     * context of an allowed tier inside $units. Applied before pagination and projection.
     * @param array<int, true> $units
     * @return array{0: string, 1: array}
     */
    public function scopePredicate(string $alias, array $units, array $tiers = [self::GENERAL]): array
    {
        if ($units === []) {
            return ['1 = 0', []];
        }
        $now = $this->now();
        $unitList = implode(',', array_map('intval', array_keys($units)));
        $clauses = [];
        $bindings = [];
        foreach ($this->sources() as [, $tier, , $unitColumn, , $from, $personColumn, $temporal]) {
            if (!in_array($tier, $tiers, true)) {
                continue;
            }
            $clause = "EXISTS (SELECT 1 {$from} AND {$personColumn} = {$alias}.id AND {$unitColumn} IN ({$unitList})";
            if ($temporal !== null) {
                $clause .= " AND {$temporal}.starts_at <= ? AND ({$temporal}.ends_at IS NULL OR {$temporal}.ends_at > ?)";
                $bindings[] = $now;
                $bindings[] = $now;
            }
            $clauses[] = $clause . ')';
        }
        return ['(' . implode(' OR ', $clauses) . ')', $bindings];
    }

    /**
     * permission + scope + compatible context -> decision. $preferredUnit, when given, must be one of the
     * eligible context units (the client names its working context; the server confirms it).
     */
    public function authorize(PeopleActor $actor, string $permission, int $personId, ?int $preferredUnit = null, array $tiers = [self::GENERAL], bool $lock = false): PeopleDecision
    {
        $covered = $this->coveredUnits($actor, $permission, $lock);
        if ($covered === []) {
            throw new PeopleError(PeopleReason::NOT_AUTHORIZED, ['permission' => $permission]);
        }
        $eligible = array_values(array_filter($this->contexts($personId, $lock), fn (array $c): bool => in_array($c['tier'], $tiers, true) && isset($covered[$c['unit']])));
        if ($eligible === []) {
            throw new PeopleError(PeopleReason::OUT_OF_SCOPE, ['permission' => $permission]);
        }
        $chosen = $eligible[0];
        if ($preferredUnit !== null) {
            $match = array_values(array_filter($eligible, fn (array $c): bool => $c['unit'] === $preferredUnit));
            if ($match === []) {
                throw new PeopleError(PeopleReason::CONTEXT_UNIT_INVALID, ['permission' => $permission]);
            }
            $chosen = $match[0];
        }
        $others = array_values(array_unique(array_filter(array_map(fn (array $c): int => $c['unit'], $eligible), fn (int $u): bool => $u !== $chosen['unit'])));
        sort($others);
        return new PeopleDecision($permission, $personId, $chosen['unit'], $chosen['source'], $chosen['id'], $chosen['kind'], $chosen['tier'], $others);
    }

    /** Final, locking re-verification of a decision: the same unit must still authorize. */
    public function recheck(PeopleActor $actor, PeopleDecision $decision): void
    {
        try {
            $this->authorize($actor, $decision->permission, $decision->personId, $decision->unitId, [$decision->tier], true);
        } catch (PeopleError $e) {
            throw new PeopleError($e->reason === PeopleReason::CONTEXT_UNIT_INVALID ? PeopleReason::OUT_OF_SCOPE : $e->reason, ['stage' => 'final'], $e);
        }
    }

    /** True when the actor can see the Person at all (PEOPLE_VIEW, any tier). Used to choose 403 vs concealed 404. */
    public function canView(PeopleActor $actor, int $personId): bool
    {
        try {
            $this->authorize($actor, PeopleCatalog::PEOPLE_VIEW, $personId, null, [self::GENERAL, self::ACADEMY, self::CHILDREN]);
            return true;
        } catch (PeopleError) {
            return false;
        }
    }

    public function holds(PeopleActor $actor, string $permission, int $personId, array $tiers = [self::GENERAL]): bool
    {
        try {
            $this->authorize($actor, $permission, $personId, null, $tiers);
            return true;
        } catch (PeopleError) {
            return false;
        }
    }

    /** Effective People permission codes: held on at least one covered unit. Grants/roles never leave the server. */
    public function effectivePermissions(PeopleActor $actor): array
    {
        $codes = [];
        foreach (array_keys(PeopleCatalog::PERMISSIONS) as $code) {
            if ($this->scopeRoots($actor, $code) !== []) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    // [source, tier, id column, unit column, kind expression, FROM ... WHERE <static filter>, person column, temporal alias|null]
    private function sources(): array
    {
        return [
            ['PERSON_UNIT_CONTEXT', self::GENERAL, 'puc.id', 'puc.unit_id', 'puc.context_kind', "FROM person_unit_contexts puc WHERE puc.status = 'ACTIVE'", 'puc.person_id', 'puc'],
            ['MEMBERSHIP', self::GENERAL, 'mp.id', 'mp.congregation_id', "'MEMBERSHIP'", 'FROM membership_periods mp JOIN memberships m ON m.id = mp.membership_id WHERE 1 = 1', 'm.person_id', 'mp'],
            ['MINISTERIAL_ASSIGNMENT', self::GENERAL, 'ma.id', 'op.unit_id', "'MINISTERIAL_ASSIGNMENT'", 'FROM ministerial_assignments ma JOIN organizational_posts op ON op.id = ma.post_id WHERE 1 = 1', 'ma.person_id', 'ma'],
            ['FUNCTION_ASSIGNMENT', self::GENERAL, 'fa.id', 'fa.unit_id', "'FUNCTION_ASSIGNMENT'", 'FROM function_assignments fa WHERE 1 = 1', 'fa.person_id', 'fa'],
            ['DEPARTMENT_MEMBERSHIP', self::GENERAL, 'dm.id', 'di.unit_id', "'DEPARTMENT_MEMBERSHIP'", 'FROM department_memberships dm JOIN department_instances di ON di.id = dm.instance_id WHERE 1 = 1', 'dm.person_id', 'dm'],
            ['DEPARTMENT_APPOINTMENT', self::GENERAL, 'da.id', 'dmi.unit_id', "'DEPARTMENT_APPOINTMENT'", 'FROM department_appointments da JOIN department_posts dp ON dp.id = da.post_id JOIN department_instances dmi ON dmi.id = dp.instance_id WHERE 1 = 1', 'da.person_id', 'da'],
            ['GOVERNANCE_MEMBERSHIP', self::GENERAL, 'gm.id', 'gb.unit_id', "'GOVERNANCE_MEMBERSHIP'", 'FROM governance_body_memberships gm JOIN governance_bodies gb ON gb.id = gm.body_id WHERE 1 = 1', 'gm.person_id', 'gm'],
            ['EVENT_REGISTRATION', self::GENERAL, 'er.id', 'ev.owner_unit_id', "'EVENT_REGISTRATION'", 'FROM event_registrations er JOIN events ev ON ev.id = er.event_id WHERE 1 = 1', 'er.person_id', null],
            ['ACADEMY_ENROLLMENT', self::ACADEMY, 'e.id', 'au.unit_id', "'ACADEMY_ENROLLMENT'", 'FROM enrollments e JOIN classes c ON c.id = e.class_id JOIN academic_units au ON au.id = c.academic_unit_id WHERE 1 = 1', 'e.person_id', null],
            ['CHILD_PROFILE', self::CHILDREN, 'cp.id', 'cp.owner_unit_id', "'CHILD_PROFILE'", 'FROM child_profiles cp WHERE 1 = 1', 'cp.person_id', null],
        ];
    }
}
