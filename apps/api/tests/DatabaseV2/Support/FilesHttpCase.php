<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\Files\FilesCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * P0.8 Documents/Files HTTP test base (Test Infrastructure V2 Wave 5 pool). Reuses the Territorial base (canonical unit
 * types, staff grants on UNIT scopes), installs the ADR-0019 catalog after the TRUNCATE reset exactly as migration
 * 2026_09_30_000001 does, and gives every test process its OWN private storage root and Files key ring in the OS temp
 * directory (never inside the repository); both are removed at shutdown.
 */
abstract class FilesHttpCase extends TerritorialHttpCase
{
    protected static ?string $sandbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        FilesCatalog::install(DB::connection());
        if (self::$sandbox === null) {
            self::$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-files-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir(self::$sandbox . DIRECTORY_SEPARATOR . 'root', 0700, true);
            self::writeRing(self::$sandbox . DIRECTORY_SEPARATOR . 'keyring.json', [1 => random_bytes(32)], 1);
            $dir = self::$sandbox;
            register_shutdown_function(static fn () => self::remove($dir));
        }
        $root = getenv('P08_E2E_STORAGE_ROOT') ?: self::$sandbox . DIRECTORY_SEPARATOR . 'root';
        $ring = getenv('P08_E2E_KEYRING_PATH') ?: self::$sandbox . DIRECTORY_SEPARATOR . 'keyring.json';
        config([
            'filesystems.disks.files_private.root' => $root,
            'files.storage_root' => $root,
            'files.keyring_path' => $ring,
            'files.max_file_bytes' => 10485760,
            'files.unit_quota_bytes' => 2147483648,
            'files.total_quota_bytes' => null,
            'files.total_quota_required' => false,
            'files.reserve_bytes' => 1073741824,
            'files.clamd' => null,
        ]);
        app()->forgetInstance('files.fault');
        app()->forgetInstance('files.scanner');
        app()->offsetUnset('files.fault');
        app()->offsetUnset('files.scanner');
    }

    protected function root(): string
    {
        return (string) config('files.storage_root');
    }

    /** @param array<int, string> $keys */
    protected static function writeRing(string $path, array $keys, int $active): void
    {
        $data = ['active_version' => $active, 'keys' => []];
        foreach ($keys as $version => $kek) {
            $data['keys'][(string) $version] = ['kek' => base64_encode($kek)];
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    protected static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    protected function filePermissions(): array
    {
        return array_keys(FilesCatalog::PERMISSIONS);
    }

    /** Operation permissions WITHOUT the clearance elevations (base clearance RESTRICTED). */
    protected function basePermissions(): array
    {
        return array_values(array_diff($this->filePermissions(), [FilesCatalog::FILES_CONFIDENTIAL_ACCESS, FilesCatalog::FILES_HIGHLY_SENSITIVE_ACCESS]));
    }

    protected function world(): array
    {
        $g = $this->unit('GENERAL_DIRECTION', null, 'ACTIVE', 'Direcção Geral P08');
        $r = $this->unit('REGIONAL_DIRECTION', $g['id'], 'ACTIVE', 'Região P08');
        $p = $this->unit('PROVINCIAL_DIRECTION', $r['id'], 'ACTIVE', 'Província P08');
        $m = $this->unit('MUNICIPAL_DIRECTION', $p['id'], 'ACTIVE', 'Município P08');
        $a = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro A');
        $b = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro B');
        return compact('g', 'r', 'p', 'm', 'a', 'b');
    }

    // ---- synthetic content --------------------------------------------------------------------------------------

    protected static function pdf(string $marker = 'P08 synthetic PDF'): string
    {
        $body = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >> endobj\n"
            . "4 0 obj << /Length 44 >> stream\nBT /F1 12 Tf 20 100 Td ({$marker}) Tj ET\nendstream endobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";
        return $body . str_repeat('%' . bin2hex(random_bytes(8)) . "\n", 4);
    }

    protected static function jpegWithExif(int $w = 40, int $h = 20): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        $exif = "Exif\x00\x00GPS-LAT-8.83-LON13.23-SECRET-CAMERA";
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    protected static function png(int $w = 30, int $h = 30): string
    {
        $image = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        return $png . 'tEXtComment-POLYGLOT-TAIL';
    }

    // ---- HTTP helpers ---------------------------------------------------------------------------------------------

    protected function upload(array $actor, array $unit, string $name, string $bytes, ?string $classification = null, array $extra = []): TestResponse
    {
        $body = ['file' => UploadedFile::fake()->createWithContent($name, $bytes), 'owner_unit_public_id' => $unit['public_id']] + $extra;
        if ($classification !== null) {
            $body['classification'] = $classification;
        }
        return $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'])->post('/api/v1/files', $body);
    }

    /** @return array{public_id: string, id: int, data: array} */
    protected function uploaded(array $actor, array $unit, string $name = 'acta.pdf', ?string $bytes = null, ?string $classification = null): array
    {
        $response = $this->upload($actor, $unit, $name, $bytes ?? self::pdf(), $classification)->assertCreated();
        $public = (string) $response->json('data.public_id');
        return ['public_id' => $public, 'id' => (int) DB::table('files')->where('public_id', $public)->value('id'), 'data' => $response->json('data')];
    }

    protected function multipart(array $actor, string $uri, array $body): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'])->post('/api/v1/' . ltrim($uri, '/'), $body);
    }

    protected function download(array $actor, string $uri, ?string $reason = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'];
        if ($reason !== null) {
            $headers['X-Access-Reason'] = $reason;
        }
        return $this->withHeaders($headers)->get('/api/v1/' . ltrim($uri, '/'));
    }

    protected function objectPath(int $fileId): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) DB::table('files')->where('id', $fileId)->value('storage_key'));
    }

    protected function stagingCount(): int
    {
        $dir = $this->root() . DIRECTORY_SEPARATOR . 'staging';
        return is_dir($dir) ? count(glob($dir . DIRECTORY_SEPARATOR . '*.part') ?: []) : 0;
    }

    protected function objectCount(): int
    {
        $dir = $this->root() . DIRECTORY_SEPARATOR . 'v1';
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $item) {
            $n += $item->isFile() ? 1 : 0;
        }
        return $n;
    }

    protected function assertConcealed(TestResponse $response): void
    {
        $response->assertStatus(404)->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']]);
    }

    /** No numeric key, disk, storage_key, checksum, key_version or URL anywhere in a payload. */
    protected function assertNoInternals(array $payload): void
    {
        array_walk_recursive($payload, function ($value, $key): void {
            if (is_string($key)) {
                $this->assertFalse($key === 'id' || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')), 'internal id exposed: ' . $key);
                $this->assertNotContains($key, ['disk', 'storage_key', 'checksum', 'key_version', 'url', 'signed_url', 'path'], 'storage internal exposed: ' . $key);
            }
            if (is_string($value)) {
                $this->assertDoesNotMatchRegularExpression('#v1/\d{4}/\d{2}/[0-9a-f]{32}\.bin|files_private#', $value, 'storage key or disk exposed in a value');
            }
        });
    }

    protected function audits(string $action, ?int $entity = null): array
    {
        $q = DB::table('audit_logs')->where('source', FilesCatalog::AUDIT_SOURCE)->where('action', $action);
        if ($entity !== null) {
            $q->where('entity_id', $entity);
        }
        return $q->orderBy('id')->get()->all();
    }
}
