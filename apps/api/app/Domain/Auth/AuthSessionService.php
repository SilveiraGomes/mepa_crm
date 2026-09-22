<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;

final class AuthSessionService
{
    private ?string $dummyHash = null;

    public function __construct(private Connection $db, private Hasher $hasher, private ?array $options = null)
    {
    }

    /** @return array{token:string,expires_at:string,user:array{public_id:string,login:string,account_kind:string}} */
    public function login(string $login, string $password): array
    {
        $candidate = $this->db->table('users')->where('login', $login)->first();
        $hash = $candidate?->password_hash;
        $valid = is_string($hash) && $hash !== ''
            ? $this->hasher->check($password, $hash)
            : $this->hasher->check($password, $this->dummyHash());
        if (!$valid || !$candidate) {
            throw new AuthError('INVALID_CREDENTIALS');
        }

        return $this->db->transaction(function () use ($candidate, $password): array {
            $user = $this->db->table('users')->where('id', $candidate->id)->lockForUpdate()->first();
            if (!$user || !$this->hasher->check($password, (string) $user->password_hash)
                || $user->archived_at !== null || !$this->activeUser($user)
                || (int) $user->mfa_required === 1 || !$this->hasActiveGrant((int) $user->id)) {
                throw new AuthError('INVALID_CREDENTIALS');
            }

            $now = new DateTimeImmutable((string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS now_utc')->now_utc, new DateTimeZone('UTC'));
            $ttl = max(1, (int) $this->option('session_ttl_minutes', 480));
            $expires = $now->modify('+' . $ttl . ' minutes');
            $token = $this->token();
            $this->db->table('auth_sessions')->insert([
                'user_id' => (int) $user->id,
                'token_hash' => hash('sha256', $token, true),
                'expires_at' => $expires->format('Y-m-d H:i:s.u'),
                'revoked_at' => null,
                'ip_hash' => null,
                'device_id' => null,
                'created_at' => $now->format('Y-m-d H:i:s.u'),
                'lock_version' => 0,
            ]);

            return [
                'token' => $token,
                'expires_at' => $expires->format('Y-m-d\TH:i:s.u\Z'),
                'user' => $this->publicUser($user),
            ];
        }, 3);
    }

    public function logout(?string $token): void
    {
        if (!is_string($token) || $token === '') {
            return;
        }
        $hash = hash('sha256', $token, true);
        $this->db->transaction(function () use ($hash): void {
            $session = $this->db->table('auth_sessions')->where('token_hash', $hash)->lockForUpdate()->first();
            if ($session && $session->revoked_at === null) {
                $now = (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS now_utc')->now_utc;
                $this->db->table('auth_sessions')->where('id', $session->id)->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'lock_version' => ((int) $session->lock_version) + 1,
                ]);
            }
        }, 3);
    }

    /** @return array{public_id:string,login:string,account_kind:string} */
    public function publicUser(object $user): array
    {
        return ['public_id' => (string) $user->public_id, 'login' => (string) $user->login, 'account_kind' => (string) $user->account_kind];
    }

    private function activeUser(object $user): bool
    {
        return in_array((string) $user->status, (array) $this->option('active_user_statuses', ['ACTIVE']), true);
    }

    private function hasActiveGrant(int $user): bool
    {
        $statuses = (array) $this->option('active_grant_statuses', ['ACTIVE']);
        if ($statuses === []) {
            return false;
        }
        return $this->db->table('user_role_scopes as urs')
            ->join('roles as r', 'r.id', '=', 'urs.role_id')
            ->where('urs.user_id', $user)->where('r.is_active', 1)
            ->whereIn('urs.status', $statuses)
            ->where('urs.starts_at', '<=', $this->db->raw('UTC_TIMESTAMP(6)'))
            ->where(static function ($query): void {
                $query->whereNull('urs.ends_at')->orWhere('urs.ends_at', '>', $query->getConnection()->raw('UTC_TIMESTAMP(6)'));
            })->exists();
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->make('not-a-valid-mepa-password');
    }

    private function option(string $key, mixed $default): mixed
    {
        return $this->options !== null ? ($this->options[$key] ?? $default) : config('auth_contract.' . $key, $default);
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
