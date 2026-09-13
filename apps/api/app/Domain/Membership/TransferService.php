<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * P0.3.2-M1: fixes F-W2F-01. A membership must never have more than one `transfers` row "in
 * flight" (requested but not yet closed) at a time - two concurrent, incompatible requests for
 * the same membership must not both persist.
 *
 * `transfers.status` has no institutionally fixed vocabulary yet (D-11 pending -
 * 04_database_constraints.md: "catálogo exacto por tabela... antes de migrations"), so the
 * in-flight invariant is deliberately status-string-agnostic: it depends only on `closed_at`
 * (set the moment a transfer stops being in flight, for any reason) and `effective_at` (set only
 * when the transfer actually took effect - the "Efectivação" stage of the canonical
 * Pedido -> Validação de Origem -> Aceitação do Destino -> Efectivação flow). The physical
 * backstop is `uq_transfers_membership_open`, a UNIQUE(membership_id, open_flag) index on a
 * generated column that is 1 only while closed_at IS NULL - see migration
 * 2026_09_14_000001_wave2m1_add_transfers_open_guard.php.
 *
 * Lock order follows the same anchor-first pattern already used by MemberNumberGenerator and
 * documented in 04_database_constraints.md ("gerador idempotência->membership->contador"): the
 * membership row is locked first, before any write to `transfers`.
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
        string $status = 'PENDING',
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

    /** Marks a transfer as having taken effect (Efectivação). Idempotent: replaying the same
     * effectuation on an already-effectuated transfer is a silent no-op. */
    public function effectuate(int $transferId, DateTimeImmutable $effectiveAt, string $status = 'COMPLETED'): void
    {
        $this->db->transaction(function () use ($transferId, $effectiveAt, $status) {
            $transfer = $this->db->table('transfers')->where('id', $transferId)->lockForUpdate()->first();
            if ($transfer === null) {
                throw new RuntimeException('TRANSFER_NOT_FOUND');
            }
            if ($transfer->closed_at !== null) {
                if ($transfer->effective_at !== null) {
                    return;
                }
                throw new RuntimeException('TRANSFER_ALREADY_CANCELLED');
            }
            $timestamp = $effectiveAt->format('Y-m-d H:i:s.u');
            $this->db->table('transfers')->where('id', $transferId)->update([
                'effective_at' => $timestamp,
                'closed_at' => $timestamp,
                'status' => $status,
            ]);
        });
    }

    /** Abandons a transfer without it ever taking effect. Idempotent: replaying cancel on an
     * already-cancelled transfer is a silent no-op. Frees the membership for a new request. */
    public function cancel(int $transferId, DateTimeImmutable $cancelledAt, string $status = 'CANCELLED'): void
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
            $this->db->table('transfers')->where('id', $transferId)->update([
                'closed_at' => $cancelledAt->format('Y-m-d H:i:s.u'),
                'status' => $status,
            ]);
        });
    }

    private function violates(QueryException $e, string $constraintName): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return in_array($code, [1062, 3819], true) && str_contains($e->getMessage(), $constraintName);
    }
}
