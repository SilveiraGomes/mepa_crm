<?php

declare(strict_types=1);

namespace App\Domain\People;

// Factual relationships between two People (ADR-0017 D02). Semantics come from the catalog
// (relationship_types.semantics + inverse_relationship_type_id), never from scattered code:
//   SYMMETRIC       one row per pair, stored in canonical order (lower internal id as subject);
//                   SPOUSE(A,B) and SPOUSE(B,A) are the same fact and are never duplicated.
//   INVERSE_PAIRED  two rows written and ended in ONE transaction: PARENT(A,B) <-> CHILD(B,A).
// Authority is required over BOTH People. GUARDIAN / DEPENDENT are factual only: nothing here writes
// or reads guardian_authorizations, consents or custody, and no Children authority is derived.
final class RelationshipService
{
    private PersonRecords $people;

    public function __construct(private PeopleRuntime $rt)
    {
        $this->people = new PersonRecords($rt);
    }

    public function list(int $user, int $session, string $publicId, bool $history): array
    {
        $id = $this->people->id($publicId);
        return $this->rt->read($user, $session, function (PeopleGuard $guard) use ($id, $history): array {
            $this->readAuthority($guard, $id);
            $rows = $this->perspectiveRows($id, $history);
            $others = array_map(fn (object $r): int => (int) $r->other_id, $rows);
            $children = $this->people->childProfiles($others);
            $items = [];
            foreach ($rows as $row) {
                // The other party is shown only if the actor can see that Person at all.
                if (!$this->rt->authority->canView($guard->actor, (int) $row->other_id)) {
                    continue;
                }
                $other = $this->people->row((int) $row->other_id);
                $minimal = $this->people->minimal($other, isset($children[(int) $row->other_id]));
                $items[] = [
                    'ref' => $this->rt->refs->for('person_relationships', (int) $row->id),
                    'type' => (string) $row->type_code,
                    'type_name' => (string) $row->type_name,
                    'semantics' => (string) $row->semantics,
                    'person' => ['public_id' => $minimal['public_id'], 'display_name' => $minimal['display_name'], 'age_band' => $minimal['age_band']],
                    'status' => (string) $row->status,
                    'starts_at' => (string) $row->starts_at,
                    'ends_at' => $row->ends_at === null ? null : (string) $row->ends_at,
                ];
            }
            return $items;
        });
    }

    public function create(int $user, int $session, string $publicId, array $in): array
    {
        $subject = $this->people->id($publicId);
        $related = $this->people->id($in['related_person'] ?? null);
        if ($subject === $related) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'related_person']);
        }
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($subject, $related, $in): void {
            $subjectDecision = $guard->require(PeopleCatalog::RELATIONSHIP_MANAGE, $subject);
            $relatedDecision = $guard->require(PeopleCatalog::RELATIONSHIP_MANAGE, $related);
            $type = $this->rt->db->table('relationship_types')->where('code', (string) ($in['type'] ?? ''))->where('is_active', 1)->first();
            if (!$type) {
                throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'type']);
            }
            PeopleCatalog::assertPairing($this->rt->db, (int) $type->id);
            // Serialize concurrent writers on the same pair: both People locked in id order.
            foreach ([min($subject, $related), max($subject, $related)] as $personId) {
                $this->people->row($personId, true);
            }
            $now = $this->rt->ts();
            $ids = [];
            if ($type->semantics === PeopleCatalog::SYMMETRIC) {
                [$a, $b] = [min($subject, $related), max($subject, $related)];
                if ($this->active($a, $b, (int) $type->id) || $this->active($b, $a, (int) $type->id)) {
                    throw new PeopleError(PeopleReason::RELATIONSHIP_EXISTS);
                }
                $ids[] = $this->insert($a, $b, (int) $type->id, $now);
            } else {
                $inverse = (int) $type->inverse_relationship_type_id;
                if ($this->active($subject, $related, (int) $type->id) || $this->active($related, $subject, $inverse)) {
                    throw new PeopleError(PeopleReason::RELATIONSHIP_EXISTS);
                }
                // PARENT(A,B) contradicts CHILD(A,B) / PARENT(B,A).
                if ($this->active($subject, $related, $inverse) || $this->active($related, $subject, (int) $type->id)) {
                    throw new PeopleError(PeopleReason::RELATIONSHIP_CONFLICT);
                }
                $ids[] = $this->insert($subject, $related, (int) $type->id, $now);
                $ids[] = $this->insert($related, $subject, $inverse, $now);
            }
            $this->rt->audit->record($actor, $subjectDecision, 'RELATIONSHIP_CREATED', 'person_relationships', $ids[0], null, [
                'type' => (string) $type->code,
                'semantics' => (string) $type->semantics,
                'rows' => $ids,
                'related_context_unit' => $relatedDecision->unitId,
                'children_authority_granted' => false,
            ]);
        });
        return $this->list($user, $session, $publicId, false);
    }

    public function end(int $user, int $session, string $publicId, string $ref, ?string $reason): void
    {
        $id = $this->people->id($publicId);
        $this->rt->write($user, $session, function (PeopleGuard $guard, PeopleActor $actor) use ($id, $ref, $reason): void {
            $candidates = array_map(fn (object $r): int => (int) $r->id, $this->perspectiveRows($id, true));
            $rowId = $this->rt->refs->resolve('person_relationships', $ref, $candidates);
            if ($rowId === null) {
                // Unknown reference, or the Person itself is not visible: one external response.
                $guard->require(PeopleCatalog::RELATIONSHIP_MANAGE, $id);
                throw new PeopleError(PeopleReason::TARGET_NOT_FOUND, ['entity' => 'person_relationships']);
            }
            $row = $this->rt->db->table('person_relationships as pr')->join('relationship_types as rt', 'rt.id', '=', 'pr.relationship_type_id')
                ->where('pr.id', $rowId)->first(['pr.*', 'rt.code as type_code', 'rt.semantics', 'rt.inverse_relationship_type_id']);
            $subjectDecision = $guard->require(PeopleCatalog::RELATIONSHIP_MANAGE, (int) $row->subject_person_id);
            $guard->require(PeopleCatalog::RELATIONSHIP_MANAGE, (int) $row->related_person_id);
            foreach ([min((int) $row->subject_person_id, (int) $row->related_person_id), max((int) $row->subject_person_id, (int) $row->related_person_id)] as $personId) {
                $this->people->row($personId, true);
            }
            if ($row->status !== PeopleCatalog::LINK_ACTIVE) {
                throw new PeopleError(PeopleReason::TRANSITION_NOT_ALLOWED, ['entity' => 'person_relationships']);
            }
            $now = $this->rt->ts();
            $reason = $reason === null ? null : trim($reason);
            $ended = [$this->close((int) $row->id, $now, $reason)];
            if ($row->semantics === PeopleCatalog::INVERSE_PAIRED) {
                $pair = $this->rt->db->table('person_relationships')->where('subject_person_id', $row->related_person_id)->where('related_person_id', $row->subject_person_id)
                    ->where('relationship_type_id', $row->inverse_relationship_type_id)->where('status', PeopleCatalog::LINK_ACTIVE)->lockForUpdate()->first();
                if (!$pair) {
                    throw new PeopleError(PeopleReason::INVARIANT_VIOLATION, ['reason' => 'inverse_missing']);
                }
                $ended[] = $this->close((int) $pair->id, $now, $reason);
            }
            $this->rt->audit->record($actor, $subjectDecision, 'RELATIONSHIP_ENDED', 'person_relationships', (int) $row->id, ['status' => PeopleCatalog::LINK_ACTIVE], ['status' => PeopleCatalog::LINK_INACTIVE, 'type' => (string) $row->type_code, 'rows' => $ended], $reason);
        });
    }

    /** Rows seen from one Person: every row where it is the subject + symmetric rows where it is the related party. */
    private function perspectiveRows(int $personId, bool $history): array
    {
        $query = $this->rt->db->table('person_relationships as pr')->join('relationship_types as rt', 'rt.id', '=', 'pr.relationship_type_id')
            ->where(fn ($w) => $w->where('pr.subject_person_id', $personId)->orWhere(fn ($s) => $s->where('pr.related_person_id', $personId)->where('rt.semantics', PeopleCatalog::SYMMETRIC)));
        if (!$history) {
            $query->where('pr.status', PeopleCatalog::LINK_ACTIVE);
        }
        return $query->orderByRaw("pr.status = 'ACTIVE' DESC")->orderBy('pr.starts_at')->orderBy('pr.id')
            ->get(['pr.*', 'rt.code as type_code', 'rt.name as type_name', 'rt.semantics', $this->rt->db->raw('CASE WHEN pr.subject_person_id = ' . (int) $personId . ' THEN pr.related_person_id ELSE pr.subject_person_id END AS other_id')])->all();
    }

    /** Parentage is Class B: PEOPLE_SENSITIVE_VIEW or RELATIONSHIP_MANAGE in a GENERAL context, never for protected minors. */
    private function readAuthority(PeopleGuard $guard, int $id): void
    {
        $guard->require(PeopleCatalog::PEOPLE_VIEW, $id, null, [PeopleAuthority::GENERAL, PeopleAuthority::ACADEMY, PeopleAuthority::CHILDREN]);
        $person = $this->people->row($id);
        if ($this->people->isProtected($person, isset($this->people->childProfiles([$id])[$id]))) {
            throw new PeopleError(PeopleReason::MINOR_PROTECTED, ['area' => 'relationships']);
        }
        if (!$guard->holds(PeopleCatalog::PEOPLE_SENSITIVE_VIEW, $id) && !$guard->holds(PeopleCatalog::RELATIONSHIP_MANAGE, $id)) {
            throw new PeopleError(PeopleReason::SENSITIVE_RESTRICTED, ['area' => 'relationships']);
        }
    }

    private function active(int $subject, int $related, int $type): bool
    {
        return $this->rt->db->table('person_relationships')->where('subject_person_id', $subject)->where('related_person_id', $related)
            ->where('relationship_type_id', $type)->where('status', PeopleCatalog::LINK_ACTIVE)->exists();
    }

    private function insert(int $subject, int $related, int $type, string $now): int
    {
        return (int) $this->rt->db->table('person_relationships')->insertGetId([
            'subject_person_id' => $subject, 'related_person_id' => $related, 'relationship_type_id' => $type, 'status' => PeopleCatalog::LINK_ACTIVE,
            'starts_at' => $now, 'ends_at' => null, 'reason' => null, 'source_document_id' => null, 'created_at' => $now, 'lock_version' => 0,
        ]);
    }

    private function close(int $id, string $now, ?string $reason): int
    {
        $row = $this->rt->db->table('person_relationships')->where('id', $id)->lockForUpdate()->first();
        $updated = $this->rt->db->table('person_relationships')->where('id', $id)->where('lock_version', $row->lock_version)
            ->update(['status' => PeopleCatalog::LINK_INACTIVE, 'ends_at' => $now, 'reason' => $reason, 'lock_version' => (int) $row->lock_version + 1]);
        if ($updated !== 1) {
            throw new PeopleError(PeopleReason::STALE_WRITE, ['entity' => 'person_relationships']);
        }
        return $id;
    }
}
