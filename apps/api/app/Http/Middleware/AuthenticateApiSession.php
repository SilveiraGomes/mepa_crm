<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!is_string($token) || $token === '') {
            return $this->unauthenticated();
        }

        $session = DB::table('auth_sessions')
            ->where('token_hash', hash('sha256', $token, true))
            ->first();
        if (!$session) {
            return $this->unauthenticated();
        }
        if ($session->revoked_at !== null) {
            return $this->denied('SESSION_REVOKED', 'The session is no longer active.');
        }
        $now = (string) DB::selectOne('SELECT UTC_TIMESTAMP(6) AS now_utc')->now_utc;
        if ((string) $session->expires_at <= $now) {
            return $this->denied('SESSION_EXPIRED', 'The session has expired.');
        }

        $user = \App\Models\User::query()->find($session->user_id);
        $activeStatuses = (array) config('auth_contract.active_user_statuses', ['ACTIVE']);
        if (!$user || $user->archived_at !== null || !in_array((string) $user->status, $activeStatuses, true)) {
            return $this->unauthenticated();
        }
        $grantStatuses = (array) config('auth_contract.active_grant_statuses', ['ACTIVE']);
        $hasGrant = $grantStatuses !== [] && DB::table('user_role_scopes as urs')
            ->join('roles as r', 'r.id', '=', 'urs.role_id')
            ->where('urs.user_id', $user->id)->where('r.is_active', 1)
            ->whereIn('urs.status', $grantStatuses)
            ->where('urs.starts_at', '<=', DB::raw('UTC_TIMESTAMP(6)'))
            ->where(static function ($query): void {
                $query->whereNull('urs.ends_at')->orWhere('urs.ends_at', '>', DB::raw('UTC_TIMESTAMP(6)'));
            })->exists();
        if (!$hasGrant) {
            return $this->unauthenticated();
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('auth_session_id', (int) $session->id);
        $request->attributes->set('auth_session_expires_at', (string) $session->expires_at);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']], 401);
    }

    private function denied(string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], 401);
    }
}
