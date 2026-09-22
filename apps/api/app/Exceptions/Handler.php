<?php

namespace App\Exceptions;

use App\Domain\Academy\AcademyError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->renderable(function (TooManyRequestsHttpException $e, Request $request) {
            if (!$request->is('api/v1/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests. Try again later.']], 429, $e->getHeaders());
        });

        $this->renderable(function (AcademyError $e, Request $request) {
            if (!$request->is('api/v1/academy/*')) {
                return null;
            }

            $concealed = in_array($e->reason, [
                'TARGET_NOT_FOUND', 'NOT_AUTHORIZED', 'OUT_OF_SCOPE',
                'CLASS_ASSIGNMENT_REQUIRED', 'CONTEXT_MISMATCH', 'INVALID_PERSON_REFERENCE',
            ], true);
            if ($concealed) {
                Log::warning('academy_http_concealed', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
                return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']], 404);
            }

            [$status, $code, $message] = match ($e->reason) {
                'ALREADY_ENROLLED', 'ALREADY_ASSIGNED', 'CERTIFICATE_ALREADY_EXISTS',
                'CERTIFICATE_ALREADY_REVOKED', 'GRADE_ALREADY_RECORDED', 'STALE_WRITE',
                'CURRICULUM_ALREADY_PUBLISHED', 'CURRICULUM_PUBLISHED_IMMUTABLE' => [409, $e->reason, 'The request conflicts with the current resource state.'],
                'POLICY_NOT_CONFIGURED' => [422, 'ACADEMIC_POLICY_NOT_CONFIGURED', 'The academic policy required for this operation is not configured.'],
                'STATE_POLICY_PENDING' => [422, 'STATE_POLICY_PENDING', 'The state policy required for this operation is pending.'],
                'CONSENT_REQUIRED', 'GUARDIAN_AUTHORIZATION_INVALID', 'CHILD_NOT_ELIGIBLE' => [422, 'PARTICIPATION_REQUIREMENTS_NOT_MET', 'Participation requirements are not met.'],
                'INVALID_INPUT' => [422, 'VALIDATION_ERROR', 'The request data is invalid.'],
                default => [409, 'CONFLICT', 'The operation could not be completed.'],
            };
            Log::notice('academy_http_domain_error', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
            return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/academy/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $e->errors()]]], 422);
        });

        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
