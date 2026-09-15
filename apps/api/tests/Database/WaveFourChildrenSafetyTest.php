<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
final class WaveFourChildrenSafetyTest extends WaveFourCase
{
    public function test_child_and_nonmember_guardians_share_person_family_and_emergency(): void
    {
        $f = $this->childFixture();
        $s = $this->children();
        self::assertSame(0, $this->db()->table('memberships')->whereIn('person_id', [$f['child'], $f['guardian'], $f['other']])->count());
        $mother = $this->row('people');
        $this->row('person_relationships', ['subject_person_id' => $f['child'], 'related_person_id' => $mother, 'ends_at' => null]);
        $this->children()->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $mother, 'SYNTHETIC_GUARDIAN', $this->now()->modify('-1 hour'));
        $contact = $this->row('person_contacts', ['person_id' => $f['guardian']]);
        $emergency = $s->emergency($f['actor'], $f['auth'], $f['child'], $f['guardian'], $contact, 1);
        self::assertSame($contact, (int) $this->db()->table('child_emergency_contacts')->where('id', $emergency)->value('contact_id'));
        self::assertCount(1, $s->view($f['actor'], $f['auth'], $f['child'])['emergency_contacts']);
        self::assertSame('CHECKED_OUT', $this->childOut($f)['result']);
        self::assertSame(0, $this->db()->table('memberships')->where('person_id', $f['guardian'])->count());
        self::assertSame(1, $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
    }
    public function test_member_and_pass_without_pickup_authorization_are_denied(): void
    {
        $f = $this->childFixture();
        $x = $this->row('people');
        $m = $f;
        $m['person'] = $x;
        $this->permanent($m);
        $this->denied('PICKUP_NOT_AUTHORIZED', fn() => $this->childOut($f, $x, $f['pickup']));
        self::assertNull($this->db()->table('child_custody_visits')->where('id', $f['visit'])->value('checked_out_at'));
        self::assertSame(1, $this->db()->table('audit_logs')->where('entity_type', 'child_custody_visits')->where('entity_id', $f['visit'])->where('action', 'CHILD_CHECKOUT_DENIED')->count());
    }
    public function test_wrong_child_wrong_purpose_and_missing_identity_are_denied(): void
    {
        $f = $this->childFixture();
        $other = $this->childFixture();
        $this->denied('PICKUP_NOT_AUTHORIZED', fn() => $this->childOut($f, $other['guardian'], $other['pickup']));
        $this->denied('PICKUP_NOT_AUTHORIZED', fn() => $this->childOut($f, $f['guardian'], $f['delivery']));
        $this->denied('IDENTITY_NOT_VERIFIED', fn() => $this->children()->checkout($f['actor'], $f['auth'], $f['visit'], $f['guardian'], $f['pickup'], 'UNCONFIGURED_METHOD', true));
        $this->denied('IDENTITY_NOT_VERIFIED', fn() => $this->children()->checkout($f['actor'], $f['auth'], $f['visit'], $f['guardian'], $f['pickup'], 'SYNTHETIC_IN_PERSON', false));
    }
    public function test_revoked_expired_and_future_authorizations_preserve_history(): void
    {
        $f = $this->childFixture();
        $s = $this->children();
        $s->revokeAuthorization($f['actor'], $f['auth'], $f['pickup'], 'SYNTHETIC_REASON');
        $this->denied('PICKUP_NOT_AUTHORIZED', fn() => $this->childOut($f));
        self::assertNotNull($this->db()->table('guardian_authorizations')->where('id', $f['pickup'])->value('ends_at'));
        $expired = $s->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['guardian'], 'SYNTHETIC_PICKUP', $this->now()->modify('-2 hours'), $this->now()->modify('-1 hour'));
        $future = $s->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['guardian'], 'SYNTHETIC_PICKUP', $this->now()->modify('+1 hour'));
        foreach ([$expired, $future] as $id) {
            $this->denied('PICKUP_NOT_AUTHORIZED', fn() => $this->childOut($f, $f['guardian'], $id));
        }
        self::assertSame('CHECKED_OUT', $this->childOut($f, $f['other'], $f['otherPickup'])['result']);
    }
    public function test_consent_is_versioned_and_revoked_without_deletion(): void
    {
        $f = $this->childFixture(false);
        $s = $this->children();
        $s->revokeConsent($f['actor'], $f['auth'], $f['consent']);
        $this->denied('CONSENT_REQUIRED', fn() => $this->childIn($f));
        self::assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->count());
        $new = $s->consent($f['actor'], $f['auth'], $f['child'], $f['guardian'], $f['parentAuthorization'], 'SYNTHETIC_PARTICIPATION');
        self::assertNotSame($f['consent'], $new);
        self::assertNotNull($this->db()->table('person_consents')->where('id', $f['consent'])->value('revoked_at'));
        self::assertSame('CHECKED_IN', $this->childIn($f)['result']);
    }
    public function test_checkout_is_single_append_audited_transition_and_replay_preserves_recipient(): void
    {
        $f = $this->childFixture();
        $this->denied('OPEN_VISIT_EXISTS', fn() => $this->childIn($f));
        self::assertSame('CHECKED_OUT', $this->childOut($f)['result']);
        $before = (array) $this->db()->table('child_custody_visits')->where('id', $f['visit'])->first();
        self::assertSame('ALREADY_CHECKED_OUT', $this->childOut($f, $f['other'], $f['otherPickup'])['result']);
        self::assertSame($before, (array) $this->db()->table('child_custody_visits')->where('id', $f['visit'])->first());
        self::assertSame($f['actor'], (int) $before['checked_out_by']);
        self::assertSame($f['guardian'], (int) $before['collected_by_person_id']);
        self::assertSame(1, $this->db()->table('audit_logs')->where('entity_type', 'child_custody_visits')->where('entity_id', $f['visit'])->where('action', 'CHILD_CHECKOUT')->count());
        $this->denied('SESSION_VISIT_ALREADY_COMPLETED', fn() => $this->childIn($f));
        $logs = json_encode($this->db()->table('audit_logs')->get()->all());
        self::assertStringNotContainsString($f['credential']['token'], $logs);
        self::assertStringNotContainsString('value_ciphertext', $logs);
        self::assertStringNotContainsString('support_notes_ciphertext', $logs);
    }
    public function test_horizontal_scope_cannot_read_or_checkout_another_congregation(): void
    {
        $a = $this->childFixture();
        $b = $this->childFixture();
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $this->children()->view($a['actor'], $a['auth'], $b['child']));
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $this->children()->checkout($a['actor'], $a['auth'], $b['visit'], $b['guardian'], $b['pickup'], 'SYNTHETIC_IN_PERSON', true));
        self::assertNull($this->db()->table('child_custody_visits')->where('id', $b['visit'])->value('checked_out_at'));
    }
    public function test_family_and_emergency_context_cannot_reference_unrelated_people(): void
    {
        $f = $this->childFixture();
        $contact = $this->row('person_contacts', ['person_id' => $f['other']]);
        $this->denied('CONTEXT_MISMATCH', fn() => $this->children()->emergency($f['actor'], $f['auth'], $f['child'], $f['guardian'], $contact, 1));
        $this->denied('CONTEXT_MISMATCH', fn() => $this->children()->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['other'], 'SYNTHETIC_GUARDIAN', $this->now()->modify('-1 hour'), null, $f['relationship']));
    }
    public function test_unconfigured_vocabularies_fail_closed(): void
    {
        $this->denied('POLICY_NOT_CONFIGURED', fn() => new \App\Domain\WaveFour\DomainPolicy('', [], [], '', '', '', ''));
        $f = $this->childFixture();
        $this->denied('POLICY_VALUE_NOT_CONFIGURED', fn() => $this->children()->authorizeGuardian($f['actor'], $f['auth'], $f['child'], $f['guardian'], 'INVENTED_KIND', $this->now()));
    }
    public function test_age_changes_create_recommendation_without_rewriting_department_or_attendance(): void
    {
        $f = $this->childFixture();
        $birth = $this->now()->modify('-12 years')->format('Y-m-d');
        $this->db()->table('people')->where('id', $f['child'])->update(['birth_precision' => 'EXACT', 'birth_date' => $birth, 'birth_year' => null]);
        $from = $this->row('department_instances', ['unit_id' => $f['unit']]);
        $to = $this->row('department_instances', ['unit_id' => $f['unit']]);
        $department = (int) $this->db()->table('department_instances')->where('id', $to)->value('department_id');
        $rule = $this->row('age_band_rules', ['department_id' => $department, 'min_age_months' => 144, 'max_age_months' => 155]);
        $membership = $this->row('department_memberships', ['instance_id' => $from, 'person_id' => $f['child'], 'ends_at' => null]);
        $before = (array) $this->db()->table('department_memberships')->where('id', $membership)->first();
        $attendance = (array) $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->first();
        $id = $this->children()->recommendTransition($f['actor'], $f['auth'], $f['child'], $from, $to, $rule);
        self::assertNotNull($id);
        self::assertSame($before, (array) $this->db()->table('department_memberships')->where('id', $membership)->first());
        self::assertSame($attendance, (array) $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->first());
        $this->db()->table('people')->where('id', $f['child'])->update(['birth_precision' => 'UNKNOWN', 'birth_date' => null]);
        $this->denied('AGE_NOT_KNOWN', fn() => $this->children()->recommendTransition($f['actor'], $f['auth'], $f['child'], $from, $to, $rule));
    }
    public function test_revoked_actor_and_role_scope_cannot_be_combined_across_units(): void
    {
        $f = $this->childFixture();
        $other = $this->childFixture();
        // Another role in the target unit cannot borrow permissions from the first unit.
        $scope = $this->row('scopes', ['unit_id' => $other['unit'], 'include_descendants' => 0]);
        $role = $this->row('roles');
        $this->row('user_role_scopes', ['user_id' => $f['actor'], 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $f['actor'], 'ends_at' => null]);
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $this->children()->view($f['actor'], $f['auth'], $other['child']));
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['revoked_at' => $this->now()->format('Y-m-d H:i:s.u')]);
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $this->childOut($f));
    }
}
