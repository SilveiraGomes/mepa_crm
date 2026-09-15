<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

use App\Domain\Events\CheckinService;
use App\Domain\Events\EventPolicy;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
final class ChildrenService
{
    public function __construct(private Connection $db, private DomainPolicy $policy, private EventPolicy $eventPolicy)
    {
    }
    private function now(): DateTimeImmutable
    {
        return DomainClock::now($this->db);
    }
    private function time(DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d H:i:s.u');
    }
    private function access(): DomainAccess
    {
        return new DomainAccess($this->db, $this->eventPolicy);
    }
    private function gate(): ChildParticipationSafetyGate
    {
        return new ChildParticipationSafetyGate($this->db, $this->policy, $this->eventPolicy);
    }
    private function child(int $person): object
    {
        return $this->gate()->child($person);
    }
    private function person(int $person): void
    {
        $this->gate()->person($person);
    }
    private function run(callable $work): mixed
    {
        try {
            return $this->db->transaction($work, 5);
        } catch (QueryException) {
            throw new DomainError('CHILD_STORAGE_CONFLICT');
        }
    }
    private function authorize(int $actor, int $auth, object $child, string $action, ?DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->access()->authorize($actor, $auth, (int) $child->owner_unit_id, $action, $now, 'CHILDREN');
    }
    private function audit(int $actor, int $auth, object $child, string $action, string $entity, int $id, string $reason, DateTimeImmutable $now, array $metadata = []): void
    {
        $this->access()->audit($actor, $auth, (int) $child->owner_unit_id, $action, $entity, $id, $reason, $now, null, null, $metadata);
    }
    public function register(int $actor, int $auth, int $person, int $unit): int
    {
        return $this->run(function () use ($actor, $auth, $person, $unit) {
            $this->db->table('people')->where('id', $person)->lockForUpdate()->first();
            $this->person($person);
            $now = $this->now();
            $this->access()->authorize($actor, $auth, $unit, 'CHILD_WRITE', $now, 'CHILDREN');
            $existing = $this->db->table('child_profiles')->where('person_id', $person)->first();
            if ($existing) {
                if ((int) $existing->owner_unit_id !== $unit) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
                return (int) $existing->id;
            }
            $id = (int) $this->db->table('child_profiles')->insertGetId(['person_id' => $person, 'owner_unit_id' => $unit, 'status' => $this->policy->state('child_profiles'), 'created_at' => $this->time($now)]);
            $this->access()->audit($actor, $auth, $unit, 'CHILD_REGISTER', 'child_profiles', $id, 'REGISTERED', $now);
            return $id;
        });
    }
    public function authorizeGuardian(int $actor, int $auth, int $child, int $guardian, string $kind, DateTimeImmutable $starts, ?DateTimeImmutable $ends = null, ?int $relationship = null): int
    {
        return $this->run(function () use ($actor, $auth, $child, $guardian, $kind, $starts, $ends, $relationship) {
            $c = $this->child($child);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_AUTHORIZATION_WRITE', $now);
            $this->person($guardian);
            $this->policy->require('authorization_kinds', $kind);
            if ($child === $guardian || $ends !== null && $ends <= $starts) {
                throw new DomainError('AUTHORIZATION_INVALID');
            }
            if ($relationship !== null) {
                $r = $this->db->table('person_relationships')->where('id', $relationship)->sharedLock()->first();
                if (!$r || !((int) $r->subject_person_id === $child && (int) $r->related_person_id === $guardian || (int) $r->subject_person_id === $guardian && (int) $r->related_person_id === $child)) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
            }
            $now = $this->authorize($actor, $auth, $c, 'CHILD_AUTHORIZATION_WRITE', null);
            $id = (int) $this->db->table('guardian_authorizations')->insertGetId(['public_id' => (string) Str::ulid(), 'child_person_id' => $child, 'guardian_person_id' => $guardian, 'relationship_id' => $relationship, 'authorization_kind' => $kind, 'status' => $this->policy->state('guardian_authorizations'), 'starts_at' => $this->time($starts), 'ends_at' => $ends ? $this->time($ends) : null, 'created_at' => $this->time($now)]);
            $this->audit($actor, $auth, $c, 'GUARDIAN_AUTHORIZED', 'guardian_authorizations', $id, 'GRANTED', $now);
            return $id;
        });
    }
    public function revokeAuthorization(int $actor, int $auth, int $id, string $reason): void
    {
        $a = $this->db->table('guardian_authorizations')->where('id', $id)->first();
        if (!$a) {
            throw new DomainError('CONTEXT_MISMATCH');
        }
        $this->run(function () use ($actor, $auth, $id, $reason, $a) {
            $c = $this->child((int) $a->child_person_id);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_AUTHORIZATION_WRITE', $now);
            $a = $this->db->table('guardian_authorizations')->where('id', $id)->lockForUpdate()->first();
            $now = $this->authorize($actor, $auth, $c, 'CHILD_AUTHORIZATION_WRITE', null);
            if (trim($reason) === '') {
                throw new DomainError('REASON_REQUIRED');
            }
            if ($a->status === $this->policy->state('authorization_revoked')) {
                return;
            }
            $values = ['status' => $this->policy->state('authorization_revoked'), 'reason' => $reason, 'lock_version' => $a->lock_version + 1];
            if ($a->starts_at < $this->time($now)) {
                $values['ends_at'] = $a->ends_at === null ? $this->time($now) : min($a->ends_at, $this->time($now));
            }
            $this->db->table('guardian_authorizations')->where('id', $id)->update($values);
            $this->audit($actor, $auth, $c, 'GUARDIAN_AUTHORIZATION_REVOKED', 'guardian_authorizations', $id, 'REVOKED', $now);
        });
    }
    public function consent(int $actor, int $auth, int $child, int $guardian, int $authorization, string $purpose): int
    {
        return $this->run(function () use ($actor, $auth, $child, $guardian, $authorization, $purpose) {
            $c = $this->child($child);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_CONSENT_WRITE', $now);
            $guardianRow = $this->gate()->authorization($authorization, $guardian);
            $now = $this->authorize($actor, $auth, $c, 'CHILD_CONSENT_WRITE', null);
            $this->gate()->validateAuthorization($guardianRow, $child, $guardian, $this->policy->guardianKind, $now);
            $this->policy->require('consent_purposes', $purpose);
            $id = (int) $this->db->table('person_consents')->insertGetId(['public_id' => (string) Str::ulid(), 'subject_person_id' => $child, 'given_by_person_id' => $guardian, 'purpose' => $purpose, 'policy_version' => $this->policy->version, 'granted_at' => $this->time($now), 'status' => $this->policy->state('person_consents'), 'created_at' => $this->time($now)]);
            $this->audit($actor, $auth, $c, 'CHILD_CONSENT_GRANTED', 'person_consents', $id, 'GRANTED', $now);
            return $id;
        });
    }
    public function revokeConsent(int $actor, int $auth, int $id): void
    {
        $s = $this->db->table('person_consents')->where('id', $id)->first();
        if (!$s) {
            throw new DomainError('CONTEXT_MISMATCH');
        }
        $this->run(function () use ($actor, $auth, $id, $s) {
            $c = $this->child((int) $s->subject_person_id);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_CONSENT_WRITE', $now);
            $s = $this->db->table('person_consents')->where('id', $id)->lockForUpdate()->first();
            $now = $this->authorize($actor, $auth, $c, 'CHILD_CONSENT_WRITE', null);
            if ($s->revoked_at !== null) {
                return;
            }
            $this->db->table('person_consents')->where('id', $id)->update(['revoked_at' => $this->time($now), 'status' => $this->policy->state('consent_revoked'), 'lock_version' => $s->lock_version + 1]);
            $this->audit($actor, $auth, $c, 'CHILD_CONSENT_REVOKED', 'person_consents', $id, 'REVOKED', $now);
        });
    }
    public function emergency(int $actor, int $auth, int $child, int $contactPerson, int $contact, int $priority): int
    {
        return $this->run(function () use ($actor, $auth, $child, $contactPerson, $contact, $priority) {
            $c = $this->child($child);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_WRITE', $now);
            $this->person($contactPerson);
            $p = $this->db->table('person_contacts')->where('id', $contact)->sharedLock()->first();
            if (!$p || (int) $p->person_id !== $contactPerson || $priority < 1 || $priority > 65535) {
                throw new DomainError('CONTEXT_MISMATCH');
            }
            $id = (int) $this->db->table('child_emergency_contacts')->insertGetId(['child_person_id' => $child, 'contact_person_id' => $contactPerson, 'contact_id' => $contact, 'priority' => $priority, 'status' => $this->policy->state('child_emergency_contacts'), 'created_at' => $this->time($now)]);
            $this->audit($actor, $auth, $c, 'CHILD_EMERGENCY_LINKED', 'child_emergency_contacts', $id, 'LINKED', $now);
            return $id;
        });
    }
    public function checkin(int $actor, int $auth, int $device, int $event, int $session, int $child, int $deliveredBy, int $authorization, string $token, string $clientKey, string $method, bool $identityConfirmed): array
    {
        return (new CheckinService($this->db, $this->eventPolicy))->scanChild($actor, $auth, $device, $event, $session, $child, $token, $clientKey, $deliveredBy, $authorization, $method, $identityConfirmed, $this->policy);
    }
    public function checkout(int $actor, int $auth, int $visit, int $collectedBy, int $authorization, string $method, bool $identityConfirmed): array
    {
        $v = $this->db->table('child_custody_visits')->where('id', $visit)->first();
        if (!$v) {
            throw new DomainError('CONTEXT_MISMATCH');
        }
        try {
            return $this->run(function () use ($actor, $auth, $visit, $collectedBy, $authorization, $method, $identityConfirmed, $v) {
                $c = $this->child((int) $v->child_person_id);
                $now = $this->now();
                $now = $this->authorize($actor, $auth, $c, 'CHILD_CHECKOUT', null);
                $v = $this->db->table('child_custody_visits')->where('id', $visit)->lockForUpdate()->first();
                $this->policy->identify($method, $identityConfirmed);
                if ($v->checked_out_at !== null) {
                    return ['result' => 'ALREADY_CHECKED_OUT', 'visit_id' => $visit];
                }
                if ($v->status !== $this->policy->state('child_custody_visits')) {
                    throw new DomainError('VISIT_NOT_OPEN');
                }
                $pickup = $this->gate()->authorization($authorization, $collectedBy);
                $s = $this->db->table('event_sessions')->where('id', $v->session_id)->sharedLock()->first();
                $e = $s ? $this->db->table('events')->where('id', $s->event_id)->sharedLock()->first() : null;
                $a = $this->db->table('event_attendance')->where('session_id', $v->session_id)->where('person_id', $v->child_person_id)->sharedLock()->first();
                if (!$e || (int) $e->owner_unit_id !== (int) $c->owner_unit_id || !$a || $a->attendance_status !== 'PRESENT') {
                    throw new DomainError('ATTENDANCE_INCONSISTENT');
                }
                $check = $this->db->table('event_checkins')->where('id', $a->checkin_id)->sharedLock()->first();
                $r = $this->db->table('event_registrations')->where('id', $a->registration_id)->sharedLock()->first();
                if (!$check || !$r || $check->status !== 'VALID' || (int) $check->person_id !== (int) $v->child_person_id || (int) $check->session_id !== (int) $v->session_id || (int) $r->event_id !== (int) $e->id || (int) $r->person_id !== (int) $v->child_person_id || (int) $check->registration_id !== (int) $r->id) {
                    throw new DomainError('ATTENDANCE_INCONSISTENT');
                }
                $now = $this->authorize($actor, $auth, $c, 'CHILD_CHECKOUT', null);
                $this->gate()->validateAuthorization($pickup, (int) $v->child_person_id, $collectedBy, $this->policy->pickupKind, $now);
                $updated = $this->db->table('child_custody_visits')->where('id', $visit)->whereNull('checked_out_at')->update(['collected_by_person_id' => $collectedBy, 'collection_authorization_id' => $authorization, 'checked_out_at' => $this->time($now), 'checked_out_by' => $actor, 'status' => $this->policy->state('custody_closed'), 'lock_version' => $v->lock_version + 1]);
                if ($updated !== 1) {
                    throw new DomainError('CHILD_STORAGE_CONFLICT');
                }
                $this->audit($actor, $auth, $c, 'CHILD_CHECKOUT', 'child_custody_visits', $visit, 'CHECKED_OUT', $now, ['method' => $method, 'policy_version' => $this->policy->version, 'collection_authorization_id' => $authorization]);
                return ['result' => 'CHECKED_OUT', 'visit_id' => $visit];
            });
        } catch (DomainError $error) {
            $this->run(function () use ($actor, $auth, $visit, $v, $error) {
                $c = $this->db->table('child_profiles')->where('person_id', $v->child_person_id)->lockForUpdate()->first();
                if (!$c) {
                    throw new DomainError('AUDIT_UNAVAILABLE');
                }
                $this->audit($actor, $auth, $c, 'CHILD_CHECKOUT_DENIED', 'child_custody_visits', $visit, $error->reason, $this->now());
            });
            throw $error;
        }
    }
    public function recommendTransition(int $actor, int $auth, int $child, int $from, int $to, int $rule): int
    {
        return $this->run(function () use ($actor, $auth, $child, $from, $to, $rule) {
            $c = $this->child($child);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_WRITE', $now);
            $f = $this->db->table('department_instances')->where('id', $from)->sharedLock()->first();
            $t = $this->db->table('department_instances')->where('id', $to)->sharedLock()->first();
            $r = $this->db->table('age_band_rules')->where('id', $rule)->sharedLock()->first();
            $p = $this->db->table('people')->where('id', $child)->sharedLock()->first();
            if (!$f || !$t || !$r || $from === $to || (int) $f->unit_id !== (int) $c->owner_unit_id || (int) $t->unit_id !== (int) $c->owner_unit_id || (int) $r->department_id !== (int) $t->department_id || $r->status !== $this->policy->state('age_band_rules') || (int) $r->version < 1 || $r->max_age_months !== null && (int) $r->max_age_months < (int) $r->min_age_months) {
                throw new DomainError('CONTEXT_MISMATCH');
            }
            if ($p->birth_precision !== 'EXACT' || $p->birth_date === null) {
                throw new DomainError('AGE_NOT_KNOWN');
            }
            $birth = new DateTimeImmutable($p->birth_date, new DateTimeZone('Africa/Luanda'));
            $date = $now->setTimezone(new DateTimeZone('Africa/Luanda'));
            $age = $birth->diff($date);
            $months = $age->y * 12 + $age->m;
            if ($age->invert || $months < (int) $r->min_age_months || $r->max_age_months !== null && $months > (int) $r->max_age_months) {
                throw new DomainError('AGE_RULE_NOT_APPLICABLE');
            }
            $membership = $this->db->table('department_memberships')->where('person_id', $child)->where('instance_id', $from)->where('starts_at', '<=', $this->time($now))->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $this->time($now));
            })->sharedLock()->get();
            if ($membership->isEmpty()) {
                throw new DomainError('CONTEXT_MISMATCH');
            }
            $id = (int) $this->db->table('department_transition_recommendations')->insertGetId(['person_id' => $child, 'from_instance_id' => $from, 'to_instance_id' => $to, 'rule_id' => $rule, 'status' => $this->policy->state('department_transition_recommendations'), 'created_at' => $this->time($now)]);
            $this->audit($actor, $auth, $c, 'CHILD_TRANSITION_RECOMMENDED', 'department_transition_recommendations', $id, 'RECOMMENDED', $now);
            return $id;
        });
    }
    public function view(int $actor, int $auth, int $child): array
    {
        return $this->run(function () use ($actor, $auth, $child) {
            $c = $this->child($child);
            $now = $this->now();
            $this->authorize($actor, $auth, $c, 'CHILD_READ', $now);
            $this->audit($actor, $auth, $c, 'CHILD_READ', 'child_profiles', (int) $c->id, 'READ', $now);
            return ['profile' => $c, 'emergency_contacts' => $this->db->table('child_emergency_contacts')->where('child_person_id', $child)->get()->all()];
        });
    }
}
