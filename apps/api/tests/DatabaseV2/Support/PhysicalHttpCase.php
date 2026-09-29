<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\Physical\PhysicalCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * P0.7 Physical Locations HTTP test base (Test Infrastructure V2 Wave 5 pool). Reuses the Territorial base (canonical
 * unit types/parent rules, staff grants on UNIT scopes) and installs the ADR-0018 controlled catalog after the
 * TRUNCATE reset exactly as migration 2026_09_29_000002 does. The key ring is generated per process in the system
 * temp directory (never in the repository); units, actors and People are synthetic fixtures.
 */
abstract class PhysicalHttpCase extends TerritorialHttpCase
{
    protected const LINE1 = 'Rua da Missão Evangélica 12';
    protected const LOCALITY = 'Bairro Esperança';
    protected static ?string $keyring = null;

    protected function setUp(): void
    {
        parent::setUp();
        PhysicalCatalog::install(DB::connection());
        if (self::$keyring === null) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-physical-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($dir, 0700, true);
            self::$keyring = $dir . DIRECTORY_SEPARATOR . 'keyring.json';
            file_put_contents(self::$keyring, json_encode(['active_version' => 1, 'keys' => ['1' => ['encryption' => base64_encode(random_bytes(32)), 'blind_index' => base64_encode(random_bytes(32))]]], JSON_THROW_ON_ERROR));
        }
        // The E2E runner passes its own out-of-repository ring so the browser server can decrypt the fixture data.
        $ring = getenv('P07_E2E_KEYRING_PATH') ?: self::$keyring;
        config(['physical.keyring_path' => $ring, 'people.keyring_path' => $ring]);
        if ((string) config('app.key') === '') {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    protected function physicalPermissions(): array
    {
        return array_keys(PhysicalCatalog::PERMISSIONS);
    }

    /** direction -> center A (congregation A1), center B, all DRAFT but operational (not CLOSED). */
    protected function world(): array
    {
        $g = $this->unit('GENERAL_DIRECTION', null, 'ACTIVE', 'Direcção Geral P07');
        $r = $this->unit('REGIONAL_DIRECTION', $g['id'], 'ACTIVE', 'Região P07');
        $p = $this->unit('PROVINCIAL_DIRECTION', $r['id'], 'ACTIVE', 'Província P07');
        $m = $this->unit('MUNICIPAL_DIRECTION', $p['id'], 'ACTIVE', 'Município P07');
        $a = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro A');
        $b = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro B');
        $c = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro C');
        $a1 = $this->unit('CONGREGATION', $a['id'], 'ACTIVE', 'Congregação A1');
        return compact('g', 'r', 'p', 'm', 'a', 'b', 'c', 'a1');
    }

    protected function locationBody(array $unit, array $overrides = []): array
    {
        return array_replace_recursive([
            'unit_public_id' => $unit['public_id'],
            'name' => 'Local ' . Str::random(6),
            'address' => ['country_code' => 'AO', 'line1' => self::LINE1, 'locality' => self::LOCALITY],
            'latitude' => -8.838333,
            'longitude' => 13.234444,
            'occupation_type_code' => 'OWNED',
        ], $overrides);
    }

    /** @return array{public_id: string, id: int, data: array} */
    protected function location(array $actor, array $unit, array $overrides = []): array
    {
        $response = $this->api($actor, 'POST', 'physical/locations', $this->locationBody($unit, $overrides))->assertCreated();
        $public = (string) $response->json('data.public_id');
        return ['public_id' => $public, 'id' => (int) DB::table('physical_locations')->where('public_id', $public)->value('id'), 'data' => $response->json('data')];
    }

    protected function links(array $actor, string $location, bool $history = false): array
    {
        return $this->api($actor, 'GET', 'physical/locations/' . $location . '/links' . ($history ? '?history=1' : ''))->assertOk()->json('data');
    }

    protected function linkFor(array $actor, string $location, string $unitPublic): array
    {
        foreach ($this->links($actor, $location) as $link) {
            if (($link['unit']['public_id'] ?? null) === $unitPublic) {
                return $link;
            }
        }
        self::fail('active link not found for unit ' . $unitPublic);
    }

    protected function detail(array $actor, string $location): array
    {
        return $this->api($actor, 'GET', 'physical/locations/' . $location)->assertOk()->json('data');
    }

    protected function activeLinks(int $location): int
    {
        return DB::table('unit_location_links')->where('location_id', $location)->where('status', 'ACTIVE')->count();
    }

    protected function primaries(int $unit): int
    {
        return DB::table('unit_location_links')->where('unit_id', $unit)->where('status', 'ACTIVE')->where('is_primary', 1)->count();
    }

    protected function counts(): array
    {
        return [
            'addresses' => DB::table('addresses')->count(),
            'physical_locations' => DB::table('physical_locations')->count(),
            'unit_location_links' => DB::table('unit_location_links')->count(),
            'audit_logs' => DB::table('audit_logs')->where('source', 'P07_PHYSICAL')->count(),
        ];
    }

    protected function assertConcealed(TestResponse $response): void
    {
        $response->assertStatus(404)->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']]);
    }

    /** Asserts no numeric key or stored secret is present anywhere in a payload. */
    protected function assertNoInternalIds(array $payload): void
    {
        array_walk_recursive($payload, function ($value, $key): void {
            if (is_string($key)) {
                $this->assertFalse($key === 'id' || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')), 'internal id exposed: ' . $key);
                $this->assertNotContains($key, ['line1_ciphertext', 'key_version', 'source_document_id', 'owner_name_external'], 'secret field exposed: ' . $key);
            }
        });
    }
}
