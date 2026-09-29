<?php

declare(strict_types=1);

namespace App\Domain\People;

use Illuminate\Support\Str;

// Households (ADR-0017 D01/D02). A Household has NO owning unit: it is reached through its currently
// active members, and only the members the actor can see are projected. Household-level operations need
// the permission over at least one active member (whose context is the audit unit); membership changes
// additionally need the permission over every Person affected. Lifecycle ACTIVE / INACTIVE / ARCHIVED,
// archive and restore need a reason, and nothing is ever deleted. A Household without active members
// is out of the operational listings until it is regularised.
final class HouseholdService
{
    private PersonRecords $people;

    // action => [from states, to, reason required]
    private const TRANSITIONS = [
        'inactivate' => [[PeopleCatalog::HOUSEHOLD_ACTIVE], PeopleCatalog::HOUSEHOLD_INACTIVE, false],
        'reactivate' => [[PeopleCatalog::HOUSEHOLD_INACTIVE], PeopleCatalog::HOUSEHOLD_ACTIVE, false],
        'archive' => [[PeopleCatalog::HOUSEHOLD_ACTIVE, PeopleCatalog::HOUSEHOLD_INACTIVE], PeopleCatalog::HOUSEHOLD_ARCHIVED, true],
        'restore' => [[PeopleCatalog::HOUSEHOLD_ARCHIVED], PeopleCatalog::HOUSEHOLD_ACTIVE, true],
    ];

    public function __construct(private PeopleRuntime $rt)
    {
        $this->people = new PersonRecords($rt);
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (PeopleGuard $guard) use ($q): array {
            $covered = $guard->covered(PeopleCatalog::HOUSEHOLD_VIEW);
            [$predicate, $bindings] = $this->rt->authority->scopePredicate('p', $covered);
            $now = $this->rt->ts();
            $query = $this->rt->db->table('households as h')->whereExists(function ($sub) use ($predicate, $bindings, $now): void {
                $sub->selectRaw('1')->from('household_members as hm')->join('people as p', 'p.id', '=', 'hm.person_id')
                    ->whereColumn('hm.household_id', 'h.id')->where('hm.status', PeopleCatalog::LINK_ACTIVE)
                    ->where('hm.starts_at', '<=', $now)->where(fn ($w) => $w->whereNull('hm.ends_at')->orWhere('hm.ends_at', '>', $now))
                    ->whereRaw($predicate, $bindings);
            });
            if (isset($q['search']) && trim((string) $q['search']) !== '') {
                $term = '%' . PersonRecords::escapeLike(trim((string) $q['search'])) . '%';
                $query->where(fn ($w) => $w->where('h.name', 'like', $term)->orWhere('h.code', 'like', $term));
            }
            if (isset($q['status'])) {
                $query->where('h.status', (string) $q['status']);
            }
            $perPage = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $total = (clone $query)->count();
            $rows = $query->orderBy('h.name')->orderBy('h.id')->forPage($page, $perPage)->get(['h.*'])->all();
            return ['items' => array_map(fn (object $h): array => $this->summary($h), $rows), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        $id = $this->householdId($publicId);
        return $this->rt->read($user, $session, fn (PeopleGuard $guard): array => $this->project($guard, $id));
    }

    public function forPerson(int $user, int $session, string $personPublicId): array
    {
        $personId = $this->people->id($personPublicId);
        return $this->rt->read($user, $session, function (PeopleGuard $guard) use ($personId): array {
            $guard->require(PeopleCatalog::HOUSEHOLD_VIEW, $personId);
            $person = $this->people->row($personId);
            if ($this->people->isProtected($person, isset($this->people->childProfiles([$personId])[$personId]))) {
                throw new PeopleError(PeopleReason::MINOR_PROTECTED, ['area' => 'households']);
            }
            $now = $this->rt->ts();
            $rows = $this->activeMembers()->where('hm.person_id', $personId)->where('hm.starts_at', '<=', $now)
                ->where(fn ($w) => $w->whereNull('hm.ends_at')->orWhere('hm.ends_at', '>', $now))
                ->join('households as h', 'h.id', '=', 'hm.household_id')->orderBy('h.name')->get(['h.*', 'hrt.code as role_code', 'hrt.name as role_name'])->all();
            return array_map(fn (object $r): array => $this->summary($r) + ['role' => (string) $r->role_code, 'role_name' => (string) $r->role_name], $rows);
        });
    }

    public function create(int $user, int $session, array $in): array
    {
        $publicId = $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($in): string {
            $personId = $this->people->id($in['reference_person'] ?? null);
            $decision = $guard->require(PeopleCatalog::HOUSEHOLD_MANAGE, $personId);
            $this->assertMemberEligible($this->people->row($personId, true));
            $role = $this->role((string) ($in['role'] ?? 'REFERENCE_PERSON'));
            $now = $this->rt->ts();
            $publicId = (string) Str::ulid();
            $householdId = (int) $this->rt->db->table('households')->insertGetId([
                'public_id' => $publicId,
                'code' => 'AGR-' . (string) Str::ulid(),
                'name' => $this->name($in['name'] ?? null),
                'address_id' => null,
                'status' => PeopleCatalog::HOUSEHOLD_ACTIVE,
                'created_at' => $now,
                'lock_version' => 0,
            ]);
            $memberId = $this->insertMember($householdId, $personId, (int) $role->id, $now);
            $correlation = $this->rt->audit->record($actor, $decision, 'HOUSEHOLD_CREATED', 'households', $householdId, null, ['status' => PeopleCatalog::HOUSEHOLD_ACTIVE, 'owning_unit' => null]);
            $this->rt->audit->record($actor, $decision, 'HOUSEHOLD_MEMBER_ADDED', 'household_members', $memberId, null, ['household_entity' => $householdId, 'person_entity' => $personId, 'role' => (string) $role->code], null, $correlation);
            return $publicId;
        });
        return $this->detail($user, $session, $publicId);
    }

    public function update(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->householdId($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $in): void {
            $decision = $this->authorizeHousehold($guard, $id, PeopleCatalog::HOUSEHOLD_MANAGE);
            $household = $this->lockHousehold($id, $in['lock_version'] ?? null);
            $name = $this->name($in['name'] ?? null);
            if ($name === $household->name) {
                return;
            }
            $this->versioned($household, ['name' => $name]);
            $this->rt->audit->record($actor, $decision, 'HOUSEHOLD_UPDATED', 'households', $id, ['lock_version' => (int) $household->lock_version], ['changed' => ['name'], 'lock_version' => (int) $household->lock_version + 1]);
        });
        return $this->detail($user, $session, $publicId);
    }

    public function transition(int $user, int $session, string $publicId, string $action, ?string $reason, mixed $lockVersion): array
    {
        if (!isset(self::TRANSITIONS[$action])) {
            throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['action' => $action]);
        }
        [$from, $to, $needsReason] = self::TRANSITIONS[$action];
        $id = $this->householdId($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $from, $to, $needsReason, $reason, $lockVersion): void {
            $decision = $this->authorizeHousehold($guard, $id, PeopleCatalog::HOUSEHOLD_MANAGE);
            $household = $this->lockHousehold($id, $lockVersion);
            if (!in_array($household->status, $from, true)) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['from' => $household->status, 'to' => $to]);
            }
            $reason = $reason === null ? null : trim($reason);
            if ($needsReason && ($reason === null || mb_strlen($reason) < 3)) {
                throw new PeopleError(PeopleReason::REASON_REQUIRED);
            }
            $this->versioned($household, ['status' => $to]);
            $this->rt->audit->record($actor, $decision, 'HOUSEHOLD_STATUS_CHANGED', 'households', $id, ['status' => $household->status, 'lock_version' => (int) $household->lock_version], ['status' => $to, 'lock_version' => (int) $household->lock_version + 1], $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    public function addMember(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->householdId($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $in): void {
            $decision = $this->authorizeHousehold($guard, $id, PeopleCatalog::HOUSEHOLD_MANAGE);
            $personId = $this->people->id($in['person'] ?? null);
            $personDecision = $guard->require(PeopleCatalog::HOUSEHOLD_MANAGE, $personId);
            $household = $this->rt->db->table('households')->where('id', $id)->lockForUpdate()->first();
            if ($household->status !== PeopleCatalog::HOUSEHOLD_ACTIVE) {
                throw new PeopleError(PeopleReason::HOUSEHOLD_NOT_ACTIVE);
            }
            $this->assertMemberEligible($this->people->row($personId, true));
            $role = $this->role((string) ($in['role'] ?? ''));
            $now = $this->rt->ts();
            $duplicate = $this->rt->db->table('household_members')->where('household_id', $id)->where('person_id', $personId)->where('status', PeopleCatalog::LINK_ACTIVE)
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))->exists();
            if ($duplicate) {
                throw new PeopleError(PeopleReason::ALREADY_MEMBER);
            }
            $memberId = $this->insertMember($id, $personId, (int) $role->id, $now);
            $this->rt->audit->record($actor, $decision, 'HOUSEHOLD_MEMBER_ADDED', 'household_members', $memberId, null, ['household_entity' => $id, 'person_entity' => $personId, 'role' => (string) $role->code, 'person_context_unit' => $personDecision->unitId]);
        });
        return $this->detail($user, $session, $publicId);
    }

    /** No body: removing the last active member makes the Household unreachable (ADR-0017 D01). */
    public function endMember(int $user, int $session, string $publicId, string $ref, ?string $reason): void
    {
        $id = $this->householdId($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $ref, $reason): void {
            $decision = $this->authorizeHousehold($guard, $id, PeopleCatalog::HOUSEHOLD_MANAGE);
            $candidates = $this->rt->db->table('household_members')->where('household_id', $id)->pluck('id')->all();
            $memberId = $this->rt->refs->resolve('household_members', $ref, $candidates);
            $member = $memberId === null ? null : $this->rt->db->table('household_members')->where('id', $memberId)->lockForUpdate()->first();
            if (!$member) {
                throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'household_members']);
            }
            $guard->require(PeopleCatalog::HOUSEHOLD_MANAGE, (int) $member->person_id);
            if ($member->status !== PeopleCatalog::LINK_ACTIVE) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['entity' => 'household_members']);
            }
            $household = $this->rt->db->table('households')->where('id', $id)->lockForUpdate()->first();
            if ($household->status !== PeopleCatalog::HOUSEHOLD_ACTIVE) {
                throw new PeopleError(PeopleReason::HOUSEHOLD_NOT_ACTIVE);
            }
            $reason = $reason === null ? null : trim($reason);
            $updated = $this->rt->db->table('household_members')->where('id', $member->id)->where('lock_version', $member->lock_version)
                ->update(['status' => PeopleCatalog::LINK_INACTIVE, 'ends_at' => $this->rt->ts(), 'reason' => $reason, 'lock_version' => (int) $member->lock_version + 1]);
            if ($updated !== 1) {
                throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'household_members']);
            }
            $this->rt->audit->record($actor, $decision, 'HOUSEHOLD_MEMBER_REMOVED', 'household_members', (int) $member->id, ['status' => PeopleCatalog::LINK_ACTIVE], ['status' => PeopleCatalog::LINK_INACTIVE, 'household_entity' => $id, 'person_entity' => (int) $member->person_id], $reason);
        });
    }

    // ---- internals -------------------------------------------------------------------------------

    private function project(PeopleGuard $guard, int $id): array
    {
        $this->authorizeHousehold($guard, $id, PeopleCatalog::HOUSEHOLD_VIEW);
        $household = $this->rt->db->table('households')->where('id', $id)->first();
        $now = $this->rt->ts();
        $rows = $this->activeMembers()->where('hm.household_id', $id)->where('hm.starts_at', '<=', $now)
            ->where(fn ($w) => $w->whereNull('hm.ends_at')->orWhere('hm.ends_at', '>', $now))
            ->join('people as p', 'p.id', '=', 'hm.person_id')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')
            ->orderBy('hm.starts_at')->orderBy('hm.id')->get(['hm.id as member', 'hm.starts_at', 'hrt.code as role_code', 'hrt.name as role_name', 'p.*', 'ps.code as status_code'])->all();
        $children = $this->people->childProfiles(array_map(fn (object $r): int => (int) $r->id, $rows));
        $members = [];
        $canManage = false;
        foreach ($rows as $row) {
            // Only Persons the actor can see through the household permission are revealed (mixed households).
            if (!$guard->holds(PeopleCatalog::HOUSEHOLD_VIEW, (int) $row->id)) {
                continue;
            }
            $canManage = $canManage || $guard->holds(PeopleCatalog::HOUSEHOLD_MANAGE, (int) $row->id);
            $minimal = $this->people->minimal($row, isset($children[(int) $row->id]));
            $members[] = [
                'ref' => $this->rt->refs->for('household_members', (int) $row->member),
                'person' => ['public_id' => $minimal['public_id'], 'display_name' => $minimal['display_name'], 'age_band' => $minimal['age_band'], 'protected_minor' => $minimal['protected_minor']],
                'role' => (string) $row->role_code,
                'role_name' => (string) $row->role_name,
                'starts_at' => (string) $row->starts_at,
            ];
        }
        return $this->summary($household) + ['members' => $members, 'capabilities' => ['can_manage' => $canManage]];
    }

    /** Authority over a Household = the permission over at least one currently active member. */
    private function authorizeHousehold(PeopleGuard $guard, int $id, string $permission): PeopleDecision
    {
        $now = $this->rt->ts();
        $personIds = $this->rt->db->table('household_members')->where('household_id', $id)->where('status', PeopleCatalog::LINK_ACTIVE)
            ->where('starts_at', '<=', $now)->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('id')->pluck('person_id')->map(fn ($p) => (int) $p)->unique()->values()->all();
        $visible = false;
        foreach ($personIds as $personId) {
            try {
                $decision = $this->rt->authority->authorize($guard->actor, $permission, $personId);
                $guard->record($decision);
                return $decision;
            } catch (PeopleError) {
                $visible = $visible || $this->rt->authority->holds($guard->actor, PeopleCatalog::HOUSEHOLD_VIEW, $personId);
            }
        }
        throw new PeopleError($visible ? PeopleReason::FORBIDDEN : PeopleReason::TARGET_NOT_FOUND, ['entity' => 'households']);
    }

    private function householdId(mixed $publicId): int
    {
        if (!is_string($publicId) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $publicId) !== 1) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'households']);
        }
        $id = $this->rt->db->table('households')->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'households']);
        }
        return (int) $id;
    }

    private function lockHousehold(int $id, mixed $lockVersion): object
    {
        $household = $this->rt->db->table('households')->where('id', $id)->lockForUpdate()->first();
        if (!is_numeric($lockVersion) || (int) $lockVersion !== (int) $household->lock_version) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'households']);
        }
        return $household;
    }

    private function versioned(object $household, array $changes): void
    {
        $updated = $this->rt->db->table('households')->where('id', $household->id)->where('lock_version', $household->lock_version)
            ->update($changes + ['lock_version' => (int) $household->lock_version + 1]);
        if ($updated !== 1) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'households']);
        }
    }

    private function activeMembers()
    {
        return $this->rt->db->table('household_members as hm')->join('household_role_types as hrt', 'hrt.id', '=', 'hm.role_type_id')->where('hm.status', PeopleCatalog::LINK_ACTIVE);
    }

    private function insertMember(int $householdId, int $personId, int $roleId, string $now): int
    {
        return (int) $this->rt->db->table('household_members')->insertGetId([
            'household_id' => $householdId, 'person_id' => $personId, 'role_type_id' => $roleId, 'status' => PeopleCatalog::LINK_ACTIVE,
            'starts_at' => $now, 'ends_at' => null, 'reason' => null, 'source_document_id' => null, 'created_at' => $now, 'lock_version' => 0,
        ]);
    }

    private function role(string $code): object
    {
        $role = in_array($code, array_keys(PeopleCatalog::HOUSEHOLD_ROLES), true)
            ? $this->rt->db->table('household_role_types')->where('code', $code)->where('is_active', 1)->first() : null;
        if (!$role) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'role']);
        }
        return $role;
    }

    private function assertMemberEligible(object $person): void
    {
        if ($person->status_code === PeopleCatalog::PERSON_DECEASED) {
            throw new PeopleError(PeopleReason::PERSON_DECEASED);
        }
    }

    private function name(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > 191) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'name']);
        }
        return $name;
    }

    private function summary(object $h): array
    {
        return ['public_id' => (string) $h->public_id, 'code' => (string) $h->code, 'name' => $h->name === null ? null : (string) $h->name, 'status' => (string) $h->status, 'lock_version' => (int) $h->lock_version];
    }
}
