<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

use App\Domain\Events\EventError;
use App\Domain\Events\EventPolicy;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
final class ChildParticipationSafetyGate
{
    public function __construct(private Connection $db, private DomainPolicy $policy, private EventPolicy $events)
    {
    }
    public static function requireGenericParticipant(Connection $db, int $person): void
    {
        // Registration takes this existing Person exclusively. Shared lock fences profile creation.
        $db->table('people')->where('id', $person)->sharedLock()->first();
        if ($db->getSchemaBuilder()->hasTable('child_profiles') && $db->table('child_profiles')->where('person_id', $person)->sharedLock()->exists()) {
            throw new EventError('CHILD_SAFETY_FLOW_REQUIRED');
        }
    }
    public function person(int $person): void
    {
        $p = $this->db->table('people')->where('id', $person)->sharedLock()->first();
        $s = $p ? $this->db->table('person_statuses')->where('id', $p->status_id)->sharedLock()->first() : null;
        if (!$p || !$s || (int) $s->is_active !== 1 || $p->archived_at !== null || $p->merged_into_id !== null) {
            throw new DomainError('PERSON_NOT_ELIGIBLE');
        }
    }
    public function child(int $person): object
    {
        $c = $this->db->table('child_profiles')->where('person_id', $person)->lockForUpdate()->first();
        if (!$c || $c->status !== $this->policy->state('child_profiles')) {
            throw new DomainError('CHILD_NOT_ELIGIBLE');
        }
        $this->person($person);
        return $c;
    }
    public function authorization(int $id, int $person): ?object
    {
        $a = $this->db->table('guardian_authorizations')->where('id', $id)->lockForUpdate()->first();
        $this->person($person);
        return $a;
    }
    public function validateAuthorization(?object $a, int $child, int $person, string $kind, DateTimeImmutable $now): void
    {
        $t = $now->format('Y-m-d H:i:s.u');
        if (!$a || (int) $a->child_person_id !== $child || (int) $a->guardian_person_id !== $person || $a->authorization_kind !== $kind || $a->status !== $this->policy->state('guardian_authorizations') || $a->starts_at > $t || $a->ends_at !== null && $a->ends_at <= $t) {
            throw new DomainError('PICKUP_NOT_AUTHORIZED');
        }
    }
    public function admit(int $actor, int $auth, int $event, int $session, int $child, int $deliveredBy, int $authorization, string $method, bool $confirmed, callable $eventScan): array
    {
        $access = new DomainAccess($this->db, $this->events);
        try {
            return $this->db->transaction(function () use ($actor, $auth, $event, $session, $child, $deliveredBy, $authorization, $method, $confirmed, $eventScan, $access) {
                $c = $this->child($child);
                $access->authorize($actor, $auth, (int) $c->owner_unit_id, 'CHILD_CHECKIN', null, 'CHILDREN');
                $this->policy->identify($method, $confirmed);
                $a = $this->authorization($authorization, $deliveredBy);
                $e = $this->db->table('events')->where('id', $event)->sharedLock()->first();
                if (!$e || (int) $e->owner_unit_id !== (int) $c->owner_unit_id) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
                $consents = $this->db->table('person_consents')->where('subject_person_id', $child)->where('purpose', $this->policy->participationPurpose)->where('policy_version', $this->policy->version)->lockForUpdate()->get();
                $visits = $this->db->table('child_custody_visits')->where('child_person_id', $child)->where('session_id', $session)->lockForUpdate()->get();
                foreach ($visits as $v) {
                    if ($v->checked_out_at === null) {
                        throw new DomainError('OPEN_VISIT_EXISTS');
                    }
                }
                if ($visits->isNotEmpty()) {
                    throw new DomainError('SESSION_VISIT_ALREADY_COMPLETED');
                }
                $decisive = null;
                // Invoked by the private event writer after ALL its locks, immediately before its effects.
                $validate = function () use ($actor, $auth, $c, $a, $child, $deliveredBy, $consents, $access, &$decisive): DateTimeImmutable {
                    $decisive = $access->authorize($actor, $auth, (int) $c->owner_unit_id, 'CHILD_CHECKIN', null, 'CHILDREN');
                    $this->validateAuthorization($a, $child, $deliveredBy, $this->policy->deliveryKind, $decisive);
                    $t = $decisive->format('Y-m-d H:i:s.u');
                    $valid = false;
                    foreach ($consents as $s) {
                        if ($s->revoked_at === null && $s->status === $this->policy->state('person_consents') && $s->granted_at <= $t) {
                            $valid = true;
                        }
                    }
                    if (!$valid) {
                        throw new DomainError('CONSENT_REQUIRED');
                    }
                    return $decisive;
                };
                $attendance = $eventScan($validate);
                if ($decisive === null) {
                    throw new DomainError('CHILD_ADMISSION_NOT_VALIDATED');
                }
                // Written only after the decisive validation above committed inside the same transaction.
                $stored = $this->db->table('event_attendance')->where('id', $attendance['attendance_id'] ?? 0)->first();
                $check = $this->db->table('event_checkins')->where('id', $attendance['checkin_id'] ?? 0)->first();
                if (!$stored || !$check || (int) $stored->person_id !== $child || (int) $stored->session_id !== $session || (int) $stored->checkin_id !== (int) $check->id || (int) $check->person_id !== $child || (int) $check->session_id !== $session || $check->status !== 'VALID' || $stored->attendance_status !== 'PRESENT') {
                    throw new DomainError('ATTENDANCE_INCONSISTENT');
                }
                $t = $decisive->format('Y-m-d H:i:s.u');
                $id = (int) $this->db->table('child_custody_visits')->insertGetId(['session_id' => $session, 'child_person_id' => $child, 'delivered_by_person_id' => $deliveredBy, 'authorization_id' => $authorization, 'checked_in_at' => $t, 'checked_in_by' => $actor, 'status' => $this->policy->state('child_custody_visits'), 'created_at' => $t]);
                $access->audit($actor, $auth, (int) $c->owner_unit_id, 'CHILD_CHECKIN', 'child_custody_visits', $id, 'CHECKED_IN', $decisive, null, null, ['method' => $method, 'policy_version' => $this->policy->version]);
                return array_merge($attendance, ['result' => 'CHECKED_IN', 'visit_id' => $id]);
            }, 5);
        } catch (QueryException $e) {
            $error = new DomainError('CHILD_STORAGE_CONFLICT');
        } catch (DomainError|EventError $e) {
            $error = $e;
        }
        $this->db->transaction(function () use ($actor, $auth, $child, $session, $error, $access) {
            $c = $this->db->table('child_profiles')->where('person_id', $child)->lockForUpdate()->first();
            if (!$c) {
                throw new DomainError('AUDIT_UNAVAILABLE');
            }
            $access->audit($actor, $auth, (int) $c->owner_unit_id, 'CHILD_CHECKIN_DENIED', 'event_sessions', $session, $error->reason, DomainClock::now($this->db));
        }, 5);
        throw $error;
    }
}
