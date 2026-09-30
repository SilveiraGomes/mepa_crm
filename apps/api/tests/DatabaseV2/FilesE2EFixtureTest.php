<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\FilesHttpCase;

/**
 * Seeds the P0.8 browser fixture THROUGH the Files/Documents API (every row is a real, encrypted, inspected, audited
 * upload) into the storage root and key ring the runner gives the browser server (P08_E2E_STORAGE_ROOT /
 * P08_E2E_KEYRING_PATH, both in the OS temp directory), and writes the transient manifest .tmp/p08-e2e-fixtures.json
 * (git-ignored; removed by the runner). Credentials are random per run.
 */
final class FilesE2EFixtureTest extends FilesHttpCase
{
    public function test_seed_files_browser_fixture_only(): void
    {
        $this->assertNotFalse(getenv('P08_E2E_STORAGE_ROOT'), 'the runner must provide the shared storage root');
        $w = $this->world();
        $actor = $this->staff($this->filePermissions(), $w['a']['id'], true);
        $plain = $this->uploaded($actor, $w['a'], 'Acta de Setembro.pdf', self::pdf('E2E-ACTA'));
        $sensitive = $this->uploaded($actor, $w['a'], 'Documento de identidade.pdf', self::pdf('E2E-IDENTIDADE'), 'HIGHLY_SENSITIVE');
        $doc = $this->multipart($actor, 'documents', ['file' => UploadedFile::fake()->createWithContent('acta-assembleia-v1.pdf', self::pdf('E2E-V1')), 'owner_unit_public_id' => $w['a']['public_id'], 'type_code' => 'MINUTES', 'reference' => 'ACTA-E2E-001', 'title' => 'Acta da assembleia geral', 'issued_on' => '2026-09-01'])->assertCreated()->json('data');
        $this->multipart($actor, 'documents/' . $doc['public_id'] . '/versions', ['file' => UploadedFile::fake()->createWithContent('acta-assembleia-v2.pdf', self::pdf('E2E-V2')), 'lock_version' => 0])->assertCreated();
        $login = 'files.e2e.' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(32));
        DB::table('users')->where('id', $actor['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $manifest = [
            'login' => $login, 'password' => $password, 'user_id' => $actor['user'],
            'unit_a' => $w['a']['public_id'], 'unit_a_name' => 'Centro A',
            'file' => $plain['public_id'], 'file_name' => 'Acta de Setembro.pdf',
            'sensitive' => $sensitive['public_id'], 'sensitive_name' => 'Documento de identidade.pdf',
            'document' => $doc['public_id'], 'document_title' => 'Acta da assembleia geral',
        ];
        $dir = dirname(__DIR__, 4) . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/p08-e2e-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->assertFileExists($dir . '/p08-e2e-fixtures.json');
    }
}
