<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;

// Member lifecycle V1 (ADR 0020 D03): ACTIVE <-> INACTIVE, ACTIVE/INACTIVE -> ENDED, ENDED -> ACTIVE (readmission).
// No disciplinary state and no hard delete. The official number is never touched: inactivation, end, readmission and
// death keep it (readmission REUSES it; approved_at/approved_by keep the first approval). While a transfer is open,
// inactivation and end are refused (409 TRANSFER_IN_PROGRESS): the membership lock serializes them with the transfer
// stages (C2). A DECEASED Person only accepts end (closure).
final class LifecycleService
{
    private MembershipRecords $records;

    public function __construct(private MembershipRuntime $rt)
    {
        $this->records = new MembershipRecords($rt);
    }

    public function inactivate(int $user, int $session, string $publicId, array $in): void
    {
        $this->change($user, $session, $publicId, $in, MembershipCatalog::MANAGE, [MembershipCatalog::ACTIVE], MembershipCatalog::INACTIVE, 'membership.inactivated', false, false);
    }

    public function reactivate(int $user, int $session, string $publicId, array $in): void
    {
        $this->change($user, $session, $publicId, $in, MembershipCatalog::MANAGE, [MembershipCatalog::INACTIVE], MembershipCatalog::ACTIVE, 'membership.reactivated', false, false);
    }

    public function end(int $user, int $session, string $publicId, array $in): void
    {
        $this->change($user, $session, $publicId, $in, MembershipCatalog::MANAGE, [MembershipCatalog::ACTIVE, MembershipCatalog::INACTIVE], MembershipCatalog::ENDED, 'membership.ended', true, true);
    }

    public function readmit(int $user, int $session, string $publicId, array $in): void
    {
        $this->change($user, $session, $publicId, $in, MembershipCatalog::APPROVE, [MembershipCatalog::ENDED], MembershipCatalog::ACTIVE, 'membership.readmitted', true, false);
    }

    private function change(int $user, int $session, string $publicId, array $in, string $permission, array $from, string $to, string $action, bool $reasonRequired, bool $closure): void
    {
        $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId, $in, $permission, $from, $to, $action, $reasonRequired, $closure): void {
            $guard->requires($permission);
            $id = $this->records->membershipId($publicId);
            $reason = $reasonRequired ? MembershipRecords::requireReason($in['reason'] ?? null) : MembershipRecords::optionalReason($in['reason'] ?? null);
            [$person, $membership, $open, $decision] = $this->records->lockForWrite($guard, $id, $permission);
            $this->records->assertVersion($membership, $in['lock_version'] ?? null, 'memberships');
            if (!in_array($membership->status_code, $from, true)) {
                throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['from' => $membership->status_code, 'to' => $to]);
            }
            $this->records->assertPersonOperational($person, $closure);
            if ($this->records->openTransfer($id, true) !== null) {
                throw new MembershipError(MembershipReason::TRANSFER_IN_PROGRESS);
            }
            $number = $this->records->number($id, true);
            if ($number === null) {
                throw new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['invariant' => 'I5_member_has_number']);
            }
            if ($to === MembershipCatalog::ACTIVE) {
                $this->records->assertActiveCongregation($this->records->unitRow((int) $open->congregation_id, true));
            }
            $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
            $this->records->transition($membership, $open, $to, (int) $open->congregation_id, $reason, $document?->id === null ? null : (int) $document->id);
            $this->rt->audit->record($actor, $decision->unit, $action, 'MEMBERSHIP', $id,
                ['status' => $membership->status_code, 'lock_version' => (int) $membership->lock_version],
                ['status' => $to, 'membership' => (string) $membership->public_id, 'person' => (string) $person->public_id,
                    'congregation' => (string) $this->rt->db->table('organizational_units')->where('id', $open->congregation_id)->value('public_id'),
                    'member_number' => (string) $number->number, 'person_deceased' => $person->status_code === 'DECEASED', 'source_document' => $document?->public_id], $reason);
        });
    }
}
