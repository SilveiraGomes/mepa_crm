<?php

declare(strict_types=1);

namespace App\Domain\People;

use Illuminate\Support\Str;

// People: list, search, detail, create, update and lifecycle (ADR-0017 D01/D02/D03).
// A Person has no owning unit. Creation writes the first authorizing context (ONBOARDING) in the same
// transaction, at a unit the server confirms inside the actor's PEOPLE_CREATE scope. No membership and
// no member number are created, changed or simulated: a non-member Person is a complete Person.
final class PersonService
{
    private PersonRecords $people;

    // action => [allowed from, to, reason required]
    private const TRANSITIONS = [
        'inactivate' => [[PeopleCatalog::PERSON_ACTIVE], PeopleCatalog::PERSON_INACTIVE, false],
        'reactivate' => [[PeopleCatalog::PERSON_INACTIVE], PeopleCatalog::PERSON_ACTIVE, false],
        'mark-deceased' => [[PeopleCatalog::PERSON_ACTIVE, PeopleCatalog::PERSON_INACTIVE], PeopleCatalog::PERSON_DECEASED, true],
    ];

    public function __construct(private PeopleRuntime $rt)
    {
        $this->people = new PersonRecords($rt);
    }

    public function context(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (PeopleGuard $guard, PeopleActor $actor): array {
            // The actor's own capability projection: an authenticated user without any People permission
            // gets an empty list (no error), so the shared shell never produces noise for other verticals.
            $permissions = $this->rt->authority->effectivePermissions($actor);
            $units = in_array(PeopleCatalog::PEOPLE_CREATE, $permissions, true)
                ? array_map(fn (object $u): array => ['public_id' => (string) $u->public_id, 'code' => (string) $u->code, 'name' => (string) $u->name], $this->rt->authority->workingUnits($actor, PeopleCatalog::PEOPLE_CREATE))
                : [];
            return ['permissions' => $permissions, 'working_units' => $units];
        });
    }

    public function catalogs(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (PeopleGuard $guard, PeopleActor $actor): array {
            if ($this->rt->authority->effectivePermissions($actor) === []) {
                throw new PeopleError(PeopleReason::NOT_AUTHORIZED, ['operation' => 'catalogs']);
            }
            $db = $this->rt->db;
            $simple = fn (string $table) => array_map(fn (object $r): array => ['code' => (string) $r->code, 'name' => (string) $r->name], $db->table($table)->where('is_active', 1)->orderBy('id')->get(['code', 'name'])->all());
            $areas = $db->table('territorial_areas as t')->leftJoin('territorial_areas as parent', 'parent.id', '=', 't.parent_id')
                ->orderBy('t.name')->limit(2000)->get(['t.code', 't.name', 'parent.code as parent_code'])
                ->map(fn (object $r): array => ['code' => (string) $r->code, 'name' => (string) $r->name, 'parent_code' => $r->parent_code === null ? null : (string) $r->parent_code])->all();
            return [
                'person_statuses' => $simple('person_statuses'),
                'birth_precisions' => BirthDate::PRECISIONS,
                'contact_types' => $simple('contact_types'),
                'household_roles' => $simple('household_role_types'),
                'household_statuses' => PeopleCatalog::HOUSEHOLD_STATUSES,
                'relationship_types' => array_map(fn (object $r): array => ['code' => (string) $r->code, 'name' => (string) $r->name, 'semantics' => (string) $r->semantics],
                    $db->table('relationship_types')->where('is_active', 1)->orderBy('id')->get(['code', 'name', 'semantics'])->all()),
                'territorial_areas' => $areas,
            ];
        });
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (PeopleGuard $guard) use ($q): array {
            $covered = $guard->covered(PeopleCatalog::PEOPLE_VIEW);
            [$predicate, $bindings] = $this->rt->authority->scopePredicate('p', $covered);
            $query = $this->rt->db->table('people as p')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')->whereRaw($predicate, $bindings);
            if (isset($q['search']) && trim((string) $q['search']) !== '') {
                $query->where('p.full_name', 'like', '%' . PersonRecords::escapeLike(trim((string) $q['search'])) . '%');
            }
            if (isset($q['status'])) {
                $query->where('ps.code', (string) $q['status']);
            }
            if (isset($q['contact']) && trim((string) $q['contact']) !== '') {
                // Exact contact search through the blind index only, and only inside the sensitive scope.
                $sensitive = $this->rt->authority->coveredUnits($guard->actor, PeopleCatalog::PEOPLE_SENSITIVE_VIEW);
                if ($sensitive === []) {
                    throw new PeopleError(PeopleReason::SENSITIVE_RESTRICTED, ['operation' => 'contact_search']);
                }
                [$sensitivePredicate, $sensitiveBindings] = $this->rt->authority->scopePredicate('p', $sensitive);
                $indexes = $this->rt->crypto()->blindIndexes(ContactService::normalizeForIndex((string) $q['contact']));
                $query->whereRaw($sensitivePredicate, $sensitiveBindings)->whereExists(function ($sub) use ($indexes): void {
                    $sub->selectRaw('1')->from('person_contacts as pc')->whereColumn('pc.person_id', 'p.id')->where('pc.status', PeopleCatalog::LINK_ACTIVE)->whereIn('pc.value_blind_index', $indexes);
                });
            }
            $perPage = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $total = (clone $query)->count();
            $sort = ($q['sort'] ?? 'name') === 'created' ? ['p.created_at', 'desc'] : ['p.full_name', 'asc'];
            $rows = $query->orderBy($sort[0], $sort[1])->orderBy('p.id')->forPage($page, $perPage)->get(['p.*', 'ps.code as status_code'])->all();
            $children = $this->people->childProfiles(array_map(fn (object $r): int => (int) $r->id, $rows));
            $items = array_map(fn (object $r): array => $this->people->minimal($r, isset($children[(int) $r->id])), $rows);
            return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
        });
    }

    /** Minimal selector for household/relationship pickers: only People the purpose permission covers. */
    public function selector(int $user, int $session, string $purpose, string $search): array
    {
        $permission = match ($purpose) {
            'household' => PeopleCatalog::HOUSEHOLD_MANAGE,
            'relationship' => PeopleCatalog::RELATIONSHIP_MANAGE,
            default => throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'purpose']),
        };
        return $this->rt->read($user, $session, function (PeopleGuard $guard) use ($permission, $search): array {
            $covered = $guard->covered($permission);
            [$predicate, $bindings] = $this->rt->authority->scopePredicate('p', $covered);
            $rows = $this->rt->db->table('people as p')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')->whereRaw($predicate, $bindings)
                ->where('p.full_name', 'like', '%' . PersonRecords::escapeLike(trim($search)) . '%')
                ->orderBy('p.full_name')->orderBy('p.id')->limit(20)->get(['p.*', 'ps.code as status_code'])->all();
            $children = $this->people->childProfiles(array_map(fn (object $r): int => (int) $r->id, $rows));
            return array_map(function (object $r) use ($children): array {
                $m = $this->people->minimal($r, isset($children[(int) $r->id]));
                return ['public_id' => $m['public_id'], 'display_name' => $m['display_name'], 'age_band' => $m['age_band'], 'status' => $m['status']];
            }, $rows);
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, fn (PeopleGuard $guard): array => $this->project($guard, $this->people->id($publicId)));
    }

    public function create(int $user, int $session, array $in): array
    {
        $id = $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($in): int {
            $covered = $guard->covered(PeopleCatalog::PEOPLE_CREATE);
            $unit = $this->creationUnit($guard, $covered, $in['unit'] ?? null);
            $name = $this->name($in['full_name'] ?? null);
            $birth = BirthDate::columns($in, $this->rt->today());
            $now = $this->rt->ts();
            $personId = (int) $this->rt->db->table('people')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'full_name' => $name,
                'status_id' => $this->people->statusId(PeopleCatalog::PERSON_ACTIVE),
                'created_at' => $now,
                'lock_version' => 0,
            ] + $birth);
            $contextId = (int) $this->rt->db->table('person_unit_contexts')->insertGetId([
                'person_id' => $personId,
                'unit_id' => $unit,
                'context_kind' => PeopleCatalog::CONTEXT_ONBOARDING,
                'status' => PeopleCatalog::LINK_ACTIVE,
                'starts_at' => $now,
                'ends_at' => null,
                'reason' => 'P0.5 people.create onboarding',
                'source_document_id' => null,
                'created_at' => $now,
                'lock_version' => 0,
            ]);
            $decision = $guard->require(PeopleCatalog::PEOPLE_CREATE, $personId, $unit);
            $this->rt->audit->record($actor, $decision, 'PERSON_CREATED', 'people', $personId, null, [
                'status' => PeopleCatalog::PERSON_ACTIVE,
                'birth_precision' => $birth['birth_precision'],
                'onboarding_context_id' => $contextId,
                'membership_created' => false,
            ]);
            return $personId;
        });
        return $this->rt->read($user, $session, fn (PeopleGuard $guard): array => $this->project($guard, $id));
    }

    public function update(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->people->id($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $in): void {
            $decision = $guard->require(PeopleCatalog::PEOPLE_EDIT, $id);
            $row = $this->people->row($id, true);
            $this->assertVersion($row, $in['lock_version'] ?? null);
            if ($row->status_code === PeopleCatalog::PERSON_DECEASED) {
                throw new PeopleError(PeopleReason::PERSON_DECEASED);
            }
            $changes = [];
            $changed = [];
            if (array_key_exists('full_name', $in)) {
                $name = $this->name($in['full_name']);
                if ($name !== $row->full_name) {
                    $changes['full_name'] = $name;
                    $changed[] = 'full_name';
                }
            }
            if (array_key_exists('birth_precision', $in)) {
                $birth = BirthDate::columns($in, $this->rt->today());
                foreach ($birth as $column => $value) {
                    if ((string) ($row->{$column} ?? '') !== (string) ($value ?? '')) {
                        $changes += $birth;
                        $changed[] = 'birth';
                        break;
                    }
                }
            }
            if ($changes === []) {
                return;
            }
            $this->versionedUpdate('people', $id, (int) $row->lock_version, $changes);
            $this->rt->audit->record($actor, $decision, 'PERSON_UPDATED', 'people', $id,
                ['lock_version' => (int) $row->lock_version, 'birth_precision' => (string) $row->birth_precision],
                ['changed' => $changed, 'lock_version' => (int) $row->lock_version + 1, 'birth_precision' => (string) ($changes['birth_precision'] ?? $row->birth_precision)]);
        });
        return $this->detail($user, $session, $publicId);
    }

    public function transition(int $user, int $session, string $publicId, string $action, ?string $reason, mixed $lockVersion): array
    {
        if (!isset(self::TRANSITIONS[$action])) {
            throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['action' => $action]);
        }
        [$from, $to, $needsReason] = self::TRANSITIONS[$action];
        $id = $this->people->id($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $from, $to, $needsReason, $reason, $lockVersion): void {
            $decision = $guard->require(PeopleCatalog::PEOPLE_EDIT, $id);
            $row = $this->people->row($id, true);
            $this->assertVersion($row, $lockVersion);
            if (!in_array($row->status_code, $from, true)) {
                // DECEASED is final in V1: its correction needs a reinforced permission the catalog does not define.
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['from' => $row->status_code, 'to' => $to]);
            }
            $reason = $reason === null ? null : trim($reason);
            if ($needsReason && ($reason === null || mb_strlen($reason) < 3)) {
                throw new PeopleError(PeopleReason::REASON_REQUIRED);
            }
            $this->versionedUpdate('people', $id, (int) $row->lock_version, ['status_id' => $this->people->statusId($to)]);
            $this->rt->audit->record($actor, $decision, 'PERSON_STATUS_CHANGED', 'people', $id,
                ['status' => $row->status_code, 'lock_version' => (int) $row->lock_version],
                ['status' => $to, 'lock_version' => (int) $row->lock_version + 1], $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    /** Detail projection for the actor. Called inside read(); never exposes internal ids or member numbers. */
    public function project(PeopleGuard $guard, int $id): array
    {
        $view = $guard->require(PeopleCatalog::PEOPLE_VIEW, $id, null, [PeopleAuthority::GENERAL, PeopleAuthority::ACADEMY, PeopleAuthority::CHILDREN]);
        $row = $this->people->row($id);
        $childProfile = isset($this->people->childProfiles([$id])[$id]);
        $projection = $this->people->minimal($row, $childProfile);
        $general = $view->tier === PeopleAuthority::GENERAL;
        $protected = $projection['protected_minor'];
        $held = [];
        if ($general) {
            foreach ([PeopleCatalog::PEOPLE_EDIT, PeopleCatalog::PEOPLE_SENSITIVE_VIEW, PeopleCatalog::PEOPLE_CONTACT_MANAGE, PeopleCatalog::PEOPLE_ADDRESS_MANAGE,
                PeopleCatalog::HOUSEHOLD_VIEW, PeopleCatalog::HOUSEHOLD_MANAGE, PeopleCatalog::RELATIONSHIP_MANAGE] as $permission) {
                $held[$permission] = $guard->holds($permission, $id);
            }
        }
        $has = fn (string $permission): bool => $held[$permission] ?? false;
        $sensitive = $general && !$protected && $has(PeopleCatalog::PEOPLE_SENSITIVE_VIEW);
        $restricted = [];
        if ($sensitive) {
            $projection['birth'] = BirthDate::projection($row);
            $projection['created_at'] = (string) $row->created_at;
        } else {
            $restricted[] = 'birth';
        }
        $deceased = $row->status_code === PeopleCatalog::PERSON_DECEASED;
        $capabilities = [
            'can_edit' => $general && !$deceased && $has(PeopleCatalog::PEOPLE_EDIT),
            'can_view_contacts' => $general && !$protected && ($sensitive || $has(PeopleCatalog::PEOPLE_CONTACT_MANAGE)),
            'can_manage_contacts' => $general && !$protected && !$deceased && $has(PeopleCatalog::PEOPLE_CONTACT_MANAGE),
            'can_view_addresses' => $general && !$protected && ($sensitive || $has(PeopleCatalog::PEOPLE_ADDRESS_MANAGE)),
            'can_manage_addresses' => $general && !$protected && !$deceased && $has(PeopleCatalog::PEOPLE_ADDRESS_MANAGE),
            'can_view_households' => $general && !$protected && $has(PeopleCatalog::HOUSEHOLD_VIEW),
            'can_manage_households' => $general && $has(PeopleCatalog::HOUSEHOLD_MANAGE),
            'can_view_relationships' => $general && !$protected && ($sensitive || $has(PeopleCatalog::RELATIONSHIP_MANAGE)),
            'can_manage_relationships' => $general && $has(PeopleCatalog::RELATIONSHIP_MANAGE),
        ];
        foreach (['contacts' => 'can_view_contacts', 'addresses' => 'can_view_addresses', 'households' => 'can_view_households', 'relationships' => 'can_view_relationships'] as $area => $capability) {
            if (!$capabilities[$capability]) {
                $restricted[] = $area;
            }
        }
        $projection['projection'] = $general && !$protected ? 'COMMON' : 'MINIMAL';
        $projection['restricted'] = $restricted;
        $projection['capabilities'] = $capabilities;
        return $projection;
    }

    private function creationUnit(PeopleGuard $guard, array $covered, mixed $unitPublicId): int
    {
        if ($unitPublicId !== null) {
            $unit = is_string($unitPublicId) ? $this->rt->db->table('organizational_units')->where('public_id', $unitPublicId)->value('id') : null;
            if ($unit === null || !isset($covered[(int) $unit])) {
                throw new PeopleError(PeopleReason::CONTEXT_UNIT_INVALID, ['field' => 'unit']);
            }
            return (int) $unit;
        }
        $roots = $this->rt->authority->scopeRoots($guard->actor, PeopleCatalog::PEOPLE_CREATE);
        if (count($covered) === 1) {
            return (int) array_key_first($covered);
        }
        if (count($roots) === 1) {
            return (int) array_key_first($roots);
        }
        throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'unit']);
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'full_name']);
        }
        return $name;
    }

    private function assertVersion(object $row, mixed $expected): void
    {
        if (!is_numeric($expected) || (int) $expected !== (int) $row->lock_version) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'people']);
        }
    }

    private function versionedUpdate(string $table, int $id, int $version, array $changes): void
    {
        $updated = $this->rt->db->table($table)->where('id', $id)->where('lock_version', $version)->update($changes + ['lock_version' => $version + 1]);
        if ($updated !== 1) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => $table]);
        }
    }
}
