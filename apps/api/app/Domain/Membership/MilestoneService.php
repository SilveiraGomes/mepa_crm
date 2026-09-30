<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Territorial\TerritorialActor;

// Ecclesiastical milestones V1 (ADR 0020 D08): CONVERSION and BAPTISM are facts of the PERSON, never a membership
// state; admission is NOT a milestone (it lives in memberships.admitted_on). At most one per type per Person, checked
// under the Person lock. Precision EXACT/MONTH/YEAR/UNKNOWN (UNKNOWN stores NULL); future dates are refused and no
// day/month is invented. A correction is an UPDATE with a mandatory reason and before/after audit. In V1 they are
// recorded and read through the membership (member or candidate); the authority is the Congregation of its open
// period, and unit_public_id is informative only (it grants nothing).
final class MilestoneService
{
    private MembershipRecords $records;

    public function __construct(private MembershipRuntime $rt)
    {
        $this->records = new MembershipRecords($rt);
    }

    public function list(int $user, int $session, string $membershipPublicId): array
    {
        return $this->rt->read($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId): array {
            $guard->requires(MembershipCatalog::VIEW);
            $id = $this->records->membershipId($membershipPublicId);
            $this->records->authorizeRead($actor, $id, MembershipCatalog::VIEW);
            $personId = (int) $this->rt->db->table('memberships')->where('id', $id)->value('person_id');
            $rows = $this->rt->db->table('ecclesiastical_milestones as e')->join('milestone_types as t', 't.id', '=', 'e.milestone_type_id')
                ->where('e.person_id', $personId)->whereIn('t.code', array_keys(MembershipCatalog::MILESTONE_TYPES))->orderBy('t.code')->get(['e.*', 't.code as type_code'])->all();
            return ['items' => array_map(fn (object $row): array => $this->project($actor, $row), $rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'total' => count($rows)];
        });
    }

    public function record(int $user, int $session, string $membershipPublicId, array $in): array
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId, $in): array {
            $guard->requires(MembershipCatalog::MANAGE);
            $id = $this->records->membershipId($membershipPublicId);
            $type = $this->type($in['type'] ?? null);
            [$person, $membership, $open, $decision] = $this->records->lockForWrite($guard, $id, MembershipCatalog::MANAGE);
            $this->records->assertPersonOperational($person);
            [$date, $precision] = PrecisionDate::parse($in['occurred_on'] ?? null, $in['date_precision'] ?? null, $this->rt->today(), 'occurred_on');
            $unit = $this->unit($in['unit_public_id'] ?? null);
            if ($this->rt->db->table('ecclesiastical_milestones')->where('person_id', $person->id)->where('milestone_type_id', $type->id)->lockForUpdate()->exists()) {
                throw new MembershipError(MembershipReason::MILESTONE_EXISTS);
            }
            $document = $this->rt->sourceDocument($guard, $actor, $in['source_document'] ?? null);
            $reason = MembershipRecords::optionalReason($in['reason'] ?? null);
            $rowId = (int) $this->rt->db->table('ecclesiastical_milestones')->insertGetId([
                'person_id' => (int) $person->id, 'milestone_type_id' => (int) $type->id, 'occurred_on' => $date, 'date_precision' => $precision,
                'unit_id' => $unit?->id === null ? null : (int) $unit->id, 'source_document_id' => $document?->id === null ? null : (int) $document->id,
                'created_at' => $this->rt->ts(), 'lock_version' => 0,
            ]);
            $this->rt->audit->record($actor, $decision->unit, 'milestone.recorded', 'ECCLESIASTICAL_MILESTONE', $rowId, null, [
                'membership' => (string) $membership->public_id, 'person' => (string) $person->public_id, 'type' => (string) $type->code,
                'occurred_on' => PrecisionDate::present($date, $precision), 'date_precision' => $precision, 'unit' => $unit?->public_id, 'source_document' => $document?->public_id,
            ], $reason);
            return $this->project($actor, $this->row($rowId));
        });
    }

    public function correct(int $user, int $session, string $membershipPublicId, string $typeCode, array $in): array
    {
        return $this->rt->write($user, $session, function (MembershipGuard $guard, TerritorialActor $actor) use ($membershipPublicId, $typeCode, $in): array {
            $guard->requires(MembershipCatalog::MANAGE);
            $id = $this->records->membershipId($membershipPublicId);
            $reason = MembershipRecords::requireReason($in['reason'] ?? null);
            [$person, $membership, $open, $decision] = $this->records->lockForWrite($guard, $id, MembershipCatalog::MANAGE);
            $type = $this->rt->db->table('milestone_types')->where('code', $typeCode)->whereIn('code', array_keys(MembershipCatalog::MILESTONE_TYPES))->first();
            $row = $type ? $this->rt->db->table('ecclesiastical_milestones')->where('person_id', $person->id)->where('milestone_type_id', $type->id)->lockForUpdate()->first() : null;
            if (!$row) {
                throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'ecclesiastical_milestones']);
            }
            $this->records->assertPersonOperational($person);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'ecclesiastical_milestones');
            [$date, $precision] = PrecisionDate::parse($in['occurred_on'] ?? null, $in['date_precision'] ?? null, $this->rt->today(), 'occurred_on');
            $unit = array_key_exists('unit_public_id', $in) ? $this->unit($in['unit_public_id']) : ($row->unit_id === null ? null : $this->records->unitRow((int) $row->unit_id));
            $document = array_key_exists('source_document', $in) ? $this->rt->sourceDocument($guard, $actor, $in['source_document']) : null;
            $documentId = array_key_exists('source_document', $in) ? ($document?->id === null ? null : (int) $document->id) : ($row->source_document_id === null ? null : (int) $row->source_document_id);
            $changed = $this->rt->db->table('ecclesiastical_milestones')->where('id', $row->id)->where('lock_version', $row->lock_version)->update([
                'occurred_on' => $date, 'date_precision' => $precision, 'unit_id' => $unit?->id === null ? null : (int) $unit->id,
                'source_document_id' => $documentId, 'lock_version' => (int) $row->lock_version + 1,
            ]);
            if ($changed !== 1) {
                throw new MembershipError(MembershipReason::STALE_WRITE, ['entity' => 'ecclesiastical_milestones']);
            }
            $this->rt->audit->record($actor, $decision->unit, 'milestone.corrected', 'ECCLESIASTICAL_MILESTONE', (int) $row->id, [
                'type' => (string) $type->code, 'occurred_on' => PrecisionDate::present($row->occurred_on === null ? null : (string) $row->occurred_on, (string) $row->date_precision),
                'date_precision' => (string) $row->date_precision, 'unit' => $row->unit_id === null ? null : $this->records->unitRef((int) $row->unit_id)['public_id'] ?? null,
                'had_document' => $row->source_document_id !== null, 'lock_version' => (int) $row->lock_version,
            ], [
                'membership' => (string) $membership->public_id, 'person' => (string) $person->public_id, 'type' => (string) $type->code,
                'occurred_on' => PrecisionDate::present($date, $precision), 'date_precision' => $precision, 'unit' => $unit?->public_id,
                'has_document' => $documentId !== null, 'source_document' => $document?->public_id, 'lock_version' => (int) $row->lock_version + 1,
            ], $reason);
            return $this->project($actor, $this->row((int) $row->id));
        });
    }

    private function type(mixed $code): object
    {
        $row = is_string($code) && isset(MembershipCatalog::MILESTONE_TYPES[$code]) ? $this->rt->db->table('milestone_types')->where('code', $code)->where('is_active', 1)->first() : null;
        if (!$row) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'type']);
        }
        return $row;
    }

    /** Informative unit (any organizational unit by public id); unknown or malformed is a validation error. */
    private function unit(mixed $publicId): ?object
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        try {
            return $this->records->unit($publicId);
        } catch (MembershipError) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'unit_public_id']);
        }
    }

    private function row(int $id): object
    {
        return $this->rt->db->table('ecclesiastical_milestones as e')->join('milestone_types as t', 't.id', '=', 'e.milestone_type_id')->where('e.id', $id)->first(['e.*', 't.code as type_code']);
    }

    private function project(TerritorialActor $actor, object $row): array
    {
        return [
            'type' => (string) $row->type_code,
            'type_label' => MembershipCatalog::MILESTONE_TYPES[(string) $row->type_code] ?? (string) $row->type_code,
            'occurred_on' => PrecisionDate::present($row->occurred_on === null ? null : (string) $row->occurred_on, (string) $row->date_precision),
            'date_precision' => (string) $row->date_precision,
            'unit' => $this->records->unitRef($row->unit_id === null ? null : (int) $row->unit_id),
            'lock_version' => (int) $row->lock_version,
        ] + $this->rt->documentProjection($actor, $row->source_document_id === null ? null : (int) $row->source_document_id);
    }
}
