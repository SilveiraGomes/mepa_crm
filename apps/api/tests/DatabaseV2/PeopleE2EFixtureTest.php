<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/**
 * P0.5-I real-browser fixture (synthetic data only, isolated Wave 5 pool). Writes the manifest the
 * Playwright People suite reads. Never run against a development or production database: the
 * HttpWaveFiveCase guard refuses any schema that is not mepa_wave5_test_*.
 */
final class PeopleE2EFixtureTest extends PeopleHttpCase
{
    private const PASSWORD = 'E2E-People-Password-42!';

    public function test_seed_people_browser_fixture_only(): void
    {
        $unitA = $this->unit();
        $unitB = $this->unit();
        DB::table('organizational_units')->where('id', $unitA)->update(['name' => 'Congregação E2E A', 'code' => 'E2E-PEOPLE-A']);
        DB::table('organizational_units')->where('id', $unitB)->update(['name' => 'Congregação E2E B', 'code' => 'E2E-PEOPLE-B']);
        $manager = $this->account(['PEOPLE_VIEW', 'PEOPLE_CREATE', 'PEOPLE_EDIT', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_CONTACT_MANAGE', 'PEOPLE_ADDRESS_MANAGE', 'HOUSEHOLD_VIEW', 'HOUSEHOLD_MANAGE', 'RELATIONSHIP_MANAGE', 'PEOPLE_EXPORT'], $unitA, 'pessoas.gestor.e2e');
        $viewer = $this->account(['PEOPLE_VIEW', 'HOUSEHOLD_VIEW'], $unitA, 'pessoas.consulta.e2e');
        $wrong = $this->account(['PEOPLE_VIEW', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_EDIT', 'HOUSEHOLD_VIEW'], $unitB, 'pessoas.outra.e2e');

        $type = $this->row('territorial_area_types', ['code' => 'E2E_AREA', 'name' => 'Área E2E']);
        $province = $this->row('territorial_areas', ['area_type_id' => $type, 'parent_id' => null, 'code' => 'E2E-PROV', 'name' => 'Província E2E']);
        $this->row('territorial_areas', ['area_type_id' => $type, 'parent_id' => $province, 'code' => 'E2E-MUN', 'name' => 'Município E2E']);

        $people = [
            'base' => $this->personAt($unitA, ['full_name' => 'Pesquisa E2E Base', 'birth_precision' => 'EXACT', 'birth_date' => '1984-02-14']),
            'sensitive' => $this->personAt($unitA, ['full_name' => 'Classe B E2E', 'birth_precision' => 'EXACT', 'birth_date' => '1979-11-03']),
            'spouse_a' => $this->personAt($unitA, ['full_name' => 'Cônjuge E2E Ana']),
            'spouse_b' => $this->personAt($unitA, ['full_name' => 'Cônjuge E2E Bento']),
            'parent' => $this->personAt($unitA, ['full_name' => 'Progenitor E2E Carlos']),
            'child' => $this->personAt($unitA, ['full_name' => 'Descendente E2E Daniel']),
            'family_a' => $this->personAt($unitA, ['full_name' => 'Família E2E Elsa']),
            'family_b' => $this->personAt($unitA, ['full_name' => 'Família E2E Filipe']),
            'family_c' => $this->personAt($unitA, ['full_name' => 'Família E2E Graça']),
            'stale' => $this->personAt($unitA, ['full_name' => 'Concorrência E2E Hugo']),
            'mobile' => $this->personAt($unitA, ['full_name' => 'Telemóvel E2E Inês']),
            'minor' => $this->personAt($unitA, ['full_name' => 'Menor Protegido E2E', 'birth_precision' => 'EXACT', 'birth_date' => now('Africa/Luanda')->subYears(10)->format('Y-m-d')]),
            'foreign' => $this->personAt($unitB, ['full_name' => 'Pesquisa E2E Outra Unidade']),
        ];
        // Visual QA household.
        $householdId = $this->row('households', ['public_id' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'AGR-E2E-VISUAL', 'name' => 'Família Visual E2E', 'address_id' => null, 'status' => 'ACTIVE', 'lock_version' => 0]);
        $reference = (int) DB::table('household_role_types')->where('code', 'REFERENCE_PERSON')->value('id');
        $this->row('household_members', ['household_id' => $householdId, 'person_id' => $people['base']['id'], 'role_type_id' => $reference, 'status' => 'ACTIVE', 'starts_at' => now('UTC')->subDay()->format('Y-m-d H:i:s.u'), 'ends_at' => null, 'lock_version' => 0]);

        $expiredToken = 'e2e-people-expired-' . bin2hex(random_bytes(16));
        DB::table('auth_sessions')->insert(['user_id' => $manager['user'], 'token_hash' => hash('sha256', $expiredToken, true), 'expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u'), 'revoked_at' => null, 'ip_hash' => null, 'device_id' => null, 'created_at' => now('UTC')->subHours(2)->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);

        $manifest = [
            'password' => self::PASSWORD,
            'accounts' => ['manager' => $manager, 'viewer' => $viewer, 'wrong_scope' => $wrong],
            'people' => array_map(fn (array $p) => $p['public_id'], $people),
            'household' => (string) DB::table('households')->where('id', $householdId)->value('public_id'),
            'territory' => ['province' => 'E2E-PROV', 'municipality' => 'E2E-MUN'],
            'user_ids' => [$manager['user'], $viewer['user'], $wrong['user']],
        ];
        $dir = dirname(__DIR__, 4) . '/docs/reviews/evidence/P0.5-I';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        self::assertFileExists($dir . '/fixtures.json');
    }

    private function account(array $permissions, int $unit, string $login): array
    {
        $staff = $this->staff($permissions, $unit);
        $hasher = new BcryptHasher(['rounds' => 4]);
        DB::table('users')->where('id', $staff['user'])->update(['login' => $login, 'password_hash' => $hasher->make(self::PASSWORD), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $expires = now('UTC')->addHours(8)->format('Y-m-d H:i:s.u');
        DB::table('auth_sessions')->where('id', $staff['session'])->update(['expires_at' => $expires]);
        return [
            'login' => $login,
            'user' => $staff['user'],
            'user_public_id' => (string) DB::table('users')->where('id', $staff['user'])->value('public_id'),
            'browser_token' => $staff['token'],
            'expires_at' => $expires,
            'unit' => $this->publicUnit($unit),
        ];
    }
}
