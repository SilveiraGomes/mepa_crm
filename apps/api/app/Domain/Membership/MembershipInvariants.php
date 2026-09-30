<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use Illuminate\Database\Connection;

// Commit-time invariants of ADR 0020 (D02, D03, D06), evaluated with the operation's locks still held. Every read here
// is a LOCKING read (FOR SHARE): it sees the latest committed rows, never this transaction's older snapshot.
//
// For every membership the operation touched:
//   I1 exactly one open period (ends_at IS NULL) - also enforced physically by uq_membership_periods_membership_open;
//   I2 memberships.status_id = status of the open period;
//   I3 the open period's unit is a CONGREGATION;
//   I4 history is contiguous: no closed period ends after the open one starts, and the latest one ends exactly there;
//   I5 a candidacy (SUBMITTED/VALIDATED/REJECTED/WITHDRAWN) has no number and no approval; any other state has both;
//   I6 the official number never changes; a first number appears only on the approval that issues it;
//   I7 an open transfer exists only for an ACTIVE membership, starting at the Congregation of the open period;
//   I8 the national counter equals the highest issued sequence (checked when a number was issued).
final class MembershipInvariants
{
    public static function assert(Connection $db, MembershipGuard $guard): void
    {
        $issued = false;
        foreach ($guard->memberships() as $membership => $numberBefore) {
            $row = $db->selectOne('SELECT m.id, m.status_id, m.approved_at, m.approved_by, s.code FROM memberships m JOIN membership_statuses s ON s.id = m.status_id WHERE m.id = ? FOR SHARE', [$membership]);
            if (!$row) {
                throw self::fail('membership_missing');
            }
            $open = $db->select('SELECT mp.id, mp.congregation_id, mp.status_id, mp.starts_at, ut.code AS unit_type FROM membership_periods mp JOIN organizational_units ou ON ou.id = mp.congregation_id JOIN organizational_unit_types ut ON ut.id = ou.unit_type_id WHERE mp.membership_id = ? AND mp.ends_at IS NULL FOR SHARE', [$membership]);
            if (count($open) !== 1) {
                throw self::fail('I1_single_open_period');
            }
            $open = $open[0];
            if ((int) $open->status_id !== (int) $row->status_id) {
                throw self::fail('I2_status_matches_open_period');
            }
            if ($open->unit_type !== 'CONGREGATION') {
                throw self::fail('I3_open_period_congregation');
            }
            $closed = $db->selectOne('SELECT MAX(ends_at) AS last_end, SUM(ends_at > ?) AS overlaps FROM membership_periods WHERE membership_id = ? AND ends_at IS NOT NULL FOR SHARE', [$open->starts_at, $membership]);
            if ((int) ($closed->overlaps ?? 0) > 0 || ($closed->last_end !== null && (string) $closed->last_end !== (string) $open->starts_at)) {
                throw self::fail('I4_contiguous_history');
            }
            $number = $db->selectOne('SELECT number, sequence_value FROM member_numbers WHERE membership_id = ? FOR SHARE', [$membership]);
            $candidate = in_array($row->code, MembershipCatalog::CANDIDATE_STATES, true);
            if ($candidate && ($number !== null || $row->approved_at !== null)) {
                throw self::fail('I5_candidacy_without_number');
            }
            if (!$candidate && ($number === null || $row->approved_at === null || $row->approved_by === null)) {
                throw self::fail('I5_member_has_number');
            }
            if ($numberBefore !== null && ($number === null || (string) $number->number !== $numberBefore)) {
                throw self::fail('I6_number_immutable');
            }
            if ($numberBefore === null && $number !== null) {
                if (!$guard->isIssuing($membership)) {
                    throw self::fail('I6_number_only_on_approval');
                }
                $issued = true;
            }
            $transfers = $db->select('SELECT status, origin_unit_id FROM transfers WHERE membership_id = ? AND closed_at IS NULL FOR SHARE', [$membership]);
            foreach ($transfers as $transfer) {
                if ($row->code !== MembershipCatalog::ACTIVE || (int) $transfer->origin_unit_id !== (int) $open->congregation_id || !in_array($transfer->status, MembershipCatalog::TRANSFER_OPEN, true)) {
                    throw self::fail('I7_open_transfer_coherent');
                }
            }
        }
        if ($issued) {
            $counter = $db->selectOne('SELECT s.`last_value` AS current_value, (SELECT MAX(sequence_value) FROM member_numbers) AS max_value FROM member_number_sequences s WHERE s.code = ? FOR SHARE', [MembershipCatalog::COUNTER]);
            if (!$counter || (int) $counter->current_value !== (int) $counter->max_value) {
                throw self::fail('I8_counter_continuous');
            }
        }
    }

    private static function fail(string $invariant): MembershipError
    {
        return new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['invariant' => $invariant]);
    }
}
