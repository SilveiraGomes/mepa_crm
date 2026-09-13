<?php

declare (strict_types=1);
namespace App\Domain\Events;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;
final class CheckinService
{
    public function __construct(private Connection $db, private EventPolicy $policy)
    {
    }
    public function scan(int $actor, int $authSession, int $device, int $event, int $session, int $person, string $token, string $clientKey, DateTimeImmutable $now, bool $manual = false): array
    {
        $correlation = (string) Str::ulid();
        $e = $this->db->table('events')->where('id', $event)->first();
        if (!$e) {
            throw new EventError('CONTEXT_MISMATCH');
        }
        try {
            if ($clientKey === '' || strlen($clientKey) > 64) {
                throw new EventError('IDEMPOTENCY_KEY_INVALID');
            }
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    return $this->db->transaction(function () use ($actor, $authSession, $device, $event, $session, $person, $token, $clientKey, $now, $manual, $correlation) {
                        $time = $now->format('Y-m-d H:i:s.u');
                        $access = new EventAccess($this->db, $this->policy);
                        $e = $this->db->table('events')->where('id', $event)->sharedLock()->first();
                        $access->authorize($actor, $authSession, (int) $e->owner_unit_id, $manual ? 'CHECKIN_MANUAL' : 'CHECKIN', $now);
                        $s = $this->db->table('event_sessions')->where('id', $session)->sharedLock()->first();
                        if (!$s || (int) $s->event_id !== $event) {
                            throw new EventError('CONTEXT_MISMATCH');
                        }
                        if ($e->eligibility_policy_version !== $this->policy->version || $s->checkin_policy !== $this->policy->version) {
                            throw new EventError('POLICY_NOT_CONFIGURED');
                        }
                        if (!$this->policy->permits('events', $e->status) || !$this->policy->permits('sessions', $s->status) || $e->ends_at <= $time || $s->ends_at <= $time || $s->starts_at > $time) {
                            throw new EventError('EVENT_NOT_OPEN');
                        }
                        $d = $this->db->table('devices')->where('id', $device)->sharedLock()->first();
                        if (!$d || (int) $d->unit_id !== (int) $e->owner_unit_id || !$this->policy->permits('devices', $d->status)) {
                            throw new EventError('DEVICE_NOT_AUTHORIZED');
                        }
                        if (!preg_match('/^[pe]_[A-Za-z0-9_-]{43}$/D', $token)) {
                            throw new EventError('TOKEN_INVALID');
                        }
                        $eventToken = str_starts_with($token, 'e_');
                        $table = $eventToken ? 'event_credentials' : 'credentials';
                        $c = $this->db->table($table)->where('token_hash', hash('sha256', $token, true))->sharedLock()->first();
                        if (!$c) {
                            throw new EventError('TOKEN_INVALID');
                        }
                        if ($c->revoked_at !== null) {
                            throw new EventError('CREDENTIAL_REVOKED');
                        }
                        if ($c->expires_at === null || $c->expires_at <= $time) {
                            throw new EventError('CREDENTIAL_EXPIRED');
                        }
                        if ($c->issued_at > $time || !$this->policy->permits('credentials', $c->status)) {
                            throw new EventError('CREDENTIAL_INVALID');
                        }
                        $r = $this->db->table('event_registrations')->where('event_id', $event)->where('person_id', $person)->sharedLock()->first();
                        if (!$r) {
                            throw new EventError('NOT_ELIGIBLE');
                        }
                        if (!$this->policy->permits('registrations', $r->status)) {
                            throw new EventError('REGISTRATION_CANCELLED');
                        }
                        if ($eventToken && (int) $c->registration_id !== (int) $r->id || !$eventToken && (int) $c->person_id !== $person) {
                            throw new EventError('CONTEXT_MISMATCH');
                        }
                        $p = $this->db->table('people')->where('id', $person)->sharedLock()->first();
                        if (!$p || $p->archived_at !== null || $p->merged_into_id !== null) {
                            throw new EventError('PERSON_NOT_ELIGIBLE');
                        }
                        $personState = $this->db->table('person_statuses')->where('id', $p->status_id)->sharedLock()->first();
                        if (!$personState || (int) $personState->is_active !== 1) {
                            throw new EventError('PERSON_NOT_ELIGIBLE');
                        }
                        if (!$eventToken) {
                            $kind = $this->db->table('credential_types')->where('id', $c->credential_type_id)->sharedLock()->first();
                            if (!$kind || (int) $kind->is_active !== 1 || !in_array((int) $c->credential_type_id, $this->policy->credentialTypeIds, true) || $kind->default_validity_days === null) {
                                throw new EventError('CREDENTIAL_POLICY_NOT_APPROVED');
                            }
                        }
                        if ($r->invitee_id !== null) {
                            $invitee = $this->db->table('event_invitees')->where('id', $r->invitee_id)->sharedLock()->first();
                            $list = $invitee ? $this->db->table('event_invitation_lists')->where('id', $invitee->list_id)->sharedLock()->first() : null;
                            if (!$invitee || !$list || (int) $invitee->person_id !== $person || (int) $list->event_id !== $event) {
                                throw new EventError('CONTEXT_MISMATCH');
                            }
                            if ($list->frozen_at === null || !$this->policy->permits('lists', $list->status)) {
                                throw new EventError('NOT_ELIGIBLE');
                            }
                        } elseif ($this->policy->invitationRequired) {
                            throw new EventError('NOT_ELIGIBLE');
                        }
                        $hash = hash('sha256', json_encode([$actor, $authSession, $device, $event, $session, $person, hash('sha256', $token), $manual], JSON_THROW_ON_ERROR), true);
                        $this->db->table('idempotency_requests')->insertOrIgnore(['actor_id' => $actor, 'operation' => 'EVENT_CHECKIN', 'client_key' => $clientKey, 'request_hash' => $hash, 'status' => 'PROCESSING', 'created_at' => $time]);
                        $claim = $this->db->table('idempotency_requests')->where('actor_id', $actor)->where('operation', 'EVENT_CHECKIN')->where('client_key', $clientKey)->lockForUpdate()->first();
                        if (!$claim || !hash_equals($claim->request_hash, $hash)) {
                            throw new EventError('IDEMPOTENCY_CONFLICT');
                        }
                        $existing = $this->db->table('event_checkins')->where('session_id', $session)->where('person_id', $person)->first();
                        if ($existing) {
                            if ($existing->status !== 'VALID') {
                                throw new EventError('CHECKIN_VOIDED');
                            }
                            $attendance = $this->db->table('event_attendance')->where('session_id', $session)->where('person_id', $person)->first();
                            if (!$attendance || (int) $attendance->checkin_id !== (int) $existing->id) {
                                throw new EventError('ATTENDANCE_INCONSISTENT');
                            }
                            $this->db->table('idempotency_requests')->where('id', $claim->id)->update(['status' => 'COMPLETED', 'result_public_id' => $r->public_id]);
                            return ['result' => 'ALREADY_CHECKED_IN', 'checkin_id' => (int) $existing->id, 'attendance_id' => (int) $attendance->id];
                        }
                        $id = $this->db->table('event_checkins')->insertGetId(['session_id' => $session, 'registration_id' => $r->id, 'person_id' => $person, 'credential_id' => $eventToken ? null : $c->id, 'event_credential_id' => $eventToken ? $c->id : null, 'checked_at' => $time, 'device_id' => $device, 'actor_id' => $actor, 'idempotency_request_id' => $claim->id, 'status' => 'VALID', 'created_at' => $time]);
                        $attendance = $this->db->table('event_attendance')->insertGetId(['session_id' => $session, 'registration_id' => $r->id, 'person_id' => $person, 'checkin_id' => $id, 'attendance_status' => 'PRESENT', 'recorded_by' => $actor, 'created_at' => $time]);
                        $this->db->table('idempotency_requests')->where('id', $claim->id)->update(['status' => 'COMPLETED', 'result_public_id' => $r->public_id]);
                        $access->audit($actor, $authSession, (int) $e->owner_unit_id, $manual ? 'CHECKIN_MANUAL' : 'CHECKIN', 'event_sessions', $session, 'CHECKED_IN', $now, $correlation);
                        return ['result' => 'CHECKED_IN', 'checkin_id' => $id, 'attendance_id' => $attendance];
                    }, 5);
                } catch (QueryException $error) {
                    if ((int) ($error->errorInfo[1] ?? 0) === 1062 && $attempt < 2) {
                        continue;
                    }
                    throw new EventError('CHECKIN_STORAGE_CONFLICT');
                }
            }
            throw new EventError('CHECKIN_STORAGE_CONFLICT');
        } catch (EventError $error) {
            try {
                $this->db->transaction(fn() => (new EventAccess($this->db, $this->policy))->audit($actor, $authSession, (int) $e->owner_unit_id, 'CHECKIN_DENIED', 'event_sessions', $session, $error->reason, $now, $correlation, $person), 5);
            } catch (Throwable) {
                throw new EventError('AUDIT_UNAVAILABLE');
            }
            throw $error;
        }
    }
}
