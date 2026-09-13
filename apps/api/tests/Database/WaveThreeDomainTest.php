<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveThreeCase.php';
use App\Domain\Events\CredentialService;
use App\Domain\Events\EventError;
use App\Domain\Events\InvitationService;
use Illuminate\Database\QueryException;
use Tests\Database\Support\WaveThreeCase;
final class WaveThreeDomainTest extends WaveThreeCase
{
    private function denied(array $f, string $token, string $reason, string $key = 'denied'): void
    {
        $before = $this->db()->table('event_checkins')->where('session_id', $f['session'])->count();
        try {
            $this->scan($f, $token, $key);
            $this->fail('Expected domain refusal');
        } catch (EventError $e) {
            $this->assertSame($reason, $e->reason);
        }
        $this->assertEquals($before, $this->db()->table('event_checkins')->where('session_id', $f['session'])->count());
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'event_sessions')->where('entity_id', $f['session'])->where('action', 'CHECKIN_DENIED')->orderByDesc('id')->first();
        $this->assertNotNull($audit);
        $this->assertNotNull(self::connect()->getConnection()->table('audit_logs')->where('id', $audit->id)->first());
        $this->assertSame($reason, $audit->reason);
        $this->assertSame($f['actor'], (int) $audit->actor_id);
        if (strlen($token) > 20) {
            $this->assertStringNotContainsString($token, json_encode($audit));
        }
        $this->assertNull($audit->before_metadata);
        $metadata = json_decode($audit->after_metadata, true);
        $this->assertSame(['person_id', 'reason_code', 'correlation_id'], array_keys($metadata));
        $this->assertSame($f['person'], $metadata['person_id']);
        $this->assertSame($reason, $metadata['reason_code']);
    }
    public function test_external_person_invitation_confirmation_event_credential_and_presence(): void
    {
        $f = $this->fixture();
        $s = new InvitationService($this->db(), self::policy(), [], ['SYNTHETIC_YES', 'SYNTHETIC_NO']);
        $s->respond($f['actor'], $f['auth'], $f['registration'], 'SYNTHETIC_NO', $this->now());
        $s->respond($f['actor'], $f['auth'], $f['registration'], 'SYNTHETIC_YES', $this->now());
        $responses = $this->db()->table('event_confirmations')->where('registration_id', $f['registration'])->orderBy('version')->get()->all();
        $this->assertCount(2, $responses);
        $this->assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->count());
        $c = $this->eventCredential($f);
        $result = $this->scan($f, $c['token']);
        $this->assertSame('CHECKED_IN', $result['result']);
        $this->assertEquals($responses, $this->db()->table('event_confirmations')->where('registration_id', $f['registration'])->orderBy('version')->get()->all());
        $this->assertSame(0, $this->db()->table('memberships')->where('person_id', $f['person'])->count());
        $this->assertSame(0, $this->db()->table('credentials')->where('person_id', $f['person'])->count());
        $checkin = $this->db()->table('event_checkins')->where('id', $result['checkin_id'])->first();
        $this->assertNull($checkin->credential_id);
        $this->assertSame($c['id'], (int) $checkin->event_credential_id);
        $a = $this->db()->table('event_attendance')->where('id', $result['attendance_id'])->first();
        $this->assertSame('PRESENT', $a->attendance_status);
    }
    public function test_permanent_reissue_preserves_membership_number_and_history(): void
    {
        $f = $this->fixture();
        $v1 = $this->permanent($f);
        $number = $this->db()->table('member_numbers')->where('id', $f['number'])->first();
        $membership = $this->db()->table('memberships')->where('id', $f['membership'])->first();
        $v2 = $this->permanent($f);
        $this->assertSame(2, $v2['version']);
        $this->assertNotSame($v1['token'], $v2['token']);
        $this->denied($f, $v1['token'], 'CREDENTIAL_REVOKED');
        $this->assertSame('CHECKED_IN', $this->scan($f, $v2['token'])['result']);
        $this->assertEquals($number, $this->db()->table('member_numbers')->where('id', $f['number'])->first());
        $this->assertEquals($membership, $this->db()->table('memberships')->where('id', $f['membership'])->first());
        $old = $this->db()->table('credentials')->where('id', $v1['id'])->first();
        $this->assertSame('REVOKED', $old->status);
        $this->assertNotNull($old->revoked_at);
        $this->assertSame($f['number'], (int) $old->member_number_id);
        $this->assertSame(hash('sha256', $v2['token'], true), $this->db()->table('credentials')->where('id', $v2['id'])->value('token_hash'));
    }
    public function test_valid_permanent_pass_is_not_invitation_authorization(): void
    {
        $f = $this->fixture(false);
        $c = $this->permanent($f);
        $this->denied($f, $c['token'], 'NOT_ELIGIBLE');
    }
    public function test_expired_revoked_changed_inexistent_and_enumerated_tokens_are_refused(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        foreach (['1', 'MEPA2609000001', 'e_' . str_repeat('A', 43), substr($c['token'], 0, -1) . ($c['token'][-1] === 'A' ? 'B' : 'A')] as $i => $token) {
            $this->denied($f, $token, 'TOKEN_INVALID', 'invalid_' . $i);
        }
        $this->db()->table('event_credentials')->where('id', $c['id'])->update(['expires_at' => '2026-09-15 11:00:00.123456']);
        $this->denied($f, $c['token'], 'CREDENTIAL_EXPIRED', 'expired');
        $this->db()->table('event_credentials')->where('id', $c['id'])->update(['expires_at' => '2026-09-15 16:00:00.123456']);
        (new CredentialService($this->db(), self::policy()))->revoke($f['actor'], $f['auth'], $f['unit'], 'event_credentials', $c['id'], $this->now());
        $this->denied($f, $c['token'], 'CREDENTIAL_REVOKED', 'revoked');
        $p = $this->permanent($f);
        $this->db()->table('credentials')->where('id', $p['id'])->update(['expires_at' => '2026-09-15 11:00:00.123456']);
        $this->denied($f, $p['token'], 'CREDENTIAL_EXPIRED', 'permanent_expired');
    }
    public function test_wrong_person_event_session_and_inconsistent_invitee_fail_closed(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $other = $this->row('people');
        $r = $this->row('event_registrations', ['event_id' => $f['event'], 'person_id' => $other]);
        $wrong = $f;
        $wrong['person'] = $other;
        $wrong['registration'] = $r;
        $this->denied($wrong, $c['token'], 'CONTEXT_MISMATCH', 'wrong_person');
        $second = $this->fixture();
        $this->denied($second, $c['token'], 'CONTEXT_MISMATCH', 'wrong_event');
        $wrong = $f;
        $wrong['session'] = $second['session'];
        $this->denied($wrong, $c['token'], 'CONTEXT_MISMATCH', 'wrong_session');
        $foreign = $this->db()->table('event_registrations')->where('id', $second['registration'])->value('invitee_id');
        $this->db()->table('event_registrations')->where('id', $f['registration'])->update(['invitee_id' => $foreign]);
        $this->denied($f, $c['token'], 'CONTEXT_MISMATCH', 'wrong_invitee');
    }
    public function test_closed_session_cancelled_registration_and_revoked_actor_or_device_are_denied(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $this->db()->table('events')->where('id', $f['event'])->update(['status' => 'SYNTHETIC_CLOSED']);
        $this->denied($f, $c['token'], 'EVENT_NOT_OPEN', 'closed');
        $this->db()->table('events')->where('id', $f['event'])->update(['status' => 'SYNTHETIC_READY']);
        $this->db()->table('event_registrations')->where('id', $f['registration'])->update(['status' => 'SYNTHETIC_CANCELLED']);
        $this->denied($f, $c['token'], 'REGISTRATION_CANCELLED', 'cancelled');
        $this->db()->table('event_registrations')->where('id', $f['registration'])->update(['status' => 'SYNTHETIC_READY']);
        $this->db()->table('devices')->where('id', $f['device'])->update(['status' => 'SYNTHETIC_REVOKED']);
        $this->denied($f, $c['token'], 'DEVICE_NOT_AUTHORIZED', 'device');
        $this->db()->table('devices')->where('id', $f['device'])->update(['status' => 'SYNTHETIC_READY']);
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['revoked_at' => '2026-09-15 11:00:00.123456']);
        $this->denied($f, $c['token'], 'ACTOR_NOT_AUTHORIZED', 'actor');
    }
    public function test_permission_cannot_be_combined_with_a_different_scope(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $foreign = $this->row('organizational_units');
        $scope = $this->db()->table('user_role_scopes')->where('user_id', $f['actor'])->value('scope_id');
        $this->db()->table('scopes')->where('id', $scope)->update(['unit_id' => $foreign]);
        $this->denied($f, $c['token'], 'ACTOR_NOT_AUTHORIZED');
    }
    public function test_duplicate_scan_manual_source_and_request_key_conflict(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $first = $this->scan($f, $c['token'], 'one', true);
        $again = $this->scan($f, $c['token'], 'two', true);
        $this->assertSame('ALREADY_CHECKED_IN', $again['result']);
        $this->assertSame($first['checkin_id'], $again['checkin_id']);
        $this->assertSame(1, $this->db()->table('event_attendance')->where('session_id', $f['session'])->count());
        $this->assertSame(1, $this->db()->table('audit_logs')->where('entity_id', $f['session'])->where('entity_type', 'event_sessions')->where('action', 'CHECKIN_MANUAL')->count());
        // Same key with different manual mode changes the fingerprint.
        $this->denied($f, $c['token'], 'IDEMPOTENCY_CONFLICT', 'one');
        $row = (array) $this->db()->table('event_checkins')->where('id', $first['checkin_id'])->first();
        unset($row['id']);
        $row['idempotency_request_id'] = $this->row('idempotency_requests', ['actor_id' => $f['actor']]);
        try {
            $this->db()->table('event_checkins')->insert($row);
            $this->fail('DB UNIQUE required');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
    }
    public function test_unknown_policy_and_unapproved_credential_type_do_not_activate(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $this->db()->table('event_sessions')->where('id', $f['session'])->update(['checkin_policy' => 'UNKNOWN_POLICY']);
        $this->denied($f, $c['token'], 'POLICY_NOT_CONFIGURED');
        $this->db()->table('credential_types')->where('id', $f['type'])->update(['default_validity_days' => null]);
        try {
            $this->permanent($f);
            $this->fail('D05 must fail closed');
        } catch (EventError $e) {
            $this->assertSame('CREDENTIAL_POLICY_NOT_APPROVED', $e->reason);
        }
    }
    public function test_governance_temporal_composition_frozen_invitees_and_separate_events(): void
    {
        $f = $this->fixture(false);
        $body = $this->row('governance_bodies', ['unit_id' => $f['unit']]);
        $gov = $this->row('governance_sessions', ['body_id' => $body, 'event_id' => $f['event']]);
        $past = $this->row('people');
        $future = $this->row('people');
        $period = $this->row('governance_body_memberships', ['body_id' => $body, 'person_id' => $f['person'], 'starts_at' => '2026-01-01 00:00:00.000001', 'ends_at' => null]);
        $this->row('governance_body_memberships', ['body_id' => $body, 'person_id' => $past, 'starts_at' => '2025-01-01 00:00:00.000001', 'ends_at' => '2026-09-15 11:00:00.123456']);
        $this->row('governance_body_memberships', ['body_id' => $body, 'person_id' => $future, 'starts_at' => '2026-09-15 13:00:00.123456']);
        $this->row('invitation_criteria', ['list_id' => $f['list'], 'criterion_kind' => 'SYNTHETIC_BODY', 'body_id' => $body, 'operator' => 'SYNTHETIC_INCLUDE', 'include_descendants' => 0]);
        $s = new InvitationService($this->db(), self::policy(), ['SYNTHETIC_INCLUDE' => 'UNION'], ['SYNTHETIC_YES'], ['SYNTHETIC_BODY' => 'body_id']);
        $snapshot = $s->freeze($f['actor'], $f['auth'], $f['list'], [], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
        $this->assertSame([$f['person']], array_keys($snapshot));
        // Registration existed before freeze: preserve it; attach the original invitee explicitly for this fixture.
        $this->assertSame($snapshot[$f['person']]['invitee_id'], (int) $this->db()->table('event_registrations')->where('id', $f['registration'])->value('invitee_id'));
        $before = $this->db()->table('event_invitees')->where('list_id', $f['list'])->get()->all();
        $this->db()->table('governance_body_memberships')->where('id', $period)->update(['ends_at' => '2026-09-15 12:30:00.123456']);
        $this->assertEquals($before, $this->db()->table('event_invitees')->where('list_id', $f['list'])->get()->all());
        $this->assertCount(1, $s->composition($body, new \DateTimeImmutable('2026-09-15 11:00:00.123456')));
        $this->assertCount(1, $s->composition($body, new \DateTimeImmutable('2026-09-15 13:00:00.123456')));
        $c = $this->eventCredential($f);
        $this->scan($f, $c['token']);
        $this->db()->table('events')->where('id', $f['event'])->update(['status' => 'SYNTHETIC_CLOSED']);
        $this->assertNotNull($this->db()->table('governance_bodies')->where('id', $body)->first());
        $this->assertSame($body, (int) $this->db()->table('governance_sessions')->where('id', $gov)->value('body_id'));
        try {
            $s->freeze($f['actor'], $f['auth'], $f['list'], [$future], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
            $this->fail('Frozen history must reject mutation');
        } catch (EventError $e) {
            $this->assertSame('INVITATION_LIST_FROZEN', $e->reason);
        }
    }
    public function test_required_row_checks_fk_and_multiple_sessions(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $this->scan($f, $c['token'], 's1');
        $f['session'] = $this->row('event_sessions', ['event_id' => $f['event']]);
        $this->assertSame('CHECKED_IN', $this->scan($f, $c['token'], 's2')['result']);
        foreach ([['invitation_criteria', ['list_id' => $f['list'], 'body_id' => null, 'class_id' => null, 'position_id' => null, 'unit_id' => null, 'department_id' => null]], ['event_sessions', ['event_id' => $f['event'], 'ends_at' => '2026-09-15 09:00:00.000000']], ['event_credentials', ['registration_id' => $f['registration'], 'version' => 0]], ['event_documents', ['event_id' => 999999999]]] as [$table, $values]) {
            try {
                $this->row($table, $values);
                $this->fail('Physical invariant required');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [3819, 1452]);
            }
        }
    }
    public function test_event_queries_answer_invited_confirmed_present_absent_rate_and_used_credential(): void
    {
        $f = $this->fixture();
        $absent = $this->row('people');
        $list = $this->row('event_invitation_lists', ['event_id' => $f['event'], 'version' => 2]);
        $s = new InvitationService($this->db(), self::policy(), [], ['SYNTHETIC_YES']);
        $s->freeze($f['actor'], $f['auth'], $list, [$absent], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
        $s->respond($f['actor'], $f['auth'], $f['registration'], 'SYNTHETIC_YES', $this->now());
        $c = $this->eventCredential($f);
        $this->scan($f, $c['token']);
        $invited = $this->db()->table('event_invitees as i')->join('event_invitation_lists as l', 'l.id', '=', 'i.list_id')->where('l.event_id', $f['event'])->distinct()->pluck('i.person_id')->all();
        $present = $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('attendance_status', 'PRESENT')->pluck('person_id')->all();
        $missing = array_values(array_diff($invited, $present));
        $this->assertCount(2, $invited);
        $this->assertSame([$absent], array_map('intval', $missing));
        $this->assertSame(0.5, count($present) / count($invited));
        $confirmed = $this->db()->table('event_confirmations as c')->join('event_registrations as r', 'r.id', '=', 'c.registration_id')->where('r.event_id', $f['event'])->where('c.response', 'SYNTHETIC_YES')->pluck('r.person_id')->all();
        $this->assertSame([$f['person']], array_map('intval', $confirmed));
        $this->assertSame($c['id'], (int) $this->db()->table('event_checkins')->where('session_id', $f['session'])->value('event_credential_id'));
        $deniedFixture = $this->fixture(false);
        $pass = $this->permanent($deniedFixture);
        $this->denied($deniedFixture, $pass['token'], 'NOT_ELIGIBLE');
        $attempts = $this->db()->table('audit_logs')->where('action', 'CHECKIN_DENIED')->where('entity_type', 'event_sessions')->where('entity_id', $deniedFixture['session'])->where('reason', 'NOT_ELIGIBLE')->get()->map(fn($r) => json_decode($r->after_metadata, true)['person_id'])->all();
        $this->assertSame([$deniedFixture['person']], $attempts);
        file_put_contents(self::$root . '/docs/database/physical/wave3_query_evidence.json', json_encode(['invited' => 2, 'confirmed' => 1, 'present' => 1, 'absent' => 1, 'rate' => 0.5, 'external_present' => 1, 'credential_kind' => 'EVENT', 'uninvited_attempt_identified' => true, 'queries_verified' => true], JSON_PRETTY_PRINT) . PHP_EOL);
    }
    public function test_typed_criteria_union_exclusion_and_external_location_reuse(): void
    {
        $f = $this->fixture(false);
        $people = [];
        for ($i = 0; $i < 5; $i++) {
            $people[] = $this->row('people');
        }
        $class = $this->row('ministerial_classes');
        $this->row('ministerial_class_periods', ['person_id' => $people[0], 'class_id' => $class, 'ends_at' => null]);
        $position = $this->row('positions');
        $post = $this->row('organizational_posts', ['unit_id' => $f['unit'], 'position_id' => $position]);
        $this->row('ministerial_assignments', ['post_id' => $post, 'person_id' => $people[1], 'ends_at' => null]);
        $department = $this->row('department_instances', ['unit_id' => $f['unit']]);
        $this->row('department_memberships', ['instance_id' => $department, 'person_id' => $people[2], 'ends_at' => null]);
        $child = $this->row('organizational_units', ['parent_id' => $f['unit']]);
        $status = $this->row('membership_statuses', ['code' => 'SYNTHETIC_CRITERION_' . bin2hex(random_bytes(4))]);
        $this->db()->table('membership_statuses')->where('id', $status)->update(['code' => 'SYNTHETIC_READY_CRITERION']);
        $member = $this->row('memberships', ['person_id' => $people[3], 'status_id' => $status]);
        $this->row('membership_periods', ['membership_id' => $member, 'congregation_id' => $child, 'status_id' => $status, 'ends_at' => null]);
        // Separate synthetic adapter explicitly permits the fixture membership code.
        $states = [];
        foreach (['users', 'auth_grants', 'devices', 'events', 'sessions', 'registrations', 'lists', 'templates', 'composition', 'ministerial', 'departments', 'memberships'] as $kind) {
            $states[$kind] = ['SYNTHETIC_READY'];
        }
        $states['memberships'][] = 'SYNTHETIC_READY_CRITERION';
        $states['credentials'] = ['ISSUED'];
        $policy = new \App\Domain\Events\EventPolicy('SYNTHETIC_V1', $states, true, [$f['type']]);
        foreach ([['class_id', $class], ['position_id', $position], ['department_id', $department], ['unit_id', $f['unit']]] as [$target, $id]) {
            $this->row('invitation_criteria', ['list_id' => $f['list'], 'criterion_kind' => $target, $target => $id, 'include_descendants' => $target === 'unit_id' ? 1 : 0, 'operator' => 'SYNTHETIC_INCLUDE']);
        }
        $this->row('invitation_criteria', ['list_id' => $f['list'], 'criterion_kind' => 'position_id', 'position_id' => $position, 'include_descendants' => 0, 'operator' => 'SYNTHETIC_EXCLUDE']);
        $s = new InvitationService($this->db(), $policy, ['SYNTHETIC_INCLUDE' => 'UNION', 'SYNTHETIC_EXCLUDE' => 'SUBTRACT'], [], ['class_id' => 'class_id', 'position_id' => 'position_id', 'department_id' => 'department_id', 'unit_id' => 'unit_id']);
        $selected = $s->freeze($f['actor'], $f['auth'], $f['list'], [$people[4]], 'SYNTHETIC_READY', 'SYNTHETIC_READY', $this->now());
        $expected = [$people[0], $people[2], $people[3], $people[4]];
        sort($expected);
        $this->assertSame($expected, array_keys($selected));
        $location = $this->row('physical_locations', ['public_visibility' => 'PRIVATE']);
        $this->row('event_locations', ['event_id' => $f['event'], 'location_id' => $location, 'is_primary' => 1]);
        $another = $this->row('events', ['owner_unit_id' => $f['unit']]);
        $this->row('event_locations', ['event_id' => $another, 'location_id' => $location, 'is_primary' => 1]);
        $this->assertSame(2, $this->db()->table('event_locations')->where('location_id', $location)->count());
        $this->assertSame(0, $this->db()->table('properties')->where('location_id', $location)->count());
    }
    public function test_audit_failure_rolls_back_checkin_and_never_returns_success(): void
    {
        $f = $this->fixture();
        $c = $this->eventCredential($f);
        $this->db()->statement('RENAME TABLE audit_logs TO wave3_audit_unavailable');
        try {
            try {
                $this->scan($f, $c['token']);
                $this->fail('Audit failure must fail closed');
            } catch (EventError $e) {
                $this->assertSame('AUDIT_UNAVAILABLE', $e->reason);
            }
            $this->assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->count());
            $this->assertSame(0, $this->db()->table('event_attendance')->where('session_id', $f['session'])->count());
        } finally {
            $this->db()->statement('RENAME TABLE wave3_audit_unavailable TO audit_logs');
        }
    }
    public function test_event_credential_issuance_requires_eligible_invitation_and_reissue_preserves_history(): void
    {
        $noInvite = $this->fixture(false);
        try {
            $this->eventCredential($noInvite);
            $this->fail('Credential cannot activate uninvited registration');
        } catch (EventError $e) {
            $this->assertSame('NOT_ELIGIBLE', $e->reason);
        }
        $f = $this->fixture();
        $one = $this->eventCredential($f);
        $two = $this->eventCredential($f);
        $this->assertSame(2, $two['version']);
        $this->assertNotSame($one['token'], $two['token']);
        $this->denied($f, $one['token'], 'CREDENTIAL_REVOKED');
        $this->assertNotNull($this->db()->table('event_credentials')->where('id', $one['id'])->value('revoked_at'));
        $this->assertSame('CHECKED_IN', $this->scan($f, $two['token'])['result']);
    }
}
