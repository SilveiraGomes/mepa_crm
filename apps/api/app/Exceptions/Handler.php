<?php

namespace App\Exceptions;

use App\Domain\Academy\AcademyError;
use App\Domain\People\PeopleError;
use App\Domain\Territorial\TerritorialError;
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

        $this->renderable(function (TerritorialError $e, Request $request) {
            if (!$request->is('api/v1/territorial', 'api/v1/territorial/*')) return null;
            if (in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)) {
                Log::warning('territorial_http_concealed', ['reason'=>$e->reason,'route'=>$request->route()?->uri(),'actor_id'=>$request->user()?->getAuthIdentifier()]);
                return response()->json(['error'=>['code'=>'RESOURCE_NOT_FOUND','message'=>'The requested resource was not found.']],404);
            }
            [$status,$code,$message]=match($e->reason){
                'NOT_AUTHORIZED'=>[403,'FORBIDDEN','You are not authorized to perform this operation.'],
                'INVALID_INPUT'=>[422,'VALIDATION_ERROR','The request data is invalid.'],
                'INVALID_PARENT_TYPE','CYCLE_DETECTED','ROOT_MOVE_FORBIDDEN','TRANSITION_NOT_ALLOWED','ACTIVE_DEPENDENCIES','MUNICIPAL_CENTER_REQUIRED','GENERAL_CENTER_EXISTS','STALE_WRITE'=>[409,$e->reason,'The request conflicts with the territorial structure.'],
                default=>[409,'CONFLICT','The operation could not be completed.'],
            };
            Log::notice('territorial_http_domain_error',['reason'=>$e->reason,'route'=>$request->route()?->uri(),'actor_id'=>$request->user()?->getAuthIdentifier()]);
            return response()->json(['error'=>['code'=>$code,'message'=>$message]],$status);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/territorial', 'api/v1/territorial/*')) return null;
            return response()->json(['error'=>['code'=>'VALIDATION_ERROR','message'=>'The request data is invalid.','details'=>['fields'=>$e->errors()]]],422);
        });

        // P0.5-I People / Families. F-06: unknown, malformed and out-of-scope targets share one response.
        $this->renderable(function (PeopleError $e, Request $request) {
            if (!$request->is('api/v1/people', 'api/v1/people/*')) {
                return null;
            }
            if (in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)) {
                Log::warning('people_http_concealed', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
                return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']], 404);
            }
            [$status, $code, $message] = match ($e->reason) {
                'NOT_AUTHORIZED', 'FORBIDDEN' => [403, 'FORBIDDEN', 'You are not authorized to perform this operation.'],
                'SENSITIVE_RESTRICTED' => [403, 'SENSITIVE_DATA_RESTRICTED', 'Sensitive data requires a specific permission.'],
                'MINOR_PROTECTED' => [403, 'MINOR_PROTECTED', 'Data of minors is not available in this projection.'],
                'INVALID_INPUT', 'CONTEXT_UNIT_INVALID' => [422, 'VALIDATION_ERROR', 'The request data is invalid.'],
                'REASON_REQUIRED' => [422, 'REASON_REQUIRED', 'A reason is required for this operation.'],
                'EXPORT_TOO_LARGE' => [422, 'EXPORT_TOO_LARGE', 'The export exceeds the allowed size. Narrow the filters.'],
                'STALE_WRITE', 'TRANSITION_NOT_ALLOWED', 'PERSON_DECEASED', 'CONTACT_EXISTS', 'ALREADY_MEMBER',
                'HOUSEHOLD_NOT_ACTIVE', 'RELATIONSHIP_EXISTS', 'RELATIONSHIP_CONFLICT' => [409, $e->reason, 'The request conflicts with the current resource state.'],
                'CRYPTO_UNAVAILABLE' => [503, 'PEOPLE_CRYPTO_UNAVAILABLE', 'Protected data is temporarily unavailable.'],
                'CATALOG_INVALID', 'CONFIG_MISSING' => [503, 'PEOPLE_NOT_CONFIGURED', 'The People configuration is not available.'],
                default => [409, 'CONFLICT', 'The operation could not be completed.'],
            };
            Log::notice('people_http_domain_error', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
            $fields = $status === 422 && isset($e->context['field']) ? ['details' => ['fields' => [(string) $e->context['field'] => ['invalid']]]] : [];
            return response()->json(['error' => ['code' => $code, 'message' => $message] + $fields], $status);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/people', 'api/v1/people/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $e->errors()]]], 422);
        });

        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
