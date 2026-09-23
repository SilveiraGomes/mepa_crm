<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\DatabaseV2\Support\HttpWaveFiveCase;

final class AcademyHttpAuthenticationTest extends HttpWaveFiveCase
{
    public function test_auth_01_login_and_current_user_contract(): void
    {
        $account = $this->authAccount();
        $login = $this->postJson('/api/v1/auth/login', ['login' => $account['login'], 'password' => $account['password']])
            ->assertCreated()->assertJsonPath('data.user.login', $account['login'])
            ->assertJsonPath('data.session.token_type', 'Bearer')
            ->assertJsonMissing(['password_hash']);
        $token = (string) $login->json('data.session.token');
        self::assertGreaterThanOrEqual(43, strlen($token));
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()->assertJsonPath('data.user.login', $account['login']);
    }

    public function test_auth_02_and_03_wrong_password_and_unknown_account_are_identical(): void
    {
        $account = $this->authAccount();
        $expected = ['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'The supplied credentials are invalid.']];
        $this->postJson('/api/v1/auth/login', ['login' => $account['login'], 'password' => 'wrong'])
            ->assertStatus(401)->assertExactJson($expected);
        $this->postJson('/api/v1/auth/login', ['login' => 'unknown-' . bin2hex(random_bytes(4)), 'password' => 'wrong'])
            ->assertStatus(401)->assertExactJson($expected);
    }

    public function test_auth_04_and_05_expired_revoked_and_unknown_tokens_have_explicit_safe_errors(): void
    {
        $account = $this->authAccount();
        $expired = $this->token($account['user'], '-1 minute', null);
        $revoked = $this->token($account['user'], '+1 hour', now('UTC')->format('Y-m-d H:i:s.u'));
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer ' . $expired])
            ->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer ' . $revoked])
            ->assertStatus(401)->assertJsonPath('error.code', 'SESSION_REVOKED');
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer unknown'])
            ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_auth_06_and_07_logout_revokes_and_is_idempotent(): void
    {
        $account = $this->authAccount();
        $token = (string) $this->postJson('/api/v1/auth/login', ['login' => $account['login'], 'password' => $account['password']])->json('data.session.token');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();
        $this->getJson('/api/v1/auth/me', $headers)->assertStatus(401)->assertJsonPath('error.code', 'SESSION_REVOKED');
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();
    }

    public function test_auth_08_login_rate_limit_is_enforced_with_the_contract_error(): void
    {
        $key = md5('login' . hash('sha256', '127.0.0.1'));
        RateLimiter::clear($key);
        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->postJson('/api/v1/auth/login', ['login' => 'missing', 'password' => 'wrong'])->assertStatus(401);
            }
            $this->postJson('/api/v1/auth/login', ['login' => 'missing', 'password' => 'wrong'])
                ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', '0');
        } finally {
            // The deliberate throttle proof must not contaminate later scenarios.
            RateLimiter::clear($key);
        }
    }

    private function token(int $user, string $expiry, ?string $revoked): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('auth_sessions')->insert([
            'user_id' => $user, 'token_hash' => hash('sha256', $token, true),
            'expires_at' => now('UTC')->modify($expiry)->format('Y-m-d H:i:s.u'), 'revoked_at' => $revoked,
            'ip_hash' => null, 'device_id' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0,
        ]);
        return $token;
    }
}
