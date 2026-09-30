<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;
use Normalizer;

// Legacy member identifiers (ADR 0020 D07). They never replace, generate or change the official number.
// - raw_number is preserved exactly as received; normalized_number (NFKC, upper case, without spaces and - . /) is
//   searchable. source_system V1: MEPA_LEGACY_V1 only.
// - status ACTIVE / CONFLICT / REVOKED. The same value twice on one membership is 409 LEGACY_ID_EXISTS; the same value
//   on ANOTHER membership flags both rows CONFLICT without blocking. Revocation (reason required) is the resolution:
//   the row stays forever, is never reactivated or recycled; a remaining lone row returns to ACTIVE.
// - Concurrency (C5): every write locks the natural key (source_system, normalized_number) FOR UPDATE (a gap lock when
//   no row exists yet). Two concurrent registrations of one value on two memberships serialize (the InnoDB deadlock
//   victim is retried by the runtime) so the second always sees the first: both rows end CONFLICT, none is lost.
// - No hard delete; externally addressed by (membership public_id, source_system, normalized_number).
final class LegacyIdentifierService
{
    private MembershipRecords $records;

    public function __construct(private MembershipRuntime $rt)
    {
        $this->records = new MembershipRecords($rt);
    }

    public static function normalize(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $value = Normalizer::normalize($raw, Normalizer::FORM_KC);
        if (!is_string($value)) {
            return '';
        }
        return (string) preg_replace('/[\s\-\.\/]+/u', '', mb_strtoupper($value, 'UTF-8'));
    }

    public function list(int $user, int $session, string $membershipPublicId): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId): array {
            $guard->requires(MembershipCatalog::VIEW);
            $id = $this->records->membershipId($membershipPublicId);
            $this->records->authorizeRead($actor, $id, MembershipCatalog::VIEW);
            $rows = $this->rt->db->table('legacy_member_numbers')->where('membership_id', $id)->orderBy('created_at')->orderBy('id')->get()->all();
            return ['items' => array_map(fn (object $row): array => $this->project($actor, $row, $id), $rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'total' => count($rows)];
        });
    }

    public function register(int $user, int $session, string $membershipPublicId, array $in): array
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId, $in): array {
            $guard->requires(MembershipCatalog::LEGACY_MANAGE);
            $id = $this->records->membershipId($membershipPublicId);
            $source = $in['source_system'] ?? MembershipCatalog::LEGACY_SOURCE;
            if (!is_string($source) || !in_array($source, MembershipCatalog::LEGACY_SOURCES, true)) {
                throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'source_system']);
            }
            $raw = $in['raw_number'] ?? null;
            $normalized = self::normalize($raw);
            if (!is_string($raw) || trim($raw) === '' || mb_strlen($raw) > 191 || $normalized === '' || mb_strlen($normalized) > 191) {
                throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'raw_number']);
            }
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);
            [$person, $membership, $open, $decision] = $this->records->lockForWrite($guard, $id, MembershipCatalog::LEGACY_MANAGE);
            $this->records->assertPersonOperational($person);

            $rows = $this->keyRows($source, $normalized);
            foreach ($rows as $row) {
                if ((int) $row->membership_id === $id && $row->status !== MembershipCatalog::L_REVOKED) {
                    throw new MembershipError(MembershipReason::LEGACY_ID_EXISTS);
                }
            }
            $others = array_values(array_filter($rows, fn (object $row): bool => (int) $row->membership_id !== $id && $row->status !== MembershipCatalog::L_REVOKED));
            $status = $others === [] ? MembershipCatalog::L_ACTIVE : MembershipCatalog::L_CONFLICT;
            $at = $this->rt->ts();
            $rowId = (int) $this->rt->db->table('legacy_member_numbers')->insertGetId([
                'membership_id' => $id, 'source_system' => $source, 'raw_number' => $raw, 'normalized_number' => $normalized,
                'import_record_id' => null, 'status' => $status, 'created_at' => $at, 'lock_version' => 0,
            ]);
            $meta = ['membership' => (string) $membership->public_id, 'source_system' => $source, 'normalized_number' => $normalized];
            $this->rt->audit->record($actor, $decision->unit, 'legacy_identifier.registered', 'LEGACY_MEMBER_NUMBER', $rowId, null, $meta + ['status' => $status], $reason);
            if ($status === MembershipCatalog::L_CONFLICT) {
                $with = [];
                foreach ($others as $other) {
                    $otherPublic = (string) $this->rt->db->table('memberships')->where('id', $other->membership_id)->value('public_id');
                    $with[] = $otherPublic;
                    if ($other->status === MembershipCatalog::L_ACTIVE) {
                        $this->rt->db->table('legacy_member_numbers')->where('id', $other->id)->where('lock_version', $other->lock_version)
                            ->update(['status' => MembershipCatalog::L_CONFLICT, 'lock_version' => (int) $other->lock_version + 1]);
                        $this->rt->audit->record($actor, $decision->unit, 'legacy_identifier.conflict_flagged', 'LEGACY_MEMBER_NUMBER', (int) $other->id,
                            ['status' => MembershipCatalog::L_ACTIVE], ['status' => MembershipCatalog::L_CONFLICT, 'membership' => $otherPublic, 'source_system' => $source,
                                'normalized_number' => $normalized, 'conflict_with' => [(string) $membership->public_id]]);
                    }
                }
                $this->rt->audit->record($actor, $decision->unit, 'legacy_identifier.conflict_flagged', 'LEGACY_MEMBER_NUMBER', $rowId, null,
                    $meta + ['status' => MembershipCatalog::L_CONFLICT, 'conflict_with' => array_values(array_unique($with))]);
            }
            return $this->project($actor, $this->rt->db->table('legacy_member_numbers')->where('id', $rowId)->first(), $id);
        });
    }

    public function revoke(int $user, int $session, string $membershipPublicId, array $in): array
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId, $in): array {
            $guard->requires(MembershipCatalog::LEGACY_MANAGE);
            $id = $this->records->membershipId($membershipPublicId);
            $source = $in['source_system'] ?? MembershipCatalog::LEGACY_SOURCE;
            $normalized = self::normalize($in['normalized_number'] ?? null);
            $reason = MembershipRecords::requireReason($in['reason'] ?? null);
            [$person, $membership, $open, $decision] = $this->records->lockForWrite($guard, $id, MembershipCatalog::LEGACY_MANAGE);
            $this->records->assertPersonOperational($person, true);
            if (!is_string($source) || $normalized === '') {
                throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'legacy_member_numbers']);
            }
            $rows = $this->keyRows($source, $normalized);
            $target = null;
            foreach ($rows as $row) {
                if ((int) $row->membership_id === $id && $row->status !== MembershipCatalog::L_REVOKED) {
                    $target = $row;
                }
            }
            if ($target === null) {
                throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'legacy_member_numbers']);
            }
            if (array_key_exists('lock_version', $in)) {
                $this->records->assertVersion($target, $in['lock_version'], 'legacy_member_numbers');
            }
            $this->rt->db->table('legacy_member_numbers')->where('id', $target->id)->where('lock_version', $target->lock_version)
                ->update(['status' => MembershipCatalog::L_REVOKED, 'lock_version' => (int) $target->lock_version + 1]);
            // A value left on exactly one membership is no longer in conflict.
            $remaining = array_values(array_filter($rows, fn (object $row): bool => (int) $row->id !== (int) $target->id && $row->status !== MembershipCatalog::L_REVOKED));
            $cleared = [];
            if (count(array_unique(array_map(fn (object $row): int => (int) $row->membership_id, $remaining))) === 1) {
                foreach ($remaining as $row) {
                    if ($row->status === MembershipCatalog::L_CONFLICT) {
                        $this->rt->db->table('legacy_member_numbers')->where('id', $row->id)->where('lock_version', $row->lock_version)
                            ->update(['status' => MembershipCatalog::L_ACTIVE, 'lock_version' => (int) $row->lock_version + 1]);
                        $cleared[] = (string) $this->rt->db->table('memberships')->where('id', $row->membership_id)->value('public_id');
                    }
                }
            }
            $this->rt->audit->record($actor, $decision->unit, 'legacy_identifier.revoked', 'LEGACY_MEMBER_NUMBER', (int) $target->id,
                ['status' => (string) $target->status], ['status' => MembershipCatalog::L_REVOKED, 'membership' => (string) $membership->public_id,
                    'source_system' => $source, 'normalized_number' => $normalized, 'conflict_cleared' => array_values(array_unique($cleared))], $reason);
            return $this->project($actor, $this->rt->db->table('legacy_member_numbers')->where('id', $target->id)->first(), $id);
        });
    }

    /** @return list<object> every row of the natural key, locked FOR UPDATE in id order (gap lock when none). */
    private function keyRows(string $source, string $normalized): array
    {
        return $this->rt->db->table('legacy_member_numbers')->where('source_system', $source)->where('normalized_number', $normalized)->orderBy('id')->lockForUpdate()->get()->all();
    }

    /**
     * Projection. A CONFLICT row names the other memberships only when the actor sees ALL of them (MEMBERSHIP_VIEW over
     * the Congregation of each open period); otherwise it says "in conflict" without any identity.
     */
    private function project(TerritorialActor $actor, object $row, int $membershipId): array
    {
        $conflict = null;
        if ($row->status === MembershipCatalog::L_CONFLICT) {
            $others = $this->rt->db->table('legacy_member_numbers as l')->join('memberships as m', 'm.id', '=', 'l.membership_id')
                ->join('membership_periods as mp', function ($join): void {
                    $join->on('mp.membership_id', '=', 'm.id')->whereNull('mp.ends_at');
                })
                ->where('l.source_system', $row->source_system)->where('l.normalized_number', $row->normalized_number)
                ->where('l.membership_id', '!=', $membershipId)->where('l.status', '!=', MembershipCatalog::L_REVOKED)
                ->get(['m.public_id', 'mp.congregation_id'])->all();
            $visible = array_filter($others, fn (object $o): bool => $this->rt->authority->holdsOn($actor, MembershipCatalog::VIEW, (int) $o->congregation_id));
            $conflict = count($visible) === count($others) && $others !== []
                ? ['detail_visible' => true, 'memberships' => array_values(array_unique(array_map(fn (object $o): string => (string) $o->public_id, $others)))]
                : ['detail_visible' => false, 'memberships' => []];
        }
        return [
            'source_system' => (string) $row->source_system,
            'raw_number' => (string) $row->raw_number,
            'normalized_number' => (string) $row->normalized_number,
            'status' => (string) $row->status,
            'status_label' => MembershipCatalog::LEGACY_STATUSES[(string) $row->status] ?? (string) $row->status,
            'conflict' => $conflict,
            'registered_at' => (string) $row->created_at,
            'lock_version' => (int) $row->lock_version,
        ];
    }
}
