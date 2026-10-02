<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

/**
 * MEPA employment (ADR 0021 D22/D23).
 *
 * Employee = Person (ADR 0001/0017) + employment: the Person is consumed by public_id and must be visible to the actor
 * through PeopleAuthority; nothing is written to `people` and no personal attribute is copied. `person_employment` (the
 * Person's professional situation) is never read or written. An employment is NOT an ecclesiastical appointment,
 * ministerial assignment, membership or post: no link to any of them exists.
 *
 * Owner = the employing organizational unit (never a department). create / end only (no generic status PATCH); an ended
 * employment stays as history (no hard delete). A change of employing unit = end the employment + create a new one, so
 * "which employment did this Person have in period P?" is always answered from the rows themselves.
 * Opening an employment opens the People context EMPLOYMENT at the employing unit; ending it closes that context.
 */
final class EmploymentService extends PayrollService
{
    public function create(int $user, int $session, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(PayrollCatalog::HR_EMPLOYMENT_MANAGE);
            $unit = $this->unitByPublicId($in['unit'] ?? null);
            $guard->unit(PayrollCatalog::HR_EMPLOYMENT_MANAGE, (int) $unit->id);
            if ($unit->status !== 'ACTIVE') {
                throw new FinanceError('UNIT_NOT_ACTIVE');
            }
            $person = is_string($in['person'] ?? null) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $in['person']) === 1
                ? $this->rt->db->table('people')->where('public_id', $in['person'])->sharedLock()->first(['id', 'public_id']) : null;
            // People authority (no bypass): an unknown Person and a Person the actor cannot see are the same 404.
            if (!$person || !$this->rt->canSeePerson($actor, (int) $person->id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'people']);
            }
            $kind = $in['relationship_kind'] ?? null;
            if (!in_array($kind, PayrollCatalog::RELATIONSHIP_KINDS, true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'relationship_kind']);
            }
            $startsOn = $this->date($in['starts_on'] ?? null, 'starts_on');
            $jobTitle = $this->text($in['job_title'] ?? null, 'job_title', 160, false);
            $document = $this->rt->supportingDocument($guard, $actor, $in['contract_document'] ?? null, (int) $unit->id);
            if ($document !== null && $this->rt->db->table('legal_document_types')->where('id', $document->document_type_id)->value('code') !== 'CONTRACT') {
                throw new FinanceError('CONTRACT_DOCUMENT_TYPE', [], ['field' => 'contract_document']);
            }
            $open = $this->rt->db->table('employments')->where('person_id', $person->id)->where('employing_unit_id', $unit->id)->where('status', PayrollCatalog::EMPLOYMENT_ACTIVE)->lockForUpdate()->first();
            if ($open) {
                throw new FinanceError('EMPLOYMENT_ALREADY_OPEN', [(string) $open->public_id]);
            }
            $now = $this->rt->ts();
            $publicId = (string) Str::ulid();
            $id = (int) $this->rt->db->table('employments')->insertGetId(['public_id' => $publicId, 'person_id' => $person->id, 'employing_unit_id' => $unit->id, 'relationship_kind' => $kind,
                'job_title' => $jobTitle, 'starts_on' => $startsOn, 'ends_on' => null, 'status' => PayrollCatalog::EMPLOYMENT_ACTIVE, 'end_reason' => null,
                'contract_document_id' => $document?->id, 'created_by' => $actor->user, 'created_at' => $now, 'ended_by' => null, 'ended_at' => null, 'lock_version' => 0]);
            $this->rt->db->table('person_unit_contexts')->insert(['person_id' => $person->id, 'unit_id' => $unit->id, 'context_kind' => PayrollCatalog::PEOPLE_CONTEXT, 'status' => 'ACTIVE',
                'starts_at' => $now, 'ends_at' => null, 'reason' => 'Vínculo laboral ' . $publicId, 'source_document_id' => null, 'created_at' => $now, 'lock_version' => 0]);
            PayrollAudit::write($this->rt->db, $actor->user, 'hr.employment_created', 'employments', $id, (int) $unit->id,
                ['employment' => $publicId, 'person' => (string) $person->public_id, 'relationship_kind' => $kind, 'starts_on' => $startsOn, 'contract_document' => $document?->public_id], null, $actor->session);
            return ['public_id' => $publicId, 'status' => PayrollCatalog::EMPLOYMENT_ACTIVE];
        });
    }

    public function end(int $user, int $session, string $employment, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($employment, $in): array {
            $guard->requires(PayrollCatalog::HR_EMPLOYMENT_MANAGE);
            $e = $this->employmentByPublicId($employment, true);
            $guard->unit(PayrollCatalog::HR_EMPLOYMENT_MANAGE, (int) $e->employing_unit_id);
            if ($e->status !== PayrollCatalog::EMPLOYMENT_ACTIVE) {
                throw new FinanceError('EMPLOYMENT_ENDED');
            }
            $endsOn = $this->date($in['ends_on'] ?? null, 'ends_on');
            if ($endsOn < (string) $e->starts_on) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'ends_on']);
            }
            $reason = $this->text($in['end_reason'] ?? null, 'end_reason');
            $later = $this->rt->db->table('employment_compensations')->where('employment_id', $e->id)->where('starts_on', '>', $endsOn)->lockForUpdate()->exists();
            if ($later) {
                throw new FinanceError('COMPENSATION_AFTER_END');
            }
            $now = $this->rt->ts();
            // History is closed, never deleted: open compensation lines end with the employment.
            $this->rt->db->table('employment_compensations')->where('employment_id', $e->id)->whereNull('ends_on')
                ->update(['ends_on' => $endsOn, 'closed_by' => $actor->user, 'closed_at' => $now, 'lock_version' => $this->rt->db->raw('lock_version + 1')]);
            $changed = $this->rt->db->table('employments')->where('id', $e->id)->where('lock_version', $e->lock_version)
                ->update(['status' => PayrollCatalog::EMPLOYMENT_ENDED, 'ends_on' => $endsOn, 'end_reason' => $reason, 'ended_by' => $actor->user, 'ended_at' => $now, 'lock_version' => $e->lock_version + 1]);
            if ($changed !== 1) {
                throw new FinanceError('STALE_WRITE');
            }
            $this->rt->db->table('person_unit_contexts')->where('person_id', $e->person_id)->where('unit_id', $e->employing_unit_id)->where('context_kind', PayrollCatalog::PEOPLE_CONTEXT)
                ->where('status', 'ACTIVE')->where('reason', 'Vínculo laboral ' . $e->public_id)
                ->update(['status' => 'INACTIVE', 'ends_at' => $this->rt->db->raw('GREATEST(UTC_TIMESTAMP(6), DATE_ADD(starts_at, INTERVAL 1 MICROSECOND))'), 'lock_version' => $this->rt->db->raw('lock_version + 1')]);
            PayrollAudit::write($this->rt->db, $actor->user, 'hr.employment_ended', 'employments', (int) $e->id, (int) $e->employing_unit_id,
                ['employment' => (string) $e->public_id, 'ends_on' => $endsOn], $reason, $actor->session);
            return ['public_id' => (string) $e->public_id, 'status' => PayrollCatalog::EMPLOYMENT_ENDED];
        });
    }
}
