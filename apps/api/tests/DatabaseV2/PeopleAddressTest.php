<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/** P0.5-I block 3: addresses CRUD, encrypted line without blind index, territorial references, history preservation. */
final class PeopleAddressTest extends PeopleHttpCase
{
    private const MANAGER = ['PEOPLE_VIEW', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_ADDRESS_MANAGE'];

    private function territory(): array
    {
        $type = $this->row('territorial_area_types');
        $province = $this->row('territorial_areas', ['area_type_id' => $type, 'parent_id' => null, 'code' => 'T-PROV-' . bin2hex(random_bytes(3)), 'name' => 'Província Teste']);
        $municipality = $this->row('territorial_areas', ['area_type_id' => $type, 'parent_id' => $province, 'code' => 'T-MUN-' . bin2hex(random_bytes(3)), 'name' => 'Município Teste']);
        $foreign = $this->row('territorial_areas', ['area_type_id' => $type, 'parent_id' => null, 'code' => 'T-OUT-' . bin2hex(random_bytes(3)), 'name' => 'Outra']);
        return array_map(fn (int $id) => (string) DB::table('territorial_areas')->where('id', $id)->value('code'), compact('province', 'municipality', 'foreign'));
    }

    public function test_address_line_is_encrypted_without_blind_index_and_territory_is_referenced(): void
    {
        $staff = $this->staff(self::MANAGER);
        $person = $this->personAt($staff['unit']);
        $t = $this->territory();
        $created = $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/addresses', ['line1' => 'Rua da Missão, 12', 'locality' => 'Bairro Azul', 'province' => $t['province'], 'municipality' => $t['municipality']])->assertCreated();
        $item = $created->json('data');
        self::assertSame('Rua da Missão, 12', $item['line1']);
        self::assertSame('AGO', $item['country_code']);
        self::assertSame($t['municipality'], $item['municipality']['code']);
        $this->assertNoInternalFields($created->json());
        $address = DB::table('addresses')->orderByDesc('id')->first();
        self::assertStringNotContainsString('Missão', $address->line1_ciphertext);
        self::assertSame(1, (int) $address->key_version);
        self::assertFalse(DB::getSchemaBuilder()->hasColumn('addresses', 'line1_blind_index'), 'no blind index for the address line in V1');
        self::assertFalse(DB::getSchemaBuilder()->hasColumn('households', 'unit_id'));
        self::assertFalse(DB::getSchemaBuilder()->hasColumn('people', 'unit_id'));
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/addresses', ['line1' => 'Rua Errada', 'province' => $t['foreign'], 'municipality' => $t['municipality']])->assertStatus(422);
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/addresses', ['line1' => 'Rua Inventada', 'province' => 'NAO-EXISTE'])->assertStatus(422);
        $this->api($staff, 'POST', 'people/' . $person['public_id'] . '/addresses', ['line1' => 'Rua', 'province_id' => 1])->assertStatus(422);
    }

    public function test_update_preserves_history_and_end_is_logical(): void
    {
        $staff = $this->staff(self::MANAGER);
        $person = $this->personAt($staff['unit']);
        $base = 'people/' . $person['public_id'] . '/addresses';
        $first = $this->api($staff, 'POST', $base, ['line1' => 'Rua Antiga, 1'])->assertCreated()->json('data');
        $this->api($staff, 'PATCH', $base . '/' . $first['ref'], ['line1' => 'Rua Nova, 2', 'lock_version' => 9])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $second = $this->api($staff, 'PATCH', $base . '/' . $first['ref'], ['line1' => 'Rua Nova, 2', 'lock_version' => $first['lock_version']])->assertOk()->json('data');
        self::assertSame('Rua Nova, 2', $second['line1']);
        self::assertNotSame($first['ref'], $second['ref']);
        $current = $this->api($staff, 'GET', $base)->json('data');
        self::assertSame(['Rua Nova, 2'], array_column($current, 'line1'));
        $history = $this->api($staff, 'GET', $base . '?include_history=1')->json('data');
        self::assertEqualsCanonicalizing(['Rua Nova, 2', 'Rua Antiga, 1'], array_column($history, 'line1'));
        $old = collect($history)->firstWhere('line1', 'Rua Antiga, 1');
        self::assertSame('INACTIVE', $old['status']);
        self::assertNotNull($old['ends_at']);
        $this->api($staff, 'POST', $base . '/' . $second['ref'] . '/end', ['reason' => 'Mudou-se'])->assertNoContent();
        self::assertSame([], $this->api($staff, 'GET', $base)->json('data'));
        self::assertSame(2, DB::table('person_addresses')->where('person_id', $person['id'])->count());
        self::assertSame(2, DB::table('person_addresses')->where('person_id', $person['id'])->where('status', 'INACTIVE')->count());
        $links = DB::table('person_addresses')->where('person_id', $person['id'])->pluck('id')->all();
        self::assertCount(1, DB::table('audit_logs')->where('action', 'ADDRESS_UPDATED')->whereIn('entity_id', $links)->get());
        $audits = DB::table('audit_logs')->whereIn('action', ['ADDRESS_CREATED', 'ADDRESS_UPDATED', 'ADDRESS_ENDED'])->where('entity_type', 'person_addresses')->whereIn('entity_id', $links)->get();
        self::assertCount(3, $audits);
        foreach ($audits as $audit) {
            self::assertStringNotContainsString('Rua', (string) $audit->after_metadata . $audit->before_metadata);
            self::assertSame($staff['unit'], (int) $audit->unit_id);
        }
    }

    public function test_addresses_require_permission_scope_and_are_hidden_for_minors(): void
    {
        $unit = $this->unit();
        $person = $this->personAt($unit);
        $manager = $this->staff(self::MANAGER, $unit);
        $ref = $this->api($manager, 'POST', 'people/' . $person['public_id'] . '/addresses', ['line1' => 'Rua Privada, 3'])->assertCreated()->json('data.ref');
        $viewer = $this->staff(['PEOPLE_VIEW'], $unit);
        $this->api($viewer, 'GET', 'people/' . $person['public_id'] . '/addresses')->assertStatus(403)->assertJsonPath('error.code', 'SENSITIVE_DATA_RESTRICTED');
        $outsider = $this->staff(self::MANAGER);
        $this->api($outsider, 'GET', 'people/' . $person['public_id'] . '/addresses')->assertStatus(404)->assertExactJson($this->notFoundBody());
        $this->api($outsider, 'POST', 'people/' . $person['public_id'] . '/addresses/' . $ref . '/end')->assertStatus(404)->assertExactJson($this->notFoundBody());
        $minor = $this->personAt($unit, ['birth_precision' => 'MONTH', 'birth_year' => (int) now('Africa/Luanda')->format('Y') - 5, 'birth_month' => 1]);
        $this->api($manager, 'GET', 'people/' . $minor['public_id'] . '/addresses')->assertStatus(403)->assertJsonPath('error.code', 'MINOR_PROTECTED');
        $this->api($manager, 'POST', 'people/' . $minor['public_id'] . '/addresses', ['line1' => 'Rua do Menor'])->assertStatus(403);
    }
}
