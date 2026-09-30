<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;

// Lookups, locks and projections shared by the Membership services. Unknown and malformed public ids are
// TARGET_NOT_FOUND (F-06). Locks follow the global order Person -> membership(s) by ascending id -> transfer -> periods
// -> national counter. Projections expose public ids only, never a primary key.
final class MembershipRecords
{
    public function __construct(private MembershipRuntime $rt)
    {
    }

    // ---- resolution ---------------------------------------------------------------------------------------------

    public function membershipId(mixed $publicId): int
    {
        return $this->idOf('memberships', $publicId);
    }

    public function transferId(mixed $publicId): int
    {
        return $this->idOf('transfers', $publicId);
    }

    public function personId(mixed $publicId): int
    {
        return $this->idOf('people', $publicId);
    }

    /** Organizational unit by public id with its type code; unknown or malformed is TARGET_NOT_FOUND. */
    public function unit(mixed $publicId): object
    {
        $id = $this->idOf('organizational_units', $publicId);
        return $this->unitRow($id);
    }

    public function unitRow(int $id, bool $lock = false): object
    {
        $query = $this->rt->db->table('organizational_units as ou')->join('organizational_unit_types as ut', 'ut.id', '=', 'ou.unit_type_id')->where('ou.id', $id);
        if ($lock) {
            $query->sharedLock();
        }
        $row = $query->first(['ou.id', 'ou.public_id', 'ou.name', 'ou.status', 'ut.code as type_code']);
        if (!$row) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'organizational_units']);
        }
        return $row;
    }

    /** A Congregation able to receive members: type CONGREGATION and status ACTIVE (D04, D06). */
    public function assertActiveCongregation(object $unit): void
    {
        if ($unit->type_code !== 'CONGREGATION' || $unit->status !== 'ACTIVE') {
            throw new MembershipError(MembershipReason::CONGREGATION_NOT_ACTIVE, ['entity' => 'organizational_units']);
        }
    }

    private function idOf(string $table, mixed $publicId): int
    {
        if (!is_string($publicId) || preg_match(MembershipCatalog::PUBLIC_ID_PATTERN, $publicId) !== 1) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        $id = $this->rt->db->table($table)->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        return (int) $id;
    }

    // ---- locks (global order) ----------------------------------------------------------------------------------

    public function lockPerson(int $personId): object
    {
        $row = $this->rt->db->table('people as p')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')->where('p.id', $personId)->lockForUpdate()
            ->first(['p.id', 'p.public_id', 'p.archived_at', 'p.merged_into_id', 'p.lock_version', 'ps.code as status_code']);
        if (!$row) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'people']);
        }
        return $row;
    }

    public function lockMembership(int $id): object
    {
        $row = $this->rt->db->table('memberships as m')->join('membership_statuses as s', 's.id', '=', 'm.status_id')->where('m.id', $id)->lockForUpdate()->first(['m.*', 's.code as status_code']);
        if (!$row) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'memberships']);
        }
        return $row;
    }

    /** Person -> membership, from the membership id (the Person id is read first without a lock, then both locked). */
    public function lockByMembership(int $membershipId): array
    {
        $personId = $this->rt->db->table('memberships')->where('id', $membershipId)->value('person_id');
        if ($personId === null) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'memberships']);
        }
        $person = $this->lockPerson((int) $personId);
        $membership = $this->lockMembership($membershipId);
        return [$person, $membership];
    }

    /**
     * Membership-scoped write: locks Person -> membership -> open period, THEN decides authority on the Congregation
     * read under lock (so a concurrent transfer that moved the membership is seen), and records the membership and its
     * current number for the commit-time invariants.
     * @return array{0: object, 1: object, 2: object, 3: MembershipDecision}
     */
    public function lockForWrite(MembershipGuard $guard, int $membershipId, string $permission): array
    {
        [$person, $membership] = $this->lockByMembership($membershipId);
        $open = $this->lockOpenPeriod($membershipId);
        $decision = $guard->unit($permission, (int) $open->congregation_id);
        $number = $this->number($membershipId, true);
        $guard->touch($membershipId, $number === null ? null : (string) $number->number);
        return [$person, $membership, $open, $decision];
    }

    /** Read-side authority over a membership: $permission over the Congregation of its open period (D05). */
    public function authorizeRead(TerritorialActor $actor, int $membershipId, string $permission): object
    {
        $open = $this->openPeriod($membershipId);
        if (!$open) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'memberships']);
        }
        $this->rt->authority->forUnit($actor, $permission, (int) $open->congregation_id);
        return $open;
    }

    public function lockOpenPeriod(int $membershipId): object
    {
        $rows = $this->rt->db->table('membership_periods')->where('membership_id', $membershipId)->whereNull('ends_at')->lockForUpdate()->get()->all();
        if (count($rows) !== 1) {
            throw new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['invariant' => 'I1_single_open_period']);
        }
        return $rows[0];
    }

    public function openPeriod(int $membershipId): ?object
    {
        return $this->rt->db->table('membership_periods')->where('membership_id', $membershipId)->whereNull('ends_at')->first();
    }

    public function openTransfer(int $membershipId, bool $lock = false): ?object
    {
        $query = $this->rt->db->table('transfers')->where('membership_id', $membershipId)->whereNull('closed_at');
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function number(int $membershipId, bool $lock = false): ?object
    {
        $query = $this->rt->db->table('member_numbers')->where('membership_id', $membershipId);
        return ($lock ? $query->sharedLock() : $query)->first();
    }

    // ---- guards -------------------------------------------------------------------------------------------------

    /** D01.5 / D03: archived or merged Person => no write; DECEASED => only operations compatible with closure. */
    public function assertPersonOperational(object $person, bool $closureCompatible = false): void
    {
        if ($person->archived_at !== null || $person->merged_into_id !== null) {
            throw new MembershipError(MembershipReason::PERSON_NOT_OPERATIONAL);
        }
        if (!$closureCompatible && $person->status_code === 'DECEASED') {
            throw new MembershipError(MembershipReason::PERSON_DECEASED);
        }
    }

    public function assertVersion(object $row, mixed $expected, string $entity): void
    {
        if (!is_numeric($expected) || (int) $expected !== (int) $row->lock_version) {
            throw new MembershipError(MembershipReason::STALE_WRITE, ['entity' => $entity]);
        }
    }

    public static function requireReason(mixed $reason): string
    {
        $value = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($value) < 3) {
            throw new MembershipError(MembershipReason::REASON_REQUIRED);
        }
        return $value;
    }

    public static function optionalReason(mixed $reason): ?string
    {
        $value = is_string($reason) ? trim($reason) : '';
        return $value === '' ? null : $value;
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    // ---- the one period transition (D03) -------------------------------------------------------------------------

    /**
     * Closes the open period at T and opens the next one at T (contiguous history), copies the new status into
     * memberships.status_id and bumps memberships.lock_version, all under the locks already held. Returns T.
     */
    public function transition(object $membership, object $open, string $status, int $congregation, ?string $reason, ?int $documentId, array $membershipChanges = []): string
    {
        $statusId = MembershipCatalog::statusIds($this->rt->db)[$status] ?? null;
        if ($statusId === null) {
            throw new MembershipError(MembershipReason::CONFIG_MISSING, ['catalog' => 'membership_statuses']);
        }
        $at = $this->rt->after((string) $open->starts_at);
        $closed = $this->rt->db->table('membership_periods')->where('id', $open->id)->whereNull('ends_at')->where('lock_version', $open->lock_version)
            ->update(['ends_at' => $at, 'lock_version' => (int) $open->lock_version + 1]);
        if ($closed !== 1) {
            throw new MembershipError(MembershipReason::STALE_WRITE, ['entity' => 'membership_periods']);
        }
        $this->rt->db->table('membership_periods')->insert([
            'membership_id' => (int) $membership->id, 'congregation_id' => $congregation, 'status_id' => $statusId,
            'starts_at' => $at, 'ends_at' => null, 'reason' => $reason, 'source_document_id' => $documentId, 'created_at' => $at, 'lock_version' => 0,
        ]);
        $changed = $this->rt->db->table('memberships')->where('id', $membership->id)->where('lock_version', $membership->lock_version)
            ->update($membershipChanges + ['status_id' => $statusId, 'lock_version' => (int) $membership->lock_version + 1]);
        if ($changed !== 1) {
            throw new MembershipError(MembershipReason::STALE_WRITE, ['entity' => 'memberships']);
        }
        return $at;
    }

    // ---- projections --------------------------------------------------------------------------------------------

    public function unitRef(?int $unitId): ?array
    {
        if ($unitId === null) {
            return null;
        }
        $row = $this->rt->db->table('organizational_units')->where('id', $unitId)->first(['public_id', 'name']);
        return $row ? ['public_id' => (string) $row->public_id, 'name' => (string) $row->name] : null;
    }

    /** Membership summary (list rows and detail header). $row carries m.*, status code, open congregation, number. */
    public function summary(object $row): array
    {
        $status = (string) $row->status_code;
        $number = $row->member_number ?? null;
        $deceased = ($row->person_status ?? null) === 'DECEASED';
        return [
            'public_id' => (string) $row->public_id,
            'status' => $status,
            'status_label' => MembershipCatalog::STATUSES[$status] ?? $status,
            'is_member' => $number !== null && in_array($status, MembershipCatalog::MEMBER_STATES, true),
            'member_number' => $number === null ? null : (string) $number,
            'origin' => (string) $row->origin,
            'admitted_on' => PrecisionDate::present($row->admitted_on === null ? null : (string) $row->admitted_on, (string) $row->date_precision),
            'admitted_on_precision' => (string) $row->date_precision,
            'approved_at' => $row->approved_at === null ? null : (string) $row->approved_at,
            'congregation' => isset($row->congregation_public_id) ? ['public_id' => (string) $row->congregation_public_id, 'name' => (string) $row->congregation_name] : null,
            'person' => [
                'public_id' => (string) $row->person_public_id,
                'display_name' => (string) $row->person_name,
                'status' => (string) ($row->person_status ?? ''),
                'deceased' => $deceased,
            ],
            'deceased' => $deceased,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    /** Base query for list/search/detail: membership + status + open period Congregation + Person + number. */
    public function baseQuery()
    {
        return $this->rt->db->table('memberships as m')
            ->join('membership_statuses as s', 's.id', '=', 'm.status_id')
            ->join('membership_periods as mp', function ($join): void {
                $join->on('mp.membership_id', '=', 'm.id')->whereNull('mp.ends_at');
            })
            ->join('organizational_units as cu', 'cu.id', '=', 'mp.congregation_id')
            ->join('people as p', 'p.id', '=', 'm.person_id')
            ->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')
            ->leftJoin('member_numbers as mn', 'mn.membership_id', '=', 'm.id')
            ->select(['m.*', 's.code as status_code', 'mp.congregation_id as open_congregation_id', 'cu.public_id as congregation_public_id', 'cu.name as congregation_name',
                'p.public_id as person_public_id', 'p.full_name as person_name', 'ps.code as person_status', 'mn.number as member_number']);
    }

    public function transferProjection(TerritorialActor $actor, object $transfer): array
    {
        $membership = $this->rt->db->table('memberships')->where('id', $transfer->membership_id)->first(['public_id']);
        return [
            'public_id' => (string) $transfer->public_id,
            'membership' => ['public_id' => (string) $membership->public_id],
            'status' => (string) $transfer->status,
            'status_label' => MembershipCatalog::TRANSFER_STATUSES[(string) $transfer->status] ?? (string) $transfer->status,
            'open' => $transfer->closed_at === null,
            'origin' => $this->unitRef((int) $transfer->origin_unit_id),
            'destination' => $this->unitRef((int) $transfer->destination_unit_id),
            'requested_at' => (string) $transfer->requested_at,
            'effective_at' => $transfer->effective_at === null ? null : (string) $transfer->effective_at,
            'closed_at' => $transfer->closed_at === null ? null : (string) $transfer->closed_at,
            'lock_version' => (int) $transfer->lock_version,
        ] + $this->rt->documentProjection($actor, $transfer->source_document_id === null ? null : (int) $transfer->source_document_id);
    }
}
