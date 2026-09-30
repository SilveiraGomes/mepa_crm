<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\Files\FilesCatalog;
use App\Domain\Membership\MembershipCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * P0.9 Membership HTTP test base (Test Infrastructure V2 Wave 5 pool). Reuses the Territorial base (canonical unit types,
 * staff grants on UNIT scopes) and installs the ADR-0020 catalog after the TRUNCATE reset exactly as migration
 * 2026_09_30_000003 does (the MEPA_NATIONAL counter is re-created with last_value 0 only because the reset emptied it).
 * People, units, grants and legal documents are synthetic fixtures; Membership never creates a Person.
 */
abstract class MembershipHttpCase extends TerritorialHttpCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FilesCatalog::install(DB::connection());
        MembershipCatalog::install(DB::connection());
        if (!DB::table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->exists()) {
            $this->row('organizational_structure_lock', ['code' => 'NATIONAL_TREE']);
        }
        if ((string) config('app.key') === '') {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    protected function membershipPermissions(): array
    {
        return array_keys(MembershipCatalog::PERMISSIONS);
    }

    /** Full Membership staff on $unit (with descendants) + People view (D01.2) + optional extra permissions. */
    protected function secretary(int $unit, array $extra = [], bool $descendants = true): array
    {
        return $this->staff(array_merge($this->membershipPermissions(), ['PEOPLE_VIEW'], $extra), $unit, $descendants);
    }

    /** direction -> centers A/B -> congregations A1, A2 (under A) and B1 (under B), all ACTIVE. */
    protected function world(): array
    {
        $g = $this->unit('GENERAL_DIRECTION', null, 'ACTIVE', 'Direcção Geral P09');
        $r = $this->unit('REGIONAL_DIRECTION', $g['id'], 'ACTIVE', 'Região P09');
        $p = $this->unit('PROVINCIAL_DIRECTION', $r['id'], 'ACTIVE', 'Província P09');
        $m = $this->unit('MUNICIPAL_DIRECTION', $p['id'], 'ACTIVE', 'Município P09');
        $a = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro A');
        $b = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro B');
        $a1 = $this->unit('CONGREGATION', $a['id'], 'ACTIVE', 'Congregação A1');
        $a2 = $this->unit('CONGREGATION', $a['id'], 'ACTIVE', 'Congregação A2');
        $b1 = $this->unit('CONGREGATION', $b['id'], 'ACTIVE', 'Congregação B1');
        return compact('g', 'r', 'p', 'm', 'a', 'b', 'a1', 'a2', 'b1');
    }

    /** Existing adult Person with an ONBOARDING People context at $unit (Membership never creates a Person). */
    protected function person(array $unit, ?string $name = null, string $status = 'ACTIVE'): array
    {
        $statusId = (int) DB::table('person_statuses')->where('code', $status)->value('id');
        $id = $this->row('people', ['full_name' => $name ?? 'Candidato ' . Str::random(8), 'status_id' => $statusId, 'birth_date' => '1990-05-01', 'birth_precision' => 'EXACT', 'lock_version' => 0]);
        $at = now('UTC')->subHour()->format('Y-m-d H:i:s.u');
        DB::table('person_unit_contexts')->insert(['person_id' => $id, 'unit_id' => $unit['id'], 'context_kind' => 'ONBOARDING', 'status' => 'ACTIVE', 'starts_at' => $at, 'ends_at' => null, 'reason' => null, 'source_document_id' => null, 'created_at' => $at, 'lock_version' => 0]);
        return ['id' => $id, 'public_id' => (string) DB::table('people')->where('id', $id)->value('public_id')];
    }

    protected function submit(array $actor, array $person, array $congregation, array $extra = []): TestResponse
    {
        return $this->api($actor, 'POST', 'memberships/admissions', ['person_public_id' => $person['public_id'], 'congregation_public_id' => $congregation['public_id']] + $extra);
    }

    /** SUBMITTED membership; returns the detail payload. */
    protected function candidate(array $actor, array $congregation, array $extra = []): array
    {
        return $this->submit($actor, $this->person($congregation), $congregation, $extra)->assertCreated()->json('data');
    }

    protected function validated(array $actor, array $congregation): array
    {
        $c = $this->candidate($actor, $congregation);
        return $this->api($actor, 'POST', 'memberships/' . $c['public_id'] . '/validate', ['lock_version' => $c['lock_version']])->assertOk()->json('data');
    }

    protected function member(array $actor, array $congregation): array
    {
        $v = $this->validated($actor, $congregation);
        return $this->api($actor, 'POST', 'memberships/' . $v['public_id'] . '/approve', ['lock_version' => $v['lock_version']])->assertOk()->json('data');
    }

    protected function detail(array $actor, string $membership): array
    {
        return $this->api($actor, 'GET', 'memberships/' . $membership)->assertOk()->json('data');
    }

    protected function membershipId(string $publicId): int
    {
        return (int) DB::table('memberships')->where('public_id', $publicId)->value('id');
    }

    protected function openPeriods(string $publicId): array
    {
        return DB::table('membership_periods')->where('membership_id', $this->membershipId($publicId))->whereNull('ends_at')->get()->all();
    }

    protected function numberOf(string $publicId): ?string
    {
        $value = DB::table('member_numbers')->where('membership_id', $this->membershipId($publicId))->value('number');
        return $value === null ? null : (string) $value;
    }

    protected function counter(): int
    {
        return (int) DB::table('member_number_sequences')->where('code', 'MEPA_NATIONAL')->value('last_value');
    }

    /** Raw ACTIVE legal document with one RESTRICTED version owned by $unit (Files fixture, no storage involved). */
    protected function document(array $unit, string $classification = 'RESTRICTED', string $status = 'ACTIVE'): array
    {
        $type = (int) DB::table('legal_document_types')->where('code', 'MINUTES')->value('id');
        $doc = $this->row('legal_documents', ['document_type_id' => $type, 'owner_unit_id' => $unit['id'], 'reference' => 'ACTA-' . Str::random(6), 'title' => 'Acta de admissão', 'status' => $status]);
        $file = $this->row('files', ['owner_unit_id' => $unit['id'], 'owner_department_id' => null, 'classification' => $classification, 'disk' => 'files_private', 'storage_key' => 'v1/test/' . Str::random(20), 'original_name' => 'acta.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum' => random_bytes(32), 'status' => 'AVAILABLE']);
        $this->row('document_versions', ['document_id' => $doc, 'version' => 1, 'file_id' => $file, 'supersedes_id' => null]);
        return ['id' => $doc, 'public_id' => (string) DB::table('legal_documents')->where('id', $doc)->value('public_id')];
    }

    protected function assertConcealed(TestResponse $response): void
    {
        $response->assertStatus(404)->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']]);
    }

    /** No primary key anywhere in a payload. */
    protected function assertNoInternalIds(array $payload): void
    {
        array_walk_recursive($payload, function ($value, $key): void {
            if (is_string($key)) {
                $this->assertFalse($key === 'id' || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')), 'internal id exposed: ' . $key);
            }
        });
    }

    /** Global invariants over every membership in the pool (the validator's SQL). */
    protected function assertGlobalInvariants(): void
    {
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT membership_id FROM membership_periods WHERE ends_at IS NULL GROUP BY membership_id HAVING COUNT(*) <> 1) x')->n, 'exactly one open period');
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM memberships m WHERE NOT EXISTS (SELECT 1 FROM membership_periods mp WHERE mp.membership_id = m.id AND mp.ends_at IS NULL)')->n, 'every membership has an open period');
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM memberships m JOIN membership_periods mp ON mp.membership_id = m.id AND mp.ends_at IS NULL WHERE mp.status_id <> m.status_id')->n, 'status copy');
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM memberships m JOIN membership_statuses s ON s.id = m.status_id LEFT JOIN member_numbers n ON n.membership_id = m.id WHERE (s.code IN ('SUBMITTED','VALIDATED','REJECTED','WITHDRAWN') AND n.id IS NOT NULL) OR (s.code IN ('ACTIVE','INACTIVE','ENDED') AND n.id IS NULL)")->n, 'number iff approved');
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT number FROM member_numbers GROUP BY number HAVING COUNT(*) > 1) x')->n, 'unique numbers');
        $max = (int) DB::selectOne('SELECT COALESCE(MAX(sequence_value), 0) AS n FROM member_numbers')->n;
        $this->assertSame($max, $this->counter(), 'counter equals highest sequence');
        $this->assertSame((int) DB::selectOne('SELECT COUNT(*) AS n FROM member_numbers')->n, $max === 0 ? 0 : (int) DB::selectOne('SELECT COUNT(DISTINCT sequence_value) AS n FROM member_numbers')->n);
    }
}
