<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persistence of the membership transfer state machine (ADR 0020 D06). Authority, documents and audit live in the
 * application layer (MembershipTransferService), which calls these primitives inside its own business transaction;
 * every primitive is also safe on its own (it opens its own transaction or a savepoint).
 *
 * P0.3.2-M1 / F-W2F-01 (kept): a membership never has more than one transfer "in flight" (closed_at IS NULL). The
 * in-flight invariant depends only on closed_at; the physical backstop is uq_transfers_membership_open.
 *
 * P0.9 / P09-D-F01 (fixed): effectuate() is the ATOMIC Efectivação. It no longer only flips the transfer: under the
 * global lock order Person -> membership -> transfer -> periods it re-validates, at commit, the open transfer in
 * DESTINATION_ACCEPTED, a non-deceased operational Person, an ACTIVE membership whose open period is at the origin, and
 * an ACTIVE destination Congregation; then closes the origin period at T, opens the ACTIVE destination period at T,
 * closes the transfer (effective_at = closed_at = T) and completes its workflow instance. member_numbers is never read
 * for writing nor touched. No intermediate state is visible: it all commits or nothing does. Replaying an effectuation
 * that already completed is a no-op.
 */
final class TransferService
{
    private const OPEN_GUARD_UNIQUE_KEY = 'uq_transfers_membership_open';
    private const ORIGIN_DESTINATION_CHECK = 'ck_transfers_origin_destination';

    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{id:int, public_id:string}
     */
    public function request(
        int $membershipId,
        int $originUnitId,
        int $destinationUnitId,
        int $workflowInstanceId,
        DateTimeImmutable $requestedAt,
        string $status = MembershipCatalog::T_REQUESTED,
        ?int $sourceDocumentId = null
    ): array {
        return $this->db->transaction(function () use ($membershipId, $originUnitId, $destinationUnitId, $workflowInstanceId, $requestedAt, $status, $sourceDocumentId) {
            $membership = $this->db->table('memberships')->where('id', $membershipId)->lockForUpdate()->first();
            if ($membership === null) {
                throw new RuntimeException('MEMBERSHIP_NOT_FOUND');
            }

            $publicId = (string) Str::ulid();
            $timestamp = $requestedAt->format('Y-m-d H:i:s.u');

            try {
                $id = $this->db->table('transfers')->insertGetId([
                    'public_id' => $publicId,
                    'membership_id' => $membershipId,
                    'origin_unit_id' => $originUnitId,
                    'destination_unit_id' => $destinationUnitId,
                    'requested_at' => $timestamp,
                    'effective_at' => null,
                    'closed_at' => null,
                    'status' => $status,
                    'workflow_instance_id' => $workflowInstanceId,
                    'source_document_id' => $sourceDocumentId,
                    'created_at' => $timestamp,
                ]);
            } catch (QueryException $e) {
                if ($this->violates($e, self::OPEN_GUARD_UNIQUE_KEY)) {
                    throw new RuntimeException('TRANSFER_ALREADY_IN_PROGRESS', 0, $e);
                }
                if ($this->violates($e, self::ORIGIN_DESTINATION_CHECK)) {
                    throw new RuntimeException('TRANSFER_INVALID_ORIGIN_DESTINATION', 0, $e);
                }
                throw $e;
            }

            return ['id' => $id, 'public_id' => $publicId];
        });
    }

    /**
     * Intermediate stages (REQUESTED -> ORIGIN_VALIDATED -> DESTINATION_ACCEPTED) and REJECTED. The caller holds the
     * Person and membership locks. REJECTED is terminal without effect: closed_at is set, effective_at stays NULL.
     */
    public function advance(int $transferId, string $from, string $to): object
    {
        return $this->db->transaction(function () use ($transferId, $from, $to) {
            $transfer = $this->db->table('transfers')->where('id', $transferId)->lockForUpdate()->first();
            if ($transfer === null) {
                throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'transfers']);
            }
            $allowed = [
                MembershipCatalog::T_REQUESTED => [MembershipCatalog::T_ORIGIN_VALIDATED, MembershipCatalog::T_REJECTED],
                MembershipCatalog::T_ORIGIN_VALIDATED => [MembershipCatalog::T_DESTINATION_ACCEPTED, MembershipCatalog::T_REJECTED],
            ];
            if ($transfer->closed_at !== null || (string) $transfer->status !== $from || !in_array($to, $allowed[$from] ?? [], true)) {
                throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['entity' => 'transfers', 'from' => (string) $transfer->status, 'to' => $to]);
            }
            $at = $this->now();
            $changes = ['status' => $to, 'lock_version' => (int) $transfer->lock_version + 1];
            if ($to === MembershipCatalog::T_REJECTED) {
                $changes['closed_at'] = $at;
            }
            $this->db->table('transfers')->where('id', $transferId)->where('lock_version', $transfer->lock_version)->update($changes);
            $this->workflow((int) $transfer->workflow_instance_id, $to, $to === MembershipCatalog::T_REJECTED ? $at : null);
            return $this->db->table('transfers')->where('id', $transferId)->first();
        });
    }

    /**
     * The atomic Efectivação (P09-D-F01). Returns the closed origin period, the new destination period and T.
     * @return array{replayed: bool, at: string, transfer: object, origin_period: ?int, destination_period: ?int}
     */
    public function effectuate(int $transferId): array
    {
        return $this->db->transaction(function () use ($transferId) {
            $membershipId = $this->db->table('transfers')->where('id', $transferId)->value('membership_id');
            if ($membershipId === null) {
                throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'transfers']);
            }
            // Global lock order: Person -> membership -> transfer -> periods.
            $personId = (int) $this->db->table('memberships')->where('id', $membershipId)->value('person_id');
            $person = $this->db->table('people as p')->join('person_statuses as ps', 'ps.id', '=', 'p.status_id')->where('p.id', $personId)->lockForUpdate()->first(['p.id', 'p.archived_at', 'p.merged_into_id', 'ps.code as status_code']);
            $membership = $this->db->table('memberships as m')->join('membership_statuses as s', 's.id', '=', 'm.status_id')->where('m.id', $membershipId)->lockForUpdate()->first(['m.*', 's.code as status_code']);
            $transfer = $this->db->table('transfers')->where('id', $transferId)->lockForUpdate()->first();

            if ($transfer->closed_at !== null) {
                if ($transfer->effective_at !== null && $transfer->status === MembershipCatalog::T_COMPLETED) {
                    return ['replayed' => true, 'at' => (string) $transfer->effective_at, 'transfer' => $transfer, 'origin_period' => null, 'destination_period' => null];
                }
                throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['entity' => 'transfers', 'reason' => 'closed_without_effect']);
            }
            if ($transfer->status !== MembershipCatalog::T_DESTINATION_ACCEPTED) {
                throw new MembershipError(MembershipReason::TRANSITION_NOT_ALLOWED, ['entity' => 'transfers', 'from' => (string) $transfer->status]);
            }
            if (!$person || $person->archived_at !== null || $person->merged_into_id !== null) {
                throw new MembershipError(MembershipReason::PERSON_NOT_OPERATIONAL);
            }
            if ($person->status_code === 'DECEASED') {
                throw new MembershipError(MembershipReason::PERSON_DECEASED);
            }
            if ($membership->status_code !== MembershipCatalog::ACTIVE) {
                throw new MembershipError(MembershipReason::MEMBERSHIP_NOT_ACTIVE);
            }
            $open = $this->db->table('membership_periods')->where('membership_id', $membershipId)->whereNull('ends_at')->lockForUpdate()->get()->all();
            if (count($open) !== 1 || (int) $open[0]->congregation_id !== (int) $transfer->origin_unit_id) {
                throw new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['invariant' => 'D06_origin_is_open_period']);
            }
            $open = $open[0];
            $destination = $this->db->table('organizational_units as ou')->join('organizational_unit_types as ut', 'ut.id', '=', 'ou.unit_type_id')
                ->where('ou.id', $transfer->destination_unit_id)->sharedLock()->first(['ou.id', 'ou.status', 'ut.code as type_code']);
            if (!$destination || $destination->type_code !== 'CONGREGATION' || $destination->status !== 'ACTIVE' || (int) $destination->id === (int) $transfer->origin_unit_id) {
                throw new MembershipError(MembershipReason::CONGREGATION_NOT_ACTIVE, ['side' => 'destination']);
            }

            $at = $this->after((string) $open->starts_at);
            $closed = $this->db->table('membership_periods')->where('id', $open->id)->whereNull('ends_at')->where('lock_version', $open->lock_version)
                ->update(['ends_at' => $at, 'lock_version' => (int) $open->lock_version + 1]);
            if ($closed !== 1) {
                throw new MembershipError(MembershipReason::STALE_WRITE, ['entity' => 'membership_periods']);
            }
            $destinationPeriod = (int) $this->db->table('membership_periods')->insertGetId([
                'membership_id' => $membershipId, 'congregation_id' => (int) $transfer->destination_unit_id, 'status_id' => (int) $membership->status_id,
                'starts_at' => $at, 'ends_at' => null, 'reason' => 'Transferência ' . $transfer->public_id,
                'source_document_id' => $transfer->source_document_id, 'created_at' => $at, 'lock_version' => 0,
            ]);
            $this->db->table('memberships')->where('id', $membershipId)->where('lock_version', $membership->lock_version)->update(['lock_version' => (int) $membership->lock_version + 1]);
            $this->db->table('transfers')->where('id', $transferId)->where('lock_version', $transfer->lock_version)->update([
                'status' => MembershipCatalog::T_COMPLETED, 'effective_at' => $at, 'closed_at' => $at, 'lock_version' => (int) $transfer->lock_version + 1,
            ]);
            $this->workflow((int) $transfer->workflow_instance_id, MembershipCatalog::T_COMPLETED, $at);

            return ['replayed' => false, 'at' => $at, 'transfer' => $this->db->table('transfers')->where('id', $transferId)->first(), 'origin_period' => (int) $open->id, 'destination_period' => $destinationPeriod];
        });
    }

    /** Abandons a transfer without it ever taking effect. Idempotent: replaying cancel on an
     * already-cancelled transfer is a silent no-op. Frees the membership for a new request. */
    public function cancel(int $transferId, DateTimeImmutable $cancelledAt, string $status = MembershipCatalog::T_CANCELLED): void
    {
        $this->db->transaction(function () use ($transferId, $cancelledAt, $status) {
            $transfer = $this->db->table('transfers')->where('id', $transferId)->lockForUpdate()->first();
            if ($transfer === null) {
                throw new RuntimeException('TRANSFER_NOT_FOUND');
            }
            if ($transfer->closed_at !== null) {
                if ($transfer->effective_at === null) {
                    return;
                }
                throw new RuntimeException('TRANSFER_ALREADY_EFFECTUATED');
            }
            $at = $cancelledAt->format('Y-m-d H:i:s.u');
            $this->db->table('transfers')->where('id', $transferId)->update([
                'closed_at' => $at,
                'status' => $status,
                'lock_version' => (int) $transfer->lock_version + 1,
            ]);
            $this->workflow((int) $transfer->workflow_instance_id, $status, $at);
        });
    }

    /** The workflow instance follows the transfer state in the same transaction (D06). */
    private function workflow(int $instanceId, string $status, ?string $completedAt): void
    {
        $changes = ['status' => $status, 'lock_version' => $this->db->raw('lock_version + 1')];
        if ($completedAt !== null) {
            $changes['completed_at'] = $completedAt;
        }
        $this->db->table('workflow_instances')->where('id', $instanceId)->update($changes);
    }

    private function now(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }

    private function after(string $startsAt): string
    {
        $now = $this->now();
        return $now > $startsAt ? $now : (new DateTimeImmutable($startsAt, new DateTimeZone('UTC')))->modify('+1 microsecond')->format('Y-m-d H:i:s.u');
    }

    private function violates(QueryException $e, string $constraintName): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return in_array($code, [1062, 3819], true) && str_contains($e->getMessage(), $constraintName);
    }
}
