<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\People\PeopleCatalog;
use App\Domain\People\PeopleError;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/** P0.5-I block 6: SYMMETRIC / INVERSE_PAIRED relationships, atomicity, scope over both People, GUARDIAN factual only. */
final class PeopleRelationshipTest extends PeopleHttpCase
{
    private const MANAGER = ['PEOPLE_VIEW', 'PEOPLE_SENSITIVE_VIEW', 'RELATIONSHIP_MANAGE'];

    private function rows(int $a, int $b): array
    {
        return DB::table('person_relationships as pr')->join('relationship_types as rt', 'rt.id', '=', 'pr.relationship_type_id')
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('subject_person_id', $a)->where('related_person_id', $b))->orWhere(fn ($x) => $x->where('subject_person_id', $b)->where('related_person_id', $a)))
            ->orderBy('pr.id')->get(['pr.subject_person_id', 'pr.related_person_id', 'rt.code', 'pr.status'])->map(fn ($r) => [(int) $r->subject_person_id, (int) $r->related_person_id, $r->code, $r->status])->all();
    }

    public function test_catalog_semantics_and_inverse_pairing_are_closed(): void
    {
        $types = DB::table('relationship_types')->whereIn('code', array_keys(PeopleCatalog::RELATIONSHIPS))->get()->keyBy('code');
        self::assertCount(6, $types);
        foreach (PeopleCatalog::RELATIONSHIPS as $code => [, $semantics, $inverse]) {
            self::assertSame($semantics, $types[$code]->semantics);
            self::assertSame((int) $types[$inverse]->id, (int) $types[$code]->inverse_relationship_type_id);
            self::assertSame(1, (int) $types[$code]->is_active);
        }
        try {
            DB::table('relationship_types')->insert(['code' => 'DIRECTED_X', 'name' => 'X', 'semantics' => 'DIRECTED', 'is_active' => 0, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
            self::fail('DIRECTED semantics accepted');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame(3819, (int) $e->errorInfo[1]);
        }
        $broken = DB::table('relationship_types')->insertGetId(['code' => 'BROKEN_PAIR', 'name' => 'Quebrado', 'semantics' => 'INVERSE_PAIRED', 'inverse_relationship_type_id' => $types['SPOUSE']->id, 'is_active' => 0, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        $this->expectException(PeopleError::class);
        PeopleCatalog::assertPairing(DB::connection(), (int) $broken);
    }

    public function test_parent_produces_child_atomically_and_end_closes_both(): void
    {
        $staff = $this->staff(self::MANAGER);
        $parent = $this->personAt($staff['unit'], ['full_name' => 'Mãe Teste']);
        $child = $this->personAt($staff['unit'], ['full_name' => 'Filho Teste']);
        $list = $this->api($staff, 'POST', 'people/' . $parent['public_id'] . '/relationships', ['related_person' => $child['public_id'], 'type' => 'PARENT'])->assertCreated()->json('data');
        self::assertSame([[$parent['id'], $child['id'], 'PARENT', 'ACTIVE'], [$child['id'], $parent['id'], 'CHILD', 'ACTIVE']], $this->rows($parent['id'], $child['id']));
        self::assertSame(['PARENT'], array_column($list, 'type'));
        $fromChild = $this->api($staff, 'GET', 'people/' . $child['public_id'] . '/relationships')->assertOk()->json('data');
        self::assertSame(['CHILD'], array_column($fromChild, 'type'));
        self::assertSame($parent['public_id'], $fromChild[0]['person']['public_id']);
        $this->assertNoInternalFields(['data' => $fromChild]);

        $this->api($staff, 'POST', 'people/' . $parent['public_id'] . '/relationships', ['related_person' => $child['public_id'], 'type' => 'PARENT'])->assertStatus(409)->assertJsonPath('error.code', 'RELATIONSHIP_EXISTS');
        $this->api($staff, 'POST', 'people/' . $child['public_id'] . '/relationships', ['related_person' => $parent['public_id'], 'type' => 'CHILD'])->assertStatus(409)->assertJsonPath('error.code', 'RELATIONSHIP_EXISTS');
        $this->api($staff, 'POST', 'people/' . $child['public_id'] . '/relationships', ['related_person' => $parent['public_id'], 'type' => 'PARENT'])->assertStatus(409)->assertJsonPath('error.code', 'RELATIONSHIP_CONFLICT');
        $this->api($staff, 'POST', 'people/' . $child['public_id'] . '/relationships', ['related_person' => $child['public_id'], 'type' => 'SIBLING'])->assertStatus(422);

        $this->api($staff, 'POST', 'people/' . $child['public_id'] . '/relationships/' . $fromChild[0]['ref'] . '/end', ['reason' => 'Registo corrigido'])->assertNoContent();
        self::assertSame([[$parent['id'], $child['id'], 'PARENT', 'INACTIVE'], [$child['id'], $parent['id'], 'CHILD', 'INACTIVE']], $this->rows($parent['id'], $child['id']));
        self::assertSame([], $this->api($staff, 'GET', 'people/' . $parent['public_id'] . '/relationships')->json('data'));
        self::assertCount(1, $this->api($staff, 'GET', 'people/' . $parent['public_id'] . '/relationships?include_history=1')->json('data'));
    }

    public function test_spouse_is_symmetric_and_never_duplicated(): void
    {
        $staff = $this->staff(self::MANAGER);
        $a = $this->personAt($staff['unit']);
        $b = $this->personAt($staff['unit']);
        $this->api($staff, 'POST', 'people/' . $b['public_id'] . '/relationships', ['related_person' => $a['public_id'], 'type' => 'SPOUSE'])->assertCreated();
        self::assertSame([[min($a['id'], $b['id']), max($a['id'], $b['id']), 'SPOUSE', 'ACTIVE']], $this->rows($a['id'], $b['id']), 'one canonical row');
        $this->api($staff, 'POST', 'people/' . $a['public_id'] . '/relationships', ['related_person' => $b['public_id'], 'type' => 'SPOUSE'])->assertStatus(409)->assertJsonPath('error.code', 'RELATIONSHIP_EXISTS');
        self::assertSame(['SPOUSE'], array_column($this->api($staff, 'GET', 'people/' . $a['public_id'] . '/relationships')->json('data'), 'type'));
        self::assertSame(['SPOUSE'], array_column($this->api($staff, 'GET', 'people/' . $b['public_id'] . '/relationships')->json('data'), 'type'));
        $ref = $this->api($staff, 'GET', 'people/' . $a['public_id'] . '/relationships')->json('data.0.ref');
        $this->api($staff, 'POST', 'people/' . $a['public_id'] . '/relationships/' . $ref . '/end')->assertNoContent();
        $this->api($staff, 'POST', 'people/' . $a['public_id'] . '/relationships', ['related_person' => $b['public_id'], 'type' => 'SPOUSE'])->assertCreated();
        self::assertCount(2, $this->rows($a['id'], $b['id']), 'ended history preserved, new fact recorded');
    }

    public function test_guardian_is_factual_and_grants_no_children_authority(): void
    {
        $staff = $this->staff(self::MANAGER);
        $guardian = $this->personAt($staff['unit']);
        $dependent = $this->personAt($staff['unit'], ['birth_precision' => 'EXACT', 'birth_date' => now('Africa/Luanda')->subYears(8)->format('Y-m-d')]);
        $authorizations = DB::table('guardian_authorizations')->count();
        $consents = DB::table('person_consents')->count();
        $this->api($staff, 'POST', 'people/' . $guardian['public_id'] . '/relationships', ['related_person' => $dependent['public_id'], 'type' => 'GUARDIAN'])->assertCreated();
        self::assertSame([[$guardian['id'], $dependent['id'], 'GUARDIAN', 'ACTIVE'], [$dependent['id'], $guardian['id'], 'DEPENDENT', 'ACTIVE']], $this->rows($guardian['id'], $dependent['id']));
        self::assertSame($authorizations, DB::table('guardian_authorizations')->count());
        self::assertSame($consents, DB::table('person_consents')->count());
        self::assertSame(0, DB::table('child_profiles')->where('person_id', $dependent['id'])->count());
        self::assertFalse(json_decode(DB::table('audit_logs')->where('action', 'RELATIONSHIP_CREATED')->orderByDesc('id')->value('after_metadata'), true)['children_authority_granted']);
        // The minor's own relationship list stays hidden.
        $this->api($staff, 'GET', 'people/' . $dependent['public_id'] . '/relationships')->assertStatus(403)->assertJsonPath('error.code', 'MINOR_PROTECTED');
        $seen = $this->api($staff, 'GET', 'people/' . $guardian['public_id'] . '/relationships')->json('data.0.person');
        self::assertSame(['public_id', 'display_name', 'age_band'], array_keys($seen));
    }

    public function test_authority_over_both_people_and_concealment(): void
    {
        $unitA = $this->unit();
        $unitB = $this->unit();
        $staff = $this->staff(self::MANAGER, $unitA);
        $a = $this->personAt($unitA);
        $b = $this->personAt($unitB);
        $this->api($staff, 'POST', 'people/' . $a['public_id'] . '/relationships', ['related_person' => $b['public_id'], 'type' => 'SIBLING'])->assertStatus(404)->assertExactJson($this->notFoundBody());
        $this->api($staff, 'POST', 'people/' . $b['public_id'] . '/relationships', ['related_person' => $a['public_id'], 'type' => 'SIBLING'])->assertStatus(404)->assertExactJson($this->notFoundBody());
        self::assertSame([], $this->rows($a['id'], $b['id']));
        $viewer = $this->staff(['PEOPLE_VIEW'], $unitA);
        $this->api($viewer, 'GET', 'people/' . $a['public_id'] . '/relationships')->assertStatus(403)->assertJsonPath('error.code', 'SENSITIVE_DATA_RESTRICTED');
        $a2 = $this->personAt($unitA);
        $this->api($viewer, 'POST', 'people/' . $a['public_id'] . '/relationships', ['related_person' => $a2['public_id'], 'type' => 'SIBLING'])->assertStatus(403);
        $this->api($staff, 'POST', 'people/' . $a['public_id'] . '/relationships', ['related_person' => $a2['public_id'], 'type' => 'COUSIN'])->assertStatus(422);
        $this->api($staff, 'POST', 'people/' . $a['public_id'] . '/relationships/' . str_repeat('a', 32) . '/end')->assertStatus(404)->assertExactJson($this->notFoundBody());
    }
}
