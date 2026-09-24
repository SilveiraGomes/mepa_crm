<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\People\PeopleCatalog;
use App\Domain\People\PeopleError;
use App\Domain\People\PeopleReason;
use App\Domain\People\PersonService;
use App\Http\People\PeopleServiceFactory;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/** P0.5-I block 1: Person list / detail / create / update / lifecycle, scope resolver, F-06, birth precision. */
final class PeoplePersonTest extends PeopleHttpCase
{
    private const ALL = ['PEOPLE_VIEW', 'PEOPLE_CREATE', 'PEOPLE_EDIT', 'PEOPLE_SENSITIVE_VIEW'];

    public function test_create_non_member_person_writes_onboarding_context_and_audit_from_that_context(): void
    {
        $staff = $this->staff(self::ALL);
        $response = $this->api($staff, 'POST', 'people', ['full_name' => '  Ana   Não Membro ', 'birth_precision' => 'UNKNOWN'])->assertCreated();
        $data = $response->json('data');
        self::assertSame('Ana Não Membro', $data['display_name']);
        self::assertSame('ACTIVE', $data['status']);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $data['public_id']);
        $this->assertNoInternalFields($response->json());
        self::assertArrayNotHasKey('member_number', $data);

        $id = (int) DB::table('people')->where('public_id', $data['public_id'])->value('id');
        self::assertSame(0, DB::table('memberships')->where('person_id', $id)->count(), 'no membership is created');
        self::assertSame(0, DB::table('member_numbers')->count(), 'no member number is issued');
        $context = DB::table('person_unit_contexts')->where('person_id', $id)->first();
        self::assertSame($staff['unit'], (int) $context->unit_id);
        self::assertSame('ONBOARDING', $context->context_kind);
        self::assertSame('ACTIVE', $context->status);

        $audit = $this->audits('PERSON_CREATED', 'people', $id);
        self::assertCount(1, $audit);
        self::assertSame($staff['unit'], (int) $audit[0]->unit_id, 'audit unit comes from the authorizing context');
        self::assertSame($staff['session'], (int) $audit[0]->session_id);
        $after = json_decode($audit[0]->after_metadata, true);
        self::assertSame('PERSON_UNIT_CONTEXT', $after['authority']['context_source']);
        self::assertSame((int) $context->id, $after['authority']['context_id']);
        self::assertStringNotContainsString('Ana', $audit[0]->after_metadata);

        $this->api($staff, 'GET', 'people/' . $data['public_id'])->assertOk()->assertJsonPath('data.display_name', 'Ana Não Membro');
    }

    public function test_client_cannot_choose_context_kind_unit_id_status_or_member_number(): void
    {
        $staff = $this->staff(self::ALL);
        foreach (['unit_id' => $staff['unit'], 'context_kind' => 'EMPLOYMENT', 'status' => 'DECEASED', 'member_number' => 'X-1', 'id' => 5] as $field => $value) {
            $this->api($staff, 'POST', 'people', ['full_name' => 'Campo Proibido', 'birth_precision' => 'UNKNOWN', $field => $value])
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        self::assertSame(0, DB::table('people')->where('full_name', 'Campo Proibido')->count());
    }

    public function test_working_unit_must_be_inside_create_scope_and_descendants_are_confirmed_by_server(): void
    {
        $root = $this->unit();
        $child = $this->unit($root);
        $foreign = $this->unit();
        $staff = $this->staff(self::ALL, $root, true);
        $this->api($staff, 'POST', 'people', ['full_name' => 'Fora', 'birth_precision' => 'UNKNOWN', 'unit' => $this->publicUnit($foreign)])->assertStatus(422);
        $created = $this->api($staff, 'POST', 'people', ['full_name' => 'Na Filha', 'birth_precision' => 'UNKNOWN', 'unit' => $this->publicUnit($child)])->assertCreated()->json('data.public_id');
        $id = (int) DB::table('people')->where('public_id', $created)->value('id');
        self::assertSame($child, (int) DB::table('person_unit_contexts')->where('person_id', $id)->value('unit_id'));
        self::assertSame($child, (int) $this->audits('PERSON_CREATED', 'people', $id)[0]->unit_id);
        $context = $this->api($staff, 'GET', 'people/context')->assertOk()->json('data');
        self::assertContains('PEOPLE_CREATE', $context['permissions']);
        self::assertEqualsCanonicalizing([$this->publicUnit($root), $this->publicUnit($child)], array_column($context['working_units'], 'public_id'));
    }

    public function test_birth_precision_is_stored_without_invented_components(): void
    {
        $staff = $this->staff(self::ALL);
        $cases = [
            ['EXACT', ['birth_date' => '1990-05-17'], ['1990-05-17', null, null]],
            ['MONTH', ['birth_year' => 1985, 'birth_month' => 3], [null, 1985, 3]],
            ['YEAR', ['birth_year' => 1970], [null, 1970, null]],
            ['UNKNOWN', [], [null, null, null]],
        ];
        foreach ($cases as [$precision, $fields, [$date, $year, $month]]) {
            $public = $this->api($staff, 'POST', 'people', ['full_name' => 'Nascimento ' . $precision, 'birth_precision' => $precision] + $fields)->assertCreated()->json('data');
            $row = DB::table('people')->where('public_id', $public['public_id'])->first();
            self::assertSame($precision, $row->birth_precision);
            self::assertSame($date, $row->birth_date);
            self::assertSame($year, $row->birth_year === null ? null : (int) $row->birth_year);
            self::assertSame($month, $row->birth_month === null ? null : (int) $row->birth_month);
            self::assertSame($precision, $public['birth']['precision']);
        }
        $month = $this->api($staff, 'GET', 'people/' . DB::table('people')->where('birth_precision', 'MONTH')->value('public_id'))->json('data.birth');
        self::assertSame(['precision' => 'MONTH', 'year' => 1985, 'month' => 3], $month, 'MONTH never exposes a day');

        $invalid = [
            ['birth_precision' => 'MONTH', 'birth_year' => 1985, 'birth_month' => 3, 'birth_date' => '1985-03-01'],
            ['birth_precision' => 'MONTH', 'birth_year' => 1985],
            ['birth_precision' => 'YEAR', 'birth_year' => 1985, 'birth_month' => 1],
            ['birth_precision' => 'EXACT', 'birth_date' => '2999-01-01'],
            ['birth_precision' => 'EXACT', 'birth_date' => '1990-02-30'],
            ['birth_precision' => 'UNKNOWN', 'birth_year' => 1990],
            ['birth_precision' => 'YEAR_ONLY', 'birth_year' => 1990],
        ];
        foreach ($invalid as $body) {
            $this->api($staff, 'POST', 'people', ['full_name' => 'Inválido'] + $body)->assertStatus(422);
        }
        self::assertSame(0, DB::table('people')->where('full_name', 'Inválido')->count());
    }

    public function test_database_check_rejects_fictitious_components(): void
    {
        $status = (int) DB::table('person_statuses')->where('code', 'ACTIVE')->value('id');
        $base = ['public_id' => (string) \Illuminate\Support\Str::ulid(), 'full_name' => 'CHECK', 'status_id' => $status, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0];
        foreach ([
            ['birth_precision' => 'MONTH', 'birth_year' => 1990, 'birth_month' => null],
            ['birth_precision' => 'MONTH', 'birth_year' => 1990, 'birth_month' => 13],
            ['birth_precision' => 'MONTH', 'birth_date' => '1990-01-01', 'birth_year' => 1990, 'birth_month' => 1],
            ['birth_precision' => 'YEAR', 'birth_year' => 1990, 'birth_month' => 1],
            ['birth_precision' => 'YEAR_ONLY', 'birth_year' => 1990],
            ['birth_precision' => 'EXACT', 'birth_date' => '1990-01-01', 'birth_month' => 1],
        ] as $values) {
            try {
                DB::table('people')->insert(['public_id' => (string) \Illuminate\Support\Str::ulid()] + $values + $base);
                self::fail('CHECK accepted ' . json_encode($values));
            } catch (\Illuminate\Database\QueryException $e) {
                self::assertSame(3819, (int) $e->errorInfo[1]);
            }
        }
    }

    public function test_list_is_scoped_paginated_and_exposes_public_ids_only(): void
    {
        $staff = $this->staff(['PEOPLE_VIEW']);
        $other = $this->unit();
        for ($i = 0; $i < 55; $i++) {
            $this->personAt($staff['unit'], ['full_name' => sprintf('Lista %03d', $i)]);
        }
        $this->personAt($other, ['full_name' => 'Lista Fora do Âmbito']);
        $page = $this->api($staff, 'GET', 'people?search=Lista')->assertOk();
        self::assertCount(50, $page->json('data'));
        self::assertSame(55, $page->json('meta.total'));
        self::assertSame(50, $page->json('meta.per_page'));
        $this->assertNoInternalFields($page->json());
        $second = $this->api($staff, 'GET', 'people?search=Lista&page=2')->json('data');
        self::assertCount(5, $second);
        self::assertNotContains('Lista Fora do Âmbito', array_column(array_merge($page->json('data'), $second), 'display_name'));
        self::assertCount(55, $this->api($staff, 'GET', 'people?search=Lista&per_page=100')->json('data'));
        $this->api($staff, 'GET', 'people?per_page=101')->assertStatus(422);
        $this->api($staff, 'GET', 'people?search=Lista&sort=id')->assertStatus(422);
    }

    public function test_out_of_scope_and_nonexistent_targets_are_indistinguishable(): void
    {
        $staff = $this->staff(self::ALL);
        $foreign = $this->personAt($this->unit());
        $missing = (string) \Illuminate\Support\Str::ulid();
        $responses = [
            $this->api($staff, 'GET', 'people/' . $foreign['public_id']),
            $this->api($staff, 'GET', 'people/' . $missing),
            $this->api($staff, 'GET', 'people/123'),
            $this->api($staff, 'PATCH', 'people/' . $foreign['public_id'], ['full_name' => 'Xy', 'lock_version' => 0]),
            $this->api($staff, 'PATCH', 'people/' . $missing, ['full_name' => 'Xy', 'lock_version' => 0]),
            $this->api($staff, 'POST', 'people/' . $foreign['public_id'] . '/inactivate', ['lock_version' => 0]),
        ];
        foreach ($responses as $response) {
            $response->assertStatus(404);
            self::assertSame($this->notFoundBody(), $response->json());
        }
        self::assertSame('Pessoa', substr((string) DB::table('people')->where('id', $foreign['id'])->value('full_name'), 0, 6));
    }

    public function test_permission_is_required_and_never_replaces_scope(): void
    {
        $unit = $this->unit();
        $target = $this->personAt($unit);
        $viewer = $this->staff(['PEOPLE_VIEW'], $unit);
        $this->api($viewer, 'PATCH', 'people/' . $target['public_id'], ['full_name' => 'Tentativa', 'lock_version' => 0])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->api($viewer, 'POST', 'people', ['full_name' => 'Sem Create', 'birth_precision' => 'UNKNOWN'])->assertStatus(403);
        $editorElsewhere = $this->staff(['PEOPLE_VIEW', 'PEOPLE_EDIT', 'PEOPLE_CREATE']);
        $this->api($editorElsewhere, 'PATCH', 'people/' . $target['public_id'], ['full_name' => 'Tentativa', 'lock_version' => 0])->assertStatus(404);
        $nobody = $this->staff([]);
        $this->api($nobody, 'GET', 'people/context')->assertOk()->assertExactJson(['data' => ['permissions' => [], 'working_units' => []]]);
        $this->api($nobody, 'GET', 'people/catalogs')->assertStatus(403);
        $this->api($nobody, 'GET', 'people')->assertStatus(403);
        $this->api($nobody, 'GET', 'people/' . $target['public_id'])->assertStatus(404);
        self::assertNotSame('Tentativa', DB::table('people')->where('id', $target['id'])->value('full_name'));
    }

    public function test_update_uses_optimistic_lock_and_audits_without_personal_values(): void
    {
        $staff = $this->staff(self::ALL);
        $target = $this->personAt($staff['unit'], ['full_name' => 'Nome Antigo']);
        $updated = $this->api($staff, 'PATCH', 'people/' . $target['public_id'], ['full_name' => 'Nome Novo', 'birth_precision' => 'YEAR', 'birth_year' => 1980, 'lock_version' => 0])->assertOk();
        self::assertSame('Nome Novo', $updated->json('data.display_name'));
        self::assertSame(1, $updated->json('data.lock_version'));
        $this->api($staff, 'PATCH', 'people/' . $target['public_id'], ['full_name' => 'Escrita Obsoleta', 'lock_version' => 0])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        self::assertSame('Nome Novo', DB::table('people')->where('id', $target['id'])->value('full_name'));
        $audit = $this->audits('PERSON_UPDATED', 'people', $target['id']);
        self::assertCount(1, $audit);
        self::assertSame(['full_name', 'birth'], json_decode($audit[0]->after_metadata, true)['changed']);
        self::assertStringNotContainsString('Nome', $audit[0]->after_metadata . $audit[0]->before_metadata);
    }

    public function test_lifecycle_active_inactive_deceased_without_generic_status_endpoint(): void
    {
        $staff = $this->staff(self::ALL);
        $target = $this->personAt($staff['unit']);
        $p = 'people/' . $target['public_id'];
        $this->api($staff, 'POST', $p . '/inactivate', ['lock_version' => 0])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->api($staff, 'POST', $p . '/reactivate', ['lock_version' => 1])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->api($staff, 'POST', $p . '/mark-deceased', ['lock_version' => 2])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->api($staff, 'POST', $p . '/mark-deceased', ['lock_version' => 2, 'reason' => 'Certidão de óbito'])->assertOk()->assertJsonPath('data.status', 'DECEASED');
        $this->api($staff, 'POST', $p . '/reactivate', ['lock_version' => 3])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->api($staff, 'PATCH', $p, ['full_name' => 'Depois do óbito', 'lock_version' => 3])->assertStatus(409)->assertJsonPath('error.code', 'PERSON_DECEASED');
        $this->api($staff, 'PATCH', $p, ['status' => 'ACTIVE', 'lock_version' => 3])->assertStatus(422);
        $this->api($staff, 'POST', $p . '/status', ['status' => 'ACTIVE'])->assertStatus(404);
        self::assertSame(['ACTIVE', 'INACTIVE', 'DECEASED'], DB::table('person_statuses')->whereIn('code', ['ACTIVE', 'INACTIVE', 'DECEASED', 'MERGED'])->orderBy('id')->pluck('code')->all(), 'MERGED is not in the V1 catalog');
        self::assertCount(3, $this->audits('PERSON_STATUS_CHANGED', 'people', $target['id']));
        self::assertSame('Certidão de óbito', $this->audits('PERSON_STATUS_CHANGED', 'people', $target['id'])[2]->reason);
    }

    public function test_class_b_birth_requires_sensitive_view_and_minor_projection_is_minimal(): void
    {
        $unit = $this->unit();
        $adult = $this->personAt($unit, ['birth_precision' => 'EXACT', 'birth_date' => '1980-01-02']);
        $minor = $this->personAt($unit, ['birth_precision' => 'EXACT', 'birth_date' => now('Africa/Luanda')->subYears(10)->format('Y-m-d')]);
        $uncertain = $this->personAt($unit, ['birth_precision' => 'YEAR', 'birth_year' => (int) now('Africa/Luanda')->format('Y') - 18]);
        $viewer = $this->staff(['PEOPLE_VIEW'], $unit);
        $sensitive = $this->staff(['PEOPLE_VIEW', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_CONTACT_MANAGE', 'HOUSEHOLD_VIEW', 'RELATIONSHIP_MANAGE'], $unit);

        $hidden = $this->api($viewer, 'GET', 'people/' . $adult['public_id'])->assertOk()->json('data');
        self::assertArrayNotHasKey('birth', $hidden);
        self::assertContains('birth', $hidden['restricted']);
        self::assertContains('contacts', $hidden['restricted']);
        $shown = $this->api($sensitive, 'GET', 'people/' . $adult['public_id'])->json('data');
        self::assertSame(['precision' => 'EXACT', 'date' => '1980-01-02'], $shown['birth']);
        self::assertSame('ADULT', $shown['age_band']);
        self::assertSame('COMMON', $shown['projection']);

        foreach ([$minor, $uncertain] as $protected) {
            $data = $this->api($sensitive, 'GET', 'people/' . $protected['public_id'])->assertOk()->json('data');
            self::assertTrue($data['protected_minor']);
            self::assertSame('MINOR', $data['age_band']);
            self::assertSame('MINIMAL', $data['projection']);
            self::assertArrayNotHasKey('birth', $data);
            foreach (['birth', 'contacts', 'addresses', 'households', 'relationships'] as $area) {
                self::assertContains($area, $data['restricted']);
            }
            self::assertSame(['public_id', 'display_name', 'birth_precision', 'status', 'age_band', 'protected_minor', 'lock_version', 'projection', 'restricted', 'capabilities'], array_keys($data));
        }
    }

    public function test_academy_and_children_contexts_give_minimal_contextual_projection_only(): void
    {
        // P0.5-R1: an enrollment authorizes only while the Academy policy calls it operational (explicit test policy).
        $academy = (require base_path('config/academy_e2e.php'))['academy'];
        config(['academy' => $academy]);
        $unit = $this->unit();
        $academic = $this->row('academic_units', ['unit_id' => $unit]);
        $class = $this->row('classes', ['academic_unit_id' => $academic]);
        $student = $this->row('people', ['full_name' => 'Aluno Só Academia']);
        $this->row('enrollments', ['person_id' => $student, 'class_id' => $class, 'status' => $academy['states']['enrollments']['sets']['operational'][0]]);
        $publicStudent = (string) DB::table('people')->where('id', $student)->value('public_id');
        $staff = $this->staff(self::ALL, $unit);
        $detail = $this->api($staff, 'GET', 'people/' . $publicStudent)->assertOk()->json('data');
        self::assertSame('MINIMAL', $detail['projection']);
        self::assertFalse($detail['capabilities']['can_edit']);
        self::assertNotContains('Aluno Só Academia', array_column($this->api($staff, 'GET', 'people')->json('data'), 'display_name'), 'contextual people are not a People directory');
        $this->api($staff, 'PATCH', 'people/' . $publicStudent, ['full_name' => 'Xy', 'lock_version' => 0])->assertStatus(403);
    }

    public function test_membership_period_is_a_general_context_and_scope_descendants_follow_the_tree(): void
    {
        $root = $this->unit();
        $congregation = $this->unit($root);
        $person = $this->row('people', ['full_name' => 'Membro Por Período']);
        $membership = $this->row('memberships', ['person_id' => $person]);
        $this->row('membership_periods', ['membership_id' => $membership, 'congregation_id' => $congregation, 'starts_at' => now('UTC')->subDay()->format('Y-m-d H:i:s.u'), 'ends_at' => null]);
        $public = (string) DB::table('people')->where('id', $person)->value('public_id');
        $this->api($this->staff(['PEOPLE_VIEW'], $root, true), 'GET', 'people/' . $public)->assertOk()->assertJsonPath('data.projection', 'COMMON');
        $this->api($this->staff(['PEOPLE_VIEW'], $root, false), 'GET', 'people/' . $public)->assertStatus(404);
        DB::table('membership_periods')->where('membership_id', $membership)->update(['ends_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'starts_at' => now('UTC')->subDays(2)->format('Y-m-d H:i:s.u')]);
        $this->api($this->staff(['PEOPLE_VIEW'], $root, true), 'GET', 'people/' . $public)->assertStatus(404);
    }

    public function test_final_authorization_recheck_rolls_back_when_grant_lapses_inside_the_transaction(): void
    {
        $staff = $this->staff(self::ALL);
        $runtime = app(PeopleServiceFactory::class)->runtime();
        try {
            $runtime->write($staff['user'], $staff['session'], function ($guard, $actor) use ($staff, $runtime): void {
                $target = $this->personAt($staff['unit']);
                $guard->require(PeopleCatalog::PEOPLE_EDIT, $target['id']);
                DB::table('people')->where('id', $target['id'])->update(['full_name' => 'Nunca Confirmado']);
                // A concurrent revocation that is visible when the final locking check runs.
                DB::table('user_role_scopes')->where('id', $staff['link'])->update(['ends_at' => now('UTC')->subSecond()->format('Y-m-d H:i:s.u'), 'starts_at' => now('UTC')->subHours(2)->format('Y-m-d H:i:s.u')]);
            });
            self::fail('final recheck must reject');
        } catch (PeopleError $e) {
            self::assertContains($e->reason, [PeopleReason::NOT_AUTHORIZED, PeopleReason::OUT_OF_SCOPE]);
        }
        self::assertSame(0, DB::table('people')->where('full_name', 'Nunca Confirmado')->count());
    }

    public function test_crypto_is_not_required_for_person_core_and_service_is_container_built(): void
    {
        config(['people.keyring_path' => null]);
        $staff = $this->staff(self::ALL);
        $this->api($staff, 'POST', 'people', ['full_name' => 'Sem Chave', 'birth_precision' => 'UNKNOWN'])->assertCreated();
        self::assertInstanceOf(PersonService::class, app(PeopleServiceFactory::class)->make(PersonService::class));
    }
}
