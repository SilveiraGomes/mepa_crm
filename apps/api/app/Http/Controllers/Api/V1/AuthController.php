<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthError;
use App\Domain\Auth\AuthSessionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AuthController extends Controller
{
    public function login(Request $request, AuthSessionService $sessions): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:4096'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $validator->errors()->toArray()]]], 422);
        }
        try {
            $result = $sessions->login(trim((string) $request->input('login')), (string) $request->input('password'));
        } catch (AuthError $error) {
            return response()->json(['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'The supplied credentials are invalid.']], 401);
        }
        return response()->json(['data' => [
            'session' => ['token' => $result['token'], 'token_type' => 'Bearer', 'expires_at' => $result['expires_at']],
            'user' => $result['user'],
        ]], 201);
    }

    public function me(Request $request, AuthSessionService $sessions): JsonResponse
    {
        return response()->json(['data' => [
            'user' => $sessions->publicUser($request->user()),
            'session' => ['expires_at' => $request->attributes->get('auth_session_expires_at')],
        ]]);
    }

    public function logout(Request $request, AuthSessionService $sessions): JsonResponse
    {
        $sessions->logout($request->bearerToken());
        return response()->json(null, 204);
    }
}
