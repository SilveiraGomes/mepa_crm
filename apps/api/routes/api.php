<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Academy\AttendanceController;
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

    Route::prefix('academy')->middleware(['academy.auth', 'throttle:60,1'])->group(function (): void {
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
