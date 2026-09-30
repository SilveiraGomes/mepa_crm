<?php

namespace App\Exceptions;

use App\Domain\Academy\AcademyError;
use App\Domain\Files\FilesError;
use App\Domain\Finance\FinanceError;
use App\Domain\Membership\MembershipError;
use App\Domain\Membership\MembershipReason;
use App\Domain\People\PeopleError;
use App\Domain\Physical\PhysicalError;
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
        // P0.8 (ADR 0019 D03/D12): Files domain errors are rendered and logged as one structured line (reason, route,
        // actor) by their renderable; a reported stack trace would carry argument snippets (file names, content).
        FilesError::class,
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

        // P0.8 Documents/Files (ADR-0019 D04, F-06). Unknown, malformed, out-of-scope, above-clearance, consumer-unauthorized,
        // QUARANTINED, TOMBSTONE (without FILES_MANAGE) and PURGED targets share ONE byte-identical 404; no classification,
        // status or existence oracle. Storage/crypto failures are 503 without any detail.
        $this->renderable(function (FilesError $e, Request $request) {
            if (!$request->is('api/v1/files', 'api/v1/files/*', 'api/v1/documents', 'api/v1/documents/*')) {
                return null;
            }
            if (in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)) {
                Log::warning('files_http_concealed', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
                return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']], 404, ['Cache-Control' => 'no-store, private']);
            }
            [$status, $code, $message] = match ($e->reason) {
                'NOT_AUTHORIZED' => [403, 'FORBIDDEN', 'You are not authorized to perform this operation.'],
                'CLEARANCE_REQUIRED' => [403, 'CLEARANCE_REQUIRED', 'Your clearance does not allow this classification.'],
                'INVALID_INPUT' => [422, 'VALIDATION_ERROR', 'The request data is invalid.'],
                'REASON_REQUIRED' => [422, 'REASON_REQUIRED', 'A reason is required for this operation.'],
                'CLASSIFICATION_INVALID' => [422, 'CLASSIFICATION_INVALID', 'The classification is not a valid value.'],
                'FILE_EMPTY', 'FILE_TOO_LARGE', 'FILE_NAME_INVALID', 'FILE_TYPE_NOT_ALLOWED', 'FILE_CONTENT_REJECTED' => [422, $e->reason, 'The file was not accepted.'],
                'QUOTA_EXCEEDED' => [422, 'FILES_QUOTA_EXCEEDED', 'The storage quota does not allow this file.'],
                'FILE_IN_USE', 'STALE_WRITE', 'TRANSITION_NOT_ALLOWED', 'DOCUMENT_ARCHIVED', 'CLASSIFICATION_BELOW_FLOOR' => [409, $e->reason, 'The request conflicts with the current resource state.'],
                'STORAGE_UNAVAILABLE' => [503, 'FILES_STORAGE_UNAVAILABLE', 'File storage is temporarily unavailable.'],
                'SCANNER_UNAVAILABLE' => [503, 'FILES_SCANNER_UNAVAILABLE', 'File inspection is temporarily unavailable.'],
                'CONTENT_UNAVAILABLE', 'CRYPTO_UNAVAILABLE', 'INTEGRITY_FAILURE' => [503, 'FILE_CONTENT_UNAVAILABLE', 'The file content is temporarily unavailable.'],
                'CONFIG_MISSING' => [503, 'FILES_NOT_CONFIGURED', 'The Documents/Files configuration is not available.'],
                default => [409, 'CONFLICT', 'The operation could not be completed.'],
            };
            Log::notice('files_http_domain_error', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
            $body = ['code' => $code, 'message' => $message];
            if ($status === 422 && isset($e->context['field'])) {
                $body['details'] = ['fields' => [(string) $e->context['field'] => ['invalid']]];
            }
            $safe = array_intersect_key($e->details, array_flip(['reason_code', 'file_public_id', 'status']));
            if ($safe !== []) {
                $body['details'] = ($body['details'] ?? []) + $safe;
            }
            return response()->json(['error' => $body], $status, ['Cache-Control' => 'no-store, private']);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/files', 'api/v1/files/*', 'api/v1/documents', 'api/v1/documents/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $e->errors()]]], 422);
        });

        // P0.10 Finance (ADR-0021 D20). F-06: unknown, malformed and out-of-scope transfers, contributions, units,
        // accounts and supporting documents are the same byte-identical 404; permission (403) is decided before any
        // target is resolved. Domain codes are stable and never carry internal ids.
        $this->renderable(function (FinanceError $e, Request $request) {
            if (!$request->is('api/v1/finance', 'api/v1/finance/*')) {
                return null;
            }
            if (in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)) {
                Log::warning('finance_http_concealed', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
                return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']], 404);
            }
            [$status, $code, $message] = match (true) {
                $e->reason === 'NOT_AUTHORIZED' => [403, 'FORBIDDEN', 'You are not authorized to perform this operation.'],
                $e->reason === 'INVALID_INPUT' => [422, 'VALIDATION_ERROR', 'The request data is invalid.'],
                in_array($e->reason, ['AMOUNT_INVALID', 'AMOUNT_SCALE', 'AMOUNT_LIMIT', 'AMOUNT_NOT_POSITIVE', 'ENTRY_DATE_IN_FUTURE', 'ENTRY_DATE_INVALID', 'REASON_REQUIRED', 'INTERNAL_COUNTERPARTY', 'VALUATION_DOCUMENT_REQUIRED'], true) => [422, $e->reason, 'The request data is invalid.'],
                $e->reason === 'CONFIG_MISSING' => [503, 'FINANCE_NOT_CONFIGURED', 'The Finance configuration is not available.'],
                $e->reason === 'BUSY' => [503, 'FINANCE_BUSY', 'The operation could not be completed now; nothing was changed.'],
                in_array($e->reason, ['INVARIANT_VIOLATION', 'STORAGE_CONFLICT'], true) => [409, 'CONFLICT', 'The operation could not be completed.'],
                default => [409, $e->reason, 'The request conflicts with the current resource state.'],
            };
            Log::notice('finance_http_domain_error', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
            $body = ['code' => $code, 'message' => $message];
            if ($status === 422 && isset($e->context['field'])) {
                $body['details'] = ['fields' => [(string) $e->context['field'] => ['invalid']]];
            }
            if ($e->reason === 'RECONCILIATION_MISMATCH') {
                $body['details'] = ['mismatches' => $e->items];
            }
            return response()->json(['error' => $body], $status);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/finance', 'api/v1/finance/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $e->errors()]]], 422);
        });

        // P0.9 Membership (ADR-0020 D10). F-06: unknown, malformed and out-of-scope memberships, transfers, candidacies,
        // milestones, legacy identifiers and source documents share one byte-identical response.
        $this->renderable(function (MembershipError $e, Request $request) {
            if (!$request->is('api/v1/memberships', 'api/v1/memberships/*')) {
                return null;
            }
            if (in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)) {
                Log::warning('membership_http_concealed', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
                return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']], 404);
            }
            [$status, $code, $message] = match (true) {
                $e->reason === 'NOT_AUTHORIZED' => [403, 'FORBIDDEN', 'You are not authorized to perform this operation.'],
                $e->reason === 'INVALID_INPUT' => [422, 'VALIDATION_ERROR', 'The request data is invalid.'],
                $e->reason === 'REASON_REQUIRED' => [422, 'REASON_REQUIRED', 'A reason is required for this operation.'],
                $e->reason === 'COLLECTIVE_REJECTED' => [409, 'COLLECTIVE_APPROVAL_REJECTED', 'No membership was approved: the list contains items that cannot be approved.'],
                in_array($e->reason, MembershipReason::CONFLICTS, true) => [409, $e->reason, 'The request conflicts with the current resource state.'],
                $e->reason === 'NUMBER_UNAVAILABLE' => [503, 'MEMBER_NUMBER_UNAVAILABLE', 'The official member number cannot be issued at the moment.'],
                $e->reason === 'CONFIG_MISSING' => [503, 'MEMBERSHIP_NOT_CONFIGURED', 'The Membership configuration is not available.'],
                default => [409, 'CONFLICT', 'The operation could not be completed.'],
            };
            Log::notice('membership_http_domain_error', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
            $body = ['code' => $code, 'message' => $message];
            if ($status === 422 && isset($e->context['field'])) {
                $body['details'] = ['fields' => [(string) $e->context['field'] => ['invalid']]];
            }
            if ($e->items !== []) {
                $body['details'] = ['items' => $e->items];
            }
            return response()->json(['error' => $body], $status);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/memberships', 'api/v1/memberships/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $e->errors()]]], 422);
        });

        // P0.7 Physical Locations (ADR-0018). F-06: unknown, malformed, out-of-scope and unlinked (no active vigente
        // link) locations, properties, temples and links share one response.
        $this->renderable(function (PhysicalError $e, Request $request) {
            if (!$request->is('api/v1/physical', 'api/v1/physical/*')) {
                return null;
            }
            if (in_array($e->reason, ['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'], true)) {
                Log::warning('physical_http_concealed', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
                return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']], 404);
            }
            [$status, $code, $message] = match ($e->reason) {
                'NOT_AUTHORIZED' => [403, 'FORBIDDEN', 'You are not authorized to perform this operation.'],
                'INVALID_INPUT' => [422, 'VALIDATION_ERROR', 'The request data is invalid.'],
                'REASON_REQUIRED' => [422, 'REASON_REQUIRED', 'A reason is required for this operation.'],
                'STALE_WRITE', 'TRANSITION_NOT_ALLOWED', 'ACTIVE_DEPENDENCIES', 'LAST_ACTIVE_LINK_REQUIRED', 'LINK_EXISTS', 'LOCATION_CLOSED',
                'LOCATION_NOT_ACTIVE', 'COORDINATES_REQUIRED', 'UNIT_NOT_OPERATIONAL', 'CODE_EXISTS' => [409, $e->reason, 'The request conflicts with the current resource state.'],
                'CRYPTO_UNAVAILABLE' => [503, 'PHYSICAL_CRYPTO_UNAVAILABLE', 'Protected data is temporarily unavailable.'],
                'CONFIG_MISSING' => [503, 'PHYSICAL_NOT_CONFIGURED', 'The Physical Locations configuration is not available.'],
                default => [409, 'CONFLICT', 'The operation could not be completed.'],
            };
            Log::notice('physical_http_domain_error', ['reason' => $e->reason, 'route' => $request->route()?->uri(), 'actor_id' => $request->user()?->getAuthIdentifier()]);
            $fields = $status === 422 && isset($e->context['field']) ? ['details' => ['fields' => [(string) $e->context['field'] => ['invalid']]]] : [];
            return response()->json(['error' => ['code' => $code, 'message' => $message] + $fields], $status);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (!$request->is('api/v1/physical', 'api/v1/physical/*')) {
                return null;
            }
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The request data is invalid.', 'details' => ['fields' => $e->errors()]]], 422);
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
