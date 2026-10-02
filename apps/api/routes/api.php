<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Academy\AttendanceController;
use App\Http\Controllers\Api\V1\Payroll\HrController;
use App\Http\Controllers\Api\V1\Academy\AssessmentController;
use App\Http\Controllers\Api\V1\Academy\AttemptController;
use App\Http\Controllers\Api\V1\Academy\CertificateController;
use App\Http\Controllers\Api\V1\Academy\CurriculumController;
use App\Http\Controllers\Api\V1\Academy\EnrollmentController;
use App\Http\Controllers\Api\V1\Academy\GradeController;
use App\Http\Controllers\Api\V1\Academy\InstructorController;
use App\Http\Controllers\Api\V1\Academy\ProgressController;
use App\Http\Controllers\Api\V1\Academy\SessionController;
use App\Http\Controllers\Api\V1\Academy\TranscriptController;
use App\Http\Controllers\Api\V1\Academy\CatalogQueryController;
use App\Http\Controllers\Api\V1\Academy\ClassQueryController;
use App\Http\Controllers\Api\V1\Academy\AssessmentQueryController;
use App\Http\Controllers\Api\V1\Academy\DocumentQueryController;
use App\Http\Controllers\Api\V1\Academy\AcademyContextController;
use App\Http\Controllers\Api\V1\Academy\EligibleFileController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Territorial\TerritorialController;
use App\Http\Controllers\Api\V1\Files\DocumentController;
use App\Http\Controllers\Api\V1\Files\FileController;
use App\Http\Controllers\Api\V1\Finance\ContributionController;
use App\Http\Controllers\Api\V1\Finance\FinanceController;
use App\Http\Controllers\Api\V1\Finance\FinanceCoreController;
use App\Http\Controllers\Api\V1\Finance\FinanceTransferController;
use App\Http\Controllers\Api\V1\Finance\FinanceReportingController;
use App\Http\Controllers\Api\V1\Membership\AdmissionController;
use App\Http\Controllers\Api\V1\Membership\MemberLifecycleController;
use App\Http\Controllers\Api\V1\Membership\MembershipController;
use App\Http\Controllers\Api\V1\Membership\MembershipRecordController;
use App\Http\Controllers\Api\V1\Membership\MembershipTransferController;
use App\Http\Controllers\Api\V1\Physical\LinkController;
use App\Http\Controllers\Api\V1\Physical\LocationController;
use App\Http\Controllers\Api\V1\Physical\PropertyController;
use App\Http\Controllers\Api\V1\Physical\TempleController;
use App\Http\Controllers\Api\V1\People\AddressController;
use App\Http\Controllers\Api\V1\People\ContactController;
use App\Http\Controllers\Api\V1\People\ExportController;
use App\Http\Controllers\Api\V1\People\HouseholdController;
use App\Http\Controllers\Api\V1\People\PersonController;
use App\Http\Controllers\Api\V1\People\RelationshipController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'application' => 'MEPA CRM API',
        ]);
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('throttle:30,1');
        Route::get('me', [AuthController::class, 'me'])->middleware(['api.auth', 'throttle:60,1']);
    });

    Route::prefix('territorial')->middleware(['api.auth', 'throttle:60,1'])->group(function (): void {
        Route::get('context', [TerritorialController::class, 'context']);
        Route::get('units', [TerritorialController::class, 'index']);
        Route::get('roots', [TerritorialController::class, 'roots']);
        Route::post('units', [TerritorialController::class, 'store'])->middleware('throttle:20,1');
        Route::get('units/{unit}', [TerritorialController::class, 'show']);
        Route::patch('units/{unit}', [TerritorialController::class, 'update'])->middleware('throttle:20,1');
        Route::get('units/{unit}/children', [TerritorialController::class, 'children']);
        Route::get('units/{unit}/path', [TerritorialController::class, 'path']);
        Route::post('units/{unit}/move', [TerritorialController::class, 'move'])->middleware('throttle:10,1');
        Route::post('units/{unit}/lifecycle', [TerritorialController::class, 'lifecycle'])->middleware('throttle:10,1');
    });

    // P0.8 Documents / Files (ADR-0019). Every target is a public_id; content is served only by the authorized
    // .../content endpoints (no public or signed URL, no Range); no generic status, purge or delete endpoint.
    Route::prefix('files')->middleware(['api.auth', 'throttle:files'])->group(function (): void {
        Route::get('context', [FileController::class, 'context']);
        Route::get('/', [FileController::class, 'index']);
        Route::post('/', [FileController::class, 'store'])->middleware('throttle:files-upload');
        Route::get('{file}', [FileController::class, 'show']);
        Route::get('{file}/content', [FileController::class, 'content'])->middleware('throttle:files-download');
        Route::post('{file}/tombstone', [FileController::class, 'tombstone'])->middleware('throttle:files-write');
        Route::post('{file}/restore', [FileController::class, 'restore'])->middleware('throttle:files-write');
        Route::post('{file}/classification', [FileController::class, 'classification'])->middleware('throttle:files-write');
        Route::post('{file}/owner', [FileController::class, 'owner'])->middleware('throttle:files-write');
    });
    Route::prefix('documents')->middleware(['api.auth', 'throttle:files'])->group(function (): void {
        Route::get('/', [DocumentController::class, 'index']);
        Route::post('/', [DocumentController::class, 'store'])->middleware('throttle:files-upload');
        Route::get('{document}', [DocumentController::class, 'show']);
        Route::patch('{document}', [DocumentController::class, 'update'])->middleware('throttle:files-write');
        Route::post('{document}/versions', [DocumentController::class, 'version'])->middleware('throttle:files-upload');
        Route::get('{document}/versions/{version}/content', [DocumentController::class, 'content'])->middleware('throttle:files-download');
        Route::post('{document}/archive', [DocumentController::class, 'archive'])->middleware('throttle:files-write');
        Route::post('{document}/restore', [DocumentController::class, 'restore'])->middleware('throttle:files-write');
        Route::post('{document}/owner', [DocumentController::class, 'owner'])->middleware('throttle:files-write');
    });

    // P0.7 Physical Locations / Properties / Temples / Unit <-> Location links (ADR-0018). Every target is a public_id
    // (links: an opaque ref under their location); no generic status or visibility endpoint; no anonymous route.
    // P0.9 Membership (ADR-0020). Literal paths precede {membership}; every target is a public_id (memberships,
    // transfers) or a natural key under a membership public_id (legacy identifier, milestone type). The official number
    // is a search term, never a route key. No generic status endpoint; no anonymous route.
    Route::prefix('memberships')->middleware(['api.auth', 'throttle:membership'])->group(function (): void {
        Route::get('context', [MembershipController::class, 'context']);
        Route::get('/', [MembershipController::class, 'index'])->middleware('throttle:membership-search');
        Route::post('admissions', [AdmissionController::class, 'submit'])->middleware('throttle:membership-write');
        Route::post('admissions/collective-approval', [AdmissionController::class, 'collective'])->middleware('throttle:membership-write');
        Route::get('transfers', [MembershipController::class, 'allTransfers']);
        Route::get('transfers/{transfer}', [MembershipController::class, 'transfer']);
        Route::post('transfers/{transfer}/validate-origin', [MembershipTransferController::class, 'validateOrigin'])->middleware('throttle:membership-write');
        Route::post('transfers/{transfer}/accept', [MembershipTransferController::class, 'accept'])->middleware('throttle:membership-write');
        Route::post('transfers/{transfer}/reject', [MembershipTransferController::class, 'reject'])->middleware('throttle:membership-write');
        Route::post('transfers/{transfer}/complete', [MembershipTransferController::class, 'complete'])->middleware('throttle:membership-write');
        Route::post('transfers/{transfer}/cancel', [MembershipTransferController::class, 'cancel'])->middleware('throttle:membership-write');
        Route::get('{membership}', [MembershipController::class, 'show']);
        Route::get('{membership}/periods', [MembershipController::class, 'periods']);
        Route::get('{membership}/transfers', [MembershipController::class, 'transfers']);
        Route::post('{membership}/transfers', [MembershipTransferController::class, 'store'])->middleware('throttle:membership-write');
        Route::post('{membership}/validate', [AdmissionController::class, 'validateCandidacy'])->middleware('throttle:membership-write');
        Route::post('{membership}/withdraw', [AdmissionController::class, 'withdraw'])->middleware('throttle:membership-write');
        Route::post('{membership}/reject', [AdmissionController::class, 'reject'])->middleware('throttle:membership-write');
        Route::post('{membership}/approve', [AdmissionController::class, 'approve'])->middleware('throttle:membership-write');
        Route::post('{membership}/inactivate', [MemberLifecycleController::class, 'inactivate'])->middleware('throttle:membership-write');
        Route::post('{membership}/reactivate', [MemberLifecycleController::class, 'reactivate'])->middleware('throttle:membership-write');
        Route::post('{membership}/end', [MemberLifecycleController::class, 'end'])->middleware('throttle:membership-write');
        Route::post('{membership}/readmit', [MemberLifecycleController::class, 'readmit'])->middleware('throttle:membership-write');
        Route::get('{membership}/legacy-identifiers', [MembershipRecordController::class, 'legacy']);
        Route::post('{membership}/legacy-identifiers', [MembershipRecordController::class, 'registerLegacy'])->middleware('throttle:membership-write');
        Route::post('{membership}/legacy-identifiers/revoke', [MembershipRecordController::class, 'revokeLegacy'])->middleware('throttle:membership-write');
        Route::get('{membership}/milestones', [MembershipRecordController::class, 'milestones']);
        Route::post('{membership}/milestones', [MembershipRecordController::class, 'recordMilestone'])->middleware('throttle:membership-write');
        Route::post('{membership}/milestones/{type}/correct', [MembershipRecordController::class, 'correctMilestone'])->middleware('throttle:membership-write');
    });

    // P0.10-F1B Finance (ADR 0021 + D-04A): interunit transfers, fund custody, contributions. Targets are public ids;
    // explicit stage endpoints only (no generic status PATCH); collections paginated (max 100).
    Route::prefix('finance')->middleware(['api.auth', 'throttle:finance'])->group(function (): void {
        Route::get('context', [FinanceController::class, 'context']);
        Route::get('units', [FinanceController::class, 'units']);
        Route::get('transfers', [FinanceController::class, 'transfers']);
        Route::post('transfers', [FinanceTransferController::class, 'store'])->middleware('throttle:finance-write');
        Route::get('transfers/{transfer}', [FinanceController::class, 'transfer']);
        Route::post('transfers/{transfer}/send', [FinanceTransferController::class, 'send'])->middleware('throttle:finance-write');
        Route::post('transfers/{transfer}/receive', [FinanceTransferController::class, 'receive'])->middleware('throttle:finance-write');
        Route::post('transfers/{transfer}/cancel', [FinanceTransferController::class, 'cancel'])->middleware('throttle:finance-write');
        Route::post('transfers/{transfer}/reverse-send', [FinanceTransferController::class, 'reverseSend'])->middleware('throttle:finance-write');
        Route::post('transfers/{transfer}/reconcile', [FinanceTransferController::class, 'reconcile'])->middleware('throttle:finance-write');
        Route::get('units/{unit}/custody', [FinanceController::class, 'custody']);
        Route::get('units/{unit}/subtree-transfers', [FinanceController::class, 'subtree']);
        Route::get('contributions', [FinanceController::class, 'contributions']);
        Route::post('contributions', [ContributionController::class, 'store'])->middleware('throttle:finance-write');
        Route::get('contributions/{contribution}', [FinanceController::class, 'contribution']);
        Route::post('contributions/{contribution}/valuation', [ContributionController::class, 'valuate'])->middleware('throttle:finance-write');
        Route::post('contributions/{contribution}/approve-valuation', [ContributionController::class, 'approve'])->middleware('throttle:finance-write');
        // P0.10-F1C Finance Core: financial accounts, accrual subledgers, bank statements / reconciliation, budget, closes.
        Route::get('accounts', [FinanceCoreController::class, 'accounts']);
        Route::post('accounts', [FinanceCoreController::class, 'openAccount'])->middleware('throttle:finance-write');
        Route::get('accounts/{account}', [FinanceCoreController::class, 'account']);
        Route::get('accounts/{account}/history', [FinanceCoreController::class, 'accountHistory']);
        Route::post('accounts/{account}/close', [FinanceCoreController::class, 'closeAccount'])->middleware('throttle:finance-write');
        Route::get('receivables', [FinanceCoreController::class, 'receivables']);
        Route::post('receivables', [FinanceCoreController::class, 'recognizeReceivable'])->middleware('throttle:finance-write');
        Route::get('receivables/{receivable}', [FinanceCoreController::class, 'receivable']);
        Route::post('receivables/{receivable}/settlements', [FinanceCoreController::class, 'settleReceivable'])->middleware('throttle:finance-write');
        Route::post('receivables/{receivable}/cancel', [FinanceCoreController::class, 'cancelReceivable'])->middleware('throttle:finance-write');
        Route::get('payables', [FinanceCoreController::class, 'payables']);
        Route::post('payables', [FinanceCoreController::class, 'recognizePayable'])->middleware('throttle:finance-write');
        Route::get('payables/{payable}', [FinanceCoreController::class, 'payable']);
        Route::post('payables/{payable}/settlements', [FinanceCoreController::class, 'settlePayable'])->middleware('throttle:finance-write');
        Route::post('payables/{payable}/cancel', [FinanceCoreController::class, 'cancelPayable'])->middleware('throttle:finance-write');
        Route::get('settlements/{settlement}', [FinanceCoreController::class, 'settlement']);
        Route::post('settlements/{settlement}/cancel', [FinanceCoreController::class, 'cancelSettlement'])->middleware('throttle:finance-write');
        Route::get('bank-statements', [FinanceCoreController::class, 'statements']);
        Route::post('bank-statements', [FinanceCoreController::class, 'importStatement'])->middleware('throttle:finance-write');
        Route::get('bank-statements/{statement}', [FinanceCoreController::class, 'statement']);
        Route::get('reconciliations', [FinanceCoreController::class, 'reconciliations']);
        Route::post('reconciliations', [FinanceCoreController::class, 'openReconciliation'])->middleware('throttle:finance-write');
        Route::get('reconciliations/{reconciliation}', [FinanceCoreController::class, 'reconciliation']);
        Route::post('reconciliations/{reconciliation}/matches', [FinanceCoreController::class, 'match'])->middleware('throttle:finance-write');
        Route::post('reconciliations/{reconciliation}/unmatch', [FinanceCoreController::class, 'unmatch'])->middleware('throttle:finance-write');
        Route::post('reconciliations/{reconciliation}/close', [FinanceCoreController::class, 'closeReconciliation'])->middleware('throttle:finance-write');
        Route::post('reconciliations/{reconciliation}/adjustments', [FinanceCoreController::class, 'adjustReconciliation'])->middleware('throttle:finance-write');
        Route::get('budgets', [FinanceCoreController::class, 'budgets']);
        Route::post('budgets', [FinanceCoreController::class, 'createBudget'])->middleware('throttle:finance-write');
        Route::get('budgets/{budget}', [FinanceCoreController::class, 'budget']);
        Route::get('budgets/{budget}/actual-vs-budget', [FinanceCoreController::class, 'actualVsBudget']);
        Route::post('budgets/{budget}/lines', [FinanceCoreController::class, 'budgetLines'])->middleware('throttle:finance-write');
        Route::post('budgets/{budget}/submit', [FinanceCoreController::class, 'submitBudget'])->middleware('throttle:finance-write');
        Route::post('budgets/{budget}/return', [FinanceCoreController::class, 'returnBudget'])->middleware('throttle:finance-write');
        Route::post('budgets/{budget}/review', [FinanceCoreController::class, 'reviewBudget'])->middleware('throttle:finance-write');
        Route::post('budgets/{budget}/approve', [FinanceCoreController::class, 'approveBudget'])->middleware('throttle:finance-write');
        Route::post('budgets/{budget}/cancel', [FinanceCoreController::class, 'cancelBudget'])->middleware('throttle:finance-write');
        Route::post('budgets/{budget}/revise', [FinanceCoreController::class, 'reviseBudget'])->middleware('throttle:finance-write');
        Route::get('periods', [FinanceCoreController::class, 'periods']);
        Route::post('periods/{period}/close', [FinanceCoreController::class, 'closePeriod'])->middleware('throttle:finance-write');
        Route::post('periods/{period}/reopen', [FinanceCoreController::class, 'reopenPeriod'])->middleware('throttle:finance-write');
        Route::post('periods/{period}/national-close', [FinanceCoreController::class, 'closePeriodNationally'])->middleware('throttle:finance-write');
        // P0.10-F1D: bounded, snapshot-consistent reporting. All targets are public ids in the query string.
        Route::get('dashboard', [FinanceReportingController::class, 'dashboard']);
        Route::get('reports', [FinanceReportingController::class, 'index']);
        Route::get('reports/{report}', [FinanceReportingController::class, 'show']);
        Route::get('reports/{report}/export', [FinanceReportingController::class, 'export'])->middleware('throttle:finance');
    });

    // P0.10-F2A RH / payroll foundation (ADR 0021 D23-D28 + D-04A.15). Public ids only; permission before target (F-06).
    // No route calculates, approves, posts or pays a payroll run in F2A (the run pipeline is F2B).
    Route::prefix('hr')->middleware(['api.auth', 'throttle:finance'])->group(function (): void {
        Route::get('context', [HrController::class, 'context']);
        Route::get('payroll/status', [HrController::class, 'status']);
        Route::get('payroll/readiness', [HrController::class, 'readiness']);
        Route::get('employments', [HrController::class, 'employments']);
        Route::post('employments', [HrController::class, 'storeEmployment'])->middleware('throttle:finance-write');
        Route::get('employments/{employment}', [HrController::class, 'employment']);
        Route::post('employments/{employment}/end', [HrController::class, 'endEmployment'])->middleware('throttle:finance-write');
        Route::get('employments/{employment}/compensation', [HrController::class, 'compensation']);
        Route::post('employments/{employment}/compensation', [HrController::class, 'changeCompensation'])->middleware('throttle:finance-write');
        Route::post('employments/{employment}/compensation/stop', [HrController::class, 'stopCompensation'])->middleware('throttle:finance-write');
        Route::get('compensations', [HrController::class, 'compensationOverview']);
        Route::get('components', [HrController::class, 'components']);
        Route::get('payroll-rules', [HrController::class, 'rules']);
        Route::post('payroll-rules', [HrController::class, 'draftRule'])->middleware('throttle:finance-write');
        Route::get('payroll-rules/{code}/{version}', [HrController::class, 'rule']);
        Route::post('payroll-rules/{code}/{version}/approve', [HrController::class, 'approveRule'])->middleware('throttle:finance-write');
        Route::post('payroll-rules/{code}/{version}/retire', [HrController::class, 'retireRule'])->middleware('throttle:finance-write');
    });

    Route::prefix('physical')->middleware(['api.auth', 'throttle:physical'])->group(function (): void {
        Route::get('context', [LocationController::class, 'context']);
        Route::get('locations', [LocationController::class, 'index']);
        Route::post('locations', [LocationController::class, 'store'])->middleware('throttle:physical-write');
        Route::get('locations/{location}', [LocationController::class, 'show']);
        Route::patch('locations/{location}', [LocationController::class, 'update'])->middleware('throttle:physical-write');
        Route::get('locations/{location}/address', [LocationController::class, 'address'])->middleware('throttle:physical-sensitive');
        Route::put('locations/{location}/address', [LocationController::class, 'updateAddress'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/activate', [LocationController::class, 'activate'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/close', [LocationController::class, 'close'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/publish', [LocationController::class, 'publish'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/unpublish', [LocationController::class, 'unpublish'])->middleware('throttle:physical-write');
        Route::get('locations/{location}/links', [LinkController::class, 'index']);
        Route::post('locations/{location}/links', [LinkController::class, 'store'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/links/{link}/end', [LinkController::class, 'end'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/links/{link}/transfer', [LinkController::class, 'transfer'])->middleware('throttle:physical-write');
        Route::post('locations/{location}/links/{link}/set-primary', [LinkController::class, 'setPrimary'])->middleware('throttle:physical-write');
        Route::get('links', [LinkController::class, 'forUnit']);
        Route::get('properties', [PropertyController::class, 'index']);
        Route::post('properties', [PropertyController::class, 'store'])->middleware('throttle:physical-write');
        Route::get('properties/{property}', [PropertyController::class, 'show']);
        Route::patch('properties/{property}', [PropertyController::class, 'update'])->middleware('throttle:physical-write');
        Route::post('properties/{property}/ownership-status', [PropertyController::class, 'ownershipStatus'])->middleware('throttle:physical-write');
        Route::post('properties/{property}/activate', [PropertyController::class, 'activate'])->middleware('throttle:physical-write');
        Route::post('properties/{property}/close', [PropertyController::class, 'close'])->middleware('throttle:physical-write');
        Route::get('properties/{property}/external-owner', [PropertyController::class, 'externalOwner'])->middleware('throttle:physical-sensitive');
        Route::get('temples', [TempleController::class, 'index']);
        Route::post('temples', [TempleController::class, 'store'])->middleware('throttle:physical-write');
        Route::get('temples/{temple}', [TempleController::class, 'show']);
        Route::patch('temples/{temple}', [TempleController::class, 'update'])->middleware('throttle:physical-write');
        Route::post('temples/{temple}/activate', [TempleController::class, 'activate'])->middleware('throttle:physical-write');
        Route::post('temples/{temple}/close', [TempleController::class, 'close'])->middleware('throttle:physical-write');
    });

    // P0.5-I People / Families (ADR-0017). Literal paths precede {person}; every target is a public_id and
    // every nested resource is reached only through its parent. No generic status endpoint.
    Route::prefix('people')->middleware(['api.auth', 'throttle:120,1'])->group(function (): void {
        Route::get('context', [PersonController::class, 'context']);
        Route::get('catalogs', [PersonController::class, 'catalogs']);
        Route::get('selector', [PersonController::class, 'selector'])->middleware('throttle:60,1');
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:10,1');
        Route::get('households', [HouseholdController::class, 'index']);
        Route::post('households', [HouseholdController::class, 'store']);
        Route::get('households/{household}', [HouseholdController::class, 'show']);
        Route::patch('households/{household}', [HouseholdController::class, 'update']);
        Route::post('households/{household}/inactivate', [HouseholdController::class, 'inactivate']);
        Route::post('households/{household}/reactivate', [HouseholdController::class, 'reactivate']);
        Route::post('households/{household}/archive', [HouseholdController::class, 'archive']);
        Route::post('households/{household}/restore', [HouseholdController::class, 'restore']);
        Route::post('households/{household}/members', [HouseholdController::class, 'addMember']);
        Route::post('households/{household}/members/{member}/end', [HouseholdController::class, 'endMember']);
        Route::get('/', [PersonController::class, 'index']);
        Route::post('/', [PersonController::class, 'store']);
        Route::get('{person}', [PersonController::class, 'show']);
        Route::patch('{person}', [PersonController::class, 'update']);
        Route::post('{person}/inactivate', [PersonController::class, 'inactivate']);
        Route::post('{person}/reactivate', [PersonController::class, 'reactivate']);
        Route::post('{person}/mark-deceased', [PersonController::class, 'markDeceased']);
        Route::get('{person}/contacts', [ContactController::class, 'index']);
        Route::post('{person}/contacts', [ContactController::class, 'store']);
        Route::patch('{person}/contacts/{contact}', [ContactController::class, 'update']);
        Route::post('{person}/contacts/{contact}/end', [ContactController::class, 'end']);
        Route::get('{person}/addresses', [AddressController::class, 'index']);
        Route::post('{person}/addresses', [AddressController::class, 'store']);
        Route::patch('{person}/addresses/{address}', [AddressController::class, 'update']);
        Route::post('{person}/addresses/{address}/end', [AddressController::class, 'end']);
        Route::get('{person}/households', [HouseholdController::class, 'forPerson']);
        Route::get('{person}/relationships', [RelationshipController::class, 'index']);
        Route::post('{person}/relationships', [RelationshipController::class, 'store']);
        Route::post('{person}/relationships/{relationship}/end', [RelationshipController::class, 'end']);
    });

    Route::prefix('academy')->middleware(['academy.auth', 'throttle:60,1'])->group(function (): void {
        Route::get('context', [AcademyContextController::class, 'show']);
        Route::get('academic-units', [CatalogQueryController::class, 'academicUnits']);
        Route::get('academic-units/{academicUnit}', [CatalogQueryController::class, 'academicUnit'])->whereNumber('academicUnit');
        Route::get('programs', [CatalogQueryController::class, 'programs']);
        Route::get('programs/{program}', [CatalogQueryController::class, 'program']);
        Route::get('curricula', [CatalogQueryController::class, 'curricula']);
        Route::get('curricula/{curriculum}', [CatalogQueryController::class, 'curriculum'])->whereNumber('curriculum');
        Route::get('curricula/{curriculum}/courses', [CatalogQueryController::class, 'curriculumCourses'])->whereNumber('curriculum');
        Route::get('courses', [CatalogQueryController::class, 'courses']);
        Route::get('courses/{course}', [CatalogQueryController::class, 'course']);
        Route::get('courses/{course}/versions', [CatalogQueryController::class, 'courseVersions']);
        Route::get('course-versions/{courseVersion}', [CatalogQueryController::class, 'courseVersion'])->whereNumber('courseVersion');
        Route::get('course-versions/{courseVersion}/modules', [CatalogQueryController::class, 'courseVersionModules'])->whereNumber('courseVersion');
        Route::get('course-versions/{courseVersion}/modules/{module}/lessons', [CatalogQueryController::class, 'moduleLessons'])->whereNumber(['courseVersion', 'module']);
        Route::get('course-versions/{courseVersion}/modules/{module}/lessons/{lesson}/resources', [CatalogQueryController::class, 'lessonResources'])->whereNumber(['courseVersion', 'module', 'lesson']);
        Route::get('cohorts', [CatalogQueryController::class, 'cohorts']);
        Route::get('cohorts/{cohort}', [CatalogQueryController::class, 'cohort']);
        Route::get('classes', [ClassQueryController::class, 'index']);
        Route::get('classes/{class}', [ClassQueryController::class, 'show']);
        Route::get('classes/{class}/roster', [ClassQueryController::class, 'roster']);
        Route::get('classes/{class}/people:search', [ClassQueryController::class, 'people'])->middleware('throttle:20,1');
        Route::get('classes/{class}/instructor-candidates', [ClassQueryController::class, 'instructorCandidates'])->middleware('throttle:20,1');
        Route::get('classes/{class}/enrollments/{enrollment}', [ClassQueryController::class, 'enrollment']);
        Route::get('classes/{class}/sessions', [ClassQueryController::class, 'sessions']);
        Route::get('classes/{class}/sessions/{classSession}', [ClassQueryController::class, 'showSession'])->whereNumber('classSession');
        Route::get('enrollments/{enrollment}/progress', [ClassQueryController::class, 'progress']);
        Route::get('classes/{class}/assessments', [AssessmentQueryController::class, 'index']);
        Route::get('classes/{class}/assessments/{assessment}', [AssessmentQueryController::class, 'show']);
        Route::get('classes/{class}/assessments/{assessment}/attempts', [AssessmentQueryController::class, 'attempts']);
        Route::get('classes/{class}/attempts/{attempt}', [AssessmentQueryController::class, 'attempt'])->whereNumber('attempt');
        Route::get('classes/{class}/certificates', [DocumentQueryController::class, 'certificates']);
        Route::get('classes/{class}/certificates/{certificate}', [DocumentQueryController::class, 'certificate']);
        Route::get('curricula/{curriculum}/people/{person}/transcripts', [DocumentQueryController::class, 'transcripts'])->whereNumber('curriculum');
        Route::get('curricula/{curriculum}/people/{person}/transcripts/{transcript}', [DocumentQueryController::class, 'transcript'])->whereNumber('curriculum');
        Route::get('enrollments/{enrollment}/eligible-files', [EligibleFileController::class, 'certificate']);
        Route::get('curricula/{curriculum}/people/{person}/eligible-files', [EligibleFileController::class, 'transcript'])->whereNumber('curriculum');

        Route::post('programs/{program}/curricula', [CurriculumController::class, 'store']);
        Route::post('curricula/{curriculum}/publish', [CurriculumController::class, 'publish'])->whereNumber('curriculum');
        Route::post('curricula/{curriculum}/courses', [CurriculumController::class, 'addCourse'])->whereNumber('curriculum');

        Route::post('classes/{class}/enrollments', [EnrollmentController::class, 'store']);
        Route::get('classes/{class}/enrollments', [EnrollmentController::class, 'index']);
        Route::post('enrollments/{enrollment}/transitions', [EnrollmentController::class, 'transition']);
        Route::post('enrollments/{enrollment}/complete', [EnrollmentController::class, 'complete'])->middleware('throttle:20,1');

        Route::post('classes/{class}/instructors', [InstructorController::class, 'store']);
        Route::get('classes/{class}/instructors', [InstructorController::class, 'index']);
        Route::post('instructor-assignments/{assignment}/end', [InstructorController::class, 'end'])->whereNumber('assignment');

        Route::post('classes/{class}/sessions', [SessionController::class, 'store']);
        Route::post('sessions/{session}/transitions', [SessionController::class, 'transition'])->whereNumber('session');
        Route::get('sessions/{session}/effective-location', [SessionController::class, 'location'])->whereNumber('session');

        Route::put('sessions/{session}/attendance/{enrollment}', [AttendanceController::class, 'store'])->whereNumber('session');
        Route::post('sessions/{session}/attendance:bulk', [AttendanceController::class, 'bulk'])->whereNumber('session')->middleware('throttle:20,1');
        Route::get('sessions/{session}/attendance', [AttendanceController::class, 'index'])->whereNumber('session');

        Route::post('course-versions/{courseVersion}/assessments', [AssessmentController::class, 'store'])->whereNumber('courseVersion');
        Route::patch('assessments/{assessment}', [AssessmentController::class, 'update']);
        Route::post('assessments/{assessment}/attempts', [AttemptController::class, 'store']);
        Route::post('attempts/{attempt}/transitions', [AttemptController::class, 'transition'])->whereNumber('attempt');

        Route::post('attempts/{attempt}/grades', [GradeController::class, 'store'])->whereNumber('attempt');
        Route::post('attempts/{attempt}/grades/revisions', [GradeController::class, 'revise'])->whereNumber('attempt');
        Route::post('attempts/{attempt}/grades/finalize', [GradeController::class, 'finalize'])->whereNumber('attempt');
        Route::get('attempts/{attempt}/grades', [GradeController::class, 'index'])->whereNumber('attempt');

        Route::put('enrollments/{enrollment}/lessons/{lesson}/progress', [ProgressController::class, 'lesson'])->whereNumber('lesson');
        Route::put('enrollments/{enrollment}/resources/{resource}/progress', [ProgressController::class, 'resource']);

        Route::post('enrollments/{enrollment}/certificates', [CertificateController::class, 'store'])->middleware('throttle:10,1');
        Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke']);
        Route::post('curricula/{curriculum}/people/{person}/transcript-preview', [TranscriptController::class, 'preview'])->whereNumber('curriculum')->middleware('throttle:20,1');
        Route::post('curricula/{curriculum}/people/{person}/transcripts', [TranscriptController::class, 'store'])->whereNumber('curriculum')->middleware('throttle:10,1');
    });
});
