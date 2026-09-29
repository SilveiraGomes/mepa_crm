<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\People\PeopleCrypto;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/** P0.5-I block 7: sensitive projections and exports (STANDARD / SENSITIVE / CLASS_C), scope-bounded and audited. */
final class PeopleExportTest extends PeopleHttpCase
{
    private function lines(string $csv): array
    {
        return array_map(fn (string $l) => str_getcsv($l, ';'), array_values(array_filter(explode("\r\n", ltrim($csv, "\u{FEFF}")))));
    }

    public function test_standard_export_is_scope_bounded_never_national_and_audited(): void
    {
        $unit = $this->unit();
        $inside = $this->personAt($unit, ['full_name' => 'Exportável Dentro']);
        $outside = $this->personAt($this->unit(), ['full_name' => 'Exportável Fora']);
        $exporter = $this->staff(['PEOPLE_VIEW', 'PEOPLE_EXPORT'], $unit);
        $result = $this->api($exporter, 'POST', 'people/exports', ['class' => 'STANDARD', 'search' => 'Exportável'])->assertCreated()->json('data');
        $lines = $this->lines($result['csv']);
        self::assertSame(['public_id', 'nome', 'estado', 'precisao_nascimento', 'faixa_etaria'], $lines[0]);
        self::assertSame([$inside['public_id']], array_column(array_slice($lines, 1), 0));
        self::assertStringNotContainsString($outside['public_id'], $result['csv']);
        self::assertSame(1, $result['row_count']);
        $audit = DB::table('audit_logs')->where('action', 'PEOPLE_EXPORTED')->orderByDesc('id')->first();
        self::assertSame($unit, (int) $audit->unit_id);
        self::assertSame('STANDARD', json_decode($audit->after_metadata, true)['class']);
        self::assertSame(1, json_decode($audit->after_metadata, true)['row_count']);
        self::assertStringNotContainsString('Exportável', (string) $audit->after_metadata);
        $this->api($this->staff(['PEOPLE_VIEW'], $unit), 'POST', 'people/exports', ['class' => 'STANDARD'])->assertStatus(403);
    }

    public function test_class_b_export_needs_sensitive_view_and_minimizes_minors(): void
    {
        $unit = $this->unit();
        $adult = $this->personAt($unit, ['full_name' => 'Adulto Classe B', 'birth_precision' => 'EXACT', 'birth_date' => '1975-06-01']);
        $minor = $this->personAt($unit, ['full_name' => 'Menor Classe B', 'birth_precision' => 'EXACT', 'birth_date' => now('Africa/Luanda')->subYears(12)->format('Y-m-d')]);
        $manager = $this->staff(['PEOPLE_VIEW', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_CONTACT_MANAGE', 'PEOPLE_EXPORT'], $unit);
        $this->api($manager, 'POST', 'people/' . $adult['public_id'] . '/contacts', ['type' => 'EMAIL', 'value' => 'adulto@exemplo.ao', 'is_primary' => true])->assertCreated();
        $plain = $this->staff(['PEOPLE_VIEW', 'PEOPLE_EXPORT'], $unit);
        $this->api($plain, 'POST', 'people/exports', ['class' => 'SENSITIVE'])->assertStatus(403);
        $csv = $this->api($manager, 'POST', 'people/exports', ['class' => 'SENSITIVE', 'search' => 'Classe B'])->assertCreated()->json('data.csv');
        $rows = collect(array_slice($this->lines($csv), 1))->keyBy(0);
        self::assertSame('1975-06-01', $rows[$adult['public_id']][5]);
        self::assertSame('adulto@exemplo.ao', $rows[$adult['public_id']][9]);
        self::assertSame(['', '', '', '', ''], array_slice($rows[$minor['public_id']], 5), 'protected minor gets no Class B columns');
    }

    public function test_class_c_export_requires_separate_permission_and_reason(): void
    {
        $unit = $this->unit();
        $person = $this->personAt($unit, ['full_name' => 'Documento Classe C']);
        $crypto = PeopleCrypto::fromKeyRingFile((string) config('people.keyring_path'), []);
        [$ciphertext, $version] = $crypto->encrypt('BI-000123LA045', 'mepa.people.document.number.v1|' . $person['public_id']);
        $type = $this->row('identity_document_types', ['code' => 'SYN_BI_' . bin2hex(random_bytes(3))]);
        $this->row('person_documents', ['person_id' => $person['id'], 'document_type_id' => $type, 'issuer_country' => 'AGO', 'number_ciphertext' => $ciphertext, 'number_blind_index' => $crypto->blindIndex('doc:BI-000123LA045'), 'key_version' => $version, 'file_id' => null]);

        $exportOnly = $this->staff(['PEOPLE_VIEW', 'PEOPLE_EXPORT', 'PEOPLE_SENSITIVE_VIEW'], $unit);
        $denied = $this->api($exportOnly, 'POST', 'people/exports', ['class' => 'CLASS_C', 'reason' => 'Auditoria interna'])->assertStatus(403);
        self::assertSame('FORBIDDEN', $denied->json('error.code'));
        self::assertStringNotContainsString('BI-000123', (string) $denied->getContent());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'PEOPLE_EXPORTED')->where('actor_id', $exportOnly['user'])->count());

        $classC = $this->staff(['PEOPLE_VIEW', 'PEOPLE_EXPORT', 'PEOPLE_EXPORT_CLASS_C'], $unit);
        $this->api($classC, 'POST', 'people/exports', ['class' => 'CLASS_C'])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $csv = $this->api($classC, 'POST', 'people/exports', ['class' => 'CLASS_C', 'reason' => 'Pedido formal de auditoria', 'search' => 'Classe C'])->assertCreated()->json('data.csv');
        self::assertStringContainsString('BI-000123LA045', $csv);
        $audit = DB::table('audit_logs')->where('action', 'PEOPLE_EXPORTED')->where('actor_id', $classC['user'])->first();
        self::assertSame('Pedido formal de auditoria', $audit->reason);
        self::assertStringNotContainsString('BI-000123', (string) $audit->after_metadata);
        $classCElsewhere = $this->staff(['PEOPLE_EXPORT', 'PEOPLE_EXPORT_CLASS_C']);
        $other = $this->api($classCElsewhere, 'POST', 'people/exports', ['class' => 'CLASS_C', 'reason' => 'Outra unidade'])->assertCreated()->json('data.csv');
        self::assertStringNotContainsString('BI-000123', $other);
    }

    public function test_export_size_is_bounded_and_formula_cells_are_neutralised(): void
    {
        $unit = $this->unit();
        $this->personAt($unit, ['full_name' => '=HYPERLINK("http://x")']);
        $exporter = $this->staff(['PEOPLE_EXPORT'], $unit);
        $csv = $this->api($exporter, 'POST', 'people/exports', ['class' => 'STANDARD'])->assertCreated()->json('data.csv');
        self::assertStringContainsString('"\'=HYPERLINK', $csv);
        config(['people.export_max_rows' => 0]);
        $this->api($exporter, 'POST', 'people/exports', ['class' => 'STANDARD'])->assertStatus(422)->assertJsonPath('error.code', 'EXPORT_TOO_LARGE');
    }
}
