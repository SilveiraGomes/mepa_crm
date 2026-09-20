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
            ->whereNull('revoked_at')
            ->where('expires_at', '>', DB::raw('UTC_TIMESTAMP(6)'))
            ->first();
        $user = $session ? \App\Models\User::query()->find($session->user_id) : null;
        if (!$session || !$user || $user->archived_at !== null) {
            return $this->unauthenticated();
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('auth_session_id', (int) $session->id);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']], 401);
    }
}
