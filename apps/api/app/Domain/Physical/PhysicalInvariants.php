<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use Illuminate\Database\Connection;

// Commit-time invariants of ADR 0018 (D04, D08, D10), evaluated with the operation's locks still held. They live in
// the application (no partial unique index exists), so every write path re-asserts them before commit. Every read here
// is a LOCKING read (FOR SHARE): it sees the latest committed rows, never this transaction's older snapshot.
final class PhysicalInvariants
{
    /** @param list<int> $units @param list<int> $locations */
    public static function assert(Connection $db, array $units, array $locations, string $now): void
    {
        foreach ($units as $unit) {
            $active = $db->select("SELECT location_id, is_primary FROM unit_location_links WHERE unit_id = ? AND status = 'ACTIVE' FOR SHARE", [$unit]);
            if (count(array_filter($active, fn (object $l): bool => (int) $l->is_primary === 1)) > 1) {
                throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['invariant' => 'D10.3_single_primary']);
            }
            $pairs = array_map(fn (object $l): int => (int) $l->location_id, $active);
            if (count($pairs) !== count(array_unique($pairs))) {
                throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['invariant' => 'D10.4_single_active_pair']);
            }
        }
        foreach ($locations as $location) {
            $row = $db->selectOne('SELECT status, public_visibility, latitude, longitude FROM physical_locations WHERE id = ? FOR SHARE', [$location]);
            if (!$row) {
                continue;
            }
            $links = $db->select("SELECT unit_id, starts_at, ends_at FROM unit_location_links WHERE location_id = ? AND status = 'ACTIVE' FOR SHARE", [$location]);
            $vigente = array_filter($links, fn (object $l): bool => (string) $l->starts_at <= $now && ($l->ends_at === null || (string) $l->ends_at > $now));
            if ($row->status !== PhysicalCatalog::CLOSED && $vigente === []) {
                throw new PhysicalError(PhysicalReason::LAST_ACTIVE_LINK_REQUIRED, ['invariant' => 'D04_operational_link']);
            }
            $units = array_map(fn (object $l): int => (int) $l->unit_id, $links);
            if (count($units) !== count(array_unique($units))) {
                throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['invariant' => 'D10.4_single_active_pair']);
            }
            if ($row->public_visibility === PhysicalCatalog::APPROVED_PUBLIC && ($row->status !== PhysicalCatalog::ACTIVE || $row->latitude === null || $row->longitude === null)) {
                throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['invariant' => 'D08_publication']);
            }
        }
    }
}
