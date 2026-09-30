<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Files\FileClassification;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesError;
use App\Domain\Files\FilesMaintenance;
use App\Domain\Files\FilesReason;
use App\Domain\Files\FileScanner;
use App\Domain\Files\Mepaf1;
use App\Http\Files\FilesServiceFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\FilesHttpCase;

/**
 * P0.8-I Documents / Files (ADR 0019), HTTP level against an isolated Wave 5 pool, with a private storage root and a
 * Files key ring in the OS temp directory. F01-F21 of the P0.8-I brief plus guards used by the mutation probes.
 * Each test builds its own world so any test can run alone (mutation probes run filtered subsets).
 */
final class FilesVerticalTest extends FilesHttpCase
{
    // ---- F01 ---------------------------------------------------------------------------------------------------

    public function test_f01_valid_pdf_upload_becomes_available_encrypted_in_private_storage(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $marker = 'F01-PLAINTEXT-MARKER-' . Str::random(8);
        $pdf = self::pdf($marker);
        $response = $this->upload($staff, $w['a'], 'Acta da reunião.pdf', $pdf)->assertCreated();
        $data = $response->json('data');
        $this->assertNoInternals($data);
        $this->assertSame('AVAILABLE', $data['status']);
        $this->assertSame('RESTRICTED', $data['classification'], 'default upload classification');
        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertSame('STRUCTURAL', $data['inspection']);
        $this->assertSame([], $data['warnings']);
        $row = DB::table('files')->where('public_id', $data['public_id'])->first();
        $this->assertSame('files_private', $row->disk);
        $this->assertMatchesRegularExpression('#^v1/\d{4}/\d{2}/[0-9a-f]{32}\.bin$#', $row->storage_key);
        $this->assertStringNotContainsString('Acta', $row->storage_key);
        $this->assertStringNotContainsString($row->public_id, $row->storage_key);
        $this->assertSame(1, (int) $row->key_version);
        $this->assertNull($row->owner_department_id);
        $this->assertSame($staff['user'], (int) $row->created_by);
        $this->assertSame(hash('sha256', $pdf, true), $row->checksum);
        $object = (string) file_get_contents($this->objectPath((int) $row->id));
        $this->assertStringStartsWith('MEPAF1', $object);
        $this->assertStringNotContainsString($marker, $object, 'no plaintext at rest');
        $this->assertStringNotContainsString('%PDF', $object);
        $this->assertSame(0, $this->stagingCount());
        $this->assertFalse(str_starts_with(realpath($this->root()), realpath(public_path())), 'storage root outside public');
        $this->assertCount(1, $this->audits('file.uploaded', (int) $row->id));
        $available = $this->audits('file.available', (int) $row->id);
        $this->assertCount(1, $available);
        $this->assertSame('STRUCTURAL', json_decode($available[0]->after_metadata, true)['inspection']);
        $this->assertSame($w['a']['id'], (int) $available[0]->unit_id);
        // Detail and list show metadata only.
        $detail = $this->api($staff, 'GET', 'files/' . $data['public_id'])->assertOk()->json('data');
        $this->assertNoInternals($detail);
        $this->assertTrue($detail['actions']['download']);
        $list = $this->api($staff, 'GET', 'files')->assertOk()->json();
        $this->assertSame(1, $list['meta']['total']);
        $this->assertNoInternals($list);
    }

    // ---- F02 ---------------------------------------------------------------------------------------------------

    public function test_f02_images_are_reencoded_and_exif_removed(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $jpeg = self::jpegWithExif();
        $this->assertStringContainsString('GPS-LAT', $jpeg);
        $file = $this->uploaded($staff, $w['a'], 'foto.JPG', $jpeg);
        $this->assertSame('image/jpeg', $file['data']['mime_type']);
        $stored = $this->download($staff, 'files/' . $file['public_id'] . '/content')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xFF\xD8\xFF", $stored);
        $this->assertStringNotContainsString('GPS-LAT', $stored, 'EXIF/GPS removed');
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertNotSame($jpeg, $stored, 're-encoded');
        $this->assertSame(hash('sha256', $stored, true), DB::table('files')->where('id', $file['id'])->value('checksum'), 'checksum of the final (re-encoded) content');
        $png = self::png();
        $pngFile = $this->uploaded($staff, $w['a'], 'imagem.png', $png);
        $storedPng = $this->download($staff, 'files/' . $pngFile['public_id'] . '/content')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('POLYGLOT-TAIL', $storedPng, 'trailing polyglot payload dropped');
        $this->assertSame((int) DB::table('files')->where('id', $pngFile['id'])->value('size_bytes'), strlen($storedPng));
    }

    // ---- F03 ---------------------------------------------------------------------------------------------------

    public function test_f03_forbidden_types_are_rejected_to_a_purged_tombstone(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $cases = [
            ['setup.exe', "MZ\x90\x00" . random_bytes(64), 'FILE_TYPE_NOT_ALLOWED', 'EXECUTABLE_CONTENT'],
            ['script.sh', "#!/bin/sh\nrm -rf /\n", 'FILE_TYPE_NOT_ALLOWED', 'EXECUTABLE_CONTENT'],
            ['pagina.html', '<!doctype html><script>alert(1)</script>', 'FILE_TYPE_NOT_ALLOWED', 'MARKUP_CONTENT'],
            ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'FILE_TYPE_NOT_ALLOWED', 'MARKUP_CONTENT'],
            ['pacote.zip', "PK\x03\x04" . random_bytes(40), 'FILE_TYPE_NOT_ALLOWED', 'ARCHIVE_CONTENT'],
            ['relatorio.docx', "PK\x03\x04" . random_bytes(40), 'FILE_TYPE_NOT_ALLOWED', 'ARCHIVE_CONTENT'],
            ['relatorio.php.jpg', self::jpegWithExif(), 'FILE_TYPE_NOT_ALLOWED', 'DOUBLE_EXTENSION'],
            ['acta.pdf.exe', self::pdf(), 'FILE_TYPE_NOT_ALLOWED', 'DOUBLE_EXTENSION'],
            ['activo.pdf', self::pdf() . "<< /S /JavaScript /JS (app.alert(1)) >>", 'FILE_CONTENT_REJECTED', 'PDF_ACTIVE_CONTENT'],
            ['cifrado.pdf', self::pdf() . "trailer << /Encrypt 9 0 R >>", 'FILE_CONTENT_REJECTED', 'PDF_ENCRYPTED'],
            ['lancar.pdf', self::pdf() . "/OpenAction << /S /Launch /F (cmd.exe) >>", 'FILE_CONTENT_REJECTED', 'PDF_ACTIVE_CONTENT'],
        ];
        foreach ($cases as [$name, $bytes, $code, $reason]) {
            $response = $this->upload($staff, $w['a'], $name, $bytes)->assertStatus(422)->assertJsonPath('error.code', $code)->assertJsonPath('error.details.reason_code', $reason)->assertJsonPath('error.details.status', 'PURGED');
            $public = (string) $response->json('error.details.file_public_id');
            $row = DB::table('files')->where('public_id', $public)->first();
            $this->assertNotNull($row, $name);
            $this->assertSame('PURGED', $row->status, $name);
            $this->assertNotNull($row->purged_at);
            $this->assertFileDoesNotExist($this->objectPath((int) $row->id), 'object destroyed: ' . $name);
            $rejected = $this->audits('file.security_rejected', (int) $row->id);
            $this->assertCount(1, $rejected);
            $this->assertSame($reason, json_decode($rejected[0]->after_metadata, true)['reason_code']);
            $this->assertCount(0, $this->audits('file.available', (int) $row->id));
            $this->assertConcealed($this->api($staff, 'GET', 'files/' . $public));
            $this->assertConcealed($this->download($staff, 'files/' . $public . '/content'));
        }
        $this->assertSame(0, $this->stagingCount());
        $this->assertSame(0, DB::table('files')->where('owner_unit_id', $w['a']['id'])->where('status', 'AVAILABLE')->count());
        // A PURGED tombstone cannot be restored or reused: no operation leads out of PURGED.
        $purged = (string) DB::table('files')->where('owner_unit_id', $w['a']['id'])->where('status', 'PURGED')->value('public_id');
        $this->assertConcealed($this->api($staff, 'POST', 'files/' . $purged . '/restore', ['reason' => 'tentar', 'lock_version' => 1]));
    }

    // ---- F04 ---------------------------------------------------------------------------------------------------

    public function test_f04_mime_signature_mismatch_is_rejected(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        foreach ([['foto.png', self::jpegWithExif()], ['doc.pdf', "texto simples que não é PDF\n"], ['foto.jpg', self::pdf()], ['imagem.webp', self::png()]] as [$name, $bytes]) {
            $this->upload($staff, $w['a'], $name, $bytes)->assertStatus(422)->assertJsonPath('error.code', 'FILE_TYPE_NOT_ALLOWED')->assertJsonPath('error.details.reason_code', 'SIGNATURE_MISMATCH');
        }
        $this->assertSame(0, DB::table('files')->where('owner_unit_id', $w['a']['id'])->where('status', '!=', 'PURGED')->count());
        // The client-declared MIME type is ignored: a real PDF declared as image/png is stored as application/pdf.
        $upload = UploadedFile::fake()->createWithContent('declarado.pdf', self::pdf());
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $staff['token'], 'Accept' => 'application/json'])->post('/api/v1/files', ['file' => $upload, 'owner_unit_public_id' => $w['a']['public_id']]);
        $response->assertCreated()->assertJsonPath('data.mime_type', 'application/pdf');
    }

    // ---- F05 ---------------------------------------------------------------------------------------------------

    public function test_f05_oversized_files_and_images_are_rejected(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        config(['files.max_file_bytes' => 2048]);
        $before = DB::table('files')->count();
        $this->upload($staff, $w['a'], 'grande.pdf', self::pdf() . str_repeat('%x', 2000))->assertStatus(422)->assertJsonPath('error.code', 'FILE_TOO_LARGE');
        $this->upload($staff, $w['a'], 'vazio.pdf', '')->assertStatus(422);
        $this->assertSame($before, DB::table('files')->count(), 'no row for an oversized/empty file');
        $this->assertSame(0, $this->stagingCount());
        config(['files.max_file_bytes' => 999999999]);
        $this->assertSame(20971520, (new FilesServiceFactory(DB::connection()))->runtime()->maxFileBytes(), '20 MiB hard ceiling');
        config(['files.max_file_bytes' => 10485760, 'files.image_max_side' => 50]);
        $this->upload($staff, $w['a'], 'larga.png', self::png(120, 10))->assertStatus(422)->assertJsonPath('error.code', 'FILE_CONTENT_REJECTED')->assertJsonPath('error.details.reason_code', 'IMAGE_TOO_LARGE');
    }

    // ---- F06 ---------------------------------------------------------------------------------------------------

    public function test_f06_quota_and_storage_unavailability_create_nothing(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $pdf = self::pdf();
        config(['files.unit_quota_bytes' => (int) (strlen($pdf) * 1.5)]);
        $this->uploaded($staff, $w['a'], 'primeiro.pdf', $pdf);
        $rows = DB::table('files')->count();
        $objects = $this->objectCount();
        $this->upload($staff, $w['a'], 'segundo.pdf', self::pdf())->assertStatus(422)->assertJsonPath('error.code', 'FILES_QUOTA_EXCEEDED');
        $this->assertSame($rows, DB::table('files')->count(), 'no row');
        $this->assertSame($objects, $this->objectCount(), 'no object');
        $this->assertSame(0, $this->stagingCount(), 'no partial object');
        // National quota.
        config(['files.unit_quota_bytes' => 2147483648, 'files.total_quota_bytes' => (string) ((int) DB::table('files')->whereIn('status', ['QUARANTINED', 'AVAILABLE', 'TOMBSTONE'])->sum('size_bytes') + 10)]);
        $this->upload($staff, $w['a'], 'nacional.pdf', self::pdf())->assertStatus(422)->assertJsonPath('error.code', 'FILES_QUOTA_EXCEEDED');
        config(['files.total_quota_bytes' => null, 'files.total_quota_required' => true]);
        $this->upload($staff, $w['a'], 'producao.pdf', self::pdf())->assertStatus(503)->assertJsonPath('error.code', 'FILES_STORAGE_UNAVAILABLE');
        config(['files.total_quota_required' => false]);
        // Disk reserve below the 1 GiB floor / insufficient space => 503, nothing created.
        config(['files.reserve_bytes' => PHP_INT_MAX >> 1]);
        $this->upload($staff, $w['a'], 'reserva.pdf', self::pdf())->assertStatus(503)->assertJsonPath('error.code', 'FILES_STORAGE_UNAVAILABLE');
        config(['files.reserve_bytes' => 1]);
        $this->assertGreaterThan(0, disk_free_space($this->root()));
        // Storage root missing / inside public / key ring missing => 503, nothing created.
        foreach ([
            ['filesystems.disks.files_private.root' => $this->root() . DIRECTORY_SEPARATOR . 'nao-existe'],
            ['filesystems.disks.files_private.root' => public_path()],
            ['files.keyring_path' => $this->root() . DIRECTORY_SEPARATOR . 'sem-anel.json'],
        ] as $broken) {
            $saved = ['filesystems.disks.files_private.root' => config('filesystems.disks.files_private.root'), 'files.keyring_path' => config('files.keyring_path')];
            config($broken);
            $this->upload($staff, $w['a'], 'indisponivel.pdf', self::pdf())->assertStatus(503)->assertJsonPath('error.code', 'FILES_STORAGE_UNAVAILABLE');
            config($saved);
        }
        $this->assertSame($rows, DB::table('files')->count());
        $this->assertSame(0, DB::table('files')->where('status', 'QUARANTINED')->count());
    }

    // ---- F07 / F-06 ------------------------------------------------------------------------------------------------

    public function test_f07_wrong_scope_malformed_and_unknown_are_the_same_concealed_404(): void
    {
        $w = $this->world();
        $a = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $b = $this->staff($this->filePermissions(), $w['b']['id'], false);
        $file = $this->uploaded($a, $w['a']);
        foreach (['files/' . $file['public_id'], 'files/01ARZ3NDEKTSV4RRFFQ69G5FAV', 'files/not-a-ulid', 'files/' . $file['id']] as $uri) {
            $this->assertConcealed($this->api($b, 'GET', $uri));
            $this->assertConcealed($this->download($b, $uri . '/content'));
            $this->assertConcealed($this->api($b, 'POST', $uri . '/tombstone', ['reason' => 'fora do escopo', 'lock_version' => 1]));
            $this->assertConcealed($this->api($b, 'POST', $uri . '/classification', ['classification' => 'HIGHLY_SENSITIVE', 'lock_version' => 1]));
        }
        $this->assertSame(0, $this->api($b, 'GET', 'files')->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->api($b, 'GET', 'files?unit_public_id=' . $w['a']['public_id'])->assertOk()->json('meta.total'));
        // Upload into an out-of-scope unit: concealed, nothing written.
        $this->assertConcealed($this->upload($b, $w['a'], 'intruso.pdf', self::pdf()));
        // Permission is checked BEFORE the target: without FILES_VIEW even an unknown id is 403, never 404.
        $none = $this->staff([FilesCatalog::FILES_UPLOAD], $w['a']['id'], false);
        $this->api($none, 'GET', 'files/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertStatus(403);
        $this->api($none, 'GET', 'files/' . $file['public_id'])->assertStatus(403);
        $this->download($none, 'files/' . $file['public_id'] . '/content')->assertStatus(403);
        $this->assertCount(0, $this->audits('file.downloaded', $file['id']));
    }

    // ---- F08 ---------------------------------------------------------------------------------------------------

    public function test_f08_insufficient_clearance_is_concealed_and_unknown_classification_fails_closed(): void
    {
        $w = $this->world();
        $cleared = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $base = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $restricted = $this->uploaded($cleared, $w['a'], 'restrito.pdf');
        $confidential = $this->uploaded($cleared, $w['a'], 'confidencial.pdf', null, 'CONFIDENTIAL');
        $sensitive = $this->uploaded($cleared, $w['a'], 'sensivel.pdf', null, 'HIGHLY_SENSITIVE');
        foreach ([$confidential, $sensitive] as $file) {
            $this->assertConcealed($this->api($base, 'GET', 'files/' . $file['public_id']));
            $this->assertConcealed($this->download($base, 'files/' . $file['public_id'] . '/content', 'motivo válido'));
        }
        $this->api($base, 'GET', 'files/' . $restricted['public_id'])->assertOk();
        $list = $this->api($base, 'GET', 'files')->assertOk()->json();
        $this->assertSame(1, $list['meta']['total'], 'items above clearance are absent from lists AND counts');
        $this->assertSame([$restricted['public_id']], array_column($list['data'], 'public_id'));
        $this->assertSame(0, $this->api($base, 'GET', 'files?classification=CONFIDENTIAL')->assertOk()->json('meta.total'));
        // Uploading above one's clearance is refused (actor-only 403, no target involved).
        $this->upload($base, $w['a'], 'acima.pdf', self::pdf(), 'CONFIDENTIAL')->assertStatus(403)->assertJsonPath('error.code', 'CLEARANCE_REQUIRED');
        // Unknown classification requested: 422, never mapped to a default.
        foreach (['SECRET', 'restricted', 'PUBLIC', 'Confidential'] as $bad) {
            $response = $this->upload($cleared, $w['a'], 'mau.pdf', self::pdf(), $bad);
            $this->assertContains($response->status(), [422], $bad);
        }
        // Unknown STORED classification: invisible even to a fully cleared actor (fail closed).
        DB::table('files')->where('id', $restricted['id'])->update(['classification' => 'LEGACY_FREE_TEXT']);
        $this->assertConcealed($this->api($cleared, 'GET', 'files/' . $restricted['public_id']));
        $this->assertConcealed($this->download($cleared, 'files/' . $restricted['public_id'] . '/content'));
        $this->assertNotContains($restricted['public_id'], array_column($this->api($cleared, 'GET', 'files')->json('data'), 'public_id'));
        $this->assertFalse(FileClassification::allows(4, 'LEGACY_FREE_TEXT'));
        $this->assertSame(['INTERNAL', 'RESTRICTED', 'CONFIDENTIAL', 'HIGHLY_SENSITIVE'], array_keys(FileClassification::ORDER));
        $this->assertSame([1, 2, 3, 4], array_values(FileClassification::ORDER));
    }

    // ---- F09 ---------------------------------------------------------------------------------------------------

    public function test_f09_confidential_metadata_and_download_are_audited(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $file = $this->uploaded($staff, $w['a'], 'contrato.pdf', null, 'CONFIDENTIAL');
        $this->api($staff, 'GET', 'files/' . $file['public_id'])->assertOk()->assertJsonPath('data.classification', 'CONFIDENTIAL');
        $read = $this->audits('file.metadata_read', $file['id']);
        $this->assertCount(1, $read);
        $this->assertSame($staff['user'], (int) $read[0]->actor_id);
        $this->download($staff, 'files/' . $file['public_id'] . '/content')->assertOk()->streamedContent();
        $this->assertCount(1, $this->audits('file.downloaded', $file['id']));
        // A RESTRICTED detail is not a sensitive read.
        $plain = $this->uploaded($staff, $w['a'], 'oficio.pdf');
        $this->api($staff, 'GET', 'files/' . $plain['public_id'])->assertOk();
        $this->assertCount(0, $this->audits('file.metadata_read', $plain['id']));
        // Audit rows never carry names, checksum, storage key or content.
        foreach (DB::table('audit_logs')->where('source', 'P08_FILES')->get() as $row) {
            $blob = $row->before_metadata . $row->after_metadata . $row->reason;
            foreach (['contrato', 'oficio', 'v1/', 'files_private', '%PDF', bin2hex((string) DB::table('files')->where('id', $file['id'])->value('checksum'))] as $needle) {
                $this->assertStringNotContainsString($needle, (string) $blob);
            }
        }
    }

    // ---- F10 ---------------------------------------------------------------------------------------------------

    public function test_f10_highly_sensitive_download_requires_a_reason(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $file = $this->uploaded($staff, $w['a'], 'identidade.pdf', null, 'HIGHLY_SENSITIVE');
        $this->download($staff, 'files/' . $file['public_id'] . '/content')->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->download($staff, 'files/' . $file['public_id'] . '/content', ' x ')->assertStatus(422);
        $this->assertCount(0, $this->audits('file.downloaded', $file['id']));
        $this->assertTrue($this->api($staff, 'GET', 'files/' . $file['public_id'])->json('data.actions.download_reason_required'));
        $this->download($staff, 'files/' . $file['public_id'] . '/content', 'Verificação de identidade para processo')->assertOk()->streamedContent();
        $audit = $this->audits('file.downloaded', $file['id']);
        $this->assertCount(1, $audit);
        $this->assertSame('Verificação de identidade para processo', $audit[0]->reason);
        // The browser sends the reason percent-encoded (header values are not UTF-8 safe).
        $this->download($staff, 'files/' . $file['public_id'] . '/content', rawurlencode('Revisão do ofício nº 7'))->assertOk()->streamedContent();
        $this->assertSame('Revisão do ofício nº 7', $this->audits('file.downloaded', $file['id'])[1]->reason);
        $this->download($staff, 'files/' . $file['public_id'] . '/content', '%FF%FE%FD')->assertStatus(422);
    }

    // ---- F11 ---------------------------------------------------------------------------------------------------

    public function test_f11_download_decrypts_the_exact_bytes_with_safe_headers(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $pdf = self::pdf('F11') . str_repeat('%' . bin2hex(random_bytes(40)) . "\n", 3000);   // several 64 KiB chunks
        $file = $this->uploaded($staff, $w['a'], "Relatório \"final\"; ação.pdf", $pdf);
        $response = $this->download($staff, 'files/' . $file['public_id'] . '/content')->assertOk();
        $this->assertSame($pdf, $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen($pdf), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('Relatório final; ação.pdf'), $disposition);
        $this->assertDoesNotMatchRegularExpression('/filename="[^"]*"[^;]*"/', $disposition);
        // No plaintext copy anywhere under the private root.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS)) as $item) {
            $this->assertStringNotContainsString('%PDF', (string) file_get_contents($item->getPathname()));
        }
    }

    // ---- F12 ---------------------------------------------------------------------------------------------------

    public function test_f12_missing_wrong_or_unknown_key_fails_closed_with_503(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $file = $this->uploaded($staff, $w['a']);
        $original = (string) config('files.keyring_path');
        $alt = $this->root() . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'ring-' . Str::random(6) . '.json';
        $expect = function (string $label) use ($staff, $file): void {
            $response = $this->download($staff, 'files/' . $file['public_id'] . '/content');
            $response->assertStatus(503)->assertJsonPath('error.code', 'FILE_CONTENT_UNAVAILABLE');
            $this->assertStringNotContainsString('%PDF', (string) $response->getContent(), $label);
        };
        config(['files.keyring_path' => $alt]);                       // missing ring
        $expect('missing');
        self::writeRing($alt, [1 => random_bytes(32)], 1);            // wrong key under the same version
        $expect('wrong');
        self::writeRing($alt, [2 => random_bytes(32)], 2);            // unknown key version
        $expect('unknown');
        file_put_contents($alt, '{not json');                         // malformed
        $expect('malformed');
        @unlink($alt);
        config(['files.keyring_path' => $original]);
        $this->assertGreaterThanOrEqual(4, count($this->audits('file.content_unavailable', $file['id'])) + count($this->audits('file.integrity_failure', $file['id'])));
        $this->assertCount(0, $this->audits('file.downloaded', $file['id']));
        $this->download($staff, 'files/' . $file['public_id'] . '/content')->assertOk();
    }

    // ---- F13 ---------------------------------------------------------------------------------------------------

    public function test_f13_tampered_or_truncated_ciphertext_fails_closed(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        foreach (['tamper', 'truncate', 'swap_header', 'missing'] as $mode) {
            $file = $this->uploaded($staff, $w['a'], $mode . '.pdf');
            $path = $this->objectPath($file['id']);
            $bytes = (string) file_get_contents($path);
            match ($mode) {
                'tamper' => file_put_contents($path, substr_replace($bytes, chr(ord($bytes[140]) ^ 0x01), 140, 1)),
                'truncate' => file_put_contents($path, substr($bytes, 0, strlen($bytes) - 7)),
                'swap_header' => file_put_contents($path, substr_replace($bytes, chr(ord($bytes[40]) ^ 0x01), 40, 1)),
                'missing' => unlink($path),
            };
            $response = $this->download($staff, 'files/' . $file['public_id'] . '/content')->assertStatus(503)->assertJsonPath('error.code', 'FILE_CONTENT_UNAVAILABLE');
            $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
            $incident = $mode === 'missing' ? 'file.content_unavailable' : 'file.integrity_failure';
            $this->assertCount(1, $this->audits($incident, $file['id']), $mode);
            $this->assertCount(0, $this->audits('file.downloaded', $file['id']), $mode);
            $this->assertSame('AVAILABLE', DB::table('files')->where('id', $file['id'])->value('status'), 'status never silently changed');
        }
    }

    // ---- F14 ---------------------------------------------------------------------------------------------------

    public function test_f14_tombstone_blocks_download_and_restore_is_audited(): void
    {
        $w = $this->world();
        $manager = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $reader = $this->staff([FilesCatalog::FILES_VIEW, FilesCatalog::FILES_DOWNLOAD], $w['a']['id'], false);
        $file = $this->uploaded($manager, $w['a']);
        $this->api($manager, 'POST', 'files/' . $file['public_id'] . '/tombstone', ['lock_version' => 1])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->api($manager, 'POST', 'files/' . $file['public_id'] . '/tombstone', ['reason' => 'Substituído', 'lock_version' => 0])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $this->api($reader, 'POST', 'files/' . $file['public_id'] . '/tombstone', ['reason' => 'sem permissão', 'lock_version' => 1])->assertStatus(403);
        $tomb = $this->api($manager, 'POST', 'files/' . $file['public_id'] . '/tombstone', ['reason' => 'Substituído por nova acta', 'lock_version' => 1])->assertOk()->json('data');
        $this->assertSame('TOMBSTONE', $tomb['status']);
        $this->assertNotNull(DB::table('files')->where('id', $file['id'])->value('deleted_at'));
        $this->assertFileExists($this->objectPath($file['id']), 'tombstone preserves the object');
        $this->assertConcealed($this->download($manager, 'files/' . $file['public_id'] . '/content'));
        $this->assertConcealed($this->download($reader, 'files/' . $file['public_id'] . '/content'));
        $this->assertConcealed($this->api($reader, 'GET', 'files/' . $file['public_id']));
        $this->assertSame(0, $this->api($reader, 'GET', 'files')->json('meta.total'));
        $this->api($reader, 'GET', 'files?status=TOMBSTONE')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame(1, $this->api($manager, 'GET', 'files?status=TOMBSTONE')->json('meta.total'));
        $this->assertCount(1, $this->audits('file.tombstoned', $file['id']));
        $restored = $this->api($manager, 'POST', 'files/' . $file['public_id'] . '/restore', ['reason' => 'Retirado por engano', 'lock_version' => $tomb['lock_version']])->assertOk()->json('data');
        $this->assertSame('AVAILABLE', $restored['status']);
        $this->assertCount(1, $this->audits('file.restored', $file['id']));
        $this->download($reader, 'files/' . $file['public_id'] . '/content')->assertOk();
        // Restore never makes AVAILABLE a file whose object is gone.
        $tomb = $this->api($manager, 'POST', 'files/' . $file['public_id'] . '/tombstone', ['reason' => 'Retirar de novo', 'lock_version' => $restored['lock_version']])->assertOk()->json('data');
        unlink($this->objectPath($file['id']));
        $this->api($manager, 'POST', 'files/' . $file['public_id'] . '/restore', ['reason' => 'Sem objecto', 'lock_version' => $tomb['lock_version']])->assertStatus(503);
        $this->assertSame('TOMBSTONE', DB::table('files')->where('id', $file['id'])->value('status'));
        // No generic purge/delete: no DELETE route and no route reaching PURGED.
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/files') || str_starts_with($r->uri(), 'api/v1/documents'));
        $this->assertFalse($routes->contains(fn ($r) => in_array('DELETE', $r->methods(), true) || str_contains($r->uri(), 'purge')));
    }

    // ---- F15 ---------------------------------------------------------------------------------------------------

    public function test_f15_version_replacement_preserves_the_previous_version(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $v1 = self::pdf('VERSION-ONE');
        $doc = $this->multipart($staff, 'documents', ['file' => UploadedFile::fake()->createWithContent('acta-v1.pdf', $v1), 'owner_unit_public_id' => $w['a']['public_id'], 'type_code' => 'MINUTES', 'reference' => 'ACTA-2026-01', 'title' => 'Acta da assembleia', 'issued_on' => '2026-09-01'])->assertCreated()->json('data');
        $this->assertNoInternals($doc);
        $this->assertSame(1, $doc['current_version']);
        $this->assertSame('ACTIVE', $doc['status']);
        $first = $doc['versions'][0];
        $firstKey = DB::table('files')->where('public_id', $first['file']['public_id'])->value('storage_key');
        $firstObject = (string) file_get_contents($this->root() . '/' . $firstKey);
        // Stale lock_version and a lower classification are refused.
        $this->multipart($staff, 'documents/' . $doc['public_id'] . '/versions', ['file' => UploadedFile::fake()->createWithContent('v2.pdf', self::pdf()), 'lock_version' => 99])->assertStatus(409)->assertJsonPath('error.code', 'STALE_WRITE');
        $this->multipart($staff, 'documents/' . $doc['public_id'] . '/versions', ['file' => UploadedFile::fake()->createWithContent('v2.pdf', self::pdf()), 'lock_version' => 0, 'classification' => 'INTERNAL'])->assertStatus(409)->assertJsonPath('error.code', 'CLASSIFICATION_BELOW_FLOOR');
        $v2 = self::pdf('VERSION-TWO');
        $after = $this->multipart($staff, 'documents/' . $doc['public_id'] . '/versions', ['file' => UploadedFile::fake()->createWithContent('acta-v2.pdf', $v2), 'lock_version' => 0, 'classification' => 'CONFIDENTIAL'])->assertCreated()->json('data');
        $this->assertSame(2, $after['current_version']);
        $this->assertSame('CONFIDENTIAL', $after['classification'], 'document clearance = highest version classification');
        [$current, $previous] = $after['versions'];
        $this->assertTrue($current['is_current']);
        $this->assertSame(2, $current['version']);
        $this->assertSame($first['public_id'], $current['supersedes_public_id']);
        $this->assertSame($first['public_id'], $previous['public_id']);
        $this->assertNotSame($first['file']['public_id'], $current['file']['public_id'], 'replacement = a new file');
        $this->assertSame($firstKey, DB::table('files')->where('public_id', $first['file']['public_id'])->value('storage_key'));
        $this->assertSame($firstObject, (string) file_get_contents($this->root() . '/' . $firstKey), 'previous object never overwritten');
        $this->assertSame($v1, $this->download($staff, 'documents/' . $doc['public_id'] . '/versions/' . $previous['public_id'] . '/content')->assertOk()->streamedContent());
        $this->assertSame($v2, $this->download($staff, 'documents/' . $doc['public_id'] . '/versions/' . $current['public_id'] . '/content')->assertOk()->streamedContent());
        $row = DB::table('document_versions')->where('public_id', $first['public_id'])->first();
        $this->assertSame(1, (int) $row->version);
        $this->assertSame('2026-09-01', (string) $row->issued_on);
        $created = $this->audits('document.version_created');
        $this->assertCount(2, $created);
        $this->assertSame($first['public_id'], json_decode($created[1]->after_metadata, true)['supersedes_public_id']);
        // A version file is in use: no individual tombstone, no owner change; no reuse of the same file in another version.
        $versionFile = $current['file']['public_id'];
        $lock = (int) DB::table('files')->where('public_id', $versionFile)->value('lock_version');
        $this->api($staff, 'POST', 'files/' . $versionFile . '/tombstone', ['reason' => 'versão', 'lock_version' => $lock])->assertStatus(409)->assertJsonPath('error.code', 'FILE_IN_USE');
        $this->api($staff, 'POST', 'documents/' . $doc['public_id'] . '/versions', ['file_public_id' => $first['file']['public_id'], 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'FILE_IN_USE');
        // Explicit, re-authorized reuse of a loose AVAILABLE file of the same unit becomes version 3.
        $loose = $this->uploaded($staff, $w['a'], 'anexo.pdf', null, 'HIGHLY_SENSITIVE');
        $third = $this->api($staff, 'POST', 'documents/' . $doc['public_id'] . '/versions', ['file_public_id' => $loose['public_id'], 'lock_version' => 1])->assertCreated()->json('data');
        $this->assertSame(3, $third['current_version']);
        $this->assertCount(1, $this->audits('file.attached', $loose['id']));
        // Concurrent editors: version numbers stay unique and monotonic.
        $this->assertSame([1, 2, 3], DB::table('document_versions')->where('document_id', DB::table('legal_documents')->where('public_id', $doc['public_id'])->value('id'))->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
        // Archive / restore.
        $archived = $this->api($staff, 'POST', 'documents/' . $doc['public_id'] . '/archive', ['reason' => 'Arquivo institucional', 'lock_version' => $third['lock_version']])->assertOk()->json('data');
        $this->assertSame('ARCHIVED', $archived['status']);
        $viewer = $this->staff([FilesCatalog::DOCUMENTS_VIEW, FilesCatalog::FILES_DOWNLOAD, FilesCatalog::FILES_HIGHLY_SENSITIVE_ACCESS], $w['a']['id'], false);
        $this->assertConcealed($this->api($viewer, 'GET', 'documents/' . $doc['public_id']));
        $this->assertSame(0, $this->api($viewer, 'GET', 'documents')->json('meta.total'));
        $this->api($staff, 'POST', 'documents/' . $doc['public_id'] . '/versions', ['file' => UploadedFile::fake()->createWithContent('v4.pdf', self::pdf()), 'lock_version' => $archived['lock_version'], 'classification' => 'HIGHLY_SENSITIVE'])->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_ARCHIVED');
        $this->api($staff, 'POST', 'documents/' . $doc['public_id'] . '/restore', ['reason' => 'Reactivado', 'lock_version' => $archived['lock_version']])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame(1, $this->api($viewer, 'GET', 'documents')->json('meta.total'));
        // OTHER requires a descriptive title; unknown type rejected.
        $this->multipart($staff, 'documents', ['file' => UploadedFile::fake()->createWithContent('x.pdf', self::pdf()), 'owner_unit_public_id' => $w['a']['public_id'], 'type_code' => 'OTHER', 'reference' => 'X-1', 'title' => 'x'])->assertStatus(422);
        $this->multipart($staff, 'documents', ['file' => UploadedFile::fake()->createWithContent('x.pdf', self::pdf()), 'owner_unit_public_id' => $w['a']['public_id'], 'type_code' => 'NOPE', 'reference' => 'X-1', 'title' => 'Documento qualquer'])->assertStatus(422);
    }

    // ---- F16 ---------------------------------------------------------------------------------------------------

    public function test_f16_same_checksum_never_merges_nor_leaks_across_units(): void
    {
        $w = $this->world();
        $a = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $aBase = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $b = $this->staff($this->filePermissions(), $w['b']['id'], false);
        $pdf = self::pdf('DEDUP');
        $first = $this->uploaded($a, $w['a'], 'um.pdf', $pdf, 'CONFIDENTIAL');
        $second = $this->uploaded($a, $w['a'], 'dois.pdf', $pdf, 'CONFIDENTIAL');
        $this->assertSame(['DUPLICATE_CONTENT_IN_UNIT'], $second['data']['warnings']);
        $this->assertNotSame($first['public_id'], $second['public_id']);
        $rows = DB::table('files')->whereIn('id', [$first['id'], $second['id']])->get();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->checksum, $rows[1]->checksum);
        $this->assertNotSame($rows[0]->storage_key, $rows[1]->storage_key);
        $this->assertNotSame(file_get_contents($this->objectPath($first['id'])), file_get_contents($this->objectPath($second['id'])), 'distinct DEK and ciphertext');
        // Same content in another unit: no warning, no hint of the other unit's file.
        $other = $this->uploaded($b, $w['b'], 'tres.pdf', $pdf);
        $this->assertSame([], $other['data']['warnings']);
        // Same unit, but the existing duplicates are above the actor's clearance: no warning (no classification oracle).
        $third = $this->uploaded($aBase, $w['a'], 'quatro.pdf', $pdf);
        $this->assertSame([], $third['data']['warnings']);
        $this->assertSame(4, DB::table('files')->where('checksum', hash('sha256', $pdf, true))->count(), 'nothing merged');
    }

    // ---- F17 ---------------------------------------------------------------------------------------------------

    public function test_f17_owner_transfer_requires_authority_on_both_sides(): void
    {
        $w = $this->world();
        $onlyA = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $both = $this->staff($this->basePermissions(), $w['m']['id'], true);
        $file = $this->uploaded($onlyA, $w['a']);
        $this->assertConcealed($this->api($onlyA, 'POST', 'files/' . $file['public_id'] . '/owner', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'Transferência', 'lock_version' => 1]));
        $this->api($both, 'POST', 'files/' . $file['public_id'] . '/owner', ['to_unit_public_id' => $w['b']['public_id'], 'lock_version' => 1])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $this->assertSame($w['a']['id'], (int) DB::table('files')->where('id', $file['id'])->value('owner_unit_id'));
        $moved = $this->api($both, 'POST', 'files/' . $file['public_id'] . '/owner', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'Transferência para o Centro B', 'lock_version' => 1])->assertOk()->json('data');
        $this->assertSame($w['b']['public_id'], $moved['owner_unit']['public_id']);
        $audits = $this->audits('file.ownership_changed', $file['id']);
        $this->assertCount(2, $audits);
        $this->assertSame([$w['a']['id'], $w['b']['id']], array_map(fn ($a) => (int) $a->unit_id, $audits));
        $this->assertSame($audits[0]->correlation_id, $audits[1]->correlation_id);
        $this->assertConcealed($this->api($onlyA, 'GET', 'files/' . $file['public_id']), 'old owner loses access');
        // Sending a public_id never makes a unit the owner by itself: unknown unit is concealed.
        $this->assertConcealed($this->api($both, 'POST', 'files/' . $file['public_id'] . '/owner', ['to_unit_public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'reason' => 'desconhecida', 'lock_version' => 2]));
        // Documents: moving the owner moves every version file; an in-use version file refuses a file-level move.
        $doc = $this->multipart($both, 'documents', ['file' => UploadedFile::fake()->createWithContent('titulo.pdf', self::pdf()), 'owner_unit_public_id' => $w['a']['public_id'], 'type_code' => 'PROPERTY_TITLE', 'reference' => 'TIT-1', 'title' => 'Título do imóvel'])->assertCreated()->json('data');
        $versionFile = $doc['versions'][0]['file']['public_id'];
        $this->api($both, 'POST', 'files/' . $versionFile . '/owner', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'mover', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'FILE_IN_USE');
        $this->assertConcealed($this->api($onlyA, 'POST', 'documents/' . $doc['public_id'] . '/owner', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'mover', 'lock_version' => 0]));
        $movedDoc = $this->api($both, 'POST', 'documents/' . $doc['public_id'] . '/owner', ['to_unit_public_id' => $w['b']['public_id'], 'reason' => 'Mudança de dono', 'lock_version' => 0])->assertOk()->json('data');
        $this->assertSame($w['b']['public_id'], $movedDoc['owner_unit']['public_id']);
        $this->assertSame($w['b']['id'], (int) DB::table('files')->where('public_id', $versionFile)->value('owner_unit_id'));
        // A document referenced by another consumer cannot change owner.
        $docId = (int) DB::table('legal_documents')->where('public_id', $doc['public_id'])->value('id');
        $this->row('event_documents', ['document_id' => $docId]);
        $this->api($both, 'POST', 'documents/' . $doc['public_id'] . '/owner', ['to_unit_public_id' => $w['a']['public_id'], 'reason' => 'Voltar', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'FILE_IN_USE');
    }

    // ---- F18 P08-D-F01 -------------------------------------------------------------------------------------------

    public function test_f18_academy_source_document_is_referenced_by_public_id_only(): void
    {
        $this->app['env'] = 'e2e';
        config(['academy_e2e.enabled' => true]);
        $unit = $this->unit('CENTER', null, 'ACTIVE', 'Centro Académico P08');
        $academic = $this->row('academic_units', ['unit_id' => $unit['id']]);
        $program = $this->row('programs', ['academic_unit_id' => $academic]);
        $curriculum = $this->row('curricula', ['program_id' => $program, 'version' => 1, 'status' => 'S_CUR_DRAFT']);
        $course = $this->row('courses');
        $version = $this->row('course_versions', ['course_id' => $course, 'version' => 1]);
        $cohort = $this->row('cohorts', ['academic_unit_id' => $academic, 'curriculum_id' => $curriculum]);
        $class = $this->row('classes', ['academic_unit_id' => $academic, 'course_version_id' => $version, 'cohort_id' => $cohort, 'status' => 'S_CLS_OPEN', 'public_id' => (string) Str::ulid()]);
        $classPublic = (string) DB::table('classes')->where('id', $class)->value('public_id');
        foreach (['ACADEMY_MANAGE', 'ACADEMY_VIEW'] as $code) {
            if (!DB::table('permissions')->where('code', $code)->exists()) {
                $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => 'ACADEMY']);
            }
        }
        $manager = $this->staff(['ACADEMY_MANAGE', 'ACADEMY_VIEW'], $unit['id'], false);
        $type = (int) DB::table('legal_document_types')->where('code', 'APPOINTMENT')->value('id');
        $own = $this->row('legal_documents', ['document_type_id' => $type, 'owner_unit_id' => $unit['id'], 'status' => 'ACTIVE', 'public_id' => (string) Str::ulid()]);
        $person = fn (): string => (string) DB::table('people')->where('id', $this->row('people', ['public_id' => (string) Str::ulid()]))->value('public_id');
        $uri = 'academy/classes/' . $classPublic . '/instructors';
        // The internal key is not accepted in any form.
        $this->api($manager, 'POST', $uri, ['person' => $person(), 'source_document_id' => $own])->assertStatus(422)->assertJsonValidationErrors(['source_document_id'], 'error.details.fields');
        $this->api($manager, 'POST', $uri, ['person' => $person(), 'source_document' => (string) $own])->assertStatus(404);
        $this->api($manager, 'POST', $uri, ['person' => $person(), 'source_document' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertStatus(404);
        $this->assertSame(0, DB::table('class_instructors')->where('class_id', $class)->count());
        $public = (string) DB::table('legal_documents')->where('id', $own)->value('public_id');
        $this->api($manager, 'POST', $uri, ['person' => $person(), 'source_document' => $public])->assertCreated();
        $this->assertSame($own, (int) DB::table('class_instructors')->where('class_id', $class)->value('source_document_id'));
    }

    // ---- F19 ---------------------------------------------------------------------------------------------------

    public function test_f19_storage_and_database_fault_windows_leave_no_broken_available_state(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $inject = function (string $at, ?callable $effect = null): void {
            app()->instance('files.fault', function (string $point, array $context) use ($at, $effect): void {
                if ($point === $at) {
                    if ($effect !== null) {
                        $effect($context);
                        return;
                    }
                    throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'injected_' . $point]);
                }
            });
        };
        $rows = DB::table('files')->count();
        $uploadedAudits = count($this->audits('file.uploaded'));
        // A: object write fails before the row.
        $inject('write');
        $this->upload($staff, $w['a'], 'a.pdf', self::pdf())->assertStatus(503);
        $this->assertSame($rows, DB::table('files')->count());
        $this->assertSame(0, $this->stagingCount());
        // B: the row transaction fails after staging.
        $inject('row');
        $this->upload($staff, $w['a'], 'b.pdf', self::pdf())->assertStatus(503);
        $this->assertSame($rows, DB::table('files')->count(), 'row rolled back');
        $this->assertSame(0, $this->stagingCount(), 'staging compensated');
        $this->assertSame($uploadedAudits, count($this->audits('file.uploaded')), 'audit rolled back with the row');
        // C: finalization/move fails after the row: QUARANTINED, never AVAILABLE, not downloadable; reconciler finishes.
        $inject('promote');
        $this->upload($staff, $w['a'], 'c.pdf', self::pdf())->assertStatus(503);
        $c = DB::table('files')->orderByDesc('id')->first();
        $this->assertSame('QUARANTINED', $c->status);
        $this->assertSame(1, $this->stagingCount());
        $this->assertConcealed($this->download($staff, 'files/' . $c->public_id . '/content'));
        $this->api($staff, 'GET', 'files/' . $c->public_id)->assertOk()->assertJsonPath('data.status', 'QUARANTINED')->assertJsonPath('data.actions.download', false);
        $reader = $this->staff([FilesCatalog::FILES_VIEW, FilesCatalog::FILES_DOWNLOAD], $w['a']['id'], false);
        $this->assertConcealed($this->api($reader, 'GET', 'files/' . $c->public_id));
        $this->assertSame(0, $this->api($reader, 'GET', 'files?status=QUARANTINED')->json('meta.total'));
        // D: process interrupted after promotion (inspection never ran).
        $inject('inspect');
        $this->upload($staff, $w['a'], 'd.pdf', self::pdf())->assertStatus(503);
        $d = DB::table('files')->orderByDesc('id')->first();
        $this->assertSame('QUARANTINED', $d->status);
        $this->assertFileExists($this->objectPath((int) $d->id));
        // E: interrupted, and the object is lost before the reconciler runs.
        $this->upload($staff, $w['a'], 'e.pdf', self::pdf())->assertStatus(503);
        $e = DB::table('files')->orderByDesc('id')->first();
        unlink($this->objectPath((int) $e->id));
        // F: the object disappears between promotion and the final gate: never AVAILABLE.
        $inject('inspect', function (array $context): void {
            unlink($this->objectPath((int) $context['file']));
        });
        $this->upload($staff, $w['a'], 'f.pdf', self::pdf())->assertStatus(422)->assertJsonPath('error.details.reason_code', 'CUSTODY_FAILED');
        $f = DB::table('files')->orderByDesc('id')->first();
        $this->assertSame('PURGED', $f->status);
        app()->offsetUnset('files.fault');
        // Orphan staging object (no row) older than the window.
        $orphan = $this->root() . '/staging/' . bin2hex(random_bytes(16)) . '.part';
        file_put_contents($orphan, 'MEPAF1-orphan-ciphertext');
        touch($orphan, time() - 3600);
        // Reconciler, with the stale window reached.
        DB::table('files')->where('status', 'QUARANTINED')->update(['created_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u')]);
        $report = (new FilesMaintenance((new FilesServiceFactory(DB::connection()))->runtime()))->reconcile();
        $this->assertSame(1, $report['promoted']);
        $this->assertSame(1, $report['orphans_removed']);
        $this->assertFileDoesNotExist($orphan);
        $this->assertSame('AVAILABLE', DB::table('files')->where('id', $c->id)->value('status'), 'C promoted and inspected');
        $this->assertSame('AVAILABLE', DB::table('files')->where('id', $d->id)->value('status'), 'D inspected');
        $this->assertSame('PURGED', DB::table('files')->where('id', $e->id)->value('status'), 'E custody lost');
        $this->assertSame('CUSTODY_LOST', json_decode($this->audits('file.security_rejected', (int) $e->id)[0]->after_metadata, true)['reason_code']);
        $this->assertNull($this->audits('file.available', (int) $d->id)[0]->actor_id, 'system decision');
        $this->download($staff, 'files/' . $c->public_id . '/content')->assertOk();
        $this->assertSame(0, $this->stagingCount());
        // Invariant: every AVAILABLE row points at an existing object.
        foreach (DB::table('files')->where('owner_unit_id', $w['a']['id'])->where('status', 'AVAILABLE')->get() as $row) {
            $this->assertFileExists($this->objectPath((int) $row->id));
        }
        $this->assertSame(0, DB::table('files')->where('owner_unit_id', $w['a']['id'])->where('status', 'QUARANTINED')->count());
    }

    public function test_f19b_configured_scanner_unavailable_keeps_quarantine_and_503(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        config(['files.clamd' => 'tcp://127.0.0.1:1']);
        $this->upload($staff, $w['a'], 'scan.pdf', self::pdf())->assertStatus(503)->assertJsonPath('error.code', 'FILES_SCANNER_UNAVAILABLE');
        $row = DB::table('files')->orderByDesc('id')->first();
        $this->assertSame('QUARANTINED', $row->status);
        $this->assertConcealed($this->download($staff, 'files/' . $row->public_id . '/content'));
        config(['files.clamd' => null]);
        app()->instance('files.scanner', new class implements FileScanner {
            public function clean(string $bytes): bool { return !str_contains($bytes, 'EICAR'); }
        });
        $this->upload($staff, $w['a'], 'virus.pdf', self::pdf('EICAR'))->assertStatus(422)->assertJsonPath('error.details.reason_code', 'AV_DETECTED');
        $ok = $this->uploaded($staff, $w['a'], 'limpo.pdf');
        $this->assertSame('STRUCTURAL+AV', json_decode($this->audits('file.available', $ok['id'])[0]->after_metadata, true)['inspection']);
        DB::table('files')->where('id', $row->id)->update(['created_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u')]);
        (new FilesMaintenance((new FilesServiceFactory(DB::connection()))->runtime()))->reconcile();
        $this->assertSame('AVAILABLE', DB::table('files')->where('id', $row->id)->value('status'), 'Cron retried once the scanner answered');
    }

    // ---- F20 ---------------------------------------------------------------------------------------------------

    public function test_f20_concurrent_uploads_never_exceed_the_unit_quota(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $pdf = self::pdf('RACE') . str_repeat('%' . bin2hex(random_bytes(30)) . "\n", 200);
        $quota = strlen($pdf) * 2 + 10;   // room for exactly two
        $source = $this->root() . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'race-' . Str::random(6) . '.pdf';
        file_put_contents($source, $pdf);
        $root = dirname(__DIR__, 4);
        $php = getenv('MEPA_PHP_BIN') ?: 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe';
        $procs = [];
        for ($i = 0; $i < 5; $i++) {
            $job = base64_encode(json_encode(['user' => $staff['user'], 'session' => $staff['session'], 'unit' => $w['a']['public_id'], 'path' => $source, 'name' => 'corrida-' . $i . '.pdf', 'root' => $this->root(), 'keyring' => config('files.keyring_path'), 'quota' => $quota], JSON_THROW_ON_ERROR));
            $proc = proc_open([$php, $root . '/scripts/p08-files-worker.php', $job], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, getenv());
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        usleep(1500000);
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
            $this->assertSame(0, proc_close($proc), $err . $out);
            $statuses[] = json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR)['status'];
        }
        @unlink($source);
        sort($statuses);
        $this->assertSame(['OK', 'OK', 'QUOTA_EXCEEDED', 'QUOTA_EXCEEDED', 'QUOTA_EXCEEDED'], $statuses);
        $used = (int) DB::table('files')->where('owner_unit_id', $w['a']['id'])->whereIn('status', ['QUARANTINED', 'AVAILABLE', 'TOMBSTONE'])->sum('size_bytes');
        $this->assertLessThanOrEqual($quota, $used);
        $this->assertSame(2, DB::table('files')->where('owner_unit_id', $w['a']['id'])->count(), 'no row for the refused racers');
        $this->assertSame(0, $this->stagingCount());
        $dir = getenv('P08_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'concurrency-F20.json', json_encode(['racers' => 5, 'quota_bytes' => $quota, 'file_bytes' => strlen($pdf), 'statuses' => $statuses, 'used_bytes' => $used], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }

    // ---- F21 ---------------------------------------------------------------------------------------------------

    public function test_f21_consumer_without_adapter_or_unauthorized_is_denied(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $filesOnly = $this->staff(array_values(array_diff($this->filePermissions(), [FilesCatalog::DOCUMENTS_VIEW, FilesCatalog::DOCUMENTS_MANAGE, FilesCatalog::DOCUMENTS_VERSION_MANAGE])), $w['a']['id'], false);
        $person = $this->uploaded($staff, $w['a'], 'foto-pessoa.jpg', self::jpegWithExif(), 'HIGHLY_SENSITIVE');
        $this->row('person_files', ['file_id' => $person['id'], 'purpose' => 'PHOTO']);
        // People has no adapter in the foundation: fail closed, even for the fully cleared owner-unit actor.
        $this->assertConcealed($this->api($staff, 'GET', 'files/' . $person['public_id']));
        $this->assertConcealed($this->download($staff, 'files/' . $person['public_id'] . '/content', 'motivo suficiente'));
        $this->assertNotContains($person['public_id'], array_column($this->api($staff, 'GET', 'files')->json('data'), 'public_id'));
        // Document versions: the Documents adapter requires DOCUMENTS_VIEW over the document.
        $doc = $this->multipart($staff, 'documents', ['file' => UploadedFile::fake()->createWithContent('resolucao.pdf', self::pdf()), 'owner_unit_public_id' => $w['a']['public_id'], 'type_code' => 'RESOLUTION', 'reference' => 'RES-1', 'title' => 'Resolução do conselho'])->assertCreated()->json('data');
        $versionFile = $doc['versions'][0]['file']['public_id'];
        $this->assertConcealed($this->api($filesOnly, 'GET', 'files/' . $versionFile));
        $this->assertConcealed($this->download($filesOnly, 'files/' . $versionFile . '/content'));
        $this->api($filesOnly, 'GET', 'documents/' . $doc['public_id'])->assertStatus(403);
        $this->api($staff, 'GET', 'files/' . $versionFile)->assertOk()->assertJsonPath('data.document.public_id', $doc['public_id'])->assertJsonPath('data.in_use', true);
        $this->download($staff, 'files/' . $versionFile . '/content')->assertOk();
        // Floors: a person file never goes below CONFIDENTIAL through reclassification (and a minor's is HS).
        $adultFile = $this->uploaded($staff, $w['a'], 'certidao.pdf', null, 'CONFIDENTIAL');
        $this->row('certificates', ['file_id' => $adultFile['id'], 'revoked_at' => null]);
        $service = (new FilesServiceFactory(DB::connection()))->make(\App\Domain\Files\FileService::class);
        try {
            $service->reclassify($staff['user'], $staff['session'], $adultFile['public_id'], ['classification' => 'RESTRICTED', 'reason' => 'descer', 'lock_version' => 1]);
            $this->fail('below floor accepted');
        } catch (FilesError $e) {
            $this->assertContains($e->reason, [FilesReason::CLASSIFICATION_BELOW_FLOOR, FilesReason::TARGET_NOT_FOUND]);
        }
        $this->assertSame('CONFIDENTIAL', DB::table('files')->where('id', $adultFile['id'])->value('classification'));
    }

    // ---- classification change, rotation, context, output guards ---------------------------------------------------

    public function test_classification_change_rules_and_audit(): void
    {
        $w = $this->world();
        $full = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $base = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $file = $this->uploaded($base, $w['a']);
        $this->api($base, 'POST', 'files/' . $file['public_id'] . '/classification', ['classification' => 'CONFIDENTIAL', 'lock_version' => 1])->assertStatus(403)->assertJsonPath('error.code', 'CLEARANCE_REQUIRED');
        $this->api($full, 'POST', 'files/' . $file['public_id'] . '/classification', ['classification' => 'secret', 'lock_version' => 1])->assertStatus(422)->assertJsonPath('error.code', 'CLASSIFICATION_INVALID');
        $up = $this->api($full, 'POST', 'files/' . $file['public_id'] . '/classification', ['classification' => 'HIGHLY_SENSITIVE', 'lock_version' => 1])->assertOk()->json('data');
        $this->assertSame('HIGHLY_SENSITIVE', $up['classification']);
        $this->assertConcealed($this->api($base, 'GET', 'files/' . $file['public_id']));
        $this->api($full, 'POST', 'files/' . $file['public_id'] . '/classification', ['classification' => 'INTERNAL', 'lock_version' => $up['lock_version']])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $down = $this->api($full, 'POST', 'files/' . $file['public_id'] . '/classification', ['classification' => 'INTERNAL', 'reason' => 'Documento tornado público interno', 'lock_version' => $up['lock_version']])->assertOk()->json('data');
        $this->assertSame('INTERNAL', $down['classification']);
        $audits = $this->audits('file.classification_changed', $file['id']);
        $this->assertCount(2, $audits);
        $this->assertSame(['UP', 'DOWN'], array_map(fn ($a) => json_decode($a->after_metadata, true)['direction'], $audits));
        $this->assertSame('RESTRICTED', json_decode($audits[0]->before_metadata, true)['classification']);
    }

    public function test_key_rotation_keeps_old_versions_read_only_and_rewrap_is_separate_from_content(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->basePermissions(), $w['a']['id'], false);
        $pdf = self::pdf('ROTATION');
        $old = $this->uploaded($staff, $w['a'], 'antigo.pdf', $pdf);
        $ring = (string) config('files.keyring_path');
        $saved = (string) file_get_contents($ring);
        $k1 = base64_decode(json_decode($saved, true)['keys']['1']['kek']);
        $rotated = dirname($this->root()) . DIRECTORY_SEPARATOR . 'rotated-' . Str::random(6) . '.json';
        self::writeRing($rotated, [1 => $k1, 2 => random_bytes(32)], 2);
        config(['files.keyring_path' => $rotated]);
        $new = $this->uploaded($staff, $w['a'], 'novo.pdf');
        $this->assertSame(2, (int) DB::table('files')->where('id', $new['id'])->value('key_version'), 'new writes use the active key');
        $this->assertSame($pdf, $this->download($staff, 'files/' . $old['public_id'] . '/content')->assertOk()->streamedContent(), 'old key version readable');
        $oldKey = (string) DB::table('files')->where('id', $old['id'])->value('storage_key');
        $report = (new FilesMaintenance((new FilesServiceFactory(DB::connection()))->runtime()))->rewrap();
        $this->assertGreaterThanOrEqual(1, $report['rewrapped']);
        $row = DB::table('files')->where('id', $old['id'])->first();
        $this->assertSame(2, (int) $row->key_version);
        $this->assertNotSame($oldKey, $row->storage_key);
        $this->assertFileDoesNotExist($this->root() . '/' . $oldKey);
        $this->assertSame(hash('sha256', $pdf, true), $row->checksum, 'content unchanged');
        $this->assertSame(0, DB::table('document_versions')->where('file_id', $old['id'])->count(), 'not a document version');
        $this->assertSame($pdf, $this->download($staff, 'files/' . $old['public_id'] . '/content')->assertOk()->streamedContent());
        $this->assertCount(1, $this->audits('file.key_rewrapped', $old['id']));
        // The retired key is no longer needed; without key 2 the rotated objects fail closed.
        self::writeRing($rotated, [1 => $k1], 1);
        $this->download($staff, 'files/' . $old['public_id'] . '/content')->assertStatus(503);
        config(['files.keyring_path' => $ring]);
        @unlink($rotated);
        $handle = fopen($this->objectPath($new['id']), 'rb');
        $this->assertSame(2, Mepaf1::headerVersion($handle));
        fclose($handle);
    }

    public function test_context_and_output_never_expose_storage_internals(): void
    {
        $w = $this->world();
        $staff = $this->staff($this->filePermissions(), $w['a']['id'], false);
        $context = $this->api($staff, 'GET', 'files/context')->assertOk()->json('data');
        $this->assertNoInternals($context);
        $this->assertSame(['INTERNAL', 'RESTRICTED', 'CONFIDENTIAL', 'HIGHLY_SENSITIVE'], array_column($context['classifications'], 'code'));
        $this->assertSame('RESTRICTED', $context['default_classification']);
        $this->assertCount(8, $context['document_types']);
        $this->assertSame('HIGHLY_SENSITIVE', $context['units'][0]['clearance']);
        $file = $this->uploaded($staff, $w['a']);
        foreach (['files', 'files/' . $file['public_id'], 'documents'] as $uri) {
            $this->assertNoInternals($this->api($staff, 'GET', $uri)->assertOk()->json());
        }
        // Internal fields are never accepted as input either.
        $this->upload($staff, $w['a'], 'x.pdf', self::pdf(), null, ['storage_key' => 'v1/2026/09/' . str_repeat('a', 32) . '.bin'])->assertStatus(422);
        $this->upload($staff, $w['a'], 'x.pdf', self::pdf(), null, ['owner_unit_id' => $w['a']['id']])->assertStatus(422);
        $this->upload($staff, $w['a'], 'x.pdf', self::pdf(), null, ['status' => 'AVAILABLE'])->assertStatus(422);
        $this->upload($staff, $w['a'], 'x.pdf', self::pdf(), null, ['mime_type' => 'image/png'])->assertStatus(422);
        $this->assertSame(1, DB::table('files')->where('owner_unit_id', $w['a']['id'])->count());
        // The disk is private and has no URL.
        $this->assertNull(config('filesystems.disks.files_private.url'));
        $this->assertSame('private', config('filesystems.disks.files_private.visibility'));
    }

    public function test_files_errors_never_reach_logs_with_names_or_content(): void
    {
        $this->assertFalse(app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->shouldReport(new FilesError(FilesReason::STORAGE_UNAVAILABLE)));
        (new FilesServiceFactory(DB::connection()))->runtime();
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));
        $trace = (static fn (string $name, string $bytes) => new \RuntimeException('x'))('segredo-nome.pdf', '%PDF-secret')->getTraceAsString();
        $this->assertStringNotContainsString('segredo-nome', $trace);
        $this->assertStringNotContainsString('%PDF', $trace);
    }

    public function test_catalog_is_idempotent_and_creates_no_role(): void
    {
        $roles = DB::table('roles')->count();
        $this->assertSame([], FilesCatalog::install(DB::connection()));
        $this->assertSame(9, DB::table('permissions')->where('data_type', 'FILES')->whereColumn('action', 'code')->count());
        $this->assertSame(8, DB::table('legal_document_types')->whereIn('code', array_keys(FilesCatalog::DOCUMENT_TYPES))->count());
        $this->assertSame($roles, DB::table('roles')->count());
        $this->assertSame(['RESTRICTED', 'RESTRICTED', 'RESTRICTED', 'RESTRICTED', 'CONFIDENTIAL', 'HIGHLY_SENSITIVE', 'RESTRICTED', 'RESTRICTED', 'RESTRICTED'], array_values(FilesCatalog::PERMISSIONS));
    }
}
