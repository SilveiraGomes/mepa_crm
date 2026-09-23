<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/** P0.5-I blocks 4-5: households lifecycle and membership, reached only through active members. */
final class PeopleHouseholdTest extends PeopleHttpCase
{
    private const MANAGER = ['PEOPLE_VIEW', 'HOUSEHOLD_VIEW', 'HOUSEHOLD_MANAGE'];

    public function test_create_household_with_reference_person_and_add_two_people(): void
    {
        $staff = $this->staff(self::MANAGER);
        $a = $this->personAt($staff['unit'], ['full_name' => 'Referência Família']);
        $b = $this->personAt($staff['unit'], ['full_name' => 'Membro Família']);
        $c = $this->personAt($staff['unit'], ['full_name' => 'Dependente Família']);
        $created = $this->api($staff, 'POST', 'people/households', ['name' => 'Família Teste', 'reference_person' => $a['public_id']])->assertCreated();
        $household = $created->json('data');
        self::assertSame('ACTIVE', $household['status']);
        self::assertStringStartsWith('AGR-', $household['code']);
        self::assertSame(['REFERENCE_PERSON'], array_column($household['members'], 'role'));
        $this->assertNoInternalFields($created->json());
        $id = (int) DB::table('households')->where('public_id', $household['public_id'])->value('id');
        self::assertSame($staff['unit'], (int) $this->audits('HOUSEHOLD_CREATED', 'households', $id)[0]->unit_id, 'audit unit = context of the authorizing member');

        $this->api($staff, 'POST', 'people/households/' . $household['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'MEMBER'])->assertCreated();
        $detail = $this->api($staff, 'POST', 'people/households/' . $household['public_id'] . '/members', ['person' => $c['public_id'], 'role' => 'DEPENDENT'])->assertCreated()->json('data');
        self::assertSame(['REFERENCE_PERSON', 'MEMBER', 'DEPENDENT'], array_column($detail['members'], 'role'));
        $this->api($staff, 'POST', 'people/households/' . $household['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'MEMBER'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_MEMBER');
        $this->api($staff, 'POST', 'people/households/' . $household['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'HEAD'])->assertStatus(422);
        $this->api($staff, 'POST', 'people/households/' . $household['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'OWNER'])->assertStatus(422);
        self::assertSame([$household['public_id']], array_column($this->api($staff, 'GET', 'people/' . $b['public_id'] . '/households')->json('data'), 'public_id'));
        self::assertContains($household['public_id'], array_column($this->api($staff, 'GET', 'people/households?search=Família Teste')->json('data'), 'public_id'));
    }

    public function test_remove_member_is_logical_and_lifecycle_needs_reasons_without_delete(): void
    {
        $staff = $this->staff(self::MANAGER);
        $a = $this->personAt($staff['unit']);
        $b = $this->personAt($staff['unit']);
        $h = $this->api($staff, 'POST', 'people/households', ['reference_person' => $a['public_id']])->assertCreated()->json('data');
        $h = $this->api($staff, 'POST', 'people/households/' . $h['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'MEMBER'])->json('data');
        $ref = collect($h['members'])->firstWhere('person.public_id', $b['public_id'])['ref'];
        $this->api($staff, 'POST', 'people/households/' . $h['public_id'] . '/members/' . $ref . '/end', ['reason' => 'Saiu de casa'])->assertNoContent();
        $after = $this->api($staff, 'GET', 'people/households/' . $h['public_id'])->assertOk()->json('data');
        self::assertSame([$a['public_id']], array_column(array_column($after['members'], 'person'), 'public_id'));
        self::assertSame(2, DB::table('household_members')->where('household_id', DB::table('households')->where('public_id', $h['public_id'])->value('id'))->count());

        $p = 'people/households/' . $h['public_id'];
        $this->api($staff, 'PATCH', $p, ['name' => 'Nome Novo', 'lock_version' => 5])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $this->api($staff, 'PATCH', $p, ['name' => 'Nome Novo', 'lock_version' => 0])->assertOk()->assertJsonPath('data.name', 'Nome Novo');
        $this->api($staff, 'POST', $p . '/inactivate', ['lock_version' => 1])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->api($staff, 'POST', $p . '/members', ['person' => $b['public_id'], 'role' => 'MEMBER'])->assertStatus(409)->assertJsonPath('error.code', 'HOUSEHOLD_NOT_ACTIVE');
        $this->api($staff, 'POST', $p . '/archive', ['lock_version' => 2])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->api($staff, 'POST', $p . '/archive', ['lock_version' => 2, 'reason' => 'Agregado dissolvido'])->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->api($staff, 'POST', $p . '/reactivate', ['lock_version' => 3])->assertStatus(409);
        $this->api($staff, 'POST', $p . '/restore', ['lock_version' => 3, 'reason' => 'Reaberto'])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->api($staff, 'DELETE', $p)->assertStatus(405);
        self::assertSame(1, DB::table('households')->where('public_id', $h['public_id'])->count());
        self::assertFalse(DB::getSchemaBuilder()->hasColumn('households', 'unit_id'));
    }

    public function test_household_is_concealed_outside_scope_and_mixed_members_are_hidden(): void
    {
        $unitA = $this->unit();
        $unitB = $this->unit();
        $managerA = $this->staff(self::MANAGER, $unitA);
        $managerB = $this->staff(self::MANAGER, $unitB);
        $crossAdmin = $this->staff(self::MANAGER, $unitA);
        DB::table('user_role_scopes')->insert(['user_id' => $crossAdmin['user'], 'role_id' => $crossAdmin['role'], 'scope_id' => $this->row('scopes', ['unit_id' => $unitB, 'include_descendants' => 0, 'scope_kind' => 'UNIT', 'department_instance_id' => null]), 'granted_by' => $crossAdmin['user'], 'status' => 'SYNTHETIC_READY', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null, 'reason' => null, 'source_document_id' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        $a = $this->personAt($unitA, ['full_name' => 'Pessoa Unidade A']);
        $b = $this->personAt($unitB, ['full_name' => 'Pessoa Unidade B']);
        $h = $this->api($managerA, 'POST', 'people/households', ['reference_person' => $a['public_id']])->assertCreated()->json('data');
        // Adding a Person of unit B needs authority over that Person too.
        $this->api($managerA, 'POST', 'people/households/' . $h['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'MEMBER'])->assertStatus(404)->assertExactJson($this->notFoundBody());
        $this->api($crossAdmin, 'POST', 'people/households/' . $h['public_id'] . '/members', ['person' => $b['public_id'], 'role' => 'MEMBER'])->assertCreated();
        $member = DB::table('audit_logs')->where('action', 'HOUSEHOLD_MEMBER_ADDED')->orderByDesc('id')->first();
        self::assertSame($unitA, (int) $member->unit_id);
        self::assertSame($unitB, json_decode($member->after_metadata, true)['person_context_unit']);

        $viewA = $this->api($managerA, 'GET', 'people/households/' . $h['public_id'])->assertOk()->json('data');
        self::assertSame(['Pessoa Unidade A'], array_column(array_column($viewA['members'], 'person'), 'display_name'), 'members outside the scope are not revealed');
        $viewB = $this->api($managerB, 'GET', 'people/households/' . $h['public_id'])->assertOk()->json('data');
        self::assertSame(['Pessoa Unidade B'], array_column(array_column($viewB['members'], 'person'), 'display_name'));

        $outsider = $this->staff(self::MANAGER);
        $missing = (string) \Illuminate\Support\Str::ulid();
        foreach ([['GET', 'people/households/' . $h['public_id'], []], ['GET', 'people/households/' . $missing, []], ['PATCH', 'people/households/' . $h['public_id'], ['name' => 'X', 'lock_version' => 0]], ['POST', 'people/households/' . $h['public_id'] . '/archive', ['lock_version' => 0, 'reason' => 'Tentativa']]] as [$method, $uri, $body]) {
            $this->api($outsider, $method, $uri, $body)->assertStatus(404)->assertExactJson($this->notFoundBody());
        }
        self::assertNotContains($h['public_id'], array_column($this->api($outsider, 'GET', 'people/households')->json('data'), 'public_id'));
        $viewer = $this->staff(['PEOPLE_VIEW', 'HOUSEHOLD_VIEW'], $unitA);
        $this->api($viewer, 'PATCH', 'people/households/' . $h['public_id'], ['name' => 'Sem Permissão', 'lock_version' => 0])->assertStatus(403);
    }

    public function test_household_without_active_members_leaves_operational_listings(): void
    {
        $staff = $this->staff(self::MANAGER);
        $a = $this->personAt($staff['unit']);
        $h = $this->api($staff, 'POST', 'people/households', ['name' => 'Agregado Órfão', 'reference_person' => $a['public_id']])->assertCreated()->json('data');
        $this->api($staff, 'POST', 'people/households/' . $h['public_id'] . '/members/' . $h['members'][0]['ref'] . '/end', [])->assertNoContent();
        $this->api($staff, 'GET', 'people/households/' . $h['public_id'])->assertStatus(404);
        self::assertNotContains($h['public_id'], array_column($this->api($staff, 'GET', 'people/households?search=Órfão')->json('data'), 'public_id'));
        self::assertSame(1, DB::table('households')->where('public_id', $h['public_id'])->count(), 'preserved, not deleted');
    }

    public function test_minor_household_membership_is_hidden_from_person_view(): void
    {
        $staff = $this->staff(self::MANAGER + [3 => 'PEOPLE_SENSITIVE_VIEW']);
        $adult = $this->personAt($staff['unit']);
        $minor = $this->personAt($staff['unit'], ['birth_precision' => 'YEAR', 'birth_year' => (int) now('Africa/Luanda')->format('Y') - 6]);
        $h = $this->api($staff, 'POST', 'people/households', ['reference_person' => $adult['public_id']])->json('data');
        $detail = $this->api($staff, 'POST', 'people/households/' . $h['public_id'] . '/members', ['person' => $minor['public_id'], 'role' => 'DEPENDENT'])->assertCreated()->json('data');
        $row = collect($detail['members'])->firstWhere('person.public_id', $minor['public_id']);
        self::assertSame(['public_id', 'display_name', 'age_band', 'protected_minor'], array_keys($row['person']));
        self::assertTrue($row['person']['protected_minor']);
        $this->api($staff, 'GET', 'people/' . $minor['public_id'] . '/households')->assertStatus(403)->assertJsonPath('error.code', 'MINOR_PROTECTED');
    }
}
