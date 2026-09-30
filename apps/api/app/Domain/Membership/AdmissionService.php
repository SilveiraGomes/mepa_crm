<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use RuntimeException;

// Admission (ADR 0020 D03/D04): SUBMITTED -> VALIDATED -> ACTIVE (approval), with REJECTED and WITHDRAWN.
//
// - Membership never creates a Person: the candidate is an existing Person the actor already sees in People (D01).
// - At most one membership per Person for life (uq_memberships_person_id): a new candidacy after REJECTED/WITHDRAWN
//   reuses the same row; two concurrent submissions serialize on the Person lock (C4).
// - The official number is generated ONLY by approve()/collectiveApprove(), in the approval transaction, after
//   approved_at/approved_by are written under the same locks. submit/validate never touch the generator.
// - One generation path: an individual approval is a collective approval of one item.
final class AdmissionService
{
    public const MAX_COLLECTIVE = 200;

    private MembershipRecords $records;

    public function __construct(private MembershipRuntime $rt)
    {
        $this->records = new MembershipRecords($rt);
    }

    public function submit(int $user, int $session, array $in): string
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($in): string {
            $guard->requires(MembershipCatalog::ADMISSION_MANAGE);
            $personId = $this->records->personId($in['person_public_id'] ?? null);
            if (!$this->rt->canSeePerson($actor, $personId)) {
                throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'people', 'reason' => 'people_authority']);
            }
            $unitId = (int) $this->records->unit($in['congregation_public_id'] ?? null)->id;
            $origin = $in['origin'] ?? MembershipCatalog::ORIGIN_ADMISSION;
            if (!is_string($origin) || !isset(MembershipCatalog::NUMBER_ORIGINS[$origin])) {
                throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'origin']);
            }
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);

            $person = $this->records->lockPerson($personId);
            $existing = $this->rt->db->table('memberships as m')->join('membership_statuses as s', 's.id', '=', 'm.status_id')->where('m.person_id', $personId)->lockForUpdate()->first(['m.*', 's.code as status_code']);
            $decision = $guard->unit(MembershipCatalog::ADMISSION_MANAGE, $unitId);
            $unit = $this->records->unitRow($unitId, true);
            $this->records->assertActiveCongregation($unit);
            $this->records->assertPersonOperational($person);

            if ($existing) {
                if (!in_array($existing->status_code, [MembershipCatalog::REJECTED, MembershipCatalog::WITHDRAWN], true)) {
                    throw new MembershipError(MembershipReason::MEMBERSHIP_EXISTS);
                }
                // A new candidacy on the same row: the actor must also hold the admission over the Congregation that
                // holds the closed candidacy today.
                $open = $this->records->lockOpenPeriod((int) $existing->id);
                $guard->unit(MembershipCatalog::ADMISSION_MANAGE, (int) $open->congregation_id);
                $guard->touch((int) $existing->id, null);
                $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
                if (array_key_exists('lock_version', $in)) {
                    $this->records->assertVersion($existing, $in['lock_version'], 'memberships');
                }
                $this->records->transition($existing, $open, MembershipCatalog::SUBMITTED, $unitId, $reason, $document?->id === null ? null : (int) $document->id, ['origin' => $origin]);
                $this->rt->audit->record($actor, $decision->unit, 'membership.submitted', 'MEMBERSHIP', (int) $existing->id,
                    ['status' => $existing->status_code, 'congregation' => $this->unitPublic((int) $open->congregation_id)],
                    ['status' => MembershipCatalog::SUBMITTED, 'membership' => (string) $existing->public_id, 'person' => (string) $person->public_id,
                        'congregation' => (string) $unit->public_id, 'origin' => $origin, 'resubmission' => true, 'source_document' => $document?->public_id], $reason);
                return (string) $existing->public_id;
            }

            $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
            $statusId = MembershipCatalog::statusIds($this->rt->db)[MembershipCatalog::SUBMITTED];
            $at = $this->rt->ts();
            $publicId = (string) Str::ulid();
            $id = (int) $this->rt->db->table('memberships')->insertGetId([
                'public_id' => $publicId, 'person_id' => $personId, 'status_id' => $statusId, 'admitted_on' => null, 'date_precision' => 'UNKNOWN',
                'approved_by' => null, 'approved_at' => null, 'source_document_id' => null, 'origin' => $origin, 'created_at' => $at, 'lock_version' => 0,
            ]);
            $this->rt->db->table('membership_periods')->insert([
                'membership_id' => $id, 'congregation_id' => $unitId, 'status_id' => $statusId, 'starts_at' => $at, 'ends_at' => null,
                'reason' => $reason, 'source_document_id' => $document?->id === null ? null : (int) $document->id, 'created_at' => $at, 'lock_version' => 0,
            ]);
            $guard->touch($id, null);
            $this->rt->audit->record($actor, $decision->unit, 'membership.submitted', 'MEMBERSHIP', $id, null,
                ['status' => MembershipCatalog::SUBMITTED, 'membership' => $publicId, 'person' => (string) $person->public_id,
                    'congregation' => (string) $unit->public_id, 'origin' => $origin, 'resubmission' => false, 'source_document' => $document?->public_id], $reason);
            return $publicId;
        });
    }

    public function validate(int $user, int $session, string $publicId, array $in): void
    {
        $this->candidacy($user, $session, $publicId, $in, MembershipCatalog::ADMISSION_MANAGE, [MembershipCatalog::SUBMITTED], MembershipCatalog::VALIDATED, 'membership.validated', false);
    }

    public function withdraw(int $user, int $session, string $publicId, array $in): void
    {
        $this->candidacy($user, $session, $publicId, $in, MembershipCatalog::ADMISSION_MANAGE, [MembershipCatalog::SUBMITTED, MembershipCatalog::VALIDATED], MembershipCatalog::WITHDRAWN, 'membership.withdrawn', true);
    }

    public function reject(int $user, int $session, string $publicId, array $in): void
    {
        $this->candidacy($user, $session, $publicId, $in, MembershipCatalog::APPROVE, [MembershipCatalog::VALIDATED], MembershipCatalog::REJECTED, 'membership.rejected', true);
    }

    /** Candidacy transitions without a number. Withdraw/reject close a candidacy and stay allowed for a DECEASED Person. */
    private function candidacy(int $user, int $session, string $publicId, array $in, string $permission, array $from, string $to, string $action, bool $closure): void
    {
        $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId, $in, $permission, $from, $to, $action, $closure): void {
            $guard->requires($permission);
            $id = $this->records->membershipId($publicId);
            [$person, $membership, $open, $decision] = $this->records->lockForWrite($guard, $id, $permission);
            $this->records->assertVersion($membership, $in['lock_version'] ?? null, 'memberships');
            if (!in_array($membership->status_code, $from, true)) {
                throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['from' => $membership->status_code, 'to' => $to]);
            }
            $this->records->assertPersonOperational($person, $closure);
            if ($to === MembershipCatalog::VALIDATED) {
                $this->records->assertActiveCongregation($this->records->unitRow((int) $open->congregation_id, true));
            }
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);
            $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
            $this->records->transition($membership, $open, $to, (int) $open->congregation_id, $reason, $document?->id === null ? null : (int) $document->id);
            $this->rt->audit->record($actor, $decision->unit, $action, 'MEMBERSHIP', $id,
                ['status' => $membership->status_code, 'lock_version' => (int) $membership->lock_version],
                ['status' => $to, 'membership' => (string) $membership->public_id, 'person' => (string) $person->public_id,
                    'congregation' => $this->unitPublic((int) $open->congregation_id), 'source_document' => $document?->public_id], $reason);
        });
    }

    /** Individual approval = collective approval of exactly one membership (one generation path). */
    public function approve(int $user, int $session, string $publicId, array $in): array
    {
        return $this->approveList($user, $session, [$publicId], $in, $in['lock_version'] ?? null, false);
    }

    /**
     * Collective approval (D04): an ordered list (the order of the minutes) of at most 200 VALIDATED memberships, one
     * all-or-nothing transaction. Locks: the Persons, then the memberships (and their open periods), each by ascending
     * id, and only then the national counter (once per number, inside the generator). Numbers follow the LIST order.
     * Any item failure rolls everything back and the response lists the per-item errors.
     * @return list<array{public_id: string, member_number: string}>
     */
    public function collectiveApprove(int $user, int $session, array $in): array
    {
        $list = $in['memberships'] ?? null;
        if (!is_array($list) || $list === [] || count($list) > self::MAX_COLLECTIVE || !array_is_list($list)) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'memberships']);
        }
        foreach ($list as $item) {
            if (!is_string($item)) {
                throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'memberships']);
            }
        }
        if (count(array_unique($list)) !== count($list)) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'memberships', 'reason' => 'duplicate']);
        }
        return $this->approveList($user, $session, $list, $in, null, true);
    }

    /** @param list<string> $list */
    private function approveList(int $user, int $session, array $list, array $in, mixed $lockVersion, bool $collective): array
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($list, $in, $lockVersion, $collective): array {
            $guard->requires(MembershipCatalog::APPROVE);
            $errors = [];
            $ids = [];
            foreach ($list as $index => $publicId) {
                try {
                    $ids[$index] = $this->records->membershipId($publicId);
                } catch (MembershipError $e) {
                    $errors[] = $this->itemError($index, $publicId, $e);
                }
            }
            if (!$collective) {
                $this->rejectIfErrors($errors, false);
            }

            // Lock order: Persons (ascending id), then memberships (ascending id) and their open periods.
            $personOf = $this->rt->db->table('memberships')->whereIn('id', array_values($ids))->pluck('person_id', 'id')->all();
            $personIds = array_values(array_unique(array_map('intval', $personOf)));
            sort($personIds);
            $persons = [];
            foreach ($personIds as $personId) {
                $persons[$personId] = $this->records->lockPerson($personId);
            }
            $sorted = array_values($ids);
            sort($sorted);
            $memberships = [];
            $opens = [];
            foreach ($sorted as $id) {
                $memberships[$id] = $this->records->lockMembership($id);
                $opens[$id] = $this->records->lockOpenPeriod($id);
            }

            // Re-validation under lock, in list order, collecting every item error.
            foreach ($ids as $index => $id) {
                try {
                    $membership = $memberships[$id];
                    $this->rt->authority->forUnit($actor, MembershipCatalog::APPROVE, (int) $opens[$id]->congregation_id, true);
                    $this->records->assertPersonOperational($persons[(int) $membership->person_id]);
                    if ($lockVersion !== null || !$collective) {
                        $this->records->assertVersion($membership, $lockVersion, 'memberships');
                    }
                    if ($membership->status_code !== MembershipCatalog::VALIDATED) {
                        throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['from' => $membership->status_code, 'to' => MembershipCatalog::ACTIVE]);
                    }
                    $this->records->assertActiveCongregation($this->records->unitRow((int) $opens[$id]->congregation_id, true));
                    if ($membership->origin === MembershipCatalog::ORIGIN_LEGACY_IMPORT
                        && !$this->rt->db->table('legacy_member_numbers')->where('membership_id', $id)->where('status', '!=', MembershipCatalog::L_REVOKED)->sharedLock()->exists()) {
                        throw new MembershipError(MembershipReason::LEGACY_ID_REQUIRED);
                    }
                } catch (MembershipError $e) {
                    $errors[] = $this->itemError($index, $list[$index], $e);
                }
            }
            $this->rejectIfErrors($errors, $collective);

            // D09: the minutes/resolution is resolved only after every Membership decision.
            $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
            $documentId = $document?->id === null ? null : (int) $document->id;
            $approvalDay = MembershipRuntime::luanda($this->rt->ts())->setTime(0, 0);
            if (($in['admitted_on_precision'] ?? null) === null && ($in['admitted_on'] ?? null) === null) {
                [$admittedOn, $precision] = [$approvalDay->format('Y-m-d'), 'EXACT'];
            } else {
                [$admittedOn, $precision] = PrecisionDate::parse($in['admitted_on'] ?? null, $in['admitted_on_precision'] ?? null, $approvalDay, 'admitted_on');
            }
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);
            $correlation = (string) Str::ulid();
            $generator = new MemberNumberGenerator($this->rt->db);
            $issued = [];

            // Numbers are assigned in the LIST order (the order of the minutes).
            foreach ($ids as $index => $id) {
                $membership = $memberships[$id];
                $open = $opens[$id];
                $decision = $guard->unit(MembershipCatalog::APPROVE, (int) $open->congregation_id);
                $guard->touch($id, null);
                $guard->issuing($id);
                $at = $this->records->transition($membership, $open, MembershipCatalog::ACTIVE, (int) $open->congregation_id, $reason, $documentId);
                // approved_at/approved_by record the FIRST approval (a candidacy never had one) and are written before
                // the generator, under the same locks.
                $this->rt->db->table('memberships')->where('id', $id)->whereNull('approved_at')->update([
                    'approved_at' => $at, 'approved_by' => $actor->user, 'admitted_on' => $admittedOn, 'date_precision' => $precision, 'source_document_id' => $documentId,
                ]);
                try {
                    $number = $generator->generateFor($id, new DateTimeImmutable($at, new DateTimeZone('UTC')), MembershipCatalog::NUMBER_ORIGINS[(string) $membership->origin]);
                } catch (MembershipError $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    throw new MembershipError(MembershipReason::NUMBER_UNAVAILABLE, ['reason' => $e->getMessage()], $e);
                }
                if ($number['replayed']) {
                    throw new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['invariant' => 'D02_number_only_on_first_approval']);
                }
                $person = $persons[(int) $membership->person_id];
                $this->rt->audit->record($actor, $decision->unit, 'membership.approved', 'MEMBERSHIP', $id,
                    ['status' => MembershipCatalog::VALIDATED, 'lock_version' => (int) $membership->lock_version],
                    ['status' => MembershipCatalog::ACTIVE, 'membership' => (string) $membership->public_id, 'person' => (string) $person->public_id,
                        'congregation' => $this->unitPublic((int) $open->congregation_id), 'member_number' => $number['number'],
                        'admitted_on' => PrecisionDate::present($admittedOn, $precision), 'admitted_on_precision' => $precision,
                        'collective' => $collective, 'list_index' => $index + 1, 'list_size' => count($ids), 'source_document' => $document?->public_id],
                    $reason, $correlation);
                $issued[] = ['public_id' => (string) $membership->public_id, 'member_number' => $number['number']];
            }
            return $issued;
        });
    }

    private function itemError(int $index, string $publicId, MembershipError $e): array
    {
        $concealed = in_array($e->reason, [MembershipReason::TARGET_NOT_FOUND, MembershipReason::OUT_OF_SCOPE], true);
        return ['index' => $index, 'public_id' => $publicId, 'code' => $concealed ? 'RESOURCE_NOT_FOUND' : $e->reason, 'error' => $e];
    }

    /** A single approval surfaces its own error (natural HTTP code); a collective one lists every item error. */
    private function rejectIfErrors(array $errors, bool $collective): void
    {
        if ($errors === []) {
            return;
        }
        if (!$collective) {
            throw $errors[0]['error'];
        }
        usort($errors, fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        $items = array_map(fn (array $e): array => ['index' => $e['index'], 'public_id' => $e['public_id'], 'code' => $e['code']], $errors);
        throw new MembershipError(MembershipReason::COLLECTIVE_REJECTED, ['items' => count($items)], null, $items);
    }

    private function unitPublic(int $unitId): ?string
    {
        $value = $this->rt->db->table('organizational_units')->where('id', $unitId)->value('public_id');
        return $value === null ? null : (string) $value;
    }
}
