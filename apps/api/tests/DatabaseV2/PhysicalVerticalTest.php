<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\People\PeopleCrypto;
use App\Domain\People\PeopleError;
use App\Domain\Physical\LocationService;
use App\Domain\Physical\PhysicalCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\PhysicalHttpCase;

/**
 * P0.7-I Physical Locations / Properties / Temples / Unit <-> Location links (ADR 0018), HTTP level against an isolated
 * Wave 5 pool. Each test builds its own world so any test can run alone (mutation probes run the whole file).
 */
final class PhysicalVerticalTest extends PhysicalHttpCase
{
    // ---- L01 ---------------------------------------------------------------------------------------------------

    public function test_l01_address_location_and_first_link_are_created_atomically(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id'], false);
        $before = $this->counts();
        $created = $this->location($manager, $w['a'], ['name' => 'Sede do Centro A']);
        $this->assertSame('DRAFT', $created['data']['status']);
        $this->assertSame('PRIVATE', $created['data']['public_visibility']);
        $this->assertSame(['public_id' => $w['a']['public_id'], 'name' => 'Centro A', 'is_primary' => true], $created['data']['unit']);
        $after = $this->counts();
        $this->assertSame($before['addresses'] + 1, $after['addresses']);
        $this->assertSame($before['physical_locations'] + 1, $after['physical_locations']);
        $this->assertSame($before['unit_location_links'] + 1, $after['unit_location_links']);
        $link = DB::table('unit_location_links')->where('location_id', $created['id'])->first();
        $this->assertSame('ACTIVE', $link->status);
        $this->assertSame(1, (int) $link->is_primary);
        $this->assertNull($link->source_document_id);
        $this->assertNull($link->ends_at);
        $this->assertSame($w['a']['id'], (int) $link->unit_id);
        $audit = DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->whereIn('action', ['PHYSICAL_LOCATION_CREATE', 'UNIT_LOCATION_LINK_CREATE'])->orderBy('id', 'desc')->limit(2)->get();
        $this->assertCount(2, $audit);
        $this->assertSame($audit[0]->correlation_id, $audit[1]->correlation_id, 'address, location and first link share one transaction');
        foreach ($audit as $row) {
            $this->assertSame($w['a']['id'], (int) $row->unit_id);
        }

        // First link fails AFTER address + location rows were written -> everything rolls back.
        $snapshot = $this->counts();
        $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a'], ['occupation_type_code' => 'OTHER']))
            ->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->assertSame($snapshot, $this->counts());
        $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a'], ['occupation_type_code' => 'NOT_A_TYPE']))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame($snapshot, $this->counts());
        // OTHER with a reason is accepted.
        $other = $this->location($manager, $w['a'], ['occupation_type_code' => 'OTHER', 'reason' => 'Uso partilhado aprovado pela direcção']);
        $this->assertSame('OTHER', $this->links($manager, $other['public_id'])[0]['occupation_type']['code']);
        // A location cannot be created for a unit outside the actor's scope (concealed) nor for a CLOSED unit.
        $this->assertConcealed($this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['b'])));
        $closed = $this->unit('CENTER', $w['m']['id'], 'CLOSED', 'Centro fechado');
        $national = $this->staff($this->physicalPermissions(), $w['g']['id']);
        $this->api($national, 'POST', 'physical/locations', $this->locationBody($closed))->assertStatus(409)->assertJsonPath('error.code', 'UNIT_NOT_OPERATIONAL');
    }

    // ---- L02 ---------------------------------------------------------------------------------------------------

    public function test_l02_location_without_active_vigente_link_is_invisible(): void
    {
        $w = $this->world();
        $national = $this->staff($this->physicalPermissions(), $w['g']['id']);
        $visible = $this->location($national, $w['a'], ['name' => 'Visível L02 ' . Str::random(4)]);
        // Raw rows without an authorizing link: none, ended, future and expired.
        $none = $this->rawLocation('Sem vínculo L02');
        $ended = $this->rawLocation('Vínculo terminado L02');
        $this->rawLink($w['a']['id'], $ended['id'], ['status' => 'ENDED', 'starts_at' => now('UTC')->subDays(3)->format('Y-m-d H:i:s.u'), 'ends_at' => now('UTC')->subDay()->format('Y-m-d H:i:s.u')]);
        $future = $this->rawLocation('Vínculo futuro L02');
        $this->rawLink($w['a']['id'], $future['id'], ['starts_at' => now('UTC')->addDay()->format('Y-m-d H:i:s.u')]);
        $expired = $this->rawLocation('Vínculo expirado L02');
        $this->rawLink($w['a']['id'], $expired['id'], ['starts_at' => now('UTC')->subDays(3)->format('Y-m-d H:i:s.u'), 'ends_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u')]);

        $list = $this->api($national, 'GET', 'physical/locations?per_page=100')->assertOk()->json('data');
        $names = array_column($list, 'name');
        $this->assertContains($visible['data']['name'], $names);
        $search = $this->api($national, 'GET', 'physical/locations?search=L02')->assertOk()->json('data');
        $this->assertSame([$visible['data']['name']], array_column($search, 'name'));
        foreach ([$none, $ended, $future, $expired] as $hidden) {
            $this->assertNotContains($hidden['name'], $names);
            $this->assertConcealed($this->api($national, 'GET', 'physical/locations/' . $hidden['public_id']));
            $this->assertConcealed($this->api($national, 'GET', 'physical/locations/' . $hidden['public_id'] . '/links'));
            $this->assertConcealed($this->api($national, 'POST', 'physical/properties', ['location_public_id' => $hidden['public_id'], 'code' => 'P02-' . Str::random(6)]));
            $this->assertConcealed($this->api($national, 'POST', 'physical/temples', ['location_public_id' => $hidden['public_id'], 'name' => 'Templo órfão']));
            $this->assertConcealed($this->api($national, 'POST', 'physical/locations/' . $hidden['public_id'] . '/links', ['unit_public_id' => $w['a']['public_id'], 'occupation_type_code' => 'OWNED']));
        }
    }

    // ---- L03 ---------------------------------------------------------------------------------------------------

    public function test_l03_property_is_created_on_a_linked_location(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        $code = 'IMV-' . Str::upper(Str::random(8));
        $property = $this->api($manager, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => $code])->assertCreated()->json('data');
        $this->assertSame('UNKNOWN', $property['ownership_status']);
        $this->assertSame('DRAFT', $property['status']);
        $this->assertSame(['kind' => 'NONE', 'person' => null], $property['owner']);
        $this->assertSame($loc['public_id'], $property['location']['public_id']);
        $this->assertNoInternalIds($property);
        $listed = $this->api($manager, 'GET', 'physical/properties?location_public_id=' . $loc['public_id'])->assertOk()->json('data');
        $this->assertSame([$code], array_column($listed, 'code'));
        $this->api($manager, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => $code])->assertStatus(409)->assertJsonPath('error.code', 'CODE_EXISTS');
        // ownership_status is documentary: explicit, versioned, audited action; it never changes authority.
        $changed = $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/ownership-status', ['ownership_status' => 'IN_REGULARIZATION', 'reason' => 'Processo iniciado', 'lock_version' => $property['lock_version']])->assertOk()->json('data');
        $this->assertSame('IN_REGULARIZATION', $changed['ownership_status']);
        $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/ownership-status', ['ownership_status' => 'REGISTERED', 'reason' => 'Versão antiga', 'lock_version' => $property['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/ownership-status', ['ownership_status' => 'OWNED_BY_CHURCH', 'reason' => 'Fora do catálogo', 'lock_version' => $changed['lock_version']])->assertStatus(422);
        $this->api($manager, 'PATCH', 'physical/properties/' . $property['public_id'], ['ownership_status' => 'REGISTERED', 'lock_version' => $changed['lock_version']])->assertStatus(422);
        $this->assertSame(1, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'PROPERTY_OWNERSHIP_STATUS')->where('unit_id', $w['a']['id'])->count());
        // Lifecycle: activation requires an ACTIVE location.
        $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/activate', ['lock_version' => $changed['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'LOCATION_NOT_ACTIVE');
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => $loc['data']['lock_version']])->assertOk();
        $active = $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/activate', ['lock_version' => $changed['lock_version']])->assertOk()->json('data');
        $this->assertSame('ACTIVE', $active['status']);
    }

    // ---- L04 ---------------------------------------------------------------------------------------------------

    public function test_l04_two_temples_on_one_location_create_no_congregation(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        $unitsBefore = DB::table('organizational_units')->count();
        $parentsBefore = DB::table('unit_parent_periods')->count();
        $t1 = $this->api($manager, 'POST', 'physical/temples', ['location_public_id' => $loc['public_id'], 'name' => 'Templo Principal', 'capacity' => 350])->assertCreated()->json('data');
        $t2 = $this->api($manager, 'POST', 'physical/temples', ['location_public_id' => $loc['public_id'], 'name' => 'Templo Anexo', 'capacity' => 80])->assertCreated()->json('data');
        $this->assertSame($unitsBefore, DB::table('organizational_units')->count(), 'a temple never creates an organizational unit');
        $this->assertSame($parentsBefore, DB::table('unit_parent_periods')->count());
        $this->assertSame(2, DB::table('temples')->where('location_id', $loc['id'])->count());
        foreach ([$t1, $t2] as $temple) {
            $this->assertSame($loc['public_id'], $temple['location']['public_id']);
            $this->assertArrayNotHasKey('unit', $temple);
            $this->assertArrayNotHasKey('parent_public_id', $temple);
            $this->assertNoInternalIds($temple);
        }
        $columns = array_map(fn ($c) => $c->c, DB::select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'temples'"));
        $this->assertEqualsCanonicalizing(['id', 'public_id', 'location_id', 'name', 'capacity', 'status', 'created_at', 'lock_version'], $columns);
        // The Territorial tree is unchanged: no Congregation below the center.
        $congregations = DB::table('organizational_units as ou')->join('organizational_unit_types as t', 't.id', '=', 'ou.unit_type_id')->where('t.code', 'CONGREGATION')->where('ou.parent_id', $w['a']['id'])->count();
        $this->assertSame(1, $congregations);
        $list = $this->api($manager, 'GET', 'physical/temples?location_public_id=' . $loc['public_id'])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Templo Principal', 'Templo Anexo'], array_column($list, 'name'));
        $updated = $this->api($manager, 'PATCH', 'physical/temples/' . $t1['public_id'], ['capacity' => 400, 'lock_version' => $t1['lock_version']])->assertOk()->json('data');
        $this->assertSame(400, $updated['capacity']);
        $this->api($manager, 'PATCH', 'physical/temples/' . $t1['public_id'], ['status' => 'ACTIVE', 'lock_version' => $updated['lock_version']])->assertStatus(422);
        $this->api($manager, 'POST', 'physical/temples/' . $t2['public_id'] . '/close', ['lock_version' => $t2['lock_version']])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->api($manager, 'POST', 'physical/temples/' . $t2['public_id'] . '/close', ['lock_version' => $t2['lock_version'], 'reason' => 'Anexo desactivado'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertSame(2, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'TEMPLE_CREATE')->whereIn('entity_id', DB::table('temples')->where('location_id', $loc['id'])->pluck('id'))->count());
    }

    // ---- L05 + F-06 + IDOR -------------------------------------------------------------------------------------

    public function test_l05_f06_wrong_scope_unknown_malformed_and_unlinked_targets_are_indistinguishable(): void
    {
        $w = $this->world();
        $ownerA = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($ownerA, $w['a']);
        $property = $this->api($ownerA, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'F06-' . Str::random(8)])->assertCreated()->json('data');
        $temple = $this->api($ownerA, 'POST', 'physical/temples', ['location_public_id' => $loc['public_id'], 'name' => 'Templo F06'])->assertCreated()->json('data');
        $link = $this->links($ownerA, $loc['public_id'])[0];
        $unlinked = $this->rawLocation('F06 sem vínculo');
        $unlinkedProperty = (string) DB::table('properties')->where('id', $this->row('properties', ['location_id' => $unlinked['id'], 'ownership_status' => 'UNKNOWN', 'status' => 'DRAFT']))->value('public_id');
        $unlinkedTemple = (string) DB::table('temples')->where('id', $this->row('temples', ['location_id' => $unlinked['id'], 'status' => 'DRAFT']))->value('public_id');
        $sibling = $this->staff($this->physicalPermissions(), $w['b']['id']);
        $unknown = (string) Str::ulid();
        $targets = [
            'location' => ['physical/locations/%s', [$loc['public_id'], $unknown, 'not-a-ulid', $unlinked['public_id']]],
            'location links' => ['physical/locations/%s/links', [$loc['public_id'], $unknown, 'not-a-ulid', $unlinked['public_id']]],
            'property' => ['physical/properties/%s', [$property['public_id'], $unknown, '0000', $unlinkedProperty]],
            'temple' => ['physical/temples/%s', [$temple['public_id'], $unknown, 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ', $unlinkedTemple]],
        ];
        foreach ($targets as $label => [$pattern, $ids]) {
            $bodies = [];
            foreach ($ids as $id) {
                $response = $this->api($sibling, 'GET', sprintf($pattern, $id));
                $this->assertConcealed($response);
                $bodies[] = $response->getContent();
            }
            $this->assertCount(1, array_unique($bodies), $label . ': responses must be byte-identical');
        }
        // IDOR: mutations with a known public_id / ref from another scope are concealed and change nothing.
        $version = (int) DB::table('physical_locations')->where('id', $loc['id'])->value('lock_version');
        $probes = [
            ['PATCH', 'physical/locations/' . $loc['public_id'], ['name' => 'IDOR', 'lock_version' => $version]],
            ['GET', 'physical/locations/' . $loc['public_id'] . '/address', []],
            ['POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => $version]],
            ['POST', 'physical/locations/' . $loc['public_id'] . '/close', ['lock_version' => $version, 'reason' => 'IDOR close']],
            ['POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'OWNED']],
            ['POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/end', ['reason' => 'IDOR end', 'lock_version' => $link['lock_version']]],
            ['POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/transfer', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'IDOR transfer', 'lock_version' => $link['lock_version']]],
            ['POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/set-primary', ['lock_version' => $link['lock_version']]],
            ['PATCH', 'physical/properties/' . $property['public_id'], ['code' => 'IDOR', 'lock_version' => $property['lock_version']]],
            ['GET', 'physical/properties/' . $property['public_id'] . '/external-owner', []],
            ['PATCH', 'physical/temples/' . $temple['public_id'], ['name' => 'IDOR', 'lock_version' => $temple['lock_version']]],
            ['POST', 'physical/temples/' . $temple['public_id'] . '/activate', ['lock_version' => $temple['lock_version']]],
            ['GET', 'physical/links?unit_public_id=' . $w['a']['public_id'], []],
        ];
        $before = $this->counts();
        foreach ($probes as [$method, $uri, $body]) {
            $this->assertConcealed($this->api($sibling, $method, $uri, $body));
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame($version, (int) DB::table('physical_locations')->where('id', $loc['id'])->value('lock_version'));
        $this->assertSame('ACTIVE', DB::table('unit_location_links')->where('location_id', $loc['id'])->value('status'));
        // An unknown link ref on a visible location is concealed too.
        $this->assertConcealed($this->api($ownerA, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . str_repeat('0', 32) . '/end', ['reason' => 'ref desconhecida', 'lock_version' => 0]));
        // An actor without any Physical permission gets 403 whatever the target (no existence oracle).
        $nobody = $this->staff(['TERRITORIAL_VIEW'], $w['a']['id']);
        $forbidden = [];
        foreach ([$loc['public_id'], $unknown, 'not-a-ulid', $unlinked['public_id']] as $id) {
            $response = $this->api($nobody, 'GET', 'physical/locations/' . $id)->assertStatus(403);
            $forbidden[] = $response->getContent();
        }
        $this->assertCount(1, array_unique($forbidden));
    }

    // ---- L06 ---------------------------------------------------------------------------------------------------

    public function test_l06_linking_an_existing_location_requires_prior_authority_over_it(): void
    {
        $w = $this->world();
        $ownerA = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($ownerA, $w['a']);
        // B knows the public_id and holds UNIT_LOCATION_LINK_MANAGE on B only: cannot attach A's location.
        $linkerB = $this->staff([PhysicalCatalog::LOCATION_VIEW, PhysicalCatalog::LINK_MANAGE], $w['b']['id']);
        $before = $this->counts();
        $this->assertConcealed($this->api($linkerB, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED']));
        $this->assertSame($before, $this->counts());
        // A may not attach its location to B either (B outside A's scope).
        $this->assertConcealed($this->api($ownerA, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED']));
        // Only an actor with authority over the location AND the target unit may link.
        $municipal = $this->staff([PhysicalCatalog::LOCATION_VIEW, PhysicalCatalog::LINK_MANAGE], $w['m']['id']);
        $created = $this->api($municipal, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED', 'reason' => 'Partilha aprovada'])->assertCreated()->json('data');
        $this->assertSame($w['b']['public_id'], $created['unit']['public_id']);
        $this->assertTrue($created['is_primary'], 'the first active link of a unit is primary');
        $this->assertSame(2, $this->activeLinks($loc['id']));
        $this->api($municipal, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED'])->assertStatus(409)->assertJsonPath('error.code', 'LINK_EXISTS');
        $this->assertSame(1, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'UNIT_LOCATION_LINK_CREATE')->where('unit_id', $w['b']['id'])->count(), 'link audit unit = unit of the link');
        // Now B sees the location through its own active link.
        $this->api($linkerB, 'GET', 'physical/locations/' . $loc['public_id'])->assertOk();
        // property_id of a link must belong to the same location.
        $other = $this->location($ownerA, $w['a']);
        $foreignProperty = $this->api($ownerA, 'POST', 'physical/properties', ['location_public_id' => $other['public_id'], 'code' => 'L06-' . Str::random(6)])->assertCreated()->json('data');
        $this->api($municipal, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['c']['public_id'], 'occupation_type_code' => 'RENTED', 'property_public_id' => $foreignProperty['public_id']])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ---- L07 ---------------------------------------------------------------------------------------------------

    public function test_l07_set_primary_keeps_at_most_one_primary_per_unit(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $l1 = $this->location($manager, $w['a']);
        $l2 = $this->location($manager, $w['a']);
        $l3 = $this->location($manager, $w['a']);
        $this->assertSame(1, $this->primaries($w['a']['id']));
        $this->assertTrue($this->linkFor($manager, $l1['public_id'], $w['a']['public_id'])['is_primary']);
        $this->assertFalse($this->linkFor($manager, $l2['public_id'], $w['a']['public_id'])['is_primary']);
        $link2 = $this->linkFor($manager, $l2['public_id'], $w['a']['public_id']);
        $promoted = $this->api($manager, 'POST', 'physical/locations/' . $l2['public_id'] . '/links/' . $link2['ref'] . '/set-primary', ['lock_version' => $link2['lock_version']])->assertOk()->json('data');
        $this->assertTrue($promoted['is_primary']);
        $this->assertSame(1, $this->primaries($w['a']['id']));
        $this->assertFalse($this->linkFor($manager, $l1['public_id'], $w['a']['public_id'])['is_primary'], 'previous primary demoted in the same transaction');
        // Stale version is refused.
        $this->api($manager, 'POST', 'physical/locations/' . $l2['public_id'] . '/links/' . $link2['ref'] . '/set-primary', ['lock_version' => $link2['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $link3 = $this->linkFor($manager, $l3['public_id'], $w['a']['public_id']);
        $this->api($manager, 'POST', 'physical/locations/' . $l3['public_id'] . '/links/' . $link3['ref'] . '/set-primary', ['lock_version' => $link3['lock_version']])->assertOk();
        $this->assertSame(1, $this->primaries($w['a']['id']));
        $this->assertSame(1, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'UNIT_LOCATION_LINK_SET_PRIMARY')->where('entity_id', DB::table('unit_location_links')->where('location_id', $l3['id'])->value('id'))->count());
    }

    // ---- L08 ---------------------------------------------------------------------------------------------------

    public function test_l08_last_active_link_cannot_end_without_transfer_or_close(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['m']['id']);
        $loc = $this->location($manager, $w['a']);
        $link = $this->links($manager, $loc['public_id'])[0];
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/end', ['reason' => 'Saída do local', 'lock_version' => $link['lock_version']])
            ->assertStatus(409)->assertJsonPath('error.code', 'LAST_ACTIVE_LINK_REQUIRED');
        $this->assertSame(1, $this->activeLinks($loc['id']));
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/end', ['lock_version' => $link['lock_version']])->assertStatus(422);
        // Atomicity of end + CLOSE: an ACTIVE temple blocks the close, so the link must stay ACTIVE (rollback).
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => $loc['data']['lock_version']])->assertOk();
        $temple = $this->api($manager, 'POST', 'physical/temples', ['location_public_id' => $loc['public_id'], 'name' => 'Templo L08'])->assertCreated()->json('data');
        $this->api($manager, 'POST', 'physical/temples/' . $temple['public_id'] . '/activate', ['lock_version' => $temple['lock_version']])->assertOk();
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/end', ['reason' => 'Encerrar', 'lock_version' => $link['lock_version'], 'close_location' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'ACTIVE_DEPENDENCIES');
        $this->assertSame(1, $this->activeLinks($loc['id']));
        $this->assertSame('ACTIVE', DB::table('physical_locations')->where('id', $loc['id'])->value('status'));
        $templeRow = DB::table('temples')->where('public_id', $temple['public_id'])->first();
        $this->api($manager, 'POST', 'physical/temples/' . $temple['public_id'] . '/close', ['lock_version' => $templeRow->lock_version, 'reason' => 'Fecho do templo'])->assertOk();
        // With a second link, ending one is allowed.
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'BORROWED'])->assertCreated();
        $linkB = $this->linkFor($manager, $loc['public_id'], $w['b']['public_id']);
        // close_location on a non-last link is refused.
        $linkA = $this->linkFor($manager, $loc['public_id'], $w['a']['public_id']);
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $linkA['ref'] . '/end', ['reason' => 'Fecho indevido', 'lock_version' => $linkA['lock_version'], 'close_location' => true])->assertStatus(422);
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $linkB['ref'] . '/end', ['reason' => 'Empréstimo terminado', 'lock_version' => $linkB['lock_version']])->assertOk()->assertJsonPath('data.status', 'ENDED');
        $link = $this->links($manager, $loc['public_id'])[0];
        // Last link + CLOSE in the same transaction.
        $ended = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/end', ['reason' => 'Local encerrado', 'lock_version' => $link['lock_version'], 'close_location' => true])->assertOk()->json('data');
        $this->assertSame('ENDED', $ended['status']);
        $row = DB::table('physical_locations')->where('id', $loc['id'])->first();
        $this->assertSame('CLOSED', $row->status);
        $this->assertSame('PRIVATE', $row->public_visibility);
        $this->assertSame(0, $this->activeLinks($loc['id']));
        $this->assertConcealed($this->api($manager, 'GET', 'physical/locations/' . $loc['public_id']));
        $end = DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'UNIT_LOCATION_LINK_END')->where('entity_id', DB::table('unit_location_links')->where('location_id', $loc['id'])->where('unit_id', $w['a']['id'])->value('id'))->first();
        $close = DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'PHYSICAL_LOCATION_CLOSE')->where('entity_id', $loc['id'])->first();
        $this->assertSame($close->correlation_id, $end->correlation_id);
    }

    // ---- L09 ---------------------------------------------------------------------------------------------------

    public function test_l09_transfer_preserves_history_and_moves_authority(): void
    {
        $w = $this->world();
        $operator = $this->staff($this->physicalPermissions(), $w['m']['id']);
        $viewerA = $this->staff([PhysicalCatalog::LOCATION_VIEW], $w['a']['id']);
        $viewerB = $this->staff([PhysicalCatalog::LOCATION_VIEW], $w['b']['id']);
        $loc = $this->location($operator, $w['a']);
        $this->api($viewerA, 'GET', 'physical/locations/' . $loc['public_id'])->assertOk();
        $this->assertConcealed($this->api($viewerB, 'GET', 'physical/locations/' . $loc['public_id']));
        $old = $this->links($operator, $loc['public_id'])[0];
        $this->api($operator, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $old['ref'] . '/transfer', ['to_unit_public_id' => $w['b']['public_id'], 'lock_version' => $old['lock_version']])->assertStatus(422);
        $this->api($operator, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $old['ref'] . '/transfer', ['to_unit_public_id' => $w['a']['public_id'], 'reason' => 'Mesma unidade', 'lock_version' => $old['lock_version']])->assertStatus(422);
        $new = $this->api($operator, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $old['ref'] . '/transfer', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'Reorganização aprovada', 'lock_version' => $old['lock_version']])->assertCreated()->json('data');
        $this->assertSame($w['b']['public_id'], $new['unit']['public_id']);
        $this->assertSame('ACTIVE', $new['status']);
        $rows = DB::table('unit_location_links')->where('location_id', $loc['id'])->orderBy('id')->get();
        $this->assertCount(2, $rows, 'history preserved: the old link is ended, not rewritten');
        $this->assertSame('ENDED', $rows[0]->status);
        $this->assertSame($w['a']['id'], (int) $rows[0]->unit_id);
        $this->assertSame((string) $rows[0]->ends_at, (string) $rows[1]->starts_at, 'no gap and no overlap between periods');
        $this->assertTrue((string) $rows[0]->starts_at < (string) $rows[0]->ends_at);
        $this->assertSame(1, $this->activeLinks($loc['id']));
        // Authority moved with the link.
        $this->assertConcealed($this->api($viewerA, 'GET', 'physical/locations/' . $loc['public_id']));
        $this->api($viewerB, 'GET', 'physical/locations/' . $loc['public_id'])->assertOk();
        $history = $this->links($operator, $loc['public_id'], true);
        $this->assertSame(['ACTIVE', 'ENDED'], array_column($history, 'status'));
        // Audit: old unit on the transfer row, new unit on the new link, one correlation.
        $transfer = DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'UNIT_LOCATION_LINK_TRANSFER')->where('entity_id', $rows[0]->id)->first();
        $create = DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'UNIT_LOCATION_LINK_CREATE')->where('entity_id', $rows[1]->id)->first();
        $this->assertSame($w['a']['id'], (int) $transfer->unit_id);
        $this->assertSame($w['b']['id'], (int) $create->unit_id);
        $this->assertSame($transfer->correlation_id, $create->correlation_id);
        $after = json_decode((string) $transfer->after_metadata, true);
        $this->assertSame([$w['a']['id'], $w['b']['id']], [$after['old_unit_entity'], $after['new_unit_entity']]);
        // Destination outside the actor's scope is concealed; the source cannot be transferred again (ENDED).
        $narrow = $this->staff($this->physicalPermissions(), $w['b']['id']);
        $current = $this->links($narrow, $loc['public_id'])[0];
        $this->assertConcealed($this->api($narrow, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $current['ref'] . '/transfer', ['to_unit_public_id' => $w['c']['public_id'], 'reason' => 'Fora do escopo', 'lock_version' => $current['lock_version']]));
        $this->api($operator, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $old['ref'] . '/transfer', ['to_unit_public_id' => $w['c']['public_id'], 'reason' => 'Repetição', 'lock_version' => $old['lock_version'] + 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
    }

    // ---- L10 ---------------------------------------------------------------------------------------------------

    public function test_l10_publish_exposes_only_the_minimal_public_projection(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $managerOnly = $this->staff([PhysicalCatalog::LOCATION_VIEW, PhysicalCatalog::LOCATION_MANAGE], $w['a']['id']);
        $noCoords = $this->location($manager, $w['a'], ['latitude' => null, 'longitude' => null]);
        $loc = $this->location($manager, $w['a'], ['name' => 'Templo Central Público']);
        $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['public_visibility' => 'APPROVED_PUBLIC', 'lock_version' => 0])->assertStatus(422);
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Divulgação', 'lock_version' => 0])->assertStatus(409)->assertJsonPath('error.code', 'LOCATION_NOT_ACTIVE');
        $active = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => 0])->assertOk()->json('data');
        $this->assertNull($active['public_projection']);
        $this->api($managerOnly, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Sem permissão', 'lock_version' => $active['lock_version']])->assertStatus(403);
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['lock_version' => $active['lock_version']])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Versão antiga', 'lock_version' => 0])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $noCoordsActive = $this->api($manager, 'POST', 'physical/locations/' . $noCoords['public_id'] . '/activate', ['lock_version' => 0])->assertOk()->json('data');
        $this->api($manager, 'POST', 'physical/locations/' . $noCoords['public_id'] . '/publish', ['reason' => 'Sem coordenadas', 'lock_version' => $noCoordsActive['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'COORDINATES_REQUIRED');
        $property = $this->api($manager, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'PUB-' . Str::random(6), 'owner_name_external' => 'Proprietário Externo Lda'])->assertCreated()->json('data');
        $published = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Mapa institucional aprovado', 'lock_version' => $active['lock_version']])->assertOk()->json('data');
        $this->assertSame('APPROVED_PUBLIC', $published['public_visibility']);
        $projection = $published['public_projection'];
        $this->assertSame(['public_id', 'name', 'latitude', 'longitude'], array_keys($projection));
        $this->assertSame([$loc['public_id'], 'Templo Central Público', -8.838333, 13.234444], array_values($projection));
        $raw = json_encode($projection, JSON_UNESCAPED_UNICODE);
        foreach ([self::LINE1, self::LOCALITY, 'Proprietário Externo', $w['a']['public_id'], 'key_version', 'owner', 'status', 'links', $property['public_id']] as $needle) {
            $this->assertStringNotContainsString($needle, $raw);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'PHYSICAL_LOCATION_PUBLISH')->where('entity_id', $loc['id'])->where('reason', 'Mapa institucional aprovado')->where('unit_id', $w['a']['id'])->count());
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Repetir', 'lock_version' => $published['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $unpublished = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/unpublish', ['reason' => 'Retirar do mapa', 'lock_version' => $published['lock_version']])->assertOk()->json('data');
        $this->assertSame('PRIVATE', $unpublished['public_visibility']);
        $this->assertNull($unpublished['public_projection']);
        $this->assertNull(\App\Domain\Physical\PhysicalRecords::publicProjection(DB::table('physical_locations')->where('id', $loc['id'])->first()));
        // No anonymous endpoint: unauthenticated access is refused.
        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/v1/physical/locations/' . $loc['public_id'])->assertStatus(401);
    }

    // ---- L11 ---------------------------------------------------------------------------------------------------

    public function test_l11_coordinate_change_on_published_location_returns_it_to_private(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        $v = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => 0])->assertOk()->json('data.lock_version');
        $v = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Publicar', 'lock_version' => $v])->assertOk()->json('data.lock_version');
        $renamed = $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['name' => 'Novo nome', 'lock_version' => $v])->assertOk()->json('data');
        $this->assertSame('APPROVED_PUBLIC', $renamed['public_visibility'], 'a name change keeps the publication');
        $moved = $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['latitude' => -8.9, 'longitude' => 13.3, 'lock_version' => $renamed['lock_version']])->assertOk()->json('data');
        $this->assertSame('PRIVATE', $moved['public_visibility']);
        $this->assertNull($moved['public_projection']);
        $audit = DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where('action', 'PHYSICAL_LOCATION_UPDATE')->where('entity_id', $loc['id'])->orderByDesc('id')->first();
        $this->assertTrue(json_decode((string) $audit->after_metadata, true)['visibility_reset']);
        $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['latitude' => 91, 'longitude' => 13.3, 'lock_version' => $moved['lock_version']])->assertStatus(422);
        $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['latitude' => -8.9, 'lock_version' => $moved['lock_version']])->assertStatus(422);
        // Re-publish then CLOSE: CLOSED forces PRIVATE.
        $v = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/publish', ['reason' => 'Nova posição aprovada', 'lock_version' => $moved['lock_version']])->assertOk()->json('data.lock_version');
        $closed = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/close', ['reason' => 'Encerramento', 'lock_version' => $v])->assertOk()->json('data');
        $this->assertSame(['CLOSED', 'PRIVATE'], [$closed['status'], $closed['public_visibility']]);
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => $closed['lock_version']])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['name' => 'Fechado', 'lock_version' => $closed['lock_version']])->assertStatus(409);
    }

    // ---- L12 ---------------------------------------------------------------------------------------------------

    public function test_l12_owner_person_projection_never_leaks_people_data(): void
    {
        $w = $this->world();
        $withPeople = $this->staff([...$this->physicalPermissions(), 'PEOPLE_VIEW'], $w['a']['id']);
        $physicalOnly = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $owner = $this->row('people', ['full_name' => 'Maria Proprietária Exemplo']);
        DB::table('person_unit_contexts')->insert(['person_id' => $owner, 'unit_id' => $w['a']['id'], 'context_kind' => 'ONBOARDING', 'status' => 'ACTIVE', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null, 'reason' => null, 'source_document_id' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        $ownerPublic = (string) DB::table('people')->where('id', $owner)->value('public_id');
        $stranger = $this->row('people', ['full_name' => 'Pessoa Fora do Escopo']);
        $strangerPublic = (string) DB::table('people')->where('id', $stranger)->value('public_id');
        $loc = $this->location($withPeople, $w['a']);
        // Setting an owner Person requires independent People authority; unknown and out-of-scope look the same.
        $invalid = [];
        foreach ([$strangerPublic, (string) Str::ulid(), 'bad'] as $candidate) {
            $invalid[] = $this->api($withPeople, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'OWN-' . Str::random(6), 'owner_person_public_id' => $candidate])->assertStatus(422)->getContent();
        }
        $this->assertCount(1, array_unique($invalid));
        $this->api($physicalOnly, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'OWN-' . Str::random(6), 'owner_person_public_id' => $ownerPublic])->assertStatus(422);
        $this->api($withPeople, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'OWN-' . Str::random(6), 'owner_person_public_id' => $ownerPublic, 'owner_name_external' => 'Ambos'])->assertStatus(422);
        $property = $this->api($withPeople, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'OWN-' . Str::random(6), 'owner_person_public_id' => $ownerPublic])->assertCreated()->json('data');
        $this->assertSame(['kind' => 'PERSON', 'person' => ['public_id' => $ownerPublic, 'display_name' => 'Maria Proprietária Exemplo']], $property['owner']);
        $seen = $this->api($physicalOnly, 'GET', 'physical/properties/' . $property['public_id'])->assertOk();
        $this->assertSame(['kind' => 'PERSON', 'person' => null], $seen->json('data.owner'));
        $this->assertStringNotContainsString($ownerPublic, $seen->getContent());
        $this->assertStringNotContainsString('Maria', $seen->getContent());
        $listed = $this->api($withPeople, 'GET', 'physical/properties?location_public_id=' . $loc['public_id'])->assertOk();
        $this->assertStringNotContainsString($ownerPublic, $listed->getContent(), 'list never projects the owner Person');
        $this->assertNoInternalIds($seen->json());
        // External owner: never in list/detail; only the audited MANAGE read.
        $external = $this->api($withPeople, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'EXT-' . Str::random(6), 'owner_name_external' => 'Sociedade Imobiliária Terceira'])->assertCreated();
        $this->assertSame(['kind' => 'EXTERNAL', 'person' => null], $external->json('data.owner'));
        $this->assertStringNotContainsString('Imobiliária Terceira', $external->getContent());
        $list = $this->api($withPeople, 'GET', 'physical/properties?search=EXT-')->assertOk();
        $this->assertStringNotContainsString('Imobiliária Terceira', $list->getContent());
        $viewer = $this->staff([PhysicalCatalog::PROPERTY_VIEW], $w['a']['id']);
        $this->api($viewer, 'GET', 'physical/properties/' . $external->json('data.public_id') . '/external-owner')->assertStatus(403);
        $auditBefore = DB::table('audit_logs')->where('action', 'PROPERTY_EXTERNAL_OWNER_READ')->count();
        $this->api($physicalOnly, 'GET', 'physical/properties/' . $external->json('data.public_id') . '/external-owner')->assertOk()->assertExactJson(['data' => ['name' => 'Sociedade Imobiliária Terceira']]);
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->where('action', 'PROPERTY_EXTERNAL_OWNER_READ')->where('unit_id', $w['a']['id'])->count());
        $this->assertSame(0, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where(fn ($q) => $q->where('after_metadata', 'like', '%Terceira%')->orWhere('after_metadata', 'like', '%Maria%'))->count());
    }

    // ---- L13 ---------------------------------------------------------------------------------------------------

    public function test_l13_address_is_encrypted_with_its_own_aad_and_fails_closed(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $viewer = $this->staff([PhysicalCatalog::LOCATION_VIEW], $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        $row = DB::table('addresses')->where('id', DB::table('physical_locations')->where('id', $loc['id'])->value('address_id'))->first();
        $this->assertStringNotContainsString(self::LINE1, (string) $row->line1_ciphertext);
        $this->assertSame(1, (int) $row->key_version);
        $this->assertSame("\x01", ((string) $row->line1_ciphertext)[0]);
        $this->assertSame(0, DB::table('person_addresses')->where('address_id', $row->id)->count(), 'institutional address row is never shared with People');
        $crypto = PeopleCrypto::fromKeyRingFile((string) config('physical.keyring_path'), []);
        $this->assertSame(self::LINE1, $crypto->decrypt((string) $row->line1_ciphertext, 1, LocationService::aad($loc['public_id'])));
        foreach (['mepa.people.address.line1.v1|' . $loc['public_id'], LocationService::aad((string) Str::ulid())] as $wrongAad) {
            try {
                $crypto->decrypt((string) $row->line1_ciphertext, 1, $wrongAad);
                $this->fail('ciphertext decrypted under a foreign AAD');
            } catch (PeopleError $e) {
                $this->assertSame('CRYPTO_UNAVAILABLE', $e->reason);
            }
        }
        $detail = $this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'])->assertOk();
        $this->assertStringNotContainsString(self::LINE1, $detail->getContent());
        $this->assertStringNotContainsString(self::LOCALITY, $detail->getContent());
        $this->api($viewer, 'GET', 'physical/locations/' . $loc['public_id'] . '/address')->assertStatus(403);
        $reads = DB::table('audit_logs')->where('action', 'PHYSICAL_LOCATION_ADDRESS_READ')->count();
        $this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'] . '/address')->assertOk()->assertExactJson(['data' => ['country_code' => 'AO', 'line1' => self::LINE1, 'locality' => self::LOCALITY]]);
        $this->assertSame($reads + 1, DB::table('audit_logs')->where('action', 'PHYSICAL_LOCATION_ADDRESS_READ')->where('unit_id', $w['a']['id'])->count());
        $this->assertSame(0, DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where(fn ($q) => $q->where('after_metadata', 'like', '%Missão%')->orWhere('after_metadata', 'like', '%Esperança%'))->count());
        // Address replacement keeps the previous row (history) and re-encrypts under the same location AAD.
        $version = (int) DB::table('physical_locations')->where('id', $loc['id'])->value('lock_version');
        $this->api($manager, 'PUT', 'physical/locations/' . $loc['public_id'] . '/address', ['country_code' => 'AO', 'line1' => 'Avenida Nova 45', 'locality' => null, 'lock_version' => $version])->assertOk();
        $this->assertNotSame((int) $row->id, (int) DB::table('physical_locations')->where('id', $loc['id'])->value('address_id'));
        $this->assertTrue(DB::table('addresses')->where('id', $row->id)->exists());
        $this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'] . '/address')->assertOk()->assertJsonPath('data.line1', 'Avenida Nova 45');
        // No blind index / no address search.
        $this->assertSame([], DB::select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'addresses' AND COLUMN_NAME LIKE '%blind%'"));
        $this->assertSame([], $this->api($manager, 'GET', 'physical/locations?search=Avenida')->assertOk()->json('data'));
        // Fail closed: no ring, a ring inside the repository, or a malformed ring -> 503 and nothing written.
        foreach ([sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'absent-' . bin2hex(random_bytes(4)) . '.json', base_path('composer.json')] as $broken) {
            config(['physical.keyring_path' => $broken]);
            $before = $this->counts();
            $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a']))->assertStatus(503)->assertJsonPath('error.code', 'PHYSICAL_CRYPTO_UNAVAILABLE');
            $this->assertSame($before, $this->counts());
            $this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'] . '/address')->assertStatus(503);
            $this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'])->assertOk();
        }
        config(['physical.keyring_path' => self::$keyring]);
        // Unknown key_version fails closed too (no plaintext fallback).
        DB::table('addresses')->where('id', DB::table('physical_locations')->where('id', $loc['id'])->value('address_id'))->update(['key_version' => 9]);
        $this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'] . '/address')->assertStatus(503)->assertJsonPath('error.code', 'PHYSICAL_CRYPTO_UNAVAILABLE');
    }

    // ---- API contract guards -----------------------------------------------------------------------------------

    public function test_source_document_id_unit_id_status_and_visibility_are_never_accepted_from_requests(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['m']['id']);
        $document = $this->row('legal_documents');
        $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a'], ['source_document_id' => $document]))->assertStatus(422)->assertJsonValidationErrors(['source_document_id'], 'error.details.fields');
        $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a'], ['unit_id' => $w['a']['id']]))->assertStatus(422);
        $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a'], ['status' => 'ACTIVE']))->assertStatus(422);
        $this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a'], ['public_visibility' => 'APPROVED_PUBLIC']))->assertStatus(422);
        $loc = $this->location($manager, $w['a']);
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'OWNED', 'source_document_id' => $document])->assertStatus(422);
        $link = $this->links($manager, $loc['public_id'])[0];
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/transfer', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'Com documento', 'lock_version' => $link['lock_version'], 'source_document_id' => $document])->assertStatus(422);
        $this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['status' => 'ACTIVE', 'lock_version' => 0])->assertStatus(422);
        $this->api($manager, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'SRC-' . Str::random(6), 'status' => 'ACTIVE'])->assertStatus(422);
        $this->assertSame(0, DB::table('unit_location_links')->whereNotNull('source_document_id')->where('location_id', $loc['id'])->count());
        // Historic source_document_id is preserved; new P0.7 links stay NULL.
        DB::table('unit_location_links')->where('id', DB::table('unit_location_links')->where('location_id', $loc['id'])->value('id'))->update(['source_document_id' => $document]);
        $link = $this->links($manager, $loc['public_id'])[0];
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/links/' . $link['ref'] . '/transfer', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'Histórico', 'lock_version' => $link['lock_version']])->assertCreated();
        $rows = DB::table('unit_location_links')->where('location_id', $loc['id'])->orderBy('id')->get();
        $this->assertSame($document, (int) $rows[0]->source_document_id);
        $this->assertNull($rows[1]->source_document_id);
        // No hard delete route exists.
        $this->assertNotContains($this->api($manager, 'DELETE', 'physical/locations/' . $loc['public_id'])->status(), [200, 204]);
    }

    public function test_collections_are_paginated_default_50_max_100(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        foreach (['physical/locations', 'physical/properties', 'physical/temples', 'physical/locations/' . $loc['public_id'] . '/links', 'physical/links?unit_public_id=' . $w['a']['public_id']] as $uri) {
            $sep = str_contains($uri, '?') ? '&' : '?';
            $this->assertSame(50, $this->api($manager, 'GET', $uri)->assertOk()->json('meta.per_page'), $uri);
            $this->assertSame(100, $this->api($manager, 'GET', $uri . $sep . 'per_page=100')->assertOk()->json('meta.per_page'), $uri);
            $this->api($manager, 'GET', $uri . $sep . 'per_page=101')->assertStatus(422);
            $this->api($manager, 'GET', $uri . $sep . 'per_page=100000')->assertStatus(422);
        }
    }

    public function test_commit_time_recheck_refuses_a_grant_revoked_during_the_transaction(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        $revoke = function () use ($manager): void {
            DB::table('user_role_scopes')->where('user_id', $manager['user'])->update(['ends_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        };
        $this->app->instance('physical.before_commit', $revoke);
        $before = $this->counts();
        $this->assertContains($this->api($manager, 'POST', 'physical/locations', $this->locationBody($w['a']))->status(), [403, 404]);
        $this->assertContains($this->api($manager, 'PATCH', 'physical/locations/' . $loc['public_id'], ['name' => 'Revogado', 'lock_version' => 0])->status(), [403, 404]);
        $this->app->forgetInstance('physical.before_commit');
        $this->assertSame($before, $this->counts());
        $this->assertNull(DB::table('user_role_scopes')->where('user_id', $manager['user'])->value('ends_at'), 'the revocation itself was rolled back with the refused operation');
        $this->assertNotSame('Revogado', DB::table('physical_locations')->where('id', $loc['id'])->value('name'));
    }

    public function test_permission_alone_never_grants_scope_and_scope_alone_never_grants_permission(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $loc = $this->location($manager, $w['a']);
        $otherScope = $this->staff($this->physicalPermissions(), $w['c']['id']);
        $this->assertConcealed($this->api($otherScope, 'GET', 'physical/locations/' . $loc['public_id']));
        $this->assertSame([], $this->api($otherScope, 'GET', 'physical/locations')->assertOk()->json('data'));
        $territorialOnly = $this->staff(['TERRITORIAL_VIEW', 'TERRITORIAL_MANAGE'], $w['a']['id']);
        $this->api($territorialOnly, 'GET', 'physical/locations')->assertStatus(403);
        $context = $this->api($territorialOnly, 'GET', 'physical/context')->assertOk()->json('data');
        $this->assertSame([], $context['permissions']);
        $viewOnly = $this->staff([PhysicalCatalog::LOCATION_VIEW], $w['a']['id']);
        $this->api($viewOnly, 'GET', 'physical/locations/' . $loc['public_id'])->assertOk()->assertJsonPath('data.can', [PhysicalCatalog::LOCATION_VIEW]);
        $this->api($viewOnly, 'PATCH', 'physical/locations/' . $loc['public_id'], ['name' => 'x', 'lock_version' => 0])->assertStatus(403);
        // A descendant scope covers a congregation link; a non-descendant grant on the parent does not.
        $congregationLoc = $this->location($this->staff($this->physicalPermissions(), $w['a1']['id']), $w['a1']);
        $this->api($manager, 'GET', 'physical/locations/' . $congregationLoc['public_id'])->assertOk();
        $flat = $this->staff([PhysicalCatalog::LOCATION_VIEW], $w['a']['id'], false);
        $this->assertConcealed($this->api($flat, 'GET', 'physical/locations/' . $congregationLoc['public_id']));
        $this->assertSame(0, DB::table('permissions')->where('data_type', 'PHYSICAL')->whereNotIn('code', array_keys(PhysicalCatalog::PERMISSIONS))->count());
    }

    public function test_catalog_is_idempotent_and_matches_adr_0018(): void
    {
        $this->assertSame([], PhysicalCatalog::install(DB::connection()));
        $this->assertSame(['BORROWED', 'CEDED', 'OTHER', 'OWNED', 'RENTED', 'TEMPORARY'], DB::table('occupation_types')->whereIn('code', array_keys(PhysicalCatalog::OCCUPATION_TYPES))->where('is_active', 1)->orderBy('code')->pluck('code')->all());
        $this->assertSame(8, DB::table('permissions')->where('data_type', 'PHYSICAL')->whereColumn('action', 'code')->count());
        foreach (['physical_locations', 'properties'] as $table) {
            $this->assertSame([], DB::select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)', [$table, 'owner_unit_id', 'unit_id']), $table . ' must not have an owner unit');
        }
        $this->assertSame([], DB::select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizational_units' AND COLUMN_NAME = 'location_id'"));
    }

    public function test_lifecycle_rules_and_audit_units_come_from_the_authorizing_context(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['m']['id']);
        $loc = $this->location($manager, $w['a']);
        $v = $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/activate', ['lock_version' => 0])->assertOk()->json('data.lock_version');
        $property = $this->api($manager, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'LIFE-' . Str::random(6)])->assertCreated()->json('data');
        $property = $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/activate', ['lock_version' => $property['lock_version']])->assertOk()->json('data');
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/close', ['reason' => 'Fecho', 'lock_version' => $v])->assertStatus(409)->assertJsonPath('error.code', 'ACTIVE_DEPENDENCIES');
        $this->api($manager, 'POST', 'physical/properties/' . $property['public_id'] . '/close', ['reason' => 'Imóvel alienado', 'lock_version' => $property['lock_version']])->assertOk();
        $this->api($manager, 'POST', 'physical/locations/' . $loc['public_id'] . '/close', ['reason' => 'Fecho', 'lock_version' => $v])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->api($manager, 'POST', 'physical/properties', ['location_public_id' => $loc['public_id'], 'code' => 'LIFE-' . Str::random(6)])->assertStatus(409)->assertJsonPath('error.code', 'LOCATION_CLOSED');
        $this->api($manager, 'POST', 'physical/temples', ['location_public_id' => $loc['public_id'], 'name' => 'Tarde demais'])->assertStatus(409)->assertJsonPath('error.code', 'LOCATION_CLOSED');
        // Every P07 audit row of this location carries the unit of the authorizing link (A), never the actor's scope root.
        $entityIds = [$loc['id'], (int) DB::table('properties')->where('public_id', $property['public_id'])->value('id')];
        $scoped = fn () => DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->where(fn ($q) => $q->where(fn ($l) => $l->where('entity_type', 'PHYSICAL_LOCATION')->where('entity_id', $entityIds[0]))->orWhere(fn ($p) => $p->where('entity_type', 'PROPERTY')->where('entity_id', $entityIds[1])));
        $units = $scoped()->distinct()->pluck('unit_id')->all();
        $this->assertSame([$w['a']['id']], array_map('intval', $units));
        foreach ($scoped()->get() as $row) {
            $this->assertSame($manager['user'], (int) $row->actor_id);
            $this->assertSame($manager['session'], (int) $row->session_id);
            $this->assertNotNull($row->correlation_id);
        }
        $this->assertNoInternalIds($this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'])->json());
        $this->assertNoInternalIds($this->api($manager, 'GET', 'physical/locations/' . $loc['public_id'] . '/links?history=1')->json());
        $this->assertNoInternalIds($this->api($manager, 'GET', 'physical/context')->json());
    }

    // ---- L15 / L16: real concurrent MySQL connections ----------------------------------------------------------

    public function test_l15_concurrent_set_primary_never_leaves_two_primaries(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->physicalPermissions(), $w['a']['id']);
        $rounds = [];
        for ($round = 0; $round < 3; $round++) {
            $locations = [$this->location($manager, $w['a']), $this->location($manager, $w['a'])];
            $jobs = [];
            foreach ($locations as $loc) {
                $link = $this->linkFor($manager, $loc['public_id'], $w['a']['public_id']);
                $jobs[] = ['op' => 'set-primary', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version']];
            }
            $statuses = $this->race($manager, $jobs);
            $this->assertContains('OK', $statuses);
            foreach ($statuses as $status) {
                $this->assertContains($status, ['OK', 'STALE_WRITE']);
            }
            $this->assertSame(1, $this->primaries($w['a']['id']), 'exactly one primary after concurrent set-primary');
            $rounds[] = $statuses;
        }
        // Same link, same version, twice: one OK and one STALE_WRITE (deterministic refusal).
        $loc = $this->location($manager, $w['a']);
        $link = $this->linkFor($manager, $loc['public_id'], $w['a']['public_id']);
        $job = ['op' => 'set-primary', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version']];
        $same = $this->race($manager, [$job, $job]);
        $this->assertSame(['OK', 'STALE_WRITE'], $same);
        $this->assertSame(1, $this->primaries($w['a']['id']));
        $this->evidence('C1', ['different_links_rounds' => $rounds, 'same_link' => $same, 'final_primaries' => $this->primaries($w['a']['id'])]);
    }

    public function test_l16_concurrent_end_link_and_transfer_never_orphan_the_location(): void
    {
        $w = $this->world();
        $operator = $this->staff($this->physicalPermissions(), $w['m']['id']);
        $report = [];
        // (a) same link: end-link vs transfer -> one valid result + STALE_WRITE.
        for ($round = 0; $round < 2; $round++) {
            $loc = $this->location($operator, $w['a']);
            $this->api($operator, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED'])->assertCreated();
            $link = $this->linkFor($operator, $loc['public_id'], $w['a']['public_id']);
            $statuses = $this->race($operator, [
                ['op' => 'end', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version']],
                ['op' => 'transfer', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version'], 'to' => $w['c']['public_id']],
            ]);
            $this->assertSame(['OK', 'STALE_WRITE'], $statuses);
            $this->assertGreaterThanOrEqual(1, $this->activeLinks($loc['id']));
            $this->assertSame(0, DB::table('unit_location_links')->where('location_id', $loc['id'])->where('unit_id', $w['a']['id'])->where('status', 'ACTIVE')->count());
            $report['same_link'][] = $statuses;
        }
        // (b) single last link: end-link (no close) vs transfer -> never orphaned, exactly one active link.
        for ($round = 0; $round < 2; $round++) {
            $loc = $this->location($operator, $w['a']);
            $link = $this->linkFor($operator, $loc['public_id'], $w['a']['public_id']);
            $statuses = $this->race($operator, [
                ['op' => 'end', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version']],
                ['op' => 'transfer', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version'], 'to' => $w['c']['public_id']],
            ]);
            $this->assertContains($statuses, [['LAST_ACTIVE_LINK_REQUIRED', 'OK'], ['OK', 'STALE_WRITE']]);
            $this->assertSame(1, $this->activeLinks($loc['id']));
            $this->assertSame($w['c']['id'], (int) DB::table('unit_location_links')->where('location_id', $loc['id'])->where('status', 'ACTIVE')->value('unit_id'));
            $report['last_link'][] = $statuses;
        }
        // (c) two links ended concurrently: one OK, the other LAST_ACTIVE_LINK_REQUIRED.
        for ($round = 0; $round < 2; $round++) {
            $loc = $this->location($operator, $w['a']);
            $this->api($operator, 'POST', 'physical/locations/' . $loc['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED'])->assertCreated();
            $jobs = [];
            foreach ([$w['a'], $w['b']] as $unit) {
                $link = $this->linkFor($operator, $loc['public_id'], $unit['public_id']);
                $jobs[] = ['op' => 'end', 'location' => $loc['public_id'], 'ref' => $link['ref'], 'lock_version' => $link['lock_version']];
            }
            $statuses = $this->race($operator, $jobs);
            $this->assertSame(['LAST_ACTIVE_LINK_REQUIRED', 'OK'], $statuses);
            $this->assertSame(1, $this->activeLinks($loc['id']));
            $report['both_links'][] = $statuses;
        }
        $this->evidence('C2', $report);
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    /** Runs each job in its own PHP process / MySQL connection, released together; returns sorted statuses. */
    private function race(array $actor, array $jobs): array
    {
        $root = dirname(__DIR__, 4);
        $php = getenv('MEPA_PHP_BIN') ?: 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe';
        $env = getenv() + ['P07_APP_KEY' => (string) config('app.key')];
        $env['P07_APP_KEY'] = (string) config('app.key');
        $procs = [];
        foreach ($jobs as $job) {
            $payload = base64_encode(json_encode($job + ['user' => $actor['user'], 'session' => $actor['session'], 'reason' => 'concurrency probe'], JSON_THROW_ON_ERROR));
            $proc = proc_open([$php, $root . '/scripts/p07-physical-worker.php', $payload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        foreach ($procs as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $statuses = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $statuses[] = json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        return $statuses;
    }

    private function evidence(string $name, array $data): void
    {
        $dir = getenv('P07_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'concurrency-' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }

    /** A physical location row WITHOUT any link (legacy / archival state), built directly in the pool. */
    private function rawLocation(string $name): array
    {
        $address = $this->row('addresses', ['country_code' => 'AO', 'line1_ciphertext' => random_bytes(40), 'key_version' => 1]);
        $public = (string) Str::ulid();
        $id = $this->row('physical_locations', ['public_id' => $public, 'address_id' => $address, 'name' => $name, 'latitude' => null, 'longitude' => null, 'public_visibility' => 'PRIVATE', 'status' => 'ACTIVE']);
        return ['id' => $id, 'public_id' => $public, 'name' => $name];
    }

    private function rawLink(int $unit, int $location, array $values = []): int
    {
        return $this->row('unit_location_links', $values + [
            'unit_id' => $unit, 'location_id' => $location, 'property_id' => null,
            'occupation_type_id' => (int) DB::table('occupation_types')->where('code', 'OWNED')->value('id'),
            'is_primary' => 0, 'status' => 'ACTIVE', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'ends_at' => null, 'source_document_id' => null,
        ]);
    }
}
