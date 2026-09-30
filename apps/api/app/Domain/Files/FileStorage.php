<?php

declare(strict_types=1);

namespace App\Domain\Files;

// Private object storage of ADR 0019 D02: the Laravel disk `files_private` (driver local, no `url`, never
// `storage:link`), rooted at FILES_STORAGE_ROOT OUTSIDE the document root and the application tree.
//
//   final object : v1/<yyyy>/<mm>/<32 random hex>.bin   (files.storage_key; never derived from the name or public_id)
//   staging      : staging/<same 32 hex>.part           (written first, promoted by rename after the row exists)
//
// Only ciphertext (MEPAF1) is ever written here. The root is validated on EVERY use: unset, missing, not a directory,
// not writable, or inside a forbidden root (public_path, the application, the repository) => STORAGE_UNAVAILABLE.
// Keys are validated against their exact shape before any path is built (no traversal). Neither the disk, the key nor
// any path ever leaves the server.
final class FileStorage
{
    public const DISK = 'files_private';
    public const MIN_RESERVE_BYTES = 1073741824; // 1 GiB floor (D02)
    private const KEY = '#^v1/\d{4}/\d{2}/[0-9a-f]{32}\.bin$#D';
    private const STAGING = '#^staging/[0-9a-f]{32}\.part$#D';

    /** @param list<string> $forbiddenRoots */
    public function __construct(private ?string $root, private array $forbiddenRoots, private int $reserveBytes)
    {
        $this->reserveBytes = max(self::MIN_RESERVE_BYTES, $reserveBytes);
    }

    public function root(): string
    {
        $configured = $this->root;
        if ($configured === null || trim($configured) === '') {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'root_not_configured']);
        }
        $real = realpath($configured);
        if ($real === false || !is_dir($real) || !is_writable($real)) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'root_unavailable']);
        }
        if (self::isInside($real, $this->forbiddenRoots)) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'root_insecure_location']);
        }
        return $real;
    }

    /** @return array{key: string, staging: string} */
    public function newObject(string $utcNow): array
    {
        $hex = bin2hex(random_bytes(16));
        return ['key' => 'v1/' . substr($utcNow, 0, 4) . '/' . substr($utcNow, 5, 2) . '/' . $hex . '.bin', 'staging' => 'staging/' . $hex . '.part'];
    }

    public static function stagingFor(string $key): string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new FilesError(FilesReason::INVARIANT_VIOLATION, ['reason' => 'storage_key_shape']);
        }
        return 'staging/' . substr(basename($key), 0, 32) . '.part';
    }

    /** Free-space gate: the object must fit while keeping the reserve (>= 1 GiB) free. */
    public function assertCapacity(int $incoming): void
    {
        $free = @disk_free_space($this->root());
        if ($free === false || ($free - $incoming) < $this->reserveBytes) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'reserve']);
        }
    }

    /** @return resource */
    public function createStaging(string $staging)
    {
        $path = $this->path($staging);
        $this->ensureDirectory(dirname($path));
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'staging_create']);
        }
        return $handle;
    }

    /** Atomic promotion of a staged object to its final key (same filesystem => rename). */
    public function promote(string $staging, string $key): void
    {
        $from = $this->path($staging);
        $to = $this->path($key);
        $this->ensureDirectory(dirname($to));
        if (!is_file($from) || file_exists($to) || !@rename($from, $to)) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'promote_failed']);
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function size(string $key): int
    {
        $size = @filesize($this->path($key));
        return $size === false ? -1 : $size;
    }

    /** @return resource */
    public function openRead(string $key)
    {
        $path = $this->path($key);
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new FilesError(FilesReason::CONTENT_UNAVAILABLE, ['reason' => 'object_missing']);
        }
        return $handle;
    }

    /** Destroys an object or a staging file (idempotent). */
    public function delete(string $keyOrStaging): void
    {
        $path = $this->path($keyOrStaging);
        if (is_file($path) && !@unlink($path)) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'delete_failed']);
        }
    }

    /** @return list<array{staging: string, modified: int}> */
    public function stagingObjects(): array
    {
        $dir = $this->root() . DIRECTORY_SEPARATOR . 'staging';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/^[0-9a-f]{32}\.part$/D', $name) === 1) {
                $out[] = ['staging' => 'staging/' . $name, 'modified' => (int) filemtime($dir . DIRECTORY_SEPARATOR . $name)];
            }
        }
        return $out;
    }

    public function path(string $keyOrStaging): string
    {
        if (preg_match(self::KEY, $keyOrStaging) !== 1 && preg_match(self::STAGING, $keyOrStaging) !== 1) {
            throw new FilesError(FilesReason::INVARIANT_VIOLATION, ['reason' => 'storage_key_shape']);
        }
        return $this->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $keyOrStaging);
    }

    /** @param list<string> $roots */
    public static function isInside(string $path, array $roots): bool
    {
        $normalized = self::normalize($path);
        foreach ($roots as $root) {
            $real = is_string($root) ? realpath($root) : false;
            if ($real !== false && str_starts_with($normalized . '/', self::normalize($real) . '/')) {
                return true;
            }
        }
        return false;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'mkdir_failed']);
        }
    }

    private static function normalize(string $path): string
    {
        return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    }
}
