<?php

declare(strict_types=1);

namespace App\Domain\People;

// Application-level field encryption for People (ADR-0017 D03 / D-08), failing closed.
//
// Key ring: a JSON file OUTSIDE the database, the code tree, Git and the public directory, readable by
// the application process (secret store / mounted file). Shape:
//   {"active_version": 1, "keys": {"1": {"encryption": "<base64 32 bytes>", "blind_index": "<base64 32 bytes>"}}}
// - AES-256-GCM with a random 96-bit nonce and a 128-bit tag; the AAD binds a ciphertext to its purpose
//   and to the Person, so a ciphertext copied to another row or field does not decrypt.
// - Blind index = HMAC-SHA256 with a DISTINCT key of the same version (equality search only).
// - Missing/unreadable ring, a ring inside the code tree or public directory, a malformed or reused key,
//   an unknown key_version or any cryptographic failure raises CRYPTO_UNAVAILABLE. There is no plaintext
//   fallback. Errors and debug output never contain plaintext, keys, ciphertext or blind indexes.
final class PeopleCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const FORMAT = "\x01";

    /** @param array<int, array{encryption: string, blind_index: string}> $keys */
    private function __construct(private int $activeVersion, private array $keys)
    {
    }

    /** @param list<string> $forbiddenRoots directories the ring may never live under */
    public static function fromKeyRingFile(?string $path, array $forbiddenRoots): self
    {
        if ($path === null || trim($path) === '') {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_not_configured']);
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_unreadable']);
        }
        $normalized = self::normalizePath($real);
        foreach ($forbiddenRoots as $root) {
            $rootReal = realpath($root);
            if ($rootReal !== false && str_starts_with($normalized . '/', self::normalizePath($rootReal) . '/')) {
                throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_insecure_location']);
            }
        }
        $raw = file_get_contents($real);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_malformed']);
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'cipher_unavailable']);
        }
        $active = $data['active_version'] ?? null;
        if (!is_int($active) || $active < 1 || $active > 65535 || !is_array($data['keys'] ?? null)) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_malformed']);
        }
        $keys = [];
        foreach ($data['keys'] as $version => $pair) {
            $v = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $enc = is_array($pair) && is_string($pair['encryption'] ?? null) ? base64_decode($pair['encryption'], true) : false;
            $mac = is_array($pair) && is_string($pair['blind_index'] ?? null) ? base64_decode($pair['blind_index'], true) : false;
            if ($v === false || !is_string($enc) || !is_string($mac) || strlen($enc) !== 32 || strlen($mac) !== 32) {
                throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'keyring_malformed']);
            }
            if (hash_equals($enc, $mac)) {
                throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'key_reuse']);
            }
            $keys[$v] = ['encryption' => $enc, 'blind_index' => $mac];
        }
        if (!isset($keys[$active])) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'active_version_missing']);
        }
        return new self($active, $keys);
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    /** @return array{0: string, 1: int} [ciphertext, key_version] */
    public function encrypt(string $plaintext, string $aad): array
    {
        $key = $this->keys[$this->activeVersion]['encryption'];
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'encrypt_failed']);
        }
        return [self::FORMAT . $nonce . $tag . $ciphertext, $this->activeVersion];
    }

    public function decrypt(string $blob, int $version, string $aad): string
    {
        if (!isset($this->keys[$version])) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'unknown_key_version', 'key_version' => $version]);
        }
        if (strlen($blob) < 29 || $blob[0] !== self::FORMAT) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'ciphertext_format']);
        }
        $plaintext = openssl_decrypt(substr($blob, 29), self::CIPHER, $this->keys[$version]['encryption'], OPENSSL_RAW_DATA, substr($blob, 1, 12), substr($blob, 13, 16), $aad);
        if ($plaintext === false) {
            throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'integrity']);
        }
        return $plaintext;
    }

    /** Blind index under the active version (stored with the row). */
    public function blindIndex(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, $this->keys[$this->activeVersion]['blind_index'], true);
    }

    /** Blind indexes under every readable version (equality search across a rotation window). */
    public function blindIndexes(string $normalized): array
    {
        return array_values(array_map(fn (array $pair): string => hash_hmac('sha256', $normalized, $pair['blind_index'], true), $this->keys));
    }

    public function __debugInfo(): array
    {
        return ['active_version' => $this->activeVersion, 'versions' => array_keys($this->keys)];
    }

    public function __serialize(): array
    {
        throw new PeopleError(PeopleReason::CRYPTO_UNAVAILABLE, ['reason' => 'not_serializable']);
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    }
}
