<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\Membership\MemberNumberGenerator;
final class WaveFourEvangelismTest extends WaveFourCase
{
    public function test_pipeline_preserves_one_person_and_membership_is_a_separate_admission(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $s = $this->evangelism();
        $count = $this->db()->table('people')->count();
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_CAMPAIGN', $this->now(), null, $f['event']);
        $contact = $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $f['person']);
        self::assertSame($contact, $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $f['person']));
        $s->followup($f['actor'], $f['auth'], $f['unit'], $contact, $f['person'], $this->now()->modify('-1 day'), 'SYNTHETIC_RESULT');
        $s->followup($f['actor'], $f['auth'], $f['unit'], $contact, $f['person'], $this->now(), 'SYNTHETIC_RESULT');
        self::assertCount(2, $s->history($f['actor'], $f['auth'], $f['unit'], $contact)['followups']);
        $s->decision($f['actor'], $f['auth'], $f['unit'], $f['person'], 'SYNTHETIC_DECISION', null, 'UNKNOWN', $contact);
        $s->decision($f['actor'], $f['auth'], $f['unit'], $f['person'], 'SYNTHETIC_BAPTISM', '2026-09-14', 'DAY', $contact);
        $track = $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $s->step($f['actor'], $f['auth'], $f['unit'], $track, 1, 'SYNTHETIC_STEP');
        $enroll = $s->enroll($f['actor'], $f['auth'], $f['unit'], $f['person'], $track);
        $progress = $s->progress($f['actor'], $f['auth'], $f['unit'], $enroll, $step);
        self::assertSame($progress, $s->progress($f['actor'], $f['auth'], $f['unit'], $enroll, $step));
        $s->integrate($f['actor'], $f['auth'], $f['unit'], $f['person']);
        self::assertSame(0, $this->db()->table('memberships')->where('person_id', $f['person'])->count());
        $membership = $this->row('memberships', ['person_id' => $f['person'], 'approved_by' => $f['actor'], 'approved_at' => $this->now()->format('Y-m-d H:i:s.u')]);
        if (!$this->db()->table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->exists()) {
            $this->row('member_number_sequences', ['code' => 'MEPA_NATIONAL', 'last_value' => 0]);
        }
        $number = (new MemberNumberGenerator($this->db()))->generateFor($membership, $this->now());
        self::assertStringStartsWith('MEPA', $number['number']);
        $integration = $s->integrate($f['actor'], $f['auth'], $f['unit'], $f['person'], $membership);
        foreach ([['outreach_contacts', $contact], ['discipleship_enrollments', $enroll], ['integration_events', $integration], ['memberships', $membership]] as [$t, $id]) {
            self::assertSame($f['person'], (int) $this->db()->table($t)->where('id', $id)->value('person_id'));
        }
        self::assertSame($count, $this->db()->table('people')->count());
    }
    public function test_existing_nonmember_parent_becomes_contact_then_member_without_duplicate_identity(): void
    {
        $f = $this->childFixture();
        $s = $this->evangelism();
        $before = $this->db()->table('people')->count();
        $c = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_CROSS_DOMAIN', $this->now());
        $id = $s->contact($f['actor'], $f['auth'], $f['unit'], $c, $f['guardian']);
        $s->decision($f['actor'], $f['auth'], $f['unit'], $f['guardian'], 'SYNTHETIC_DECISION', null, 'UNKNOWN', $id);
        $m = $f;
        $m['person'] = $f['guardian'];
        $this->permanent($m);
        self::assertSame($before, $this->db()->table('people')->count());
        self::assertSame($f['guardian'], (int) $this->db()->table('memberships')->where('id', $m['membership'])->value('person_id'));
        self::assertSame($f['guardian'], (int) $this->db()->table('guardian_authorizations')->where('id', $f['pickup'])->value('guardian_person_id'));
    }
    public function test_new_contact_and_same_name_people_are_never_automatically_merged(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $s = $this->evangelism();
        $p = $this->row('people');
        $q = $this->row('people');
        $count = $this->db()->table('people')->count();
        $c = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_NEW', $this->now());
        $a = $s->contact($f['actor'], $f['auth'], $f['unit'], $c, $p);
        $b = $s->contact($f['actor'], $f['auth'], $f['unit'], $c, $q);
        self::assertNotSame($a, $b);
        self::assertSame($count, $this->db()->table('people')->count());
        self::assertNull($this->db()->table('people')->where('id', $q)->value('merged_into_id'));
    }
    public function test_decision_progress_integration_and_scope_context_are_enforced(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $s = $this->evangelism();
        $c = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_CONTEXT', $this->now());
        $id = $s->contact($f['actor'], $f['auth'], $f['unit'], $c, $f['person']);
        $other = $this->row('people');
        $this->denied('CONTEXT_MISMATCH', fn() => $s->decision($f['actor'], $f['auth'], $f['unit'], $other, 'SYNTHETIC_DECISION', null, 'UNKNOWN', $id));
        $track = $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $enroll = $s->enroll($f['actor'], $f['auth'], $f['unit'], $f['person'], $track);
        $wrong = $this->row('discipleship_steps');
        $this->denied('CONTEXT_MISMATCH', fn() => $s->progress($f['actor'], $f['auth'], $f['unit'], $enroll, $wrong));
        $membership = $this->row('memberships', ['person_id' => $other]);
        $this->denied('CONTEXT_MISMATCH', fn() => $s->integrate($f['actor'], $f['auth'], $f['unit'], $f['person'], $membership));
        $unit = $this->row('organizational_units');
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $s->history($f['actor'], $f['auth'], $unit, $id));
        $this->denied('POLICY_VALUE_NOT_CONFIGURED', fn() => $s->followup($f['actor'], $f['auth'], $f['unit'], $id, $f['person'], $this->now(), 'INVENTED'));
    }
}
