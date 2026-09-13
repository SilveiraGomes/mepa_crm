<?php

declare (strict_types=1);
namespace App\Domain\Events;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
final class InvitationService
{
    public function __construct(private Connection $db, private EventPolicy $policy, private array $operators, private array $responses, private array $criterionTargets = [])
    {
    }
    public function composition(int $body, DateTimeImmutable $at): array
    {
        $time = $at->format('Y-m-d H:i:s.u');
        return $this->db->table('governance_body_memberships')->where('body_id', $body)->where('starts_at', '<=', $time)->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $time))->orderBy('id')->get()->filter(fn($r) => $this->policy->permits('composition', $r->status))->map(fn($r) => (array) $r)->all();
    }
    private function selected(object $criterion, string $time): array
    {
        $targets = array_filter(['position_id' => $criterion->position_id, 'class_id' => $criterion->class_id, 'body_id' => $criterion->body_id, 'unit_id' => $criterion->unit_id, 'department_id' => $criterion->department_id], fn($v) => $v !== null);
        if (count($targets) !== 1) {
            throw new EventError('CRITERION_INVALID');
        }
        $kind = array_key_first($targets);
        if (($this->criterionTargets[$criterion->criterion_kind] ?? null) !== $kind) {
            throw new EventError('CRITERION_POLICY_NOT_CONFIGURED');
        }
        $id = (int) $targets[$kind];
        $period = null;
        $policyKind = null;
        if ($kind === 'body_id') {
            $q = $this->db->table('governance_body_memberships')->where('body_id', $id);
            $period = 'governance_body_memberships';
            $policyKind = 'composition';
        } elseif ($kind === 'class_id') {
            $q = $this->db->table('ministerial_class_periods')->where('class_id', $id);
            $period = 'ministerial_class_periods';
            $policyKind = 'ministerial';
        } elseif ($kind === 'department_id') {
            $q = $this->db->table('department_memberships')->where('instance_id', $id);
            $period = 'department_memberships';
            $policyKind = 'departments';
        } elseif ($kind === 'position_id') {
            $q = $this->db->table('ministerial_assignments as m')->join('organizational_posts as p', 'p.id', '=', 'm.post_id')->where('p.position_id', $id)->select('m.*');
            $period = 'ministerial_assignments';
            $policyKind = 'ministerial';
        } else {
            $units = [$id];
            if ((int) $criterion->include_descendants === 1) {
                $front = [$id];
                while ($front) {
                    $next = $this->db->table('organizational_units')->whereIn('parent_id', $front)->pluck('id')->map(fn($v) => (int) $v)->all();
                    if (array_intersect($next, $units)) {
                        throw new EventError('CONTEXT_MISMATCH');
                    }
                    $units = array_merge($units, $next);
                    $front = $next;
                }
            }
            $q = $this->db->table('membership_periods as mp')->join('memberships as m', 'm.id', '=', 'mp.membership_id')->join('membership_statuses as s', 's.id', '=', 'mp.status_id')->whereIn('mp.congregation_id', $units)->select('mp.*', 'm.person_id', 's.code as status');
            $period = 'membership_periods';
            $policyKind = 'memberships';
        }
        $prefix = $kind === 'position_id' ? 'm.' : ($kind === 'unit_id' ? 'mp.' : '');
        return $q->where($prefix . 'starts_at', '<=', $time)->where(fn($q) => $q->whereNull($prefix . 'ends_at')->orWhere($prefix . 'ends_at', '>', $time))->orderBy($prefix . 'id')->sharedLock()->get()->filter(fn($r) => $this->policy->permits($policyKind, $r->status))->map(fn($r) => ['person_id' => (int) $r->person_id, 'criterion_id' => (int) $criterion->id, 'period_table' => $period, 'period_id' => (int) $r->id])->all();
    }
    public function freeze(int $actor, int $authSession, int $list, array $manual, string $frozenStatus, string $registrationStatus, DateTimeImmutable $now): array
    {
        return $this->db->transaction(function () use ($actor, $authSession, $list, $manual, $frozenStatus, $registrationStatus, $now) {
            $l = $this->db->table('event_invitation_lists')->where('id', $list)->lockForUpdate()->first();
            if (!$l) {
                throw new EventError('CONTEXT_MISMATCH');
            }
            $event = $this->db->table('events')->where('id', $l->event_id)->sharedLock()->first();
            $access = new EventAccess($this->db, $this->policy);
            $access->authorize($actor, $authSession, (int) $event->owner_unit_id, 'INVITATION_WRITE', $now);
            if ($l->frozen_at !== null) {
                throw new EventError('INVITATION_LIST_FROZEN');
            }
            if ($event->eligibility_policy_version !== $this->policy->version || !$this->policy->permits('lists', $frozenStatus) || !$this->policy->permits('registrations', $registrationStatus)) {
                throw new EventError('POLICY_NOT_CONFIGURED');
            }
            if ($this->db->table('event_invitees')->where('list_id', $list)->exists()) {
                throw new EventError('INVITATION_LIST_NOT_EMPTY');
            }
            $criteria = $this->db->table('invitation_criteria')->where('list_id', $list)->orderBy('id')->lockForUpdate()->get();
            $chosen = [];
            $excluded = [];
            foreach ($criteria as $c) {
                $op = $this->operators[$c->operator] ?? null;
                if (!in_array($op, ['UNION', 'SUBTRACT'], true)) {
                    throw new EventError('CRITERION_POLICY_NOT_CONFIGURED');
                }
                foreach ($this->selected($c, $l->selection_at) as $hit) {
                    if ($op === 'SUBTRACT') {
                        $excluded[$hit['person_id']] = true;
                    } else {
                        $chosen[$hit['person_id']][] = $hit;
                    }
                }
            }
            foreach ($manual as $person) {
                if (!is_int($person) || $person < 1) {
                    throw new EventError('CRITERION_INVALID');
                }
                $chosen[$person][] = ['person_id' => $person, 'selection' => 'MANUAL'];
            }
            foreach ($excluded as $person => $_) {
                unset($chosen[$person]);
            }
            ksort($chosen);
            $result = [];
            $time = $now->format('Y-m-d H:i:s.u');
            foreach ($chosen as $person => $evidence) {
                $p = $this->db->table('people')->where('id', $person)->sharedLock()->first();
                if (!$p || $p->archived_at !== null || $p->merged_into_id !== null) {
                    throw new EventError('PERSON_NOT_ELIGIBLE');
                }
                $invitee = $this->db->table('event_invitees')->insertGetId(['list_id' => $list, 'person_id' => $person, 'selection_origin' => isset($evidence[0]['criterion_id']) ? 'CRITERION' : 'MANUAL', 'eligibility_evidence' => json_encode(['selection_at' => $l->selection_at, 'sources' => $evidence], JSON_THROW_ON_ERROR), 'created_at' => $time]);
                $r = $this->db->table('event_registrations')->where('event_id', $l->event_id)->where('person_id', $person)->lockForUpdate()->first();
                // A later list is a new snapshot; the prior registration and invitation remain historical.
                if (!$r) {
                    $registration = $this->db->table('event_registrations')->insertGetId(['public_id' => (string) Str::ulid(), 'event_id' => $l->event_id, 'person_id' => $person, 'invitee_id' => $invitee, 'status' => $registrationStatus, 'created_at' => $time]);
                } else {
                    $registration = (int) $r->id;
                    if ($r->invitee_id === null) {
                        $this->db->table('event_registrations')->where('id', $registration)->update(['invitee_id' => $invitee, 'lock_version' => $r->lock_version + 1]);
                    }
                }
                $invitation = $this->db->table('event_invitations')->insertGetId(['invitee_id' => $invitee, 'status' => $frozenStatus, 'created_at' => $time]);
                $access->audit($actor, $authSession, (int) $event->owner_unit_id, 'INVITATION_CREATE', 'event_invitations', $invitation, 'SNAPSHOT', $now);
                $result[$person] = ['invitee_id' => $invitee, 'registration_id' => $registration];
            }
            $this->db->table('event_invitation_lists')->where('id', $list)->update(['status' => $frozenStatus, 'frozen_at' => $time, 'lock_version' => $l->lock_version + 1]);
            $access->audit($actor, $authSession, (int) $event->owner_unit_id, 'INVITATION_FREEZE', 'event_invitation_lists', $list, 'SNAPSHOT', $now);
            return $result;
        }, 5);
    }
    public function respond(int $actor, int $authSession, int $registration, string $response, DateTimeImmutable $now): int
    {
        return $this->db->transaction(function () use ($actor, $authSession, $registration, $response, $now) {
            if (!in_array($response, $this->responses, true)) {
                throw new EventError('RESPONSE_POLICY_NOT_CONFIGURED');
            }
            $r = $this->db->table('event_registrations')->where('id', $registration)->lockForUpdate()->first();
            if (!$r) {
                throw new EventError('CONTEXT_MISMATCH');
            }
            $e = $this->db->table('events')->where('id', $r->event_id)->sharedLock()->first();
            $a = new EventAccess($this->db, $this->policy);
            $a->authorize($actor, $authSession, (int) $e->owner_unit_id, 'INVITATION_WRITE', $now);
            $v = (int) $this->db->table('event_confirmations')->where('registration_id', $registration)->max('version') + 1;
            $time = $now->format('Y-m-d H:i:s.u');
            $id = $this->db->table('event_confirmations')->insertGetId(['registration_id' => $registration, 'version' => $v, 'response' => $response, 'responded_at' => $time, 'created_at' => $time]);
            $a->audit($actor, $authSession, (int) $e->owner_unit_id, 'INVITATION_RESPONSE', 'event_confirmations', $id, 'VERSIONED', $now);
            return $id;
        }, 5);
    }
}
