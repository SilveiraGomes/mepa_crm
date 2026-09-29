<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\People\BirthDate;
use App\Domain\People\OpaqueRef;
use App\Domain\People\PeopleCrypto;
use App\Domain\People\PeopleError;
use App\Domain\People\PeopleReason;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * P0.5-I secret store qualification (ADR-0017 D-08) for the test environment: the key ring is read from
 * a file outside the repository, the encryption key and the blind-index key are distinct, every missing /
 * misplaced / malformed / reused / unknown key fails closed, and no secret or plaintext leaves through
 * exceptions or debug output. Also covers birth-precision validation (D-10) and opaque references.
 */
final class PeopleCryptoTest extends TestCase
{
    private string $dir;
    private string $enc;
    private string $mac;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-people-unit-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->enc = base64_encode(random_bytes(32));
        $this->mac = base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function ring(array $data): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    private function refused(callable $fn, string $reason): void
    {
        try {
            $fn();
            self::fail('expected CRYPTO_UNAVAILABLE / ' . $reason);
        } catch (PeopleError $e) {
            self::assertSame(PeopleReason::CRYPTO_UNAVAILABLE, $e->reason);
            self::assertSame($reason, $e->context['reason']);
            foreach ([$this->enc, $this->mac, base64_decode($this->enc), base64_decode($this->mac)] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage() . json_encode($e->context) . $e->getTraceAsString());
            }
        }
    }

    public function test_loads_encryption_and_distinct_blind_index_keys_from_a_ring_outside_the_repository(): void
    {
        $repo = dirname(__DIR__, 4);
        $crypto = PeopleCrypto::fromKeyRingFile($this->ring(['active_version' => 3, 'keys' => ['3' => ['encryption' => $this->enc, 'blind_index' => $this->mac]]]), [$repo]);
        self::assertSame(3, $crypto->activeVersion());
        [$ciphertext, $version] = $crypto->encrypt('+244 923 000 000', 'aad-1');
        self::assertSame(3, $version);
        self::assertStringNotContainsString('923', $ciphertext);
        self::assertSame('+244 923 000 000', $crypto->decrypt($ciphertext, 3, 'aad-1'));
        self::assertNotSame($crypto->encrypt('x', 'a')[0], $crypto->encrypt('x', 'a')[0], 'random nonce');
        $index = $crypto->blindIndex('phone:+244923000000');
        self::assertSame(32, strlen($index));
        self::assertSame(hash_hmac('sha256', 'phone:+244923000000', base64_decode($this->mac), true), $index);
        self::assertNotSame(hash_hmac('sha256', 'phone:+244923000000', base64_decode($this->enc), true), $index, 'HMAC key differs from the encryption key');
        self::assertStringNotContainsString(base64_decode($this->enc), print_r($crypto, true));
        self::assertStringContainsString('active_version', print_r($crypto, true));
    }

    public function test_fails_closed_for_every_missing_or_unsafe_key_material(): void
    {
        $repo = dirname(__DIR__, 4);
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile(null, []), 'keyring_not_configured');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile('', []), 'keyring_not_configured');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile($this->dir . '/absent.json', []), 'keyring_unreadable');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile($repo . '/apps/api/composer.json', [$repo]), 'keyring_insecure_location');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile($this->ring(['active_version' => 1, 'keys' => ['1' => ['encryption' => $this->enc, 'blind_index' => $this->enc]]]), []), 'key_reuse');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile($this->ring(['active_version' => 1, 'keys' => ['1' => ['encryption' => base64_encode('short'), 'blind_index' => $this->mac]]]), []), 'keyring_malformed');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile($this->ring(['active_version' => 2, 'keys' => ['1' => ['encryption' => $this->enc, 'blind_index' => $this->mac]]]), []), 'active_version_missing');
        $this->refused(fn () => PeopleCrypto::fromKeyRingFile($this->ring(['keys' => []]), []), 'keyring_malformed');
        $crypto = PeopleCrypto::fromArray(['active_version' => 1, 'keys' => ['1' => ['encryption' => $this->enc, 'blind_index' => $this->mac]]]);
        [$ciphertext] = $crypto->encrypt('segredo-contacto', 'person-A');
        $this->refused(fn () => $crypto->decrypt($ciphertext, 9, 'person-A'), 'unknown_key_version');
        $this->refused(fn () => $crypto->decrypt($ciphertext, 1, 'person-B'), 'integrity');
        $this->refused(fn () => $crypto->decrypt(substr($ciphertext, 0, -1) . chr(ord(substr($ciphertext, -1)) ^ 1), 1, 'person-A'), 'integrity');
        $this->refused(fn () => $crypto->decrypt('plaintext-fallback', 1, 'person-A'), 'ciphertext_format');
        $this->refused(fn () => serialize($crypto), 'not_serializable');
    }

    public function test_birth_precision_never_invents_components(): void
    {
        $today = new DateTimeImmutable('2026-09-23');
        self::assertSame(['birth_precision' => 'MONTH', 'birth_date' => null, 'birth_year' => 1990, 'birth_month' => 3], BirthDate::columns(['birth_precision' => 'MONTH', 'birth_year' => 1990, 'birth_month' => 3], $today));
        self::assertSame(['birth_precision' => 'YEAR', 'birth_date' => null, 'birth_year' => 1990, 'birth_month' => null], BirthDate::columns(['birth_precision' => 'YEAR', 'birth_year' => '1990'], $today));
        foreach ([['birth_precision' => 'MONTH', 'birth_year' => 2026, 'birth_month' => 10], ['birth_precision' => 'EXACT', 'birth_date' => '2026-09-24'], ['birth_precision' => 'YEAR', 'birth_year' => 1899]] as $future) {
            try {
                BirthDate::columns($future, $today);
                self::fail('accepted ' . json_encode($future));
            } catch (PeopleError $e) {
                self::assertSame(PeopleReason::INVALID_INPUT, $e->reason);
            }
        }
        self::assertSame('MINOR', BirthDate::ageBand((object) ['birth_precision' => 'EXACT', 'birth_date' => '2008-09-24'], $today, 18));
        self::assertSame('ADULT', BirthDate::ageBand((object) ['birth_precision' => 'EXACT', 'birth_date' => '2008-09-23'], $today, 18));
        self::assertSame('UNCERTAIN', BirthDate::ageBand((object) ['birth_precision' => 'MONTH', 'birth_year' => 2008, 'birth_month' => 9], $today, 18));
        self::assertSame('ADULT', BirthDate::ageBand((object) ['birth_precision' => 'MONTH', 'birth_year' => 2008, 'birth_month' => 8], $today, 18));
        self::assertSame('UNCERTAIN', BirthDate::ageBand((object) ['birth_precision' => 'YEAR', 'birth_year' => 2008], $today, 18));
        self::assertNull(BirthDate::ageBand((object) ['birth_precision' => 'UNKNOWN'], $today, 18));
    }

    public function test_opaque_references_are_parent_scoped_and_not_primary_keys(): void
    {
        $refs = new OpaqueRef(random_bytes(32));
        $ref = $refs->for('person_contacts', 42);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ref);
        self::assertNotSame('42', $ref);
        self::assertSame(42, $refs->resolve('person_contacts', $ref, [7, 42]));
        self::assertNull($refs->resolve('person_contacts', $ref, [7, 43]), 'resolution only inside the parent candidates');
        self::assertNull($refs->resolve('person_addresses', $ref, [42]), 'kind-bound');
        self::assertNull($refs->resolve('person_contacts', '42', [42]));
        $this->expectException(PeopleError::class);
        new OpaqueRef('short');
    }
}
