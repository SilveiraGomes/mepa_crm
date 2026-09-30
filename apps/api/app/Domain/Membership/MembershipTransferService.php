<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use RuntimeException;

// Membership transfers (ADR 0020 D06): REQUESTED -> ORIGIN_VALIDATED -> DESTINATION_ACCEPTED -> COMPLETED, with the
// terminal REJECTED and CANCELLED. Authority per stage (MEMBERSHIP_TRANSFER + scope):
//   request            origin OR destination (audit: the requester's side, origin preferred)
//   validate-origin    origin
//   accept             destination
//   reject             origin while REQUESTED, destination while ORIGIN_VALIDATED
//   complete           destination (origin in the audit metadata)
//   cancel             origin OR destination, before COMPLETED
// The authority is never derived from the URI: the origin is the Congregation of the membership's open period (read
// under lock) and the destination is the transfer row's. A requester that covers only the destination learns nothing
// about the membership: every state refusal is concealed as 404 for it.
// Completion is TransferService::effectuate (P09-D-F01): the atomic period move, under the same business transaction
// as the commit-time recheck of authority and invariants.
final class MembershipTransferService
{
    private MembershipRecords $records;
    private TransferService $transfers;

    public function __construct(private MembershipRuntime $rt)
    {
        $this->records = new MembershipRecords($rt);
        $this->transfers = new TransferService($rt->db);
    }

    public function request(int $user, int $session, string $membershipPublicId, array $in): string
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId, $in): string {
            $guard->requires(MembershipCatalog::TRANSFER);
            $id = $this->records->membershipId($membershipPublicId);
            $destination = $this->records->unit($in['destination_public_id'] ?? null);
            [$person, $membership] = $this->records->lockByMembership($id);
            $open = $this->records->lockOpenPeriod($id);
            $origin = (int) $open->congregation_id;
            $coversOrigin = $this->rt->authority->holdsOn($actor, MembershipCatalog::TRANSFER, $origin);
            if (!$coversOrigin && !$this->rt->authority->holdsOn($actor, MembershipCatalog::TRANSFER, (int) $destination->id)) {
                throw new MembershipError(MembershipReason::OUT_OF_SCOPE, ['entity' => 'transfers']);
            }
            $decision = $guard->unit(MembershipCatalog::TRANSFER, $coversOrigin ? $origin : (int) $destination->id);
            $number = $this->records->number($id, true);
            $guard->touch($id, $number === null ? null : (string) $number->number);
            try {
                $this->records->assertPersonOperational($person);
                if ($membership->status_code !== MembershipCatalog::ACTIVE) {
                    throw new MembershipError(MembershipReason::MEMBERSHIP_NOT_ACTIVE);
                }
                if ($this->records->openTransfer($id, true) !== null) {
                    throw new MembershipError(MembershipReason::TRANSFER_IN_PROGRESS);
                }
            } catch (MembershipError $e) {
                throw $coversOrigin ? $e : new MembershipError(MembershipReason::OUT_OF_SCOPE, ['entity' => 'transfers', 'concealed' => $e->reason]);
            }
            if ((int) $destination->id === $origin) {
                throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'destination_public_id']);
            }
            $this->records->assertActiveCongregation($this->records->unitRow((int) $destination->id, true));
            $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);

            $workflow = $this->rt->db->table('workflows')->where('code', MembershipCatalog::WORKFLOW_TRANSFER)->where('version', MembershipCatalog::WORKFLOW_VERSION)->where('status', 'ACTIVE')->value('id');
            if ($workflow === null) {
                throw new MembershipError(MembershipReason::CONFIG_MISSING, ['catalog' => 'workflows']);
            }
            $at = $this->rt->ts();
            $instance = (int) $this->rt->db->table('workflow_instances')->insertGetId([
                'public_id' => (string) Str::ulid(), 'workflow_id' => (int) $workflow, 'unit_id' => $origin, 'requested_by' => $actor->user,
                'status' => MembershipCatalog::T_REQUESTED, 'submitted_at' => $at, 'completed_at' => null, 'created_at' => $at, 'lock_version' => 0,
            ]);
            try {
                $created = $this->transfers->request($id, $origin, (int) $destination->id, $instance, new DateTimeImmutable($at, new DateTimeZone('UTC')), MembershipCatalog::T_REQUESTED, $document?->id === null ? null : (int) $document->id);
            } catch (MembershipError $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $reasonCode = $e->getMessage() === 'TRANSFER_ALREADY_IN_PROGRESS' ? MembershipReason::TRANSFER_IN_PROGRESS : MembershipReason::INVALID_INPUT;
                throw $coversOrigin ? new MembershipError($reasonCode, [], $e) : new MembershipError(MembershipReason::OUT_OF_SCOPE, ['entity' => 'transfers'], $e);
            }
            $this->rt->audit->record($actor, $decision->unit, 'membership_transfer.requested', 'MEMBERSHIP_TRANSFER', (int) $created['id'], null, [
                'status' => MembershipCatalog::T_REQUESTED, 'transfer' => $created['public_id'], 'membership' => (string) $membership->public_id,
                'origin' => $this->unitPublic($origin), 'destination' => (string) $destination->public_id, 'requested_by_side' => $coversOrigin ? 'ORIGIN' : 'DESTINATION',
                'source_document' => $document?->public_id,
            ], $reason);
            return $created['public_id'];
        });
    }

    public function validateOrigin(int $user, int $session, string $publicId, array $in): void
    {
        $this->stage($user, $session, $publicId, $in, 'validate');
    }

    public function accept(int $user, int $session, string $publicId, array $in): void
    {
        $this->stage($user, $session, $publicId, $in, 'accept');
    }

    public function reject(int $user, int $session, string $publicId, array $in): void
    {
        $this->stage($user, $session, $publicId, $in, 'reject');
    }

    public function complete(int $user, int $session, string $publicId, array $in): void
    {
        $this->stage($user, $session, $publicId, $in, 'complete');
    }

    public function cancel(int $user, int $session, string $publicId, array $in): void
    {
        $this->stage($user, $session, $publicId, $in, 'cancel');
    }

    private function stage(int $user, int $session, string $publicId, array $in, string $stage): void
    {
        $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($publicId, $in, $stage): void {
            $guard->requires(MembershipCatalog::TRANSFER);
            $transferId = $this->records->transferId($publicId);
            $membershipId = (int) $this->rt->db->table('transfers')->where('id', $transferId)->value('membership_id');
            // Lock order: Person -> membership -> transfer -> periods.
            [$person, $membership] = $this->records->lockByMembership($membershipId);
            $transfer = $this->rt->db->table('transfers')->where('id', $transferId)->lockForUpdate()->first();
            $this->records->lockOpenPeriod($membershipId);
            $origin = (int) $transfer->origin_unit_id;
            $destination = (int) $transfer->destination_unit_id;
            $status = (string) $transfer->status;

            $side = match ($stage) {
                'validate' => $origin,
                'accept', 'complete' => $destination,
                'reject' => $status === MembershipCatalog::T_ORIGIN_VALIDATED ? $destination : $origin,
                'cancel' => $this->rt->authority->holdsOn($actor, MembershipCatalog::TRANSFER, $origin) ? $origin : $destination,
            };
            $decision = $guard->unit(MembershipCatalog::TRANSFER, $side);
            $number = $this->records->number($membershipId, true);
            $guard->touch($membershipId, $number === null ? null : (string) $number->number);
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);
            if (array_key_exists('lock_version', $in) && !($stage === 'complete' && $status === MembershipCatalog::T_COMPLETED)) {
                $this->records->assertVersion($transfer, $in['lock_version'], 'transfers');
            }
            $meta = ['transfer' => (string) $transfer->public_id, 'membership' => (string) $membership->public_id, 'origin' => $this->unitPublic($origin), 'destination' => $this->unitPublic($destination)];

            if ($stage === 'complete') {
                $result = $this->transfers->effectuate($transferId);
                if ($result['replayed']) {
                    return;
                }
                $this->rt->audit->record($actor, $decision->unit, 'membership_transfer.completed', 'MEMBERSHIP_TRANSFER', $transferId,
                    ['status' => $status, 'congregation' => $meta['origin']],
                    $meta + ['status' => MembershipCatalog::T_COMPLETED, 'congregation' => $meta['destination'], 'effective_at' => $result['at'], 'member_number' => $number?->number], $reason);
                return;
            }
            if ($stage === 'cancel') {
                if ($transfer->closed_at !== null) {
                    if ($status === MembershipCatalog::T_CANCELLED) {
                        return;
                    }
                    throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['entity' => 'transfers', 'from' => $status]);
                }
                $this->transfers->cancel($transferId, new DateTimeImmutable($this->rt->ts(), new DateTimeZone('UTC')));
                $this->rt->audit->record($actor, $decision->unit, 'membership_transfer.cancelled', 'MEMBERSHIP_TRANSFER', $transferId, ['status' => $status],
                    $meta + ['status' => MembershipCatalog::T_CANCELLED, 'cancelled_by_side' => $side === $origin ? 'ORIGIN' : 'DESTINATION'], $reason);
                return;
            }
            // validate / accept / reject: stages that keep the Person operational (reject closes and stays allowed).
            $this->records->assertPersonOperational($person, $stage === 'reject');
            [$from, $to, $action] = match ($stage) {
                'validate' => [MembershipCatalog::T_REQUESTED, MembershipCatalog::T_ORIGIN_VALIDATED, 'membership_transfer.origin_validated'],
                'accept' => [MembershipCatalog::T_ORIGIN_VALIDATED, MembershipCatalog::T_DESTINATION_ACCEPTED, 'membership_transfer.accepted'],
                'reject' => [$status, MembershipCatalog::T_REJECTED, 'membership_transfer.rejected'],
            };
            $document = null;
            if ($stage === 'accept') {
                $this->records->assertActiveCongregation($this->records->unitRow($destination, true));
                $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
                if ($document !== null && $transfer->source_document_id === null) {
                    $this->rt->db->table('transfers')->where('id', $transferId)->update(['source_document_id' => (int) $document->id]);
                }
            }
            $this->transfers->advance($transferId, $from, $to);
            $this->rt->audit->record($actor, $decision->unit, $action, 'MEMBERSHIP_TRANSFER', $transferId, ['status' => $status],
                $meta + ['status' => $to, 'source_document' => $document?->public_id], $reason);
        });
    }

    private function unitPublic(int $unitId): ?string
    {
        $value = $this->rt->db->table('organizational_units')->where('id', $unitId)->value('public_id');
        return $value === null ? null : (string) $value;
    }
}
