<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PhysicalHttpCase;

/**
 * Seeds the P0.7 browser fixture THROUGH the Physical API (so every row is a real, audited, encrypted write) and writes
 * the transient manifest .tmp/p07-e2e-fixtures.json (git-ignored; removed by the runner). Credentials are random per run.
 */
final class PhysicalE2EFixtureTest extends PhysicalHttpCase
{
    public function test_seed_physical_browser_fixture_only(): void
    {
        $w = $this->world();
        $actor = $this->staff([...$this->physicalPermissions(), 'PEOPLE_VIEW'], $w['g']['id']);
        $sede = $this->location($actor, $w['a'], ['name' => 'Sede Central MEPA', 'latitude' => -8.838333, 'longitude' => 13.234444]);
        $this->api($actor, 'POST', 'physical/locations/' . $sede['public_id'] . '/activate', ['lock_version' => 0])->assertOk();
        $this->api($actor, 'POST', 'physical/locations/' . $sede['public_id'] . '/links', ['unit_public_id' => $w['b']['public_id'], 'occupation_type_code' => 'CEDED', 'reason' => 'Partilha com o Centro B'])->assertCreated();
        $property = $this->api($actor, 'POST', 'physical/properties', ['location_public_id' => $sede['public_id'], 'code' => 'IMV-SEDE-' . strtoupper(bin2hex(random_bytes(2))), 'owner_name_external' => 'Proprietário Externo E2E'])->assertCreated()->json('data');
        $temple = $this->api($actor, 'POST', 'physical/temples', ['location_public_id' => $sede['public_id'], 'name' => 'Templo Principal', 'capacity' => 350])->assertCreated()->json('data');
        $this->api($actor, 'POST', 'physical/temples', ['location_public_id' => $sede['public_id'], 'name' => 'Templo Juvenil', 'capacity' => 120])->assertCreated();
        $formacao = $this->location($actor, $w['a'], ['name' => 'Centro de Formação', 'occupation_type_code' => 'RENTED']);
        $login = 'physical.e2e.' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(32));
        DB::table('users')->where('id', $actor['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $manifest = [
            'login' => $login, 'password' => $password, 'user_id' => $actor['user'],
            'unit_a' => $w['a']['public_id'], 'unit_b' => $w['b']['public_id'], 'unit_c' => $w['c']['public_id'],
            'location' => $sede['public_id'], 'second_location' => $formacao['public_id'],
            'property' => $property['public_id'], 'property_code' => $property['code'], 'temple' => $temple['public_id'],
            'congregations_before' => DB::table('organizational_units as ou')->join('organizational_unit_types as t', 't.id', '=', 'ou.unit_type_id')->where('t.code', 'CONGREGATION')->count(),
        ];
        $dir = dirname(__DIR__, 4) . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/p07-e2e-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->assertFileExists($dir . '/p07-e2e-fixtures.json');
    }
}
