<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AcademyHttpAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $dsn = (string) getenv('WAVE5_DSN');
        parse_str(str_replace(';', '&', substr($dsn, 6)), $parts);
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $parts['host'] ?? '127.0.0.1',
            'database.connections.mysql.port' => $parts['port'] ?? 3306,
            'database.connections.mysql.database' => $parts['dbname'] ?? '',
            'database.connections.mysql.username' => getenv('WAVE5_USER') ?: 'root',
            'database.connections.mysql.password' => getenv('WAVE5_PASSWORD') ?: '',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    public function test_valid_existing_session_reaches_the_academy_layer(): void
    {
        $token = 'a3-http-valid-' . bin2hex(random_bytes(8));
        $this->createAuthSession($token, null, '+1 hour');

        $this->getJson('/api/v1/academy/classes/not-a-public-id/enrollments', ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(422)
            ->assertExactJson(['error' => ['code' => 'ACADEMIC_POLICY_NOT_CONFIGURED', 'message' => 'The academic policy required for this operation is not configured.']]);
    }

    public function test_revoked_expired_and_unknown_tokens_are_uniformly_unauthenticated(): void
    {
        $revoked = 'a3-http-revoked-' . bin2hex(random_bytes(8));
        $expired = 'a3-http-expired-' . bin2hex(random_bytes(8));
        $this->createAuthSession($revoked, now()->subMinute()->format('Y-m-d H:i:s.u'), '+1 hour');
        $this->createAuthSession($expired, null, '-1 minute');
        $expected = ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']];

        foreach ([$revoked, $expired, 'a3-http-unknown'] as $token) {
            $this->getJson('/api/v1/academy/classes/not-a-public-id/enrollments', ['Authorization' => 'Bearer ' . $token])
                ->assertStatus(401)->assertExactJson($expected);
        }
    }

    private function createAuthSession(string $token, ?string $revokedAt, string $expiry): void
    {
        $user = (int) DB::table('users')->min('id');
        self::assertGreaterThan(0, $user);
        DB::table('auth_sessions')->insert([
            'user_id' => $user,
            'token_hash' => hash('sha256', $token, true),
            'expires_at' => now('UTC')->modify($expiry)->format('Y-m-d H:i:s.u'),
            'revoked_at' => $revokedAt,
            'ip_hash' => null,
            'device_id' => null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'lock_version' => 0,
        ]);
    }
}
