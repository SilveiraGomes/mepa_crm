<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Membership\MemberNumberGenerator;
use App\Domain\Membership\MembershipCatalog;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\MembershipHttpCase;

/**
 * P0.9-I Membership (ADR 0020), HTTP level against an isolated Wave 5 pool. Each test builds its own world so any test
 * can run alone (the mutation probes run the whole file). Concurrency (C1-C5, M21, M22) uses real PHP processes, one
 * MySQL connection each, released together by a ready/go barrier (scripts/p09-membership-worker.php).
 */
final class MembershipVerticalTest extends MembershipHttpCase
{
    // ---- S: schema guard + catalog -------------------------------------------------------------------------------

    public function test_s01_open_period_guard_is_physical_and_the_migration_refuses_incompatible_data(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $m = $this->candidate($staff, $w['a1']);
        $id = $this->membershipId($m['public_id']);
        $status = (int) DB::table('membership_statuses')->where('code', 'SUBMITTED')->value('id');
        $raw = fn () => DB::table('membership_periods')->insert(['membership_id' => $id, 'congregation_id' => $w['a2']['id'], 'status_id' => $status, 'starts_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'ends_at' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        try {
            $raw();
            $this->fail('a second open period must be refused physically');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
            $this->assertStringContainsString('uq_membership_periods_membership_open', $e->getMessage());
        }

        // Migration pre-check: remove the guard, create incompatible data, the migration aborts WITHOUT changing a row.
        $migration = require base_path('database/migrations/2026_09_30_000002_p09_add_membership_periods_open_guard.php');
        $migration->down();
        try {
            $raw();
            $before = DB::table('membership_periods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            try {
                $migration->up();
                $this->fail('the guard migration must refuse a membership with two open periods');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('MEMBERSHIP_OPEN_PERIOD_GUARD_INVALID_DATA', $e->getMessage());
            }
            $this->assertSame($before, DB::table('membership_periods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'no data repaired or changed');
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('membership_periods', 'open_flag'));
        } finally {
            // Test cleanup only (the synthetic duplicate), then the guard is re-installed.
            DB::table('membership_periods')->where('membership_id', $id)->where('congregation_id', $w['a2']['id'])->delete();
            if (!DB::getSchemaBuilder()->hasColumn('membership_periods', 'open_flag')) {
                $migration->up();
            }
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('membership_periods', 'open_flag'));
        $this->assertGlobalInvariants();
    }

    public function test_s02_catalog_is_complete_idempotent_role_free_and_never_resets_the_counter(): void
    {
        $this->assertSame([], MembershipCatalog::install(DB::connection()), 'second install inserts nothing');
        $this->assertSame(array_keys(MembershipCatalog::STATUSES), DB::table('membership_statuses')->whereIn('code', array_keys(MembershipCatalog::STATUSES))->orderByRaw("FIELD(code,'SUBMITTED','VALIDATED','REJECTED','WITHDRAWN','ACTIVE','INACTIVE','ENDED')")->pluck('code')->all());
        $this->assertSame(['BAPTISM', 'CONVERSION'], DB::table('milestone_types')->whereIn('code', ['CONVERSION', 'BAPTISM'])->orderBy('code')->pluck('code')->all());
        $this->assertSame(6, DB::table('permissions')->where('data_type', 'MEMBERSHIP')->whereColumn('action', 'code')->where('maximum_classification', 'RESTRICTED')->count());
        $this->assertSame(1, DB::table('workflows')->where('code', 'MEMBERSHIP_TRANSFER')->where('version', 1)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, DB::table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->count());
        $baseline = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.data_type', 'MEMBERSHIP')->count();
        $roles = DB::table('roles')->count();
        $current = $this->counter();
        DB::table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->update(['last_value' => $current + 17]);
        $this->assertSame([], MembershipCatalog::install(DB::connection()));
        $this->assertSame($current + 17, $this->counter(), 'install never updates an existing counter');
        DB::table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->update(['last_value' => $current]);
        $this->assertSame($roles, DB::table('roles')->count(), 'no role created');
        $this->assertSame($baseline, DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.data_type', 'MEMBERSHIP')->count());
    }

    // ---- M01..M05 admission ---------------------------------------------------------------------------------------

    public function test_m01_submission_uses_an_existing_person_and_issues_no_number(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $person = $this->person($w['a1'], 'Ana Submetida');
        $people = DB::table('people')->count();
        $contexts = DB::table('person_unit_contexts')->count();
        $counter = $this->counter();
        $m = $this->submit($staff, $person, $w['a1'])->assertCreated()->json('data');
        $this->assertSame('SUBMITTED', $m['status']);
        $this->assertFalse($m['is_member']);
        $this->assertNull($m['member_number']);
        $this->assertSame($w['a1']['public_id'], $m['congregation']['public_id']);
        $this->assertSame($person['public_id'], $m['person']['public_id']);
        $this->assertNoInternalIds($m);
        $this->assertSame($people, DB::table('people')->count(), 'Membership never creates a Person');
        $this->assertSame($contexts, DB::table('person_unit_contexts')->count(), 'Membership never writes person_unit_contexts');
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('people', 'unit_id'));
        $this->assertSame($counter, $this->counter());
        $this->assertCount(1, $this->openPeriods($m['public_id']));
        $this->assertSame(1, DB::table('audit_logs')->where('source', 'P09_MEMBERSHIP')->where('action', 'membership.submitted')->where('entity_id', $this->membershipId($m['public_id']))->where('unit_id', $w['a1']['id'])->count());
        // One membership per Person for life.
        $this->submit($staff, $person, $w['a1'])->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_EXISTS');
        // A Person the actor does not see in People is concealed; so is a Congregation outside the scope.
        $stranger = $this->person($w['b1']);
        $this->assertConcealed($this->submit($staff, $stranger, $w['a1']));
        $this->assertConcealed($this->submit($staff, $this->person($w['a1']), $w['b1']));
        // Only an ACTIVE CONGREGATION receives candidacies.
        $this->submit($staff, $this->person($w['a1']), $w['a'])->assertStatus(409)->assertJsonPath('error.code', 'CONGREGATION_NOT_ACTIVE');
        $this->assertGlobalInvariants();
    }

    public function test_m02_validation_moves_the_candidacy_without_a_number(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $c = $this->candidate($staff, $w['a1']);
        $counter = $this->counter();
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $c['lock_version'] + 5])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $v = $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $c['lock_version']])->assertOk()->json('data');
        $this->assertSame('VALIDATED', $v['status']);
        $this->assertNull($v['member_number']);
        $this->assertSame($counter, $this->counter());
        $this->assertNull($this->numberOf($c['public_id']));
        // SUBMITTED -> ACTIVE directly does not exist (validation is mandatory); VALIDATED cannot be validated twice.
        $c2 = $this->candidate($staff, $w['a1']);
        $this->api($staff, 'POST', 'memberships/' . $c2['public_id'] . '/approve', ['lock_version' => $c2['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertNull($this->numberOf($c2['public_id']));
        $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/validate', ['lock_version' => $v['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertSame(2, DB::table('membership_periods')->where('membership_id', $this->membershipId($c['public_id']))->count(), 'SUBMITTED period closed, VALIDATED period opened');
        $this->assertGlobalInvariants();
    }

    public function test_m03_approval_issues_the_official_number_in_the_same_transaction(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $v = $this->validated($staff, $w['a1']);
        $counter = $this->counter();
        $m = $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version'], 'admitted_on' => '2026-08', 'admitted_on_precision' => 'MONTH'])->assertOk()->json('data');
        $this->assertSame('ACTIVE', $m['status']);
        $this->assertTrue($m['is_member']);
        $this->assertMatchesRegularExpression(MembershipCatalog::NUMBER_PATTERN, $m['member_number']);
        $row = DB::table('member_numbers')->where('membership_id', $this->membershipId($v['public_id']))->first();
        $luanda = (new DateTimeImmutable((string) $row->issued_at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Luanda'));
        $this->assertSame(sprintf('MEPA%s%06d', $luanda->format('ym'), $counter + 1), $m['member_number'], 'AA/MM = Luanda month of issuance; SSSSSS = national counter');
        $this->assertSame($counter + 1, $this->counter());
        $this->assertSame('APPROVED_ADMISSION', $row->origin);
        $this->assertSame('2026-08', $m['admitted_on']);
        $this->assertSame('MONTH', $m['admitted_on_precision']);
        $membership = DB::table('memberships')->where('public_id', $v['public_id'])->first();
        $this->assertNotNull($membership->approved_at);
        $this->assertSame($staff['user'], (int) $membership->approved_by);
        $audit = DB::table('audit_logs')->where('source', 'P09_MEMBERSHIP')->where('action', 'membership.approved')->where('entity_id', $membership->id)->first();
        $this->assertSame($m['member_number'], json_decode($audit->after_metadata, true)['member_number'], 'the issued number is in the institutional audit');
        $this->assertSame($w['a1']['id'], (int) $audit->unit_id);
        $this->assertStringNotContainsString('full_name', (string) $audit->after_metadata);
        // Replay of the approval: no second number.
        $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $m['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertSame($counter + 1, $this->counter());
        // admitted_on after the approval day is refused; a day is never invented.
        $v2 = $this->validated($staff, $w['a1']);
        $this->api($staff, 'POST', 'memberships/' . $v2['public_id'] . '/approve', ['lock_version' => $v2['lock_version'], 'admitted_on' => '2099-01-01', 'admitted_on_precision' => 'EXACT'])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/' . $v2['public_id'] . '/approve', ['lock_version' => $v2['lock_version'], 'admitted_on' => '2026-08-01', 'admitted_on_precision' => 'MONTH'])->assertStatus(422);
        $this->assertSame($counter + 1, $this->counter(), 'a refused approval consumes no number');
        // Separate approve permission: ADMISSION_MANAGE alone cannot approve.
        $clerk = $this->staff(['MEMBERSHIP_VIEW', 'MEMBERSHIP_ADMISSION_MANAGE', 'PEOPLE_VIEW'], $w['a']['id']);
        $this->api($clerk, 'POST', 'memberships/' . $v2['public_id'] . '/approve', ['lock_version' => $v2['lock_version']])->assertStatus(403);
        $this->assertGlobalInvariants();
    }

    public function test_m03b_luanda_month_boundary_and_utc_storage(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $v = $this->validated($staff, $w['a1']);
        $id = $this->membershipId($v['public_id']);
        DB::table('memberships')->where('id', $id)->update(['approved_at' => '2026-09-30 23:30:00.000000', 'approved_by' => $staff['user']]);
        $counter = $this->counter();
        $number = (new MemberNumberGenerator(DB::connection()))->generateFor($id, new DateTimeImmutable('2026-09-30 23:30:00', new DateTimeZone('UTC')));
        $this->assertSame(sprintf('MEPA2610%06d', $counter + 1), $number['number'], '23:30 UTC on 30 Sep is 00:30 on 1 Oct in Luanda');
        $row = DB::table('member_numbers')->where('membership_id', $id)->first();
        $this->assertSame('2026-09-30 23:30:00.000000', (string) $row->issued_at, 'issued_at stored in UTC');
        $this->assertSame(2026, (int) $row->issued_year);
        $this->assertSame(10, (int) $row->issued_month);
        // Test-only rollback of this synthetic direct generator call (the row is not reachable through the API state).
        DB::table('member_numbers')->where('membership_id', $id)->delete();
        DB::table('memberships')->where('id', $id)->update(['approved_at' => null, 'approved_by' => null]);
        DB::table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->update(['last_value' => $counter]);
    }

    public function test_m04_rejection_issues_no_number_and_allows_a_new_candidacy_on_the_same_row(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $v = $this->validated($staff, $w['a1']);
        $counter = $this->counter();
        $r = $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/reject', ['lock_version' => $v['lock_version'], 'reason' => 'Processo incompleto'])->assertOk()->json('data');
        $this->assertSame('REJECTED', $r['status']);
        $this->assertNull($r['member_number']);
        $this->assertSame($counter, $this->counter());
        $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $r['lock_version']])->assertStatus(409);
        $personPublic = $r['person']['public_id'];
        $again = $this->api($staff, 'POST', 'memberships/admissions', ['person_public_id' => $personPublic, 'congregation_public_id' => $w['a2']['public_id']])->assertCreated()->json('data');
        $this->assertSame($v['public_id'], $again['public_id'], 'same membership row');
        $this->assertSame('SUBMITTED', $again['status']);
        $this->assertSame($w['a2']['public_id'], $again['congregation']['public_id']);
        $this->assertSame(1, DB::table('memberships')->where('person_id', DB::table('people')->where('public_id', $personPublic)->value('id'))->count());
        $this->assertGlobalInvariants();
    }

    public function test_m05_withdrawal_issues_no_number(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $c = $this->candidate($staff, $w['a1']);
        $counter = $this->counter();
        $x = $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/withdraw', ['lock_version' => $c['lock_version']])->assertOk()->json('data');
        $this->assertSame('WITHDRAWN', $x['status']);
        $this->assertNull($x['member_number']);
        $this->assertSame($counter, $this->counter());
        $v = $this->validated($staff, $w['a1']);
        $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/withdraw', ['lock_version' => $v['lock_version']])->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $x['lock_version']])->assertStatus(409);
        $this->assertGlobalInvariants();
    }

    // ---- M06/M07 collective ----------------------------------------------------------------------------------------

    public function test_m06_collective_approval_is_all_or_nothing(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $other = $this->secretary($w['b']['id']);
        $ok = [$this->validated($staff, $w['a1']), $this->validated($staff, $w['a2']), $this->validated($staff, $w['a1'])];
        $submitted = $this->candidate($staff, $w['a1']);
        $foreign = $this->validated($other, $w['b1']);
        $counter = $this->counter();
        $snapshot = fn () => DB::table('memberships')->orderBy('id')->get(['id', 'status_id', 'lock_version', 'approved_at'])->map(fn ($r) => (array) $r)->all();
        $before = $snapshot();
        $periods = DB::table('membership_periods')->count();
        $list = [$ok[0]['public_id'], $ok[1]['public_id'], $submitted['public_id'], $ok[2]['public_id'], $foreign['public_id'], 'NOT-A-ULID'];
        $response = $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => $list]);
        $response->assertStatus(409)->assertJsonPath('error.code', 'COLLECTIVE_APPROVAL_REJECTED');
        $items = $response->json('error.details.items');
        $this->assertSame([[2, 'TRANSITION_NOT_ALLOWED'], [4, 'RESOURCE_NOT_FOUND'], [5, 'RESOURCE_NOT_FOUND']], array_map(fn ($i) => [$i['index'], $i['code']], $items));
        $this->assertSame($before, $snapshot(), 'nothing approved');
        $this->assertSame($periods, DB::table('membership_periods')->count());
        $this->assertSame($counter, $this->counter(), 'no number consumed');
        // A failure after numbers were already allocated rolls everything back too: the last item's Congregation closes
        // between validation and approval (commit-time state), numbers are allocated in list order before it fails.
        $good = [$ok[0]['public_id'], $ok[1]['public_id'], $ok[2]['public_id']];
        $done = $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => $good, 'reason' => 'Acta 12/2026'])->assertOk()->json('data');
        $this->assertSame(3, $done['count']);
        $this->assertSame($counter + 3, $this->counter());
        $audits = DB::table('audit_logs')->where('source', 'P09_MEMBERSHIP')->where('action', 'membership.approved')->whereIn('entity_id', array_map(fn ($p) => $this->membershipId($p), $good))->get();
        $this->assertCount(3, $audits, 'one audit row per membership');
        $this->assertCount(1, array_unique($audits->pluck('correlation_id')->all()), 'one correlation id for the whole list');
        foreach ($audits as $audit) {
            $this->assertTrue(json_decode($audit->after_metadata, true)['collective']);
        }
        // Size limits.
        $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => []])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => array_map(fn () => (string) Str::ulid(), range(1, 201))])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => [$submitted['public_id'], $submitted['public_id']]])->assertStatus(422);
        $this->assertGlobalInvariants();
    }

    public function test_m06b_collective_failure_after_allocation_rolls_back_numbers(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $a = $this->validated($staff, $w['a1']);
        $b = $this->validated($staff, $w['a1']);
        $counter = $this->counter();
        // The commit-time recheck runs after every number was allocated: revoking the grant just before commit must
        // roll back the approvals AND the counter.
        app()->instance('membership.before_commit', function () use ($staff): void {
            DB::table('user_role_scopes')->where('user_id', $staff['user'])->update(['ends_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        });
        try {
            $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => [$a['public_id'], $b['public_id']]])->assertStatus(403);
        } finally {
            app()->offsetUnset('membership.before_commit');
        }
        $this->assertSame($counter, $this->counter(), 'allocated numbers rolled back');
        $this->assertNull($this->numberOf($a['public_id']));
        $this->assertNull($this->numberOf($b['public_id']));
        $this->assertSame('VALIDATED', DB::table('memberships as m')->join('membership_statuses as s', 's.id', '=', 'm.status_id')->where('m.public_id', $a['public_id'])->value('s.code'));
        $this->assertNull(DB::table('user_role_scopes')->where('user_id', $staff['user'])->value('ends_at'), 'the revocation itself was rolled back with the transaction');
        $this->assertGlobalInvariants();
    }

    public function test_m07_collective_numbers_follow_the_list_order_not_the_id_order(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $items = [];
        foreach (range(1, 5) as $_) {
            $items[] = $this->validated($staff, $w['a1']);
        }
        $order = [$items[3], $items[0], $items[4], $items[2], $items[1]];
        $counter = $this->counter();
        $done = $this->api($staff, 'POST', 'memberships/admissions/collective-approval', ['memberships' => array_column($order, 'public_id'), 'admitted_on' => '2026', 'admitted_on_precision' => 'YEAR'])->assertOk()->json('data.approved');
        $this->assertSame(array_column($order, 'public_id'), array_column($done, 'public_id'));
        foreach ($order as $position => $item) {
            $this->assertSame($counter + $position + 1, (int) DB::table('member_numbers')->where('membership_id', $this->membershipId($item['public_id']))->value('sequence_value'));
            $this->assertSame(substr($done[$position]['member_number'], -6), sprintf('%06d', $counter + $position + 1));
        }
        $this->assertSame('2026', $this->detail($staff, $order[0]['public_id'])['admitted_on']);
        $this->assertGlobalInvariants();
    }

    // ---- M08/M09 lifecycle -----------------------------------------------------------------------------------------

    public function test_m08_inactivation_and_reactivation_preserve_the_number(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $m = $this->member($staff, $w['a1']);
        $number = $m['member_number'];
        $i = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $m['lock_version']])->assertOk()->json('data');
        $this->assertSame('INACTIVE', $i['status']);
        $this->assertTrue($i['is_member']);
        $this->assertSame($number, $i['member_number']);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $i['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $r = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/reactivate', ['lock_version' => $i['lock_version']])->assertOk()->json('data');
        $this->assertSame('ACTIVE', $r['status']);
        $this->assertSame($number, $r['member_number']);
        $this->assertSame(1, DB::table('member_numbers')->where('membership_id', $this->membershipId($m['public_id']))->count());
        $this->assertGlobalInvariants();
    }

    public function test_m09_end_and_readmission_keep_the_same_number_and_first_approval(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $m = $this->member($staff, $w['a1']);
        $approvedAt = DB::table('memberships')->where('public_id', $m['public_id'])->value('approved_at');
        $counter = $this->counter();
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/end', ['lock_version' => $m['lock_version']])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $e = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/end', ['lock_version' => $m['lock_version'], 'reason' => 'Saída voluntária'])->assertOk()->json('data');
        $this->assertSame('ENDED', $e['status']);
        $this->assertFalse($e['is_member']);
        $this->assertSame($m['member_number'], $e['member_number'], 'an ex-member keeps the number');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/readmit', ['lock_version' => $e['lock_version']])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $manager = $this->staff(['MEMBERSHIP_VIEW', 'MEMBERSHIP_MANAGE'], $w['a']['id']);
        $this->api($manager, 'POST', 'memberships/' . $m['public_id'] . '/readmit', ['lock_version' => $e['lock_version'], 'reason' => 'Regresso'])->assertStatus(403);
        $r = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/readmit', ['lock_version' => $e['lock_version'], 'reason' => 'Regresso aprovado'])->assertOk()->json('data');
        $this->assertSame('ACTIVE', $r['status']);
        $this->assertSame($m['member_number'], $r['member_number']);
        $this->assertSame($counter, $this->counter(), 'readmission never generates a number');
        $this->assertSame($approvedAt, DB::table('memberships')->where('public_id', $m['public_id'])->value('approved_at'), 'first approval kept');
        $this->assertSame(1, DB::table('audit_logs')->where('source', 'P09_MEMBERSHIP')->where('action', 'membership.readmitted')->count());
        $this->assertGlobalInvariants();
    }

    // ---- M10..M12 transfers ----------------------------------------------------------------------------------------

    public function test_m10_m11_transfer_moves_the_congregation_atomically_keeps_the_number_and_history(): void
    {
        $w = $this->world();
        $origin = $this->secretary($w['a']['id']);
        $destination = $this->secretary($w['b']['id']);
        $m = $this->member($origin, $w['a1']);
        $number = $m['member_number'];
        $t = $this->api($origin, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id'], 'reason' => 'Mudança de residência'])->assertCreated()->json('data');
        $this->assertSame('REQUESTED', $t['status']);
        $this->assertSame($w['a1']['public_id'], $t['origin']['public_id']);
        $this->assertSame($w['b1']['public_id'], $t['destination']['public_id']);
        $this->assertNoInternalIds($t);
        $instance = DB::table('transfers as t')->join('workflow_instances as wi', 'wi.id', '=', 't.workflow_instance_id')->join('workflows as wf', 'wf.id', '=', 'wi.workflow_id')->where('t.public_id', $t['public_id'])->first(['wf.code', 'wi.unit_id', 'wi.status']);
        $this->assertSame(['MEMBERSHIP_TRANSFER', $w['a1']['id'], 'REQUESTED'], [$instance->code, (int) $instance->unit_id, $instance->status]);
        // Stage authority: the destination cannot validate the origin, the origin cannot accept.
        $this->assertConcealed($this->api($destination, 'POST', 'memberships/transfers/' . $t['public_id'] . '/validate-origin'));
        $this->api($origin, 'POST', 'memberships/transfers/' . $t['public_id'] . '/validate-origin')->assertOk()->assertJsonPath('data.status', 'ORIGIN_VALIDATED');
        $this->assertConcealed($this->api($origin, 'POST', 'memberships/transfers/' . $t['public_id'] . '/accept'));
        $this->api($destination, 'POST', 'memberships/transfers/' . $t['public_id'] . '/complete')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->api($destination, 'POST', 'memberships/transfers/' . $t['public_id'] . '/accept')->assertOk()->assertJsonPath('data.status', 'DESTINATION_ACCEPTED');
        // Until completion the membership is still at the origin (no intermediate state visible).
        $this->assertSame($w['a1']['id'], (int) $this->openPeriods($m['public_id'])[0]->congregation_id);
        $this->assertConcealed($this->api($destination, 'GET', 'memberships/' . $m['public_id']));
        $this->assertConcealed($this->api($origin, 'POST', 'memberships/transfers/' . $t['public_id'] . '/complete'));
        $done = $this->api($destination, 'POST', 'memberships/transfers/' . $t['public_id'] . '/complete')->assertOk()->json('data');
        $this->assertSame('COMPLETED', $done['status']);
        $this->assertNotNull($done['effective_at']);
        $this->assertSame($done['effective_at'], $done['closed_at']);
        // P09-D-F01: the periods moved in the same transaction.
        $periods = DB::table('membership_periods')->where('membership_id', $this->membershipId($m['public_id']))->orderBy('starts_at')->orderBy('id')->get();
        $open = $periods->whereNull('ends_at')->values();
        $this->assertCount(1, $open);
        $this->assertSame($w['b1']['id'], (int) $open[0]->congregation_id);
        $closedOrigin = $periods->firstWhere('ends_at', $open[0]->starts_at);
        $this->assertSame($w['a1']['id'], (int) $closedOrigin->congregation_id, 'origin period closed exactly when the destination period opened');
        $this->assertSame((string) $done['effective_at'], (string) $open[0]->starts_at);
        $this->assertSame($number, $this->numberOf($m['public_id']), 'transfer never changes the number');
        $this->assertSame('COMPLETED', DB::table('transfers as t')->join('workflow_instances as wi', 'wi.id', '=', 't.workflow_instance_id')->where('t.public_id', $t['public_id'])->value('wi.status'));
        // D05: the destination now sees the membership, the origin does not; the origin still sees its transfer row.
        $seen = $this->detail($destination, $m['public_id']);
        $this->assertSame($w['b1']['public_id'], $seen['congregation']['public_id']);
        $this->assertSame($number, $seen['member_number']);
        $this->assertConcealed($this->api($origin, 'GET', 'memberships/' . $m['public_id']));
        $this->api($origin, 'GET', 'memberships/transfers/' . $t['public_id'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertSame([$t['public_id']], array_column($this->api($origin, 'GET', 'memberships/transfers?direction=outgoing&state=all')->assertOk()->json('data'), 'public_id'));
        // M11: history preserved (periods + transfer), audit at the destination with the origin in the metadata.
        $history = $this->api($destination, 'GET', 'memberships/' . $m['public_id'] . '/periods')->assertOk()->json('data');
        $this->assertSame([$w['b1']['public_id'], $w['a1']['public_id']], array_slice(array_column(array_column($history, 'congregation'), 'public_id'), 0, 2));
        $this->assertSame(1, $this->api($destination, 'GET', 'memberships/' . $m['public_id'] . '/transfers')->assertOk()->json('meta.total'));
        $audit = DB::table('audit_logs')->where('source', 'P09_MEMBERSHIP')->where('action', 'membership_transfer.completed')->first();
        $this->assertSame($w['b1']['id'], (int) $audit->unit_id);
        $this->assertSame($w['a1']['public_id'], json_decode($audit->after_metadata, true)['origin']);
        $this->assertSame($number, json_decode($audit->after_metadata, true)['member_number']);
        // Replay of the completion is a no-op.
        $this->api($destination, 'POST', 'memberships/transfers/' . $t['public_id'] . '/complete')->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'membership_transfer.completed')->count());
        $this->assertCount(1, $this->openPeriods($m['public_id']));
        $this->assertGlobalInvariants();
    }

    public function test_m12_open_transfer_blocks_incompatible_lifecycle(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['m']['id']);
        $m = $this->member($staff, $w['a1']);
        $t = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id']])->assertCreated()->json('data');
        $current = $this->detail($staff, $m['public_id']);
        $this->assertSame($t['public_id'], $current['open_transfer']['public_id']);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['a2']['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSFER_IN_PROGRESS');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $current['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSFER_IN_PROGRESS');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/end', ['lock_version' => $current['lock_version'], 'reason' => 'Saída'])->assertStatus(409)->assertJsonPath('error.code', 'TRANSFER_IN_PROGRESS');
        // Invalid destinations.
        $this->api($staff, 'POST', 'memberships/transfers/' . $t['public_id'] . '/cancel', ['reason' => 'Desistência'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['a1']['public_id']])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b']['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'CONGREGATION_NOT_ACTIVE');
        // Cancel frees the membership; a cancelled transfer can never complete.
        $this->api($staff, 'POST', 'memberships/transfers/' . $t['public_id'] . '/complete')->assertStatus(409);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $current['lock_version']])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $inactive = $this->detail($staff, $m['public_id']);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_NOT_ACTIVE');
        // Reject by the origin while REQUESTED, then by the destination while ORIGIN_VALIDATED.
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/reactivate', ['lock_version' => $inactive['lock_version']])->assertOk();
        $t2 = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id']])->assertCreated()->json('data');
        $this->api($staff, 'POST', 'memberships/transfers/' . $t2['public_id'] . '/reject', ['reason' => 'Documentação em falta'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $t3 = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id']])->assertCreated()->json('data');
        $this->api($staff, 'POST', 'memberships/transfers/' . $t3['public_id'] . '/validate-origin')->assertOk();
        $this->api($staff, 'POST', 'memberships/transfers/' . $t3['public_id'] . '/reject')->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame($w['a1']['id'], (int) $this->openPeriods($m['public_id'])[0]->congregation_id, 'rejected/cancelled transfers never move the membership');
        $this->assertSame(3, DB::table('transfers')->where('membership_id', $this->membershipId($m['public_id']))->whereNotNull('closed_at')->whereNull('effective_at')->count());
        $this->assertGlobalInvariants();
    }

    // ---- M13/M14 legacy ---------------------------------------------------------------------------------------------

    public function test_m13_legacy_identifier_is_preserved_normalized_and_searchable_in_scope_only(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $outsider = $this->secretary($w['b']['id']);
        $m = $this->member($staff, $w['a1']);
        $counter = $this->counter();
        $row = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/legacy-identifiers', ['raw_number' => ' mepa-07/1 234 '])->assertCreated()->json('data');
        $this->assertSame(' mepa-07/1 234 ', $row['raw_number'], 'raw value preserved exactly');
        $this->assertSame('MEPA071234', $row['normalized_number']);
        $this->assertSame('ACTIVE', $row['status']);
        $this->assertSame('MEPA_LEGACY_V1', $row['source_system']);
        $this->assertSame($m['member_number'], $this->numberOf($m['public_id']), 'a legacy identifier never replaces the official number');
        $this->assertSame($counter, $this->counter());
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/legacy-identifiers', ['raw_number' => 'MEPA 071234'])->assertStatus(409)->assertJsonPath('error.code', 'LEGACY_ID_EXISTS');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/legacy-identifiers', ['raw_number' => 'X-1', 'source_system' => 'OTHER'])->assertStatus(422);
        $found = $this->api($staff, 'GET', 'memberships?search=' . urlencode('mepa.07.1234'))->assertOk()->json('data');
        $this->assertSame([$m['public_id']], array_column($found, 'public_id'));
        $this->assertSame([], $this->api($outsider, 'GET', 'memberships?search=' . urlencode('MEPA071234'))->assertOk()->json('data'), 'out of scope = empty, like not existing');
        $this->assertSame([], $this->api($outsider, 'GET', 'memberships?search=NOPE99999')->assertOk()->json('data'));
        $this->assertGreaterThanOrEqual(1, DB::table('audit_logs')->where('action', 'membership.lookup')->where('actor_id', $staff['user'])->count(), 'sensitive lookup audited');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'legacy_identifier.registered')->count());
        $this->assertGlobalInvariants();
    }

    public function test_m14_legacy_collision_flags_both_rows_and_revocation_resolves_it(): void
    {
        $w = $this->world();
        $national = $this->secretary($w['m']['id']);
        $local = $this->secretary($w['a']['id']);
        $x = $this->member($national, $w['a1']);
        $y = $this->member($national, $w['b1']);
        $this->api($national, 'POST', 'memberships/' . $x['public_id'] . '/legacy-identifiers', ['raw_number' => 'L-500'])->assertCreated()->assertJsonPath('data.status', 'ACTIVE');
        $second = $this->api($national, 'POST', 'memberships/' . $y['public_id'] . '/legacy-identifiers', ['raw_number' => 'l 500'])->assertCreated()->json('data');
        $this->assertSame('CONFLICT', $second['status']);
        $this->assertSame(['detail_visible' => true, 'memberships' => [$x['public_id']]], $second['conflict']);
        $this->assertSame(['CONFLICT', 'CONFLICT'], DB::table('legacy_member_numbers')->where('normalized_number', 'L500')->orderBy('id')->pluck('status')->all(), 'both rows flagged, none lost');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'legacy_identifier.conflict_flagged')->count());
        // An actor that sees only one of the two memberships sees "in conflict" without identity.
        $mine = $this->api($local, 'GET', 'memberships/' . $x['public_id'] . '/legacy-identifiers')->assertOk()->json('data');
        $this->assertSame(['detail_visible' => false, 'memberships' => []], $mine[0]['conflict']);
        $this->assertStringNotContainsString($y['public_id'], json_encode($mine));
        // Resolution: revoke the wrong row (reason required); the remaining row becomes ACTIVE; nothing is deleted.
        $this->api($national, 'POST', 'memberships/' . $y['public_id'] . '/legacy-identifiers/revoke', ['normalized_number' => 'L500'])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $revoked = $this->api($national, 'POST', 'memberships/' . $y['public_id'] . '/legacy-identifiers/revoke', ['normalized_number' => 'L500', 'reason' => 'Atribuído por engano'])->assertOk()->json('data');
        $this->assertSame('REVOKED', $revoked['status']);
        $this->assertSame(['ACTIVE', 'REVOKED'], DB::table('legacy_member_numbers')->where('normalized_number', 'L500')->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, DB::table('legacy_member_numbers')->where('normalized_number', 'L500')->count());
        $this->assertConcealed($this->api($national, 'POST', 'memberships/' . $y['public_id'] . '/legacy-identifiers/revoke', ['normalized_number' => 'L500', 'reason' => 'De novo']));
        // A revoked value is never recycled silently: a new row on another membership re-enters conflict detection.
        $z = $this->member($national, $w['a2']);
        $this->api($national, 'POST', 'memberships/' . $z['public_id'] . '/legacy-identifiers', ['raw_number' => 'L500'])->assertCreated()->assertJsonPath('data.status', 'CONFLICT');
        $this->assertSame([$x['member_number'], $y['member_number']], [$this->numberOf($x['public_id']), $this->numberOf($y['public_id'])]);
        $this->assertGlobalInvariants();
    }

    // ---- M15/M16 milestones -----------------------------------------------------------------------------------------

    public function test_m15_conversion_milestone_with_precision_and_at_most_one_per_person(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $c = $this->candidate($staff, $w['a1']);
        $row = $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/milestones', ['type' => 'CONVERSION', 'occurred_on' => '2019-06', 'date_precision' => 'MONTH', 'unit_public_id' => $w['a1']['public_id']])->assertCreated()->json('data');
        $this->assertSame(['CONVERSION', '2019-06', 'MONTH'], [$row['type'], $row['occurred_on'], $row['date_precision']]);
        $this->assertSame('2019-06-01', (string) DB::table('ecclesiastical_milestones')->value('occurred_on'));
        $this->assertSame('SUBMITTED', $this->detail($staff, $c['public_id'])['status'], 'a milestone never changes the membership state');
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/milestones', ['type' => 'CONVERSION', 'occurred_on' => '2020', 'date_precision' => 'YEAR'])->assertStatus(409)->assertJsonPath('error.code', 'MILESTONE_EXISTS');
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/milestones', ['type' => 'ADMISSION', 'date_precision' => 'UNKNOWN'])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/milestones', ['type' => 'BAPTISM', 'occurred_on' => '2099-01-01', 'date_precision' => 'EXACT'])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/milestones', ['type' => 'BAPTISM', 'occurred_on' => '2019-06-01', 'date_precision' => 'MONTH'])->assertStatus(422);
        $unknown = $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/milestones', ['type' => 'BAPTISM', 'date_precision' => 'UNKNOWN'])->assertCreated()->json('data');
        $this->assertNull($unknown['occurred_on']);
        $this->assertSame(2, $this->api($staff, 'GET', 'memberships/' . $c['public_id'] . '/milestones')->assertOk()->json('meta.total'));
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'milestone.recorded')->count());
    }

    public function test_m16_baptism_correction_is_versioned_and_audited_before_after(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $m = $this->member($staff, $w['a1']);
        $row = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones', ['type' => 'BAPTISM', 'occurred_on' => '2015', 'date_precision' => 'YEAR'])->assertCreated()->json('data');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones/BAPTISM/correct', ['occurred_on' => '2015-03-08', 'date_precision' => 'EXACT', 'lock_version' => $row['lock_version']])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $fixed = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones/BAPTISM/correct', ['occurred_on' => '2015-03-08', 'date_precision' => 'EXACT', 'reason' => 'Certificado encontrado', 'lock_version' => $row['lock_version']])->assertOk()->json('data');
        $this->assertSame(['2015-03-08', 'EXACT'], [$fixed['occurred_on'], $fixed['date_precision']]);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones/BAPTISM/correct', ['occurred_on' => '2015', 'date_precision' => 'YEAR', 'reason' => 'Versão antiga', 'lock_version' => $row['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $this->assertConcealed($this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones/CONVERSION/correct', ['occurred_on' => '2015', 'date_precision' => 'YEAR', 'reason' => 'Não existe', 'lock_version' => 0]));
        $audit = DB::table('audit_logs')->where('action', 'milestone.corrected')->first();
        $this->assertSame('2015', json_decode($audit->before_metadata, true)['occurred_on']);
        $this->assertSame('2015-03-08', json_decode($audit->after_metadata, true)['occurred_on']);
        $this->assertSame('Certificado encontrado', $audit->reason);
    }

    // ---- M17 documents ------------------------------------------------------------------------------------------------

    public function test_m17_source_document_by_public_id_with_cumulative_files_authority(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id'], ['DOCUMENTS_VIEW']);
        $noFiles = $this->secretary($w['a']['id']);
        $acta = $this->document($w['a']);
        $v = $this->validated($staff, $w['a1']);
        // Unknown field source_document_id (numeric PK) and a numeric source_document are refused.
        $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version'], 'source_document_id' => $acta['id']])->assertStatus(422);
        $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version'], 'source_document' => $acta['id']])->assertStatus(422);
        // Without Files authority, of another unit, archived, above clearance, unknown or malformed: the same 404.
        $foreign = $this->document($w['b']);
        $archived = $this->document($w['a'], 'RESTRICTED', 'ARCHIVED');
        $secret = $this->document($w['a'], 'CONFIDENTIAL');
        $this->assertConcealed($this->api($noFiles, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version'], 'source_document' => $acta['public_id']]));
        foreach ([$foreign['public_id'], $archived['public_id'], $secret['public_id'], (string) Str::ulid(), 'acta-12'] as $bad) {
            $this->assertConcealed($this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version'], 'source_document' => $bad]));
        }
        $this->assertNull($this->numberOf($v['public_id']), 'a concealed document failure consumes no number');
        $m = $this->api($staff, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version'], 'source_document' => $acta['public_id']])->assertOk()->json('data');
        $this->assertSame(['has_document' => true, 'source_document' => ['public_id' => $acta['public_id']]], ['has_document' => $m['has_document'], 'source_document' => $m['source_document']]);
        $this->assertSame($acta['id'], (int) DB::table('memberships')->where('public_id', $v['public_id'])->value('source_document_id'));
        $hidden = $this->detail($noFiles, $v['public_id']);
        $this->assertSame([true, null], [$hidden['has_document'], $hidden['source_document']], 'without Files authority only has_document is shown');
        // Optional everywhere: no document is ever required.
        $this->member($staff, $w['a1']);
        $this->assertGlobalInvariants();
    }

    // ---- M18 / F-06 / IDOR -----------------------------------------------------------------------------------------------

    public function test_m18_wrong_scope_malformed_and_unknown_targets_are_byte_identical(): void
    {
        $w = $this->world();
        $owner = $this->secretary($w['a']['id']);
        $outsider = $this->secretary($w['b']['id']);
        $m = $this->member($owner, $w['a1']);
        $t = $this->api($owner, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['a2']['public_id']])->assertCreated()->json('data');
        $targets = [$m['public_id'], (string) Str::ulid(), 'malformed', '12'];
        $responses = [];
        foreach ($targets as $target) {
            foreach (['GET memberships/%s', 'GET memberships/%s/periods', 'GET memberships/%s/transfers', 'GET memberships/%s/legacy-identifiers', 'GET memberships/%s/milestones',
                'POST memberships/%s/inactivate', 'POST memberships/%s/end', 'POST memberships/%s/validate', 'POST memberships/%s/approve', 'POST memberships/%s/transfers',
                'POST memberships/%s/legacy-identifiers', 'POST memberships/%s/milestones'] as $route) {
                [$method, $uri] = explode(' ', $route);
                $body = ['lock_version' => 0, 'reason' => 'Sonda IDOR', 'destination_public_id' => $w['b1']['public_id'], 'raw_number' => 'X1', 'type' => 'BAPTISM', 'date_precision' => 'UNKNOWN'];
                $body = $method === 'GET' ? [] : array_intersect_key($body, array_flip(match (true) {
                    str_ends_with($uri, '/transfers') => ['destination_public_id'],
                    str_ends_with($uri, '/legacy-identifiers') => ['raw_number'],
                    str_ends_with($uri, '/milestones') => ['type', 'date_precision'],
                    default => ['lock_version', 'reason'],
                }));
                $response = $this->api($outsider, $method, sprintf($uri, $target), $body);
                $this->assertConcealed($response);
                $responses[] = $response->getContent();
            }
        }
        $this->assertCount(1, array_unique($responses), 'wrong scope, unknown and malformed are indistinguishable');
        // Transfers outside authority, including the stage endpoints.
        foreach (['GET memberships/transfers/%s', 'POST memberships/transfers/%s/validate-origin', 'POST memberships/transfers/%s/accept', 'POST memberships/transfers/%s/complete', 'POST memberships/transfers/%s/cancel', 'POST memberships/transfers/%s/reject'] as $route) {
            [$method, $uri] = explode(' ', $route);
            foreach ([$t['public_id'], (string) Str::ulid(), 'bad'] as $target) {
                $this->assertConcealed($this->api($outsider, $method, sprintf($uri, $target)));
            }
        }
        // Permission before target (P07-I-02): without the permission it is 403 whatever the target.
        $viewer = $this->staff(['MEMBERSHIP_VIEW'], $w['a']['id']);
        foreach ([$m['public_id'], (string) Str::ulid(), 'bad'] as $target) {
            $this->api($viewer, 'POST', 'memberships/' . $target . '/inactivate', ['lock_version' => 0])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }
        $nobody = $this->staff(['PEOPLE_VIEW'], $w['a']['id']);
        $this->api($nobody, 'GET', 'memberships/' . $m['public_id'])->assertStatus(403);
        $this->api($nobody, 'GET', 'memberships')->assertStatus(403);
        // A known public id in a filter grants nothing: out-of-scope congregation filter = empty.
        $this->assertSame([], $this->api($outsider, 'GET', 'memberships?congregation_public_id=' . $w['a1']['public_id'])->assertOk()->json('data'));
        // Destination-only requester learns nothing about a membership it cannot see.
        $dest = $this->staff(['MEMBERSHIP_TRANSFER'], $w['a2']['id']);
        $this->assertConcealed($this->api($dest, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['a2']['public_id']]));
        // Pagination bounds.
        $this->api($owner, 'GET', 'memberships?per_page=101')->assertStatus(422);
        $this->assertNoInternalIds($this->api($owner, 'GET', 'memberships')->assertOk()->json());
        $this->assertNoInternalIds($this->api($owner, 'GET', 'memberships/transfers?state=all')->assertOk()->json());
        $this->assertNoInternalIds($this->detail($owner, $m['public_id']));
    }

    public function test_m19_official_number_search_is_scoped_and_audited(): void
    {
        $w = $this->world();
        $owner = $this->secretary($w['a']['id']);
        $outsider = $this->secretary($w['b']['id']);
        $m = $this->member($owner, $w['a1']);
        $this->assertSame([$m['public_id']], array_column($this->api($owner, 'GET', 'memberships?search=' . $m['member_number'])->assertOk()->json('data'), 'public_id'));
        $this->assertSame([$m['public_id']], array_column($this->api($owner, 'GET', 'memberships?search=' . strtolower($m['member_number']))->assertOk()->json('data'), 'public_id'));
        $outside = $this->api($outsider, 'GET', 'memberships?search=' . $m['member_number'])->assertOk();
        $missing = $this->api($outsider, 'GET', 'memberships?search=MEPA2601999999')->assertOk();
        $this->assertSame($missing->json('data'), $outside->json('data'));
        $this->assertSame($missing->json('meta'), $outside->json('meta'), 'out of scope and nonexistent numbers are indistinguishable');
        $audit = DB::table('audit_logs')->where('action', 'membership.lookup')->where('actor_id', $owner['user'])->first();
        $this->assertSame(['kind' => 'OFFICIAL_NUMBER', 'term' => $m['member_number'], 'results' => 1], json_decode($audit->after_metadata, true));
        $this->assertSame($w['a']['id'], (int) $audit->unit_id);
        // Name search.
        $named = $this->person($w['a1'], 'Zacarias Únicoteste');
        $c = $this->submit($owner, $named, $w['a1'])->assertCreated()->json('data');
        $this->assertSame([$c['public_id']], array_column($this->api($owner, 'GET', 'memberships?search=' . urlencode('Únicoteste'))->assertOk()->json('data'), 'public_id'));
        $this->assertSame([], $this->api($outsider, 'GET', 'memberships?search=' . urlencode('Únicoteste'))->assertOk()->json('data'));
    }

    // ---- M20 deceased ----------------------------------------------------------------------------------------------------

    public function test_m20_deceased_person_keeps_history_and_only_accepts_closure(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $m = $this->member($staff, $w['a1']);
        $other = $this->member($staff, $w['a1']);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones', ['type' => 'BAPTISM', 'occurred_on' => '2001', 'date_precision' => 'YEAR'])->assertCreated();
        $before = $this->api($staff, 'GET', 'memberships/context')->assertOk()->json('data.counts.active_members');
        $personId = (int) DB::table('memberships')->where('public_id', $m['public_id'])->value('person_id');
        DB::table('people')->where('id', $personId)->update(['status_id' => (int) DB::table('person_statuses')->where('code', 'DECEASED')->value('id')]);
        $d = $this->detail($staff, $m['public_id']);
        $this->assertTrue($d['deceased']);
        $this->assertTrue($d['person']['deceased']);
        $this->assertSame($m['member_number'], $d['member_number']);
        $this->assertSame($before - 1, $this->api($staff, 'GET', 'memberships/context')->assertOk()->json('data.counts.active_members'), 'a deceased member leaves the active count');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $d['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['a2']['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/milestones', ['type' => 'CONVERSION', 'date_precision' => 'UNKNOWN'])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        $ended = $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/end', ['lock_version' => $d['lock_version'], 'reason' => 'Falecimento'])->assertOk()->json('data');
        $this->assertSame('ENDED', $ended['status']);
        $this->assertSame($m['member_number'], $ended['member_number']);
        $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/readmit', ['lock_version' => $ended['lock_version'], 'reason' => 'Engano'])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        $this->assertSame(1, DB::table('ecclesiastical_milestones')->where('person_id', $personId)->count(), 'milestones preserved');
        $this->assertGreaterThanOrEqual(4, DB::table('membership_periods')->where('membership_id', $this->membershipId($m['public_id']))->count(), 'periods preserved');
        // A deceased candidate cannot be submitted/validated/approved; withdrawal (closure) stays possible.
        $c = $this->candidate($staff, $w['a1']);
        DB::table('people')->where('public_id', $c['person']['public_id'])->update(['status_id' => (int) DB::table('person_statuses')->where('code', 'DECEASED')->value('id')]);
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $c['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        $this->api($staff, 'POST', 'memberships/' . $c['public_id'] . '/withdraw', ['lock_version' => $c['lock_version']])->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->assertSame($other['member_number'], $this->numberOf($other['public_id']));
        // Archived Person: any write refused.
        $a = $this->member($staff, $w['a1']);
        DB::table('people')->where('public_id', $a['person']['public_id'])->update(['archived_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        $this->api($staff, 'POST', 'memberships/' . $a['public_id'] . '/end', ['lock_version' => $a['lock_version'], 'reason' => 'Arquivo'])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_NOT_OPERATIONAL');
        $this->assertGlobalInvariants();
    }

    // ---- commit-time recheck ----------------------------------------------------------------------------------------------

    public function test_commit_time_recheck_rejects_a_grant_revoked_while_the_operation_ran(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $m = $this->member($staff, $w['a1']);
        app()->instance('membership.before_commit', function () use ($staff): void {
            DB::table('user_role_scopes')->where('user_id', $staff['user'])->update(['ends_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        });
        try {
            $this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $m['lock_version']])->assertStatus(403);
        } finally {
            app()->offsetUnset('membership.before_commit');
        }
        $this->assertSame('ACTIVE', $this->detail($staff, $m['public_id'])['status']);
        // The scope moving away (unit re-parented out of the grant) is concealed at commit.
        $moved = $this->unit('CENTER', $w['m']['id'], 'ACTIVE', 'Centro C');
        app()->instance('membership.before_commit', function () use ($w, $moved): void {
            DB::table('organizational_units')->where('id', $w['a1']['id'])->update(['parent_id' => $moved['id']]);
        });
        try {
            $this->assertConcealed($this->api($staff, 'POST', 'memberships/' . $m['public_id'] . '/inactivate', ['lock_version' => $m['lock_version']]));
        } finally {
            app()->offsetUnset('membership.before_commit');
        }
        $this->assertSame($w['a']['id'], (int) DB::table('organizational_units')->where('id', $w['a1']['id'])->value('parent_id'));
        $this->assertGlobalInvariants();
    }

    // ---- C1..C5 (ADR 0020 D12): real processes, one MySQL connection each, ready/go barrier -----------------------------

    public function test_c1a_m21_concurrent_approvals_issue_distinct_contiguous_numbers(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $evidence = [];
        foreach ([2, 10, 30] as $n) {
            $items = array_map(fn () => $this->validated($staff, $w['a1']), range(1, $n));
            $before = $this->counter();
            $results = $this->race(array_map(fn ($v) => ['op' => 'approve', 'membership' => $v['public_id'], 'lock_version' => $v['lock_version']] + $this->actor($staff), $items));
            $this->assertSame(array_fill(0, $n, 'OK'), array_column($results, 'status'), json_encode($results));
            $numbers = array_map(fn ($v) => $this->numberOf($v['public_id']), $items);
            $this->assertCount($n, array_unique($numbers), 'distinct numbers');
            $sequences = array_map(fn ($v) => (int) DB::table('member_numbers')->where('membership_id', $this->membershipId($v['public_id']))->value('sequence_value'), $items);
            sort($sequences);
            $this->assertSame(range($before + 1, $before + $n), $sequences, 'contiguous national sequence, no gap, no duplicate');
            $this->assertSame($before + $n, $this->counter());
            $evidence['N' . $n] = ['workers' => $n, 'ok' => $n, 'distinct_numbers' => count(array_unique($numbers)), 'first' => $before + 1, 'last' => $before + $n, 'counter_after' => $this->counter(), 'duplicates' => 0];
        }
        $this->assertGlobalInvariants();
        $this->evidence('C1a', $evidence);
    }

    public function test_c1b_c1c_c1d_same_membership_and_overlapping_lots_never_double_number(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['a']['id']);
        $evidence = [];
        // C1b: the same membership approved twice at once.
        $v = $this->validated($staff, $w['a1']);
        $before = $this->counter();
        $r = $this->race([
            ['op' => 'approve', 'membership' => $v['public_id'], 'lock_version' => $v['lock_version']] + $this->actor($staff),
            ['op' => 'approve', 'membership' => $v['public_id'], 'lock_version' => $v['lock_version']] + $this->actor($staff),
        ]);
        $statuses = array_column($r, 'status');
        sort($statuses);
        $this->assertSame('OK', $statuses[0], json_encode($r));
        $this->assertContains($statuses[1], ['STALE_WRITE', 'TRANSITION_NOT_ALLOWED'], json_encode($r));
        $this->assertSame($before + 1, $this->counter());
        $this->assertSame(1, DB::table('member_numbers')->where('membership_id', $this->membershipId($v['public_id']))->count());
        $evidence['C1b'] = ['statuses' => $statuses, 'numbers_issued' => 1];
        // C1c: collective lot vs individual approval of one of its items (no deadlock, no double number).
        $lot = array_map(fn () => $this->validated($staff, $w['a1']), range(1, 4));
        $before = $this->counter();
        $r = $this->race([
            ['op' => 'collective', 'memberships' => array_column($lot, 'public_id')] + $this->actor($staff),
            ['op' => 'approve', 'membership' => $lot[2]['public_id'], 'lock_version' => $lot[2]['lock_version']] + $this->actor($staff),
        ]);
        [$collective, $single] = array_column($r, 'status');
        $this->assertTrue(($collective === 'OK' && in_array($single, ['STALE_WRITE', 'TRANSITION_NOT_ALLOWED'], true)) || ($single === 'OK' && $collective === 'COLLECTIVE_REJECTED'), json_encode($r));
        $this->assertSame($before + ($collective === 'OK' ? 4 : 1), $this->counter());
        $evidence['C1c'] = ['collective' => $collective, 'individual' => $single, 'numbers_issued' => $this->counter() - $before];
        // C1d: two lots sharing items.
        $x = array_map(fn () => $this->validated($staff, $w['a1']), range(1, 4));
        $before = $this->counter();
        $r = $this->race([
            ['op' => 'collective', 'memberships' => [$x[0]['public_id'], $x[1]['public_id'], $x[2]['public_id']]] + $this->actor($staff),
            ['op' => 'collective', 'memberships' => [$x[2]['public_id'], $x[3]['public_id'], $x[0]['public_id']]] + $this->actor($staff),
        ]);
        $statuses = array_column($r, 'status');
        sort($statuses);
        $this->assertSame(['COLLECTIVE_REJECTED', 'OK'], $statuses, json_encode($r));
        $this->assertSame($before + 3, $this->counter(), 'one lot wins entirely, the other fails entirely');
        $evidence['C1d'] = ['statuses' => $statuses, 'numbers_issued' => 3];
        $this->assertGlobalInvariants();
        $this->evidence('C1bcd', $evidence);
    }

    public function test_c2_transfer_completion_or_request_against_inactivation_and_end(): void
    {
        $w = $this->world();
        $origin = $this->secretary($w['a']['id']);
        $destination = $this->secretary($w['b']['id']);
        $evidence = [];
        foreach (['inactivate', 'end', 'inactivate', 'end'] as $round => $op) {
            $m = $this->member($origin, $w['a1']);
            $t = $this->api($origin, 'POST', 'memberships/' . $m['public_id'] . '/transfers', ['destination_public_id' => $w['b1']['public_id']])->assertCreated()->json('data');
            $this->api($origin, 'POST', 'memberships/transfers/' . $t['public_id'] . '/validate-origin')->assertOk();
            $this->api($destination, 'POST', 'memberships/transfers/' . $t['public_id'] . '/accept')->assertOk();
            $lock = (int) DB::table('memberships')->where('public_id', $m['public_id'])->value('lock_version');
            $r = $this->race([
                ['op' => 'complete', 'transfer' => $t['public_id']] + $this->actor($destination),
                ['op' => $op, 'membership' => $m['public_id'], 'lock_version' => $lock] + $this->actor($origin),
            ]);
            [$complete, $lifecycle] = array_column($r, 'status');
            $this->assertSame('OK', $complete, json_encode($r));
            $this->assertContains($lifecycle, ['TRANSFER_IN_PROGRESS', 'OUT_OF_SCOPE', 'STALE_WRITE'], json_encode($r));
            $open = $this->openPeriods($m['public_id']);
            $this->assertCount(1, $open, 'never open at origin and destination at once');
            $this->assertSame($w['b1']['id'], (int) $open[0]->congregation_id);
            $this->assertSame('COMPLETED', DB::table('transfers')->where('public_id', $t['public_id'])->value('status'));
            $this->assertSame($m['member_number'], $this->numberOf($m['public_id']));
            $evidence['C2a_' . $round] = ['race' => 'complete vs ' . $op, 'complete' => $complete, $op => $lifecycle, 'open_periods' => 1, 'at' => 'destination'];
        }
        // C2b: request vs end on an ACTIVE membership.
        foreach (range(1, 3) as $round) {
            $m = $this->member($origin, $w['a1']);
            $r = $this->race([
                ['op' => 'request', 'membership' => $m['public_id'], 'destination' => $w['b1']['public_id']] + $this->actor($origin),
                ['op' => 'end', 'membership' => $m['public_id'], 'lock_version' => $m['lock_version']] + $this->actor($origin),
            ]);
            [$request, $end] = array_column($r, 'status');
            $this->assertTrue(($request === 'OK' && $end === 'TRANSFER_IN_PROGRESS') || ($end === 'OK' && $request === 'MEMBERSHIP_NOT_ACTIVE'), json_encode($r));
            $this->assertCount(1, $this->openPeriods($m['public_id']));
            $evidence['C2b_' . $round] = ['request' => $request, 'end' => $end];
        }
        $this->assertGlobalInvariants();
        $this->evidence('C2', $evidence);
    }

    public function test_c3_m22_concurrent_transfers(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['m']['id']);
        $evidence = [];
        // C3a: concurrent requests for one membership (2 and 5 racers) -> exactly one open transfer.
        foreach ([2, 5] as $n) {
            $m = $this->member($staff, $w['a1']);
            $destinations = [$w['b1'], $w['a2']];
            $r = $this->race(array_map(fn ($i) => ['op' => 'request', 'membership' => $m['public_id'], 'destination' => $destinations[$i % 2]['public_id']] + $this->actor($staff), range(0, $n - 1)));
            $statuses = array_count_values(array_column($r, 'status'));
            ksort($statuses);
            $this->assertSame(['OK' => 1, 'TRANSFER_IN_PROGRESS' => $n - 1], $statuses, json_encode($r));
            $this->assertSame(1, DB::table('transfers')->where('membership_id', $this->membershipId($m['public_id']))->whereNull('closed_at')->count());
            $evidence['C3a_N' . $n] = $statuses;
        }
        // C3b: two completions of the same transfer -> one pair of periods, the other is a no-op replay.
        $m = $this->member($staff, $w['a1']);
        $t = $this->acceptedTransfer($staff, $m, $w['b1']);
        $periods = DB::table('membership_periods')->where('membership_id', $this->membershipId($m['public_id']))->count();
        $r = $this->race([['op' => 'complete', 'transfer' => $t] + $this->actor($staff), ['op' => 'complete', 'transfer' => $t] + $this->actor($staff)]);
        $this->assertSame(['OK', 'OK'], array_column($r, 'status'), json_encode($r));
        $this->assertSame($periods + 1, DB::table('membership_periods')->where('membership_id', $this->membershipId($m['public_id']))->count(), 'exactly one period pair (one closed, one opened)');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'membership_transfer.completed')->where('entity_id', DB::table('transfers')->where('public_id', $t)->value('id'))->count());
        $evidence['C3b'] = ['statuses' => array_column($r, 'status'), 'new_periods' => 1];
        // C3c: completion vs cancellation -> exactly one terminal state.
        foreach (range(1, 3) as $round) {
            $m = $this->member($staff, $w['a1']);
            $t = $this->acceptedTransfer($staff, $m, $w['b1']);
            $r = $this->race([['op' => 'complete', 'transfer' => $t] + $this->actor($staff), ['op' => 'cancel', 'transfer' => $t] + $this->actor($staff)]);
            [$complete, $cancel] = array_column($r, 'status');
            $status = DB::table('transfers')->where('public_id', $t)->value('status');
            $this->assertTrue(($complete === 'OK' && $cancel === 'TRANSITION_NOT_ALLOWED' && $status === 'COMPLETED') || ($cancel === 'OK' && $complete === 'TRANSITION_NOT_ALLOWED' && $status === 'CANCELLED'), json_encode($r));
            $open = $this->openPeriods($m['public_id']);
            $this->assertCount(1, $open);
            $this->assertSame($status === 'COMPLETED' ? $w['b1']['id'] : $w['a1']['id'], (int) $open[0]->congregation_id);
            $evidence['C3c_' . $round] = ['complete' => $complete, 'cancel' => $cancel, 'final' => $status];
        }
        $this->assertGlobalInvariants();
        $this->evidence('C3', $evidence);
    }

    public function test_c4_c5_concurrent_submissions_and_legacy_registrations(): void
    {
        $w = $this->world();
        $staff = $this->secretary($w['m']['id']);
        $evidence = [];
        foreach (range(1, 3) as $round) {
            $person = $this->person($w['a1']);
            $r = $this->race([
                ['op' => 'submit', 'person' => $person['public_id'], 'congregation' => $w['a1']['public_id']] + $this->actor($staff),
                ['op' => 'submit', 'person' => $person['public_id'], 'congregation' => $w['a2']['public_id']] + $this->actor($staff),
            ]);
            $statuses = array_column($r, 'status');
            sort($statuses);
            $this->assertSame(['MEMBERSHIP_EXISTS', 'OK'], $statuses, json_encode($r));
            $this->assertSame(1, DB::table('memberships')->where('person_id', $person['id'])->count());
            $evidence['C4_' . $round] = $statuses;
        }
        foreach (range(1, 3) as $round) {
            $x = $this->member($staff, $w['a1']);
            $y = $this->member($staff, $w['b1']);
            $value = 'C5-' . $round . '-' . Str::upper(Str::random(5));
            $r = $this->race([
                ['op' => 'legacy', 'membership' => $x['public_id'], 'raw' => $value] + $this->actor($staff),
                ['op' => 'legacy', 'membership' => $y['public_id'], 'raw' => strtolower($value)] + $this->actor($staff),
            ]);
            $this->assertSame(['OK', 'OK'], array_column($r, 'status'), json_encode($r));
            $rows = DB::table('legacy_member_numbers')->where('normalized_number', str_replace('-', '', $value))->orderBy('id')->pluck('status')->all();
            $this->assertSame(['CONFLICT', 'CONFLICT'], $rows, 'both flagged, none lost');
            $evidence['C5_' . $round] = ['statuses' => array_column($r, 'status'), 'rows' => $rows];
        }
        $this->assertGlobalInvariants();
        $this->evidence('C4C5', $evidence);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------

    private function acceptedTransfer(array $actor, array $member, array $destination): string
    {
        $t = $this->api($actor, 'POST', 'memberships/' . $member['public_id'] . '/transfers', ['destination_public_id' => $destination['public_id']])->assertCreated()->json('data.public_id');
        $this->api($actor, 'POST', 'memberships/transfers/' . $t . '/validate-origin')->assertOk();
        $this->api($actor, 'POST', 'memberships/transfers/' . $t . '/accept')->assertOk();
        return $t;
    }

    private function actor(array $staff): array
    {
        return ['user' => $staff['user'], 'session' => $staff['session']];
    }

    /**
     * Each job in its own PHP process / MySQL connection. Barrier: every worker prints READY after booting and
     * connecting; only when ALL are ready is "go" written to all of them. Returns results in job order.
     */
    private function race(array $jobs): array
    {
        $root = dirname(__DIR__, 4);
        $php = getenv('MEPA_PHP_BIN') ?: 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe';
        $db = config('database.connections.mysql');
        $env = getenv() + [];
        $env = array_merge($env, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'APP_KEY' => (string) config('app.key'), 'WAVE5_ALLOW_SYNTHETIC' => '1', 'XDEBUG_MODE' => 'off']);
        $procs = [];
        foreach ($jobs as $job) {
            $payload = base64_encode(json_encode($job, JSON_THROW_ON_ERROR));
            $proc = proc_open([$php, $root . '/scripts/p09-membership-worker.php', $payload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        foreach ($procs as [, $pipes]) {
            $line = str_replace("\r", '', (string) fgets($pipes[1]));
            if ($line !== "READY\n") {
                // stderr is read only on failure: reading it eagerly would block until the worker exits (it is waiting
                // for "go").
                $this->fail('worker not ready: ' . $line . stream_get_contents($pipes[2]));
            }
        }
        foreach ($procs as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $results[] = json_decode(trim((string) $out), true, 512, JSON_THROW_ON_ERROR);
        }
        foreach ($results as $result) {
            $this->assertNotSame('UNEXPECTED', $result['status'], json_encode($result));
        }
        return $results;
    }

    private function evidence(string $name, array $data): void
    {
        $dir = getenv('P09_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'concurrency-' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
