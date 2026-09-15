<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

use App\Domain\Events\EventPolicy;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
final class EvangelismService
{
    public function __construct(private Connection $db, private DomainPolicy $policy, private EventPolicy $accessPolicy)
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
        return new DomainAccess($this->db, $this->accessPolicy);
    }
    private function run(int $actor, int $auth, int $unit, callable $work, array $additionalPermissions = []): mixed
    {
        try {
            return $this->db->transaction(function () use ($actor, $auth, $unit, $work, $additionalPermissions) {
                // Provisional: fails fast before $work() takes any lock of its own.
                $this->access()->authorize($actor, $auth, $unit, 'OUTREACH_WRITE', $this->now(), 'EVANGELISM');
                $result = $work();
                // All writes, including the success audit, have completed.
                $this->commitAuthorize($actor, $auth, $unit, $additionalPermissions);
                return $result;
            }, 5);
        } catch (QueryException) {
            throw new DomainError('OUTREACH_STORAGE_CONFLICT');
        }
    }
    private function commitAuthorize(int $actor, int $auth, int $unit, array $additionalPermissions = []): void
    {
        $this->access()->authorizeMany($actor, $auth, $unit, array_merge(['OUTREACH_WRITE'], $additionalPermissions), null, 'EVANGELISM');
    }
    private function person(int $id): void
    {
        $p = $this->db->table('people')->where('id', $id)->sharedLock()->first();
        $s = $p ? $this->db->table('person_statuses')->where('id', $p->status_id)->sharedLock()->first() : null;
        if (!$p || !$s || (int) $s->is_active !== 1 || $p->archived_at !== null || $p->merged_into_id !== null) {
            throw new DomainError('PERSON_NOT_ELIGIBLE');
        }
    }
    private function audit(int $actor, int $auth, int $unit, string $entity, int $id): void
    {
        $this->access()->audit($actor, $auth, $unit, 'OUTREACH_RECORD_CREATED', $entity, $id, 'RECORDED', $this->now());
    }
    private function insert(int $actor, int $auth, int $unit, string $table, array $values): int
    {
        $values['created_at'] = $this->time($this->now());
        $id = (int) $this->db->table($table)->insertGetId($values);
        $this->audit($actor, $auth, $unit, $table, $id);
        return $id;
    }
    private function campaignRow(int $id, int $unit): object
    {
        $c = $this->db->table('outreach_campaigns')->where('id', $id)->sharedLock()->first();
        if (!$c || (int) $c->unit_id !== $unit || $c->status !== $this->policy->state('outreach_campaigns')) {
            throw new DomainError('CONTEXT_MISMATCH');
        }
        return $c;
    }
    private function contactRow(int $id, int $unit): object
    {
        $c = $this->db->table('outreach_contacts')->where('id', $id)->sharedLock()->first();
        if (!$c) {
            throw new DomainError('CONTEXT_MISMATCH');
        }
        $this->campaignRow((int) $c->campaign_id, $unit);
        return $c;
    }
    private function participation(int $person, int $unit): void
    {
        $contacts = $this->db->table('outreach_contacts as c')->join('outreach_campaigns as p', 'p.id', '=', 'c.campaign_id')->where('c.person_id', $person)->where('p.unit_id', $unit)->sharedLock()->get();
        if ($contacts->isEmpty()) {
            throw new DomainError('CONTEXT_MISMATCH');
        }
        $this->person($person);
    }
    public function campaign(int $actor, int $auth, int $unit, string $name, DateTimeImmutable $starts, ?DateTimeImmutable $ends = null, ?int $event = null): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $name, $starts, $ends, $event) {
            if (trim($name) === '' || mb_strlen($name) > 191 || $ends !== null && $ends <= $starts) {
                throw new DomainError('CAMPAIGN_INVALID');
            }
            if ($event !== null) {
                $e = $this->db->table('events')->where('id', $event)->sharedLock()->first();
                if (!$e || (int) $e->owner_unit_id !== $unit) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
            }
            return $this->insert($actor, $auth, $unit, 'outreach_campaigns', ['public_id' => (string) Str::ulid(), 'unit_id' => $unit, 'event_id' => $event, 'name' => $name, 'starts_at' => $this->time($starts), 'ends_at' => $ends ? $this->time($ends) : null, 'status' => $this->policy->state('outreach_campaigns')]);
        });
    }
    public function contact(int $actor, int $auth, int $unit, int $campaign, int $person, ?int $assigned = null): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $campaign, $person, $assigned) {
            $this->campaignRow($campaign, $unit);
            // Lock existing Person also serializes duplicate contact insertion; never match by name.
            $this->db->table('people')->where('id', $person)->lockForUpdate()->first();
            $this->person($person);
            if ($assigned !== null) {
                $this->person($assigned);
            }
            $id = $this->db->table('outreach_contacts')->where('campaign_id', $campaign)->where('person_id', $person)->value('id');
            if ($id) {
                return (int) $id;
            }
            return $this->insert($actor, $auth, $unit, 'outreach_contacts', ['campaign_id' => $campaign, 'person_id' => $person, 'assigned_to_person_id' => $assigned, 'status' => $this->policy->state('outreach_contacts')]);
        });
    }
    public function followup(int $actor, int $auth, int $unit, int $contact, int $performer, DateTimeImmutable $occurred, string $outcome): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $contact, $performer, $occurred, $outcome) {
            $this->contactRow($contact, $unit);
            $this->person($performer);
            $this->policy->require('followup_outcomes', $outcome);
            return $this->insert($actor, $auth, $unit, 'followups', ['contact_id' => $contact, 'performed_by_person_id' => $performer, 'occurred_at' => $this->time($occurred), 'outcome' => $outcome]);
        });
    }
    public function decision(int $actor, int $auth, int $unit, int $person, string $type, ?string $date, string $precision, ?int $contact = null): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $person, $type, $date, $precision, $contact) {
            $this->participation($person, $unit);
            $this->policy->require('decision_types', $type);
            $this->policy->require('date_precisions', $precision);
            if ($contact !== null && (int) $this->contactRow($contact, $unit)->person_id !== $person) {
                throw new DomainError('CONTEXT_MISMATCH');
            }
            if ($date !== null) {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$d || $d->format('Y-m-d') !== $date) {
                    throw new DomainError('DATE_INVALID');
                }
            }
            return $this->insert($actor, $auth, $unit, 'decisions', ['person_id' => $person, 'contact_id' => $contact, 'decision_type' => $type, 'occurred_on' => $date, 'date_precision' => $precision]);
        });
    }
    public function track(int $actor, int $auth, int $unit, string $code, int $version, string $name): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $code, $version, $name) {
            if ($version < 1 || $code === '' || strlen($code) > 64 || $name === '' || mb_strlen($name) > 191) {
                throw new DomainError('TRACK_INVALID');
            }
            // Provisional dedicated permission; run() checks it after insert and audit.
            $this->access()->authorize($actor, $auth, $unit, 'DISCIPLESHIP_CONFIGURE', null, 'EVANGELISM');
            return $this->insert($actor, $auth, $unit, 'discipleship_tracks', ['code' => $code, 'version' => $version, 'name' => $name, 'status' => $this->policy->state('discipleship_tracks')]);
        }, ['DISCIPLESHIP_CONFIGURE']);
    }
    public function step(int $actor, int $auth, int $unit, int $track, int $sequence, string $name): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $track, $sequence, $name) {
            $t = $this->db->table('discipleship_tracks')->where('id', $track)->sharedLock()->first();
            if (!$t || $t->status !== $this->policy->state('discipleship_tracks') || $sequence < 1 || $name === '' || mb_strlen($name) > 191) {
                throw new DomainError('TRACK_INVALID');
            }
            // Provisional dedicated permission; run() checks it after insert and audit.
            $this->access()->authorize($actor, $auth, $unit, 'DISCIPLESHIP_CONFIGURE', null, 'EVANGELISM');
            return $this->insert($actor, $auth, $unit, 'discipleship_steps', ['track_id' => $track, 'sequence' => $sequence, 'name' => $name]);
        }, ['DISCIPLESHIP_CONFIGURE']);
    }
    public function enroll(int $actor, int $auth, int $unit, int $person, int $track, ?int $mentor = null): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $person, $track, $mentor) {
            $this->participation($person, $unit);
            if ($mentor !== null) {
                $this->person($mentor);
            }
            $t = $this->db->table('discipleship_tracks')->where('id', $track)->sharedLock()->first();
            if (!$t || $t->status !== $this->policy->state('discipleship_tracks')) {
                throw new DomainError('TRACK_INVALID');
            }
            return $this->insert($actor, $auth, $unit, 'discipleship_enrollments', ['public_id' => (string) Str::ulid(), 'person_id' => $person, 'track_id' => $track, 'mentor_person_id' => $mentor, 'status' => $this->policy->state('discipleship_enrollments'), 'starts_at' => $this->time($this->now())]);
        });
    }
    public function progress(int $actor, int $auth, int $unit, int $enrollment, int $step): int
    {
        try {
            return $this->db->transaction(function () use ($actor, $auth, $unit, $enrollment, $step) {
                $e = $this->db->table('discipleship_enrollments')->where('id', $enrollment)->lockForUpdate()->first();
                if (!$e) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
                $scope = new DiscipleshipEnrollmentScope($this->db);
                $resolvedUnit = $scope->resolve($enrollment);
                $this->access()->authorize($actor, $auth, $resolvedUnit, 'OUTREACH_WRITE', null, 'EVANGELISM');
                if ($resolvedUnit !== $unit) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
                $s = $this->db->table('discipleship_steps')->where('id', $step)->sharedLock()->first();
                if (!$e || !$s || (int) $e->track_id !== (int) $s->track_id || $e->ends_at !== null || $e->status !== $this->policy->state('discipleship_enrollments')) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
                $this->participation((int) $e->person_id, $unit);
                $id = $this->db->table('discipleship_progress')->where('enrollment_id', $enrollment)->where('step_id', $step)->value('id');
                if (!$id) {
                    $id = $this->insert($actor, $auth, $unit, 'discipleship_progress', ['enrollment_id' => $enrollment, 'step_id' => $step, 'completed_at' => $this->time($this->now()), 'status' => $this->policy->state('discipleship_progress')]);
                }
                // Provenance and all possible blocking SQL precede the decisive barrier.
                if ($scope->resolve($enrollment) !== $unit) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
                $this->commitAuthorize($actor, $auth, $unit);
                return (int) $id;
            }, 5);
        } catch (QueryException) {
            throw new DomainError('OUTREACH_STORAGE_CONFLICT');
        }
    }
    public function integrate(int $actor, int $auth, int $unit, int $person, ?int $membership = null): int
    {
        return $this->run($actor, $auth, $unit, function () use ($actor, $auth, $unit, $person, $membership) {
            $this->participation($person, $unit);
            if ($membership !== null) {
                $m = $this->db->table('memberships')->where('id', $membership)->sharedLock()->first();
                if (!$m || (int) $m->person_id !== $person) {
                    throw new DomainError('CONTEXT_MISMATCH');
                }
            }
            return $this->insert($actor, $auth, $unit, 'integration_events', ['person_id' => $person, 'unit_id' => $unit, 'membership_id' => $membership, 'occurred_at' => $this->time($this->now())]);
        });
    }
    public function history(int $actor, int $auth, int $unit, int $contact): array
    {
        return $this->db->transaction(function () use ($actor, $auth, $unit, $contact) {
            $this->access()->authorize($actor, $auth, $unit, 'OUTREACH_READ', $this->now(), 'EVANGELISM');
            $c = $this->contactRow($contact, $unit);
            return ['contact' => $c, 'followups' => $this->db->table('followups')->where('contact_id', $contact)->orderBy('occurred_at')->get()->all()];
        }, 5);
    }
}
