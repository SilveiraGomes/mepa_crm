<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Auth\AuthError;
use App\Domain\Auth\AuthSessionService;
use Illuminate\Hashing\BcryptHasher;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

final class AuthSessionContractTest extends PooledWaveFiveCase
{
    private const PASSWORD = 'Correct-Horse-42!';

    public function test_auth_01_valid_credentials_issue_an_opaque_bound_expiring_session(): void
    {
        [$actor, $service] = $this->account(['ACADEMY_VIEW']);
        $result = $service->login($actor['login'], self::PASSWORD);

        self::assertSame($actor['public_id'], $result['user']['public_id']);
        self::assertArrayNotHasKey('password_hash', $result['user']);
        self::assertGreaterThanOrEqual(43, strlen($result['token']));
        $stored = $this->db()->table('auth_sessions')->where('token_hash', hash('sha256', $result['token'], true))->first();
        self::assertNotNull($stored);
        self::assertSame($actor['user'], (int) $stored->user_id);
        self::assertNull($stored->revoked_at);
    }

    public function test_auth_02_and_03_wrong_and_unknown_credentials_have_the_same_external_reason(): void
    {
        [$actor, $service] = $this->account(['ACADEMY_VIEW']);
        foreach ([[$actor['login'], 'wrong'], ['missing-user', self::PASSWORD]] as [$login, $password]) {
            try {
                $service->login($login, $password);
                self::fail('Expected credential denial');
            } catch (AuthError $error) {
                self::assertSame('INVALID_CREDENTIALS', $error->reason);
            }
        }
    }

    public function test_auth_04_05_06_and_07_expiration_revocation_logout_and_repeat_logout_are_deterministic(): void
    {
        [$actor, $service] = $this->account(['ACADEMY_VIEW']);
        $result = $service->login($actor['login'], self::PASSWORD);
        $hash = hash('sha256', $result['token'], true);
        $session = $this->db()->table('auth_sessions')->where('token_hash', $hash)->first();
        self::assertGreaterThan($this->now()->format('Y-m-d H:i:s.u'), (string) $session->expires_at);

        $service->logout($result['token']);
        $revoked = $this->db()->table('auth_sessions')->where('token_hash', $hash)->first();
        self::assertNotNull($revoked->revoked_at);
        $version = (int) $revoked->lock_version;
        $service->logout($result['token']);
        self::assertSame($version, (int) $this->db()->table('auth_sessions')->where('token_hash', $hash)->value('lock_version'));
    }

    public function test_auth_09_effective_permissions_belong_to_the_authenticated_user(): void
    {
        [$actor, $service] = $this->account(['ACADEMY_VIEW', 'ACADEMY_CERTIFY']);
        $result = $service->login($actor['login'], self::PASSWORD);
        $session = (int) $this->db()->table('auth_sessions')->where('token_hash', hash('sha256', $result['token'], true))->value('id');
        self::assertSame(['ACADEMY_VIEW', 'ACADEMY_CERTIFY'], $this->rt()->access->effectivePermissionCodes($actor['user'], $session));
    }

    public function test_auth_10_tokens_are_isolated_between_users(): void
    {
        [$first, $service] = $this->account(['ACADEMY_VIEW']);
        [$second] = $this->account(['ACADEMY_VIEW']);
        $result = $service->login($first['login'], self::PASSWORD);
        $owner = (int) $this->db()->table('auth_sessions')->where('token_hash', hash('sha256', $result['token'], true))->value('user_id');
        self::assertSame($first['user'], $owner);
        self::assertNotSame($second['user'], $owner);
    }

    /** @return array{0:array{user:int,login:string,public_id:string},1:AuthSessionService} */
    private function account(array $permissions): array
    {
        $world = $this->world();
        $actor = $this->actor($world['unit'], $permissions);
        $login = 'auth_' . bin2hex(random_bytes(6));
        $publicId = (string) $this->db()->table('users')->where('id', $actor['user'])->value('public_id');
        $hasher = new BcryptHasher(['rounds' => 4]);
        $this->db()->table('users')->where('id', $actor['user'])->update([
            'login' => $login, 'password_hash' => $hasher->make(self::PASSWORD), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0,
        ]);
        $this->db()->table('user_role_scopes')->where('id', $actor['link'])->update([
            'status' => 'SYNTHETIC_READY', 'starts_at' => $this->later('-1 hour'), 'ends_at' => null,
        ]);
        $service = new AuthSessionService($this->db(), $hasher, [
            'session_ttl_minutes' => 480, 'active_user_statuses' => ['SYNTHETIC_READY'], 'active_grant_statuses' => ['SYNTHETIC_READY'],
        ]);
        return [['user' => $actor['user'], 'login' => $login, 'public_id' => $publicId], $service];
    }
}
