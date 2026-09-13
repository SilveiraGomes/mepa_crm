<?php

declare (strict_types=1);
namespace App\Domain\Events;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
final class CredentialService
{
    public function __construct(private Connection $db, private EventPolicy $policy)
    {
    }
    private function token(string $prefix): array
    {
        $token = $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$token, hash('sha256', $token, true)];
    }
    private function membershipUnit(int $membership, int $unit, DateTimeImmutable $now): void
    {
        $time = $now->format('Y-m-d H:i:s.u');
        $periods = $this->db->table('membership_periods')->where('membership_id', $membership)->where('congregation_id', $unit)->where('starts_at', '<=', $time)->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $time))->sharedLock()->get();
        foreach ($periods as $period) {
            $state = $this->db->table('membership_statuses')->where('id', $period->status_id)->sharedLock()->value('code');
            if ($this->policy->permits('memberships', (string) $state)) {
                return;
            }
        }
        throw new EventError('CONTEXT_MISMATCH');
    }
    public function issuePermanent(int $actor, int $authSession, int $unit, int $person, int $type, int $template, ?int $number, DateTimeImmutable $now, DateTimeImmutable $expires): array
    {
        return $this->db->transaction(function () use ($actor, $authSession, $unit, $person, $type, $template, $number, $now, $expires) {
            $access = new EventAccess($this->db, $this->policy);
            $access->authorize($actor, $authSession, $unit, 'CREDENTIAL_ISSUE', $now);
            $p = $this->db->table('people')->where('id', $person)->lockForUpdate()->first();
            if (!$p || $p->archived_at !== null || $p->merged_into_id !== null) {
                throw new EventError('PERSON_NOT_ELIGIBLE');
            }
            $personState = $this->db->table('person_statuses')->where('id', $p->status_id)->sharedLock()->first();
            if (!$personState || (int) $personState->is_active !== 1) {
                throw new EventError('PERSON_NOT_ELIGIBLE');
            }
            $kind = $this->db->table('credential_types')->where('id', $type)->sharedLock()->first();
            if (!$kind || (int) $kind->is_active !== 1 || !in_array($type, $this->policy->credentialTypeIds, true) || $kind->default_validity_days === null) {
                throw new EventError('CREDENTIAL_POLICY_NOT_APPROVED');
            }
            if ($expires <= $now || $expires > $now->modify('+' . $kind->default_validity_days . ' days')) {
                throw new EventError('CREDENTIAL_VALIDITY_INVALID');
            }
            $tpl = $this->db->table('credential_templates')->where('id', $template)->sharedLock()->first();
            if (!$tpl || (int) $tpl->credential_type_id !== $type || !$this->policy->permits('templates', $tpl->status)) {
                throw new EventError('CONTEXT_MISMATCH');
            }
            $membership = $this->db->table('memberships')->where('person_id', $person)->sharedLock()->first();
            if ((int) $kind->requires_member_number === 1 || $number !== null) {
                $n = $this->db->table('member_numbers')->where('id', $number)->sharedLock()->first();
                if (!$membership || !$n || (int) $n->membership_id !== (int) $membership->id) {
                    throw new EventError('MEMBER_NUMBER_REQUIRED');
                }
                $this->membershipUnit((int) $membership->id, $unit, $now);
                $state = $this->db->table('membership_statuses')->where('id', $membership->status_id)->sharedLock()->value('code');
                if (!$this->policy->permits('memberships', (string) $state)) {
                    throw new EventError('MEMBERSHIP_NOT_ELIGIBLE');
                }
            }
            if ((int) $kind->requires_formal_approval === 1 && (!$membership || $membership->approved_at === null || $membership->approved_by === null)) {
                throw new EventError('FORMAL_APPROVAL_REQUIRED');
            }
            $old = $this->db->table('credentials')->where('person_id', $person)->where('credential_type_id', $type)->orderBy('id')->lockForUpdate()->get();
            $v = 1;
            $time = $now->format('Y-m-d H:i:s.u');
            foreach ($old as $row) {
                $v = max($v, (int) $row->version + 1);
                if ($row->revoked_at === null) {
                    $this->db->table('credentials')->where('id', $row->id)->update(['revoked_at' => $time, 'revoked_by' => $actor, 'status' => 'REVOKED', 'lock_version' => $row->lock_version + 1]);
                    $access->audit($actor, $authSession, $unit, 'CREDENTIAL_REVOKE', 'credentials', (int) $row->id, 'REISSUED', $now);
                }
            }
            [$token, $hash] = $this->token('p_');
            $public = (string) Str::ulid();
            $id = $this->db->table('credentials')->insertGetId(['public_id' => $public, 'person_id' => $person, 'credential_type_id' => $type, 'member_number_id' => $number, 'template_id' => $template, 'version' => $v, 'token_hash' => $hash, 'issued_at' => $time, 'expires_at' => $expires->format('Y-m-d H:i:s.u'), 'status' => 'ISSUED', 'created_at' => $time]);
            $access->audit($actor, $authSession, $unit, 'CREDENTIAL_ISSUE', 'credentials', $id, 'ISSUED', $now);
            return ['id' => $id, 'public_id' => $public, 'version' => $v, 'token' => $token];
        }, 5);
    }
    public function issueEvent(int $actor, int $authSession, int $registration, DateTimeImmutable $now, DateTimeImmutable $expires): array
    {
        return $this->db->transaction(function () use ($actor, $authSession, $registration, $now, $expires) {
            $r = $this->db->table('event_registrations')->where('id', $registration)->lockForUpdate()->first();
            if (!$r) {
                throw new EventError('CONTEXT_MISMATCH');
            }
            $e = $this->db->table('events')->where('id', $r->event_id)->sharedLock()->first();
            $access = new EventAccess($this->db, $this->policy);
            $access->authorize($actor, $authSession, (int) $e->owner_unit_id, 'CREDENTIAL_ISSUE', $now);
            if ($e->eligibility_policy_version !== $this->policy->version || !$this->policy->permits('events', $e->status) || !$this->policy->permits('registrations', $r->status)) {
                throw new EventError('NOT_ELIGIBLE');
            }
            $person = $this->db->table('people')->where('id', $r->person_id)->sharedLock()->first();
            if (!$person || $person->archived_at !== null || $person->merged_into_id !== null) {
                throw new EventError('PERSON_NOT_ELIGIBLE');
            }
            if ($this->policy->invitationRequired || $r->invitee_id !== null) {
                $invitee = $this->db->table('event_invitees')->where('id', $r->invitee_id)->sharedLock()->first();
                $list = $invitee ? $this->db->table('event_invitation_lists')->where('id', $invitee->list_id)->sharedLock()->first() : null;
                if (!$invitee || !$list || $list->frozen_at === null || !$this->policy->permits('lists', $list->status)) {
                    throw new EventError('NOT_ELIGIBLE');
                }
                if ((int) $invitee->person_id !== (int) $r->person_id || (int) $list->event_id !== (int) $r->event_id) {
                    throw new EventError('CONTEXT_MISMATCH');
                }
            }
            if ($expires <= $now || $expires > new DateTimeImmutable($e->ends_at)) {
                throw new EventError('CREDENTIAL_VALIDITY_INVALID');
            }
            $old = $this->db->table('event_credentials')->where('registration_id', $registration)->orderBy('id')->lockForUpdate()->get();
            $v = 1;
            $time = $now->format('Y-m-d H:i:s.u');
            foreach ($old as $row) {
                $v = max($v, (int) $row->version + 1);
                if ($row->revoked_at === null) {
                    $this->db->table('event_credentials')->where('id', $row->id)->update(['revoked_at' => $time, 'status' => 'REVOKED', 'lock_version' => $row->lock_version + 1]);
                    $access->audit($actor, $authSession, (int) $e->owner_unit_id, 'CREDENTIAL_REVOKE', 'event_credentials', (int) $row->id, 'REISSUED', $now);
                }
            }
            [$token, $hash] = $this->token('e_');
            $public = (string) Str::ulid();
            $id = $this->db->table('event_credentials')->insertGetId(['public_id' => $public, 'registration_id' => $registration, 'version' => $v, 'token_hash' => $hash, 'issued_at' => $time, 'expires_at' => $expires->format('Y-m-d H:i:s.u'), 'status' => 'ISSUED', 'created_at' => $time]);
            $access->audit($actor, $authSession, (int) $e->owner_unit_id, 'CREDENTIAL_ISSUE', 'event_credentials', $id, 'ISSUED', $now);
            return ['id' => $id, 'public_id' => $public, 'version' => $v, 'token' => $token];
        }, 5);
    }
    public function revoke(int $actor, int $authSession, int $unit, string $kind, int $id, DateTimeImmutable $now): void
    {
        if (!in_array($kind, ['credentials', 'event_credentials'], true)) {
            throw new EventError('CONTEXT_MISMATCH');
        }
        $this->db->transaction(function () use ($actor, $authSession, $unit, $kind, $id, $now) {
            $access = new EventAccess($this->db, $this->policy);
            $access->authorize($actor, $authSession, $unit, 'CREDENTIAL_REVOKE', $now);
            $c = $this->db->table($kind)->where('id', $id)->lockForUpdate()->first();
            if (!$c) {
                throw new EventError('TOKEN_INVALID');
            }
            if ($kind === 'event_credentials') {
                $r = $this->db->table('event_registrations')->where('id', $c->registration_id)->sharedLock()->first();
                $e = $this->db->table('events')->where('id', $r->event_id)->sharedLock()->first();
                if ((int) $e->owner_unit_id !== $unit) {
                    throw new EventError('CONTEXT_MISMATCH');
                }
            }
            if ($kind === 'credentials' && $c->member_number_id !== null) {
                $number = $this->db->table('member_numbers')->where('id', $c->member_number_id)->sharedLock()->first();
                if (!$number) {
                    throw new EventError('CONTEXT_MISMATCH');
                }
                $this->membershipUnit((int) $number->membership_id, $unit, $now);
            }
            $update = ['revoked_at' => $now->format('Y-m-d H:i:s.u'), 'status' => 'REVOKED', 'lock_version' => $c->lock_version + 1];
            if ($kind === 'credentials') {
                $update['revoked_by'] = $actor;
            }
            if ($c->revoked_at === null) {
                $this->db->table($kind)->where('id', $id)->update($update);
                $access->audit($actor, $authSession, $unit, 'CREDENTIAL_REVOKE', $kind, $id, 'REVOKED', $now);
            }
        }, 5);
    }
}
