<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;

// Read side of Membership (ADR 0020 D05, D10, D13). Every collection is paginated (default 50, max 100) and filtered
// by scope IN SQL (coveredUnits) before pagination, never in memory. A membership is visible only through
// MEMBERSHIP_VIEW over the Congregation of its OPEN period; the period history follows the same rule. A transfer is
// visible to both of its parties (MEMBERSHIP_VIEW or MEMBERSHIP_TRANSFER over the origin or the destination), also after
// completion. Search by name, official number or legacy identifier returns only in-scope rows: out of scope is an empty
// list, identical to "does not exist". A lookup by official number or legacy identifier is audited.
final class MembershipQueryService
{
    private MembershipRecords $records;

    public function __construct(private MembershipRuntime $rt)
    {
        $this->records = new MembershipRecords($rt);
    }

    public function context(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor): array {
            $byUnit = [];
            foreach (array_keys(MembershipCatalog::PERMISSIONS) as $permission) {
                foreach ($this->rt->authority->covered($actor, $permission) as $unit => $_) {
                    $byUnit[$unit][] = $permission;
                }
            }
            $congregations = [];
            if ($byUnit !== []) {
                $rows = $this->rt->db->table('organizational_units as ou')->join('organizational_unit_types as ut', 'ut.id', '=', 'ou.unit_type_id')
                    ->whereIn('ou.id', array_keys($byUnit))->where('ut.code', 'CONGREGATION')->where('ou.status', 'ACTIVE')
                    ->orderBy('ou.name')->orderBy('ou.id')->limit(500)->get(['ou.id', 'ou.public_id', 'ou.name']);
                foreach ($rows as $row) {
                    $congregations[] = ['public_id' => (string) $row->public_id, 'name' => (string) $row->name, 'permissions' => $byUnit[(int) $row->id]];
                }
            }
            $view = $this->rt->authority->covered($actor, MembershipCatalog::VIEW);
            $activeMembers = 0;
            if ($view !== []) {
                // D03: a DECEASED Person leaves the active member count (join with people.status_id); nothing is deleted.
                $activeMembers = (int) $this->records->baseQuery()->where('s.code', MembershipCatalog::ACTIVE)->whereNotNull('mn.id')->where('ps.code', '!=', 'DECEASED')
                    ->whereIn('mp.congregation_id', array_keys($view))->count('m.id');
            }
            return [
                'permissions' => $this->rt->authority->effectivePermissions($actor),
                'congregations' => $congregations,
                'statuses' => $this->pairs(MembershipCatalog::STATUSES),
                'transfer_statuses' => $this->pairs(MembershipCatalog::TRANSFER_STATUSES),
                'legacy_sources' => MembershipCatalog::LEGACY_SOURCES,
                'legacy_statuses' => $this->pairs(MembershipCatalog::LEGACY_STATUSES),
                'milestone_types' => $this->pairs(MembershipCatalog::MILESTONE_TYPES),
                'date_precisions' => MembershipCatalog::PRECISIONS,
                'collective_max' => AdmissionService::MAX_COLLECTIVE,
                'counts' => ['active_members' => $activeMembers],
                'pagination' => ['default' => (int) ($this->rt->settings['pagination']['default'] ?? 50), 'max' => (int) ($this->rt->settings['pagination']['max'] ?? 100)],
            ];
        });
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($q): array {
            $guard->requires(MembershipCatalog::VIEW);
            $covered = $this->rt->authority->covered($actor, MembershipCatalog::VIEW);
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $units = array_keys($covered);
            if (($q['congregation_public_id'] ?? '') !== '') {
                $unit = $this->rt->db->table('organizational_units')->where('public_id', (string) $q['congregation_public_id'])->value('id');
                $units = $unit !== null && isset($covered[(int) $unit]) ? [(int) $unit] : [];
            }
            $query = $this->records->baseQuery()->whereIn('mp.congregation_id', $units === [] ? [0] : $units);
            if (!empty($q['queue'])) {
                $query->whereIn('s.code', [MembershipCatalog::SUBMITTED, MembershipCatalog::VALIDATED]);
            } elseif (($q['status'] ?? '') !== '') {
                $query->where('s.code', (string) $q['status']);
            }
            $term = trim((string) ($q['search'] ?? ''));
            $lookup = null;
            if ($term !== '') {
                $number = strtoupper((string) preg_replace('/\s+/', '', $term));
                $legacy = LegacyIdentifierService::normalize($term);
                $query->where(function ($w) use ($term, $number, $legacy): void {
                    $w->where('p.full_name', 'like', '%' . MembershipRecords::escapeLike($term) . '%')
                        ->orWhere('mn.number', $number);
                    if ($legacy !== '') {
                        $w->orWhereExists(function ($e) use ($legacy): void {
                            $e->selectRaw('1')->from('legacy_member_numbers as l')->whereColumn('l.membership_id', 'm.id')
                                ->whereIn('l.source_system', MembershipCatalog::LEGACY_SOURCES)->where('l.normalized_number', $legacy)->where('l.status', '!=', MembershipCatalog::L_REVOKED);
                        });
                    }
                });
                if (preg_match('/\d/', $term) === 1) {
                    $lookup = preg_match(MembershipCatalog::NUMBER_PATTERN, $number) === 1 ? ['kind' => 'OFFICIAL_NUMBER', 'term' => $number] : ['kind' => 'LEGACY_IDENTIFIER', 'term' => $legacy];
                }
            }
            $total = (clone $query)->count('m.id');
            $rows = $query->orderBy('p.full_name')->orderBy('m.id')->forPage($page, $per)->get()->all();
            if ($lookup !== null) {
                // Sensitive lookup (official number / legacy identifier): audited with its scoped result count only.
                $unit = $this->rt->authority->rootUnit($actor, MembershipCatalog::VIEW);
                if ($unit !== null) {
                    $this->rt->audit->record($actor, $unit, 'membership.lookup', 'MEMBERSHIP', 0, null, $lookup + ['results' => $total]);
                }
            }
            return ['items' => array_map(fn (object $row): array => $this->records->summary($row), $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(MembershipCatalog::VIEW);
            $id = $this->records->membershipId($publicId);
            $open = $this->records->authorizeRead($actor, $id, MembershipCatalog::VIEW);
            $row = $this->records->baseQuery()->where('m.id', $id)->first();
            $summary = $this->records->summary($row);
            $transfer = $this->records->openTransfer($id);
            $summary['person'] = $this->rt->personProjection((int) $row->person_id);
            return $summary + $this->rt->documentProjection($actor, $row->source_document_id === null ? null : (int) $row->source_document_id) + [
                'open_transfer' => $transfer ? $this->records->transferProjection($actor, $transfer) : null,
                'period_started_at' => (string) $open->starts_at,
                'legacy_identifiers' => $this->rt->db->table('legacy_member_numbers')->where('membership_id', $id)->where('status', '!=', MembershipCatalog::L_REVOKED)->count(),
                'legacy_conflicts' => $this->rt->db->table('legacy_member_numbers')->where('membership_id', $id)->where('status', MembershipCatalog::L_CONFLICT)->count(),
                'can' => $this->rt->authority->permissionsOn($actor, (int) $open->congregation_id),
            ];
        });
    }

    public function periods(int $user, int $session, string $publicId, array $q): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId, $q): array {
            $guard->requires(MembershipCatalog::VIEW);
            $id = $this->records->membershipId($publicId);
            $this->records->authorizeRead($actor, $id, MembershipCatalog::VIEW);
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $query = $this->rt->db->table('membership_periods as mp')->join('membership_statuses as s', 's.id', '=', 'mp.status_id')
                ->join('organizational_units as ou', 'ou.id', '=', 'mp.congregation_id')->where('mp.membership_id', $id);
            $total = (clone $query)->count('mp.id');
            $rows = $query->orderBy('mp.starts_at', 'desc')->orderBy('mp.id', 'desc')->forPage($page, $per)
                ->get(['mp.starts_at', 'mp.ends_at', 'mp.reason', 'mp.source_document_id', 's.code', 'ou.public_id as unit_public_id', 'ou.name as unit_name'])->all();
            $items = array_map(fn (object $row): array => [
                'status' => (string) $row->code,
                'status_label' => MembershipCatalog::STATUSES[(string) $row->code] ?? (string) $row->code,
                'congregation' => ['public_id' => (string) $row->unit_public_id, 'name' => (string) $row->unit_name],
                'starts_at' => (string) $row->starts_at,
                'ends_at' => $row->ends_at === null ? null : (string) $row->ends_at,
                'open' => $row->ends_at === null,
                'reason' => $row->reason === null ? null : (string) $row->reason,
            ] + $this->rt->documentProjection($actor, $row->source_document_id === null ? null : (int) $row->source_document_id), $rows);
            return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function membershipTransfers(int $user, int $session, string $publicId, array $q): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId, $q): array {
            $guard->requires(MembershipCatalog::VIEW);
            $id = $this->records->membershipId($publicId);
            $this->records->authorizeRead($actor, $id, MembershipCatalog::VIEW);
            return $this->transferPage($actor, $this->rt->db->table('transfers')->where('membership_id', $id), $q);
        });
    }

    public function transfers(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($q): array {
            $units = $this->partyUnits($actor);
            if ($units === []) {
                throw new MembershipError(MembershipReason::NOT_AUTHORIZED, ['permission' => MembershipCatalog::VIEW]);
            }
            $list = array_keys($units);
            $query = $this->rt->db->table('transfers');
            $direction = (string) ($q['direction'] ?? 'all');
            match ($direction) {
                'incoming' => $query->whereIn('destination_unit_id', $list),
                'outgoing' => $query->whereIn('origin_unit_id', $list),
                default => $query->where(fn ($w) => $w->whereIn('origin_unit_id', $list)->orWhereIn('destination_unit_id', $list)),
            };
            $state = (string) ($q['state'] ?? 'open');
            if ($state === 'open') {
                $query->whereNull('closed_at');
            } elseif ($state === 'closed') {
                $query->whereNotNull('closed_at');
            }
            return $this->transferPage($actor, $query, $q);
        });
    }

    public function transferDetail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $units = $this->partyUnits($actor);
            if ($units === []) {
                throw new MembershipError(MembershipReason::NOT_AUTHORIZED, ['permission' => MembershipCatalog::VIEW]);
            }
            $id = $this->records->transferId($publicId);
            $transfer = $this->rt->db->table('transfers')->where('id', $id)->first();
            if (!isset($units[(int) $transfer->origin_unit_id]) && !isset($units[(int) $transfer->destination_unit_id])) {
                throw new MembershipError(MembershipReason::OUT_OF_SCOPE, ['entity' => 'transfers']);
            }
            return $this->projectTransfer($actor, $transfer);
        });
    }

    /** Units where the actor is a potential transfer party: MEMBERSHIP_VIEW or MEMBERSHIP_TRANSFER. */
    private function partyUnits(TerritorialActor $actor): array
    {
        return $this->rt->authority->covered($actor, MembershipCatalog::VIEW) + $this->rt->authority->covered($actor, MembershipCatalog::TRANSFER);
    }

    private function transferPage(TerritorialActor $actor, $query, array $q): array
    {
        $per = $this->rt->perPage($q['per_page'] ?? null);
        $page = max(1, (int) ($q['page'] ?? 1));
        $total = (clone $query)->count('id');
        $rows = $query->orderBy('requested_at', 'desc')->orderBy('id', 'desc')->forPage($page, $per)->get()->all();
        return ['items' => array_map(fn (object $row): array => $this->projectTransfer($actor, $row), $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    /** Transfer projection + the stage actions the actor may attempt (presentation only; the backend decides). */
    private function projectTransfer(TerritorialActor $actor, object $transfer): array
    {
        $origin = (int) $transfer->origin_unit_id;
        $destination = (int) $transfer->destination_unit_id;
        $onOrigin = $this->rt->authority->holdsOn($actor, MembershipCatalog::TRANSFER, $origin);
        $onDestination = $this->rt->authority->holdsOn($actor, MembershipCatalog::TRANSFER, $destination);
        $status = (string) $transfer->status;
        $actions = [];
        if ($transfer->closed_at === null) {
            if ($status === MembershipCatalog::T_REQUESTED && $onOrigin) {
                array_push($actions, 'validate_origin', 'reject');
            }
            if ($status === MembershipCatalog::T_ORIGIN_VALIDATED && $onDestination) {
                array_push($actions, 'accept', 'reject');
            }
            if ($status === MembershipCatalog::T_DESTINATION_ACCEPTED && $onDestination) {
                $actions[] = 'complete';
            }
            if ($onOrigin || $onDestination) {
                $actions[] = 'cancel';
            }
        }
        $person = $this->rt->db->table('memberships as m')->join('people as p', 'p.id', '=', 'm.person_id')->where('m.id', $transfer->membership_id)->first(['p.public_id', 'p.full_name']);
        $projection = $this->records->transferProjection($actor, $transfer);
        $projection['person'] = ['public_id' => (string) $person->public_id, 'display_name' => (string) $person->full_name];
        $projection['side'] = $this->side($actor, $origin, $destination);
        $projection['actions'] = $actions;
        return $projection;
    }

    private function side(TerritorialActor $actor, int $origin, int $destination): string
    {
        $units = $this->partyUnits($actor);
        return match (true) {
            isset($units[$origin]) && isset($units[$destination]) => 'BOTH',
            isset($units[$origin]) => 'ORIGIN',
            default => 'DESTINATION',
        };
    }

    private function pairs(array $map): array
    {
        return array_map(fn ($code, $label) => ['code' => (string) $code, 'label' => (string) $label], array_keys($map), $map);
    }
}
