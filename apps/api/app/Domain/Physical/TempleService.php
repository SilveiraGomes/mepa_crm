<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

// Temples (ADR 0018 D01, D05): the religious use of a physical location. A temple belongs to exactly one location,
// has no parent, no unit and no hierarchy: it is NOT a Congregation, never creates an organizational unit and never
// takes part in the Territorial tree. Several temples on one location create no Congregation. Authority over a
// temple is the authority over its location_id. Lifecycle DRAFT -> ACTIVE -> CLOSED, no hard delete.
final class TempleService
{
    private PhysicalRecords $records;

    public function __construct(private PhysicalRuntime $rt)
    {
        $this->records = new PhysicalRecords($rt);
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($q): array {
            $covered = $this->rt->authority->covered($actor, PhysicalCatalog::TEMPLE_VIEW);
            if ($covered === []) {
                throw new PhysicalError(PhysicalReason::NOT_AUTHORIZED, ['permission' => PhysicalCatalog::TEMPLE_VIEW]);
            }
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            [$predicate, $bindings] = $this->rt->authority->visiblePredicate('pl', $covered);
            $query = $this->rt->db->table('temples as t')->join('physical_locations as pl', 'pl.id', '=', 't.location_id')->whereRaw($predicate, $bindings);
            if (($q['location_public_id'] ?? '') !== '') {
                $query->where('pl.public_id', (string) $q['location_public_id']);
            }
            if (($q['search'] ?? '') !== '') {
                $query->where('t.name', 'like', '%' . PhysicalRecords::escapeLike((string) $q['search']) . '%');
            }
            if (($q['status'] ?? '') !== '') {
                $query->where('t.status', (string) $q['status']);
            }
            $total = (clone $query)->count('t.id');
            $rows = $query->orderBy('t.name')->orderBy('t.id')->forPage($page, $per)->get(['t.*', 'pl.public_id as location_public_id', 'pl.name as location_name'])->all();
            $items = array_map(fn (object $r): array => $this->records->projectTemple($r, (object) ['public_id' => $r->location_public_id, 'name' => $r->location_name]), $rows);
            return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(PhysicalCatalog::TEMPLE_VIEW);
            $id = $this->records->templeId($publicId);
            $row = $this->row($id);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::TEMPLE_VIEW, (int) $row->location_id);
            $location = $this->rt->db->table('physical_locations')->where('id', $row->location_id)->first(['public_id', 'name']);
            return $this->records->projectTemple($row, $location) + [
                'can' => array_values(array_filter([PhysicalCatalog::TEMPLE_VIEW, PhysicalCatalog::TEMPLE_MANAGE], fn (string $p): bool => $this->rt->authority->holdsOnLocation($actor, $p, (int) $row->location_id))),
            ];
        });
    }

    public function create(int $user, int $session, array $in): array
    {
        $id = $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($in): int {
            $guard->requires(PhysicalCatalog::TEMPLE_MANAGE);
            $location = $this->records->locationId($in['location_public_id'] ?? null);
            $this->rt->authority->forLocation($actor, PhysicalCatalog::TEMPLE_MANAGE, $location, false);
            $loc = $this->rt->lockLocation($location);
            $this->rt->lockLinks($location);
            $decision = $guard->location(PhysicalCatalog::TEMPLE_MANAGE, $location);
            if ($loc->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::LOCATION_CLOSED);
            }
            $name = $this->name($in['name'] ?? null);
            $capacity = $this->capacity($in['capacity'] ?? null);
            $id = (int) $this->rt->db->table('temples')->insertGetId([
                'public_id' => (string) Str::ulid(), 'location_id' => $location, 'name' => $name, 'capacity' => $capacity,
                'status' => PhysicalCatalog::DRAFT, 'created_at' => $this->rt->ts(), 'lock_version' => 0,
            ]);
            $guard->touch(null, $location);
            $this->rt->audit->record($actor, $decision->unit, 'TEMPLE_CREATE', 'TEMPLE', $id, null,
                ['location_entity' => $location, 'capacity' => $capacity, 'status' => PhysicalCatalog::DRAFT], PhysicalRecords::optionalReason($in['reason'] ?? null));
            return $id;
        });
        return $this->detail($user, $session, (string) $this->rt->db->table('temples')->where('id', $id)->value('public_id'));
    }

    public function update(int $user, int $session, string $publicId, array $in): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(PhysicalCatalog::TEMPLE_MANAGE);
            $id = $this->records->templeId($publicId);
            [$decision, $row] = $this->lockForManage($guard, $actor, $id);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'temples');
            if ($row->status === PhysicalCatalog::CLOSED) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'temples']);
            }
            $name = array_key_exists('name', $in) ? $this->name($in['name']) : (string) $row->name;
            $capacity = array_key_exists('capacity', $in) ? $this->capacity($in['capacity']) : ($row->capacity === null ? null : (int) $row->capacity);
            $this->versioned((int) $row->id, (int) $row->lock_version, ['name' => $name, 'capacity' => $capacity]);
            $this->rt->audit->record($actor, $decision->unit, 'TEMPLE_UPDATE', 'TEMPLE', (int) $row->id,
                ['capacity' => $row->capacity === null ? null : (int) $row->capacity, 'lock_version' => (int) $row->lock_version],
                ['capacity' => $capacity, 'name_changed' => $name !== (string) $row->name, 'lock_version' => (int) $row->lock_version + 1],
                PhysicalRecords::optionalReason($in['reason'] ?? null));
        });
        return $this->detail($user, $session, $publicId);
    }

    public function activate(int $user, int $session, string $publicId, array $in): array
    {
        return $this->lifecycle($user, $session, $publicId, $in, PhysicalCatalog::ACTIVE);
    }

    public function close(int $user, int $session, string $publicId, array $in): array
    {
        return $this->lifecycle($user, $session, $publicId, $in, PhysicalCatalog::CLOSED);
    }

    private function lifecycle(int $user, int $session, string $publicId, array $in, string $to): array
    {
        $this->rt->write($user, $session, function (PhysicalGuard $guard, TerritorialActor $actor) use ($publicId, $in, $to): void {
            $guard->requires(PhysicalCatalog::TEMPLE_MANAGE);
            $id = $this->records->templeId($publicId);
            $reason = $to === PhysicalCatalog::CLOSED ? PhysicalRecords::requireReason($in['reason'] ?? null) : PhysicalRecords::optionalReason($in['reason'] ?? null);
            [$decision, $row, $location] = $this->lockForManage($guard, $actor, $id);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'temples');
            $from = (string) $row->status;
            $allowed = ($from === PhysicalCatalog::DRAFT && in_array($to, [PhysicalCatalog::ACTIVE, PhysicalCatalog::CLOSED], true)) || ($from === PhysicalCatalog::ACTIVE && $to === PhysicalCatalog::CLOSED);
            if (!$allowed) {
                throw new PhysicalError(PhysicalReason::TRANSITION_NOT_ALLOWED, ['entity' => 'temples']);
            }
            if ($to === PhysicalCatalog::ACTIVE && $location->status !== PhysicalCatalog::ACTIVE) {
                throw new PhysicalError(PhysicalReason::LOCATION_NOT_ACTIVE);
            }
            $this->versioned((int) $row->id, (int) $row->lock_version, ['status' => $to]);
            $this->rt->audit->record($actor, $decision->unit, $to === PhysicalCatalog::ACTIVE ? 'TEMPLE_ACTIVATE' : 'TEMPLE_CLOSE', 'TEMPLE', (int) $row->id,
                ['status' => $from, 'lock_version' => (int) $row->lock_version], ['status' => $to, 'lock_version' => (int) $row->lock_version + 1], $reason);
        });
        return $this->detail($user, $session, $publicId);
    }

    /** @return array{0: PhysicalDecision, 1: object, 2: object} lock order: location -> temple -> links */
    private function lockForManage(PhysicalGuard $guard, TerritorialActor $actor, int $id): array
    {
        $location = (int) $this->row($id)->location_id;
        $this->rt->authority->forLocation($actor, PhysicalCatalog::TEMPLE_MANAGE, $location, false);
        $loc = $this->rt->lockLocation($location);
        $row = $this->rt->db->table('temples')->where('id', $id)->lockForUpdate()->first();
        $this->rt->lockLinks($location);
        return [$guard->location(PhysicalCatalog::TEMPLE_MANAGE, $location), $row, $loc];
    }

    private function row(int $id): object
    {
        $row = $this->rt->db->table('temples')->where('id', $id)->first();
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'temples']);
        }
        return $row;
    }

    private function versioned(int $id, int $version, array $changes): void
    {
        $changed = $this->rt->db->table('temples')->where('id', $id)->where('lock_version', $version)->update($changes + ['lock_version' => $version + 1]);
        if ($changed !== 1) {
            throw new PhysicalError(PhysicalReason::STALE_WRITE, ['entity' => 'temples']);
        }
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 191) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'name']);
        }
        return $name;
    }

    private function capacity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]) === false) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'capacity']);
        }
        return (int) $value;
    }
}
