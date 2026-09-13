<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * P0.3.2: implements the MEPAAAMMSSSSSS generator documented in
 * docs/database/04_database_constraints.md ("Número Único: algoritmo documental").
 *
 * The national sequence is a single locked row (member_number_sequences.code = MEPA_NATIONAL).
 * AA/MM label the calendar period of issuance; SSSSSS is a strictly continuous national counter
 * that never resets on a month or year boundary. A membership can only ever receive one number
 * (member_numbers.membership_id is UNIQUE) - calling this again for the same membership replays
 * the existing number instead of allocating a new one.
 */
final class MemberNumberGenerator
{
    private const SEQUENCE_CODE = 'MEPA_NATIONAL';
    private const MAX_SEQUENCE = 999999;

    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{number:string, sequence_value:int, replayed:bool}
     */
    public function generateFor(int $membershipId, DateTimeImmutable $issuedAt, string $origin = 'APPROVED_ADMISSION'): array
    {
        return $this->db->transaction(function () use ($membershipId, $issuedAt, $origin) {
            // Lock the membership row first (documented lock order: idempotency -> membership -> sequence)
            // so two concurrent approvals of the SAME membership serialize here, before either reaches
            // the national singleton.
            $membership = $this->db->table('memberships')->where('id', $membershipId)->lockForUpdate()->first();
            if ($membership === null) {
                throw new RuntimeException('MEMBERSHIP_NOT_FOUND');
            }
            if ($membership->approved_at === null) {
                throw new RuntimeException('MEMBERSHIP_NOT_APPROVED');
            }

            $existing = $this->db->table('member_numbers')->where('membership_id', $membershipId)->first();
            if ($existing !== null) {
                return ['number' => $existing->number, 'sequence_value' => (int) $existing->sequence_value, 'replayed' => true];
            }

            $sequenceRow = $this->db->table('member_number_sequences')
                ->where('code', self::SEQUENCE_CODE)
                ->lockForUpdate()
                ->first();
            if ($sequenceRow === null) {
                throw new RuntimeException('MEMBER_NUMBER_SEQUENCE_NOT_PROVISIONED');
            }

            $next = (int) $sequenceRow->last_value + 1;
            if ($next > self::MAX_SEQUENCE) {
                throw new RuntimeException('MEMBER_NUMBER_SEQUENCE_EXHAUSTED');
            }

            $year = (int) $issuedAt->format('y');
            $month = (int) $issuedAt->format('n');
            $number = sprintf('MEPA%02d%02d%06d', $year, $month, $next);
            $timestamp = $issuedAt->format('Y-m-d H:i:s.u');

            $this->db->table('member_numbers')->insert([
                'membership_id' => $membershipId,
                'number' => $number,
                'sequence_value' => $next,
                'issued_year' => (int) $issuedAt->format('Y'),
                'issued_month' => $month,
                'issued_at' => $timestamp,
                'origin' => $origin,
                'created_at' => $timestamp,
            ]);

            $this->db->table('member_number_sequences')
                ->where('code', self::SEQUENCE_CODE)
                ->update(['last_value' => $next]);

            return ['number' => $number, 'sequence_value' => $next, 'replayed' => false];
        });
    }
}
