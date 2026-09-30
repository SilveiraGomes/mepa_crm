<?php

declare(strict_types=1);

namespace App\Domain\Files;

// MEPAF1: the self-describing encrypted container of every stored file object (ADR 0019 D06). No plaintext mode exists.
//
//   offset  size  field
//   0       6     magic "MEPAF1"
//   6       2     key_version (uint16, big endian) of the KEK that wrapped the DEK
//   8       24    wrap nonce (XChaCha20-Poly1305-IETF)
//   32      48    wrapped DEK = crypto_aead_xchacha20poly1305_ietf(DEK 32 bytes) + 16-byte tag,
//                 AAD "mepa.files.content.v1|<files.public_id>|<key_version>"
//   80      24    crypto_secretstream_xchacha20poly1305 header
//   104     ...   chunks: each 64 KiB of plaintext (the last may be shorter) + 17 bytes (tag + MAC); the LAST chunk
//                 carries TAG_FINAL, so truncation, reordering, trailing bytes and tampering are all detected.
//
// One random DEK per file; the DEK is never stored usable in clear. Memory is constant (one chunk). Every failure
// (unknown/missing key, bad header, unwrap failure, bad chunk MAC, missing TAG_FINAL, trailing data) raises a FilesError
// and never yields partial plaintext as a success.
final class Mepaf1
{
    public const MAGIC = 'MEPAF1';
    public const CHUNK = 65536;
    public const HEADER_BYTES = 104;
    private const WRAP_OFFSET = 8;

    public static function aad(string $publicId, int $keyVersion): string
    {
        return 'mepa.files.content.v1|' . $publicId . '|' . $keyVersion;
    }

    /**
     * Encrypts $in (plaintext stream) into $out under a fresh DEK wrapped by the ring's active KEK.
     * @param resource $in @param resource $out
     * @return array{sha256: string, bytes: int, key_version: int} sha256 of the PLAINTEXT (binary, 32 bytes)
     */
    public static function encrypt($in, $out, string $publicId, FilesKeyRing $ring): array
    {
        $version = $ring->activeVersion();
        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::aad($publicId, $version), $nonce, $ring->kek($version));
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
            self::write($out, self::MAGIC . pack('n', $version) . $nonce . $wrapped . $header);
            $hash = hash_init('sha256');
            $bytes = 0;
            $current = self::readExactly($in, self::CHUNK);
            while (true) {
                $next = $current === '' ? '' : self::readExactly($in, self::CHUNK);
                $final = $next === '';
                hash_update($hash, $current);
                $bytes += strlen($current);
                self::write($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
                if ($final) {
                    break;
                }
                $current = $next;
            }
            if (!fflush($out)) {
                throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'flush_failed']);
            }
            return ['sha256' => hash_final($hash, true), 'bytes' => $bytes, 'key_version' => $version];
        } finally {
            sodium_memzero($dek);
        }
    }

    /**
     * Streams the authenticated plaintext of $in to $sink, chunk by chunk. $expectedVersion is files.key_version: a
     * header that disagrees with the row is an integrity failure.
     * @param resource $in
     * @return array{sha256: string, bytes: int}
     */
    public static function decrypt($in, string $publicId, FilesKeyRing $ring, ?int $expectedVersion, ?callable $sink = null): array
    {
        [$version, $dek] = self::openHeader($in, $publicId, $ring);
        try {
            if ($expectedVersion !== null && $version !== $expectedVersion) {
                throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'key_version_mismatch']);
            }
            $ssHeader = self::readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($ssHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'truncated_header']);
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($ssHeader, $dek);
            $hash = hash_init('sha256');
            $bytes = 0;
            $final = false;
            while (true) {
                $chunk = self::readExactly($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($chunk === '') {
                    if (!$final) {
                        throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'truncated']);
                    }
                    break;
                }
                if ($final) {
                    throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'trailing_data']);
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($result === false) {
                    throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'chunk_authentication']);
                }
                [$plain, $tag] = $result;
                hash_update($hash, $plain);
                $bytes += strlen($plain);
                if ($sink !== null) {
                    $sink($plain);
                }
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            return ['sha256' => hash_final($hash, true), 'bytes' => $bytes];
        } finally {
            sodium_memzero($dek);
        }
    }

    /** Key version recorded in a container header (no key needed). @param resource $in */
    public static function headerVersion($in): int
    {
        $head = self::readExactly($in, self::WRAP_OFFSET);
        if (strlen($head) !== self::WRAP_OFFSET || substr($head, 0, 6) !== self::MAGIC) {
            throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'bad_magic']);
        }
        return (int) unpack('n', substr($head, 6, 2))[1];
    }

    /**
     * Operational re-wrap (D06 rotation): the DEK is unwrapped with its old KEK and re-wrapped with the ACTIVE KEK; the
     * secretstream header and every encrypted chunk are copied byte for byte (the content is never re-encrypted and
     * never touches the disk in clear). The caller verifies the new object's plaintext checksum before switching.
     * @param resource $in @param resource $out
     */
    public static function rewrap($in, $out, string $publicId, FilesKeyRing $ring): int
    {
        [, $dek] = self::openHeader($in, $publicId, $ring);
        try {
            $version = $ring->activeVersion();
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::aad($publicId, $version), $nonce, $ring->kek($version));
            self::write($out, self::MAGIC . pack('n', $version) . $nonce . $wrapped);
            while (($block = fread($in, 1 << 20)) !== false && $block !== '') {
                self::write($out, $block);
            }
            fflush($out);
            return $version;
        } finally {
            sodium_memzero($dek);
        }
    }

    /** @param resource $in @return array{0: int, 1: string} [key_version, DEK] */
    private static function openHeader($in, string $publicId, FilesKeyRing $ring): array
    {
        $version = self::headerVersion($in);
        $kek = $ring->kek($version);   // unknown version => CRYPTO_UNAVAILABLE (fail closed)
        $nonce = self::readExactly($in, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrapped = self::readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES);
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES || strlen($wrapped) !== 48) {
            throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'truncated_header']);
        }
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($wrapped, self::aad($publicId, $version), $nonce, $kek);
        if (!is_string($dek) || strlen($dek) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new FilesError(FilesReason::INTEGRITY_FAILURE, ['reason' => 'dek_unwrap']);
        }
        return [$version, $dek];
    }

    /** @param resource $in */
    private static function readExactly($in, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $part = fread($in, $length - strlen($buffer));
            if ($part === false) {
                throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'read_failed']);
            }
            if ($part === '') {
                break;
            }
            $buffer .= $part;
        }
        return $buffer;
    }

    /** @param resource $out */
    private static function write($out, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($out, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'write_failed']);
            }
            $offset += $written;
        }
    }
}
