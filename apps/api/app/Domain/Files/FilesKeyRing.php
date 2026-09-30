<?php

declare(strict_types=1);

namespace App\Domain\Files;

// Files key-encryption keys (ADR 0019 D06), distinct from the People keys. Same location rules as ADR 0017 D-08: a
// JSON file OUTSIDE the database, the code tree, Git, the public directory and the logs, at FILES_KEYRING_PATH:
//
//   {"active_version": 2, "keys": {"1": {"kek": "<base64 32 bytes>"}, "2": {"kek": "<base64 32 bytes>"}}}
//
// The active version wraps every new DEK; older versions stay read-only (unwrap) until an operational re-wrap moves
// their objects. Unset, unreadable, misplaced or malformed ring, a missing active key or a reused key => CRYPTO_UNAVAILABLE
// (fail closed). Keys never appear in debug output, serialization, logs or exceptions.
final class FilesKeyRing
{
    /** @param array<int, string> $keys */
    private function __construct(private int $activeVersion, private array $keys)
    {
    }

    /** @param list<string> $forbiddenRoots directories the ring may never live under */
    public static function fromFile(?string $path, array $forbiddenRoots): self
    {
        if ($path === null || trim($path) === '') {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_not_configured']);
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_unreadable']);
        }
        if (FileStorage::isInside($real, $forbiddenRoots)) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_insecure_location']);
        }
        $raw = file_get_contents($real);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_malformed']);
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'sodium_unavailable']);
        }
        $active = $data['active_version'] ?? null;
        if (!is_int($active) || $active < 1 || $active > 65535 || !is_array($data['keys'] ?? null)) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_malformed']);
        }
        $keys = [];
        foreach ($data['keys'] as $version => $entry) {
            $v = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $kek = is_array($entry) && is_string($entry['kek'] ?? null) ? base64_decode($entry['kek'], true) : false;
            if ($v === false || !is_string($kek) || strlen($kek) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_malformed']);
            }
            foreach ($keys as $other) {
                if (hash_equals($other, $kek)) {
                    throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'key_reuse']);
                }
            }
            $keys[$v] = $kek;
        }
        if (!isset($keys[$active])) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'active_version_missing']);
        }
        return new self($active, $keys);
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    public function has(int $version): bool
    {
        return isset($this->keys[$version]);
    }

    /** KEK of a version; an unknown version fails closed. */
    public function kek(int $version): string
    {
        if (!isset($this->keys[$version])) {
            throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'unknown_key_version', 'key_version' => $version]);
        }
        return $this->keys[$version];
    }

    public function __debugInfo(): array
    {
        return ['active_version' => $this->activeVersion, 'versions' => array_keys($this->keys)];
    }

    public function __serialize(): array
    {
        throw new FilesError(FilesReason::CRYPTO_UNAVAILABLE, ['reason' => 'not_serializable']);
    }
}
