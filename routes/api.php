<?php

use App\Http\Controllers\Api\V1\AcademicController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\ExaminationController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\ModuleCatalogController;
use App\Http\Controllers\Api\V1\ModuleResourceController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\Api\V1\SchoolProfileController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\AuthController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'error' => '',
        'data' => [
            'app' => 'Shengena API',
            'status' => 'ok',
            'version' => 'v1',
            'timestamp' => now()->toIso8601String(),
        ],
    ]);
});

Route::prefix('v1')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('register-school', [AuthController::class, 'registerSchool']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
            Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
            Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
        });

        Route::get('modules', [ModuleCatalogController::class, 'index']);
        Route::get('modules/{key}', [ModuleCatalogController::class, 'show']);

        // ── Super Admin SaaS Platform (no school tenant required) ──
        Route::middleware(['auth:api', 'role:super_admin'])
            ->prefix('platform')
            ->group(function () {
                Route::get('dashboard', [PlatformController::class, 'dashboard']);

                Route::get('schools', [PlatformController::class, 'schoolsIndex']);
                Route::post('schools', [PlatformController::class, 'schoolsStore']);
                Route::patch('schools/{id}', [PlatformController::class, 'schoolsUpdate'])->whereNumber('id');

                Route::get('plans', [PlatformController::class, 'plansIndex']);
                Route::post('plans', [PlatformController::class, 'plansStore']);
                Route::patch('plans/{id}', [PlatformController::class, 'plansUpdate'])->whereNumber('id');
                Route::delete('plans/{id}', [PlatformController::class, 'plansDestroy'])->whereNumber('id');
                Route::get('subscriptions', [PlatformController::class, 'subscriptionsIndex']);
                Route::patch('subscriptions/{id}', [PlatformController::class, 'subscriptionsUpdate'])->whereNumber('id');

                Route::get('users', [PlatformController::class, 'usersIndex']);
                Route::post('users', [PlatformController::class, 'usersStore']);
                Route::get('roles', [PlatformController::class, 'rolesIndex']);

                Route::get('billing', [PlatformController::class, 'billingIndex']);
                Route::post('billing', [PlatformController::class, 'billingStore']);
                Route::post('billing/{id}/pay', [PlatformController::class, 'billingMarkPaid'])->whereNumber('id');

                Route::get('notifications', [PlatformController::class, 'notificationsIndex']);
                Route::post('notifications', [PlatformController::class, 'notificationsStore']);

                Route::get('settings', [PlatformController::class, 'settingsShow']);
                Route::get('audit-logs', [PlatformController::class, 'auditIndex']);
            });

        Route::middleware(['auth:api', 'tenant'])->group(function () {
            Route::get('schools/{school}', [SchoolController::class, 'show']);
            Route::patch('schools/{school}', [SchoolController::class, 'update']);

            // ── School profile (current tenant) ───────────────────
            Route::get('school/profile', [SchoolProfileController::class, 'show']);
            Route::put('school/profile', [SchoolProfileController::class, 'update']);
            Route::patch('school/profile', [SchoolProfileController::class, 'update']);

            // ── Students ──────────────────────────────────────────
            Route::get('students', [StudentController::class, 'index']);
            Route::post('students', [StudentController::class, 'store']);
            Route::get('students/headcount-by-level', [StudentController::class, 'headcountByLevel']);
            Route::get('students/{id}', [StudentController::class, 'show'])->whereNumber('id');
            Route::put('students/{id}', [StudentController::class, 'update'])->whereNumber('id');
            Route::patch('students/{id}', [StudentController::class, 'update'])->whereNumber('id');
            Route::delete('students/{id}', [StudentController::class, 'destroy'])->whereNumber('id');

            // ── Staff (non-teaching) ──────────────────────────────
            Route::get('staff', [StaffController::class, 'index']);
            Route::post('staff', [StaffController::class, 'store']);
            Route::get('staff/{id}', [StaffController::class, 'show'])->whereNumber('id');
            Route::patch('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
            Route::put('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
            Route::delete('staff/{id}', [StaffController::class, 'destroy'])->whereNumber('id');

            Route::get('staff-departments', [StaffController::class, 'departmentsIndex']);
            Route::post('staff-departments', [StaffController::class, 'departmentsStore']);
            Route::patch('staff-departments/{id}', [StaffController::class, 'departmentsUpdate'])->whereNumber('id');
            Route::delete('staff-departments/{id}', [StaffController::class, 'departmentsDestroy'])->whereNumber('id');

            Route::get('staff-positions', [StaffController::class, 'positionsIndex']);
            Route::post('staff-positions', [StaffController::class, 'positionsStore']);
            Route::patch('staff-positions/{id}', [StaffController::class, 'positionsUpdate'])->whereNumber('id');
            Route::delete('staff-positions/{id}', [StaffController::class, 'positionsDestroy'])->whereNumber('id');

            // ── Academic setup ────────────────────────────────────
            Route::get('academic/years', [AcademicController::class, 'yearsIndex']);
            Route::post('academic/years', [AcademicController::class, 'yearsStore']);
            Route::patch('academic/years/{id}', [AcademicController::class, 'yearsUpdate'])->whereNumber('id');
            Route::delete('academic/years/{id}', [AcademicController::class, 'yearsDestroy'])->whereNumber('id');

            Route::get('academic/terms', [AcademicController::class, 'termsIndex']);
            Route::post('academic/terms', [AcademicController::class, 'termsStore']);
            Route::patch('academic/terms/{id}', [AcademicController::class, 'termsUpdate'])->whereNumber('id');
            Route::delete('academic/terms/{id}', [AcademicController::class, 'termsDestroy'])->whereNumber('id');

            Route::get('academic/levels', [AcademicController::class, 'levelsIndex']);
            Route::post('academic/levels', [AcademicController::class, 'levelsStore']);
            Route::patch('academic/levels/{id}', [AcademicController::class, 'levelsUpdate'])->whereNumber('id');
            Route::delete('academic/levels/{id}', [AcademicController::class, 'levelsDestroy'])->whereNumber('id');

            Route::get('academic/streams', [AcademicController::class, 'streamsIndex']);
            Route::post('academic/streams', [AcademicController::class, 'streamsStore']);
            Route::patch('academic/streams/{id}', [AcademicController::class, 'streamsUpdate'])->whereNumber('id');
            Route::delete('academic/streams/{id}', [AcademicController::class, 'streamsDestroy'])->whereNumber('id');

            Route::get('academic/classes', [AcademicController::class, 'classesIndex']);
            Route::post('academic/classes', [AcademicController::class, 'classesStore']);
            Route::patch('academic/classes/{id}', [AcademicController::class, 'classesUpdate'])->whereNumber('id');
            Route::delete('academic/classes/{id}', [AcademicController::class, 'classesDestroy'])->whereNumber('id');

            // ── Subjects (TAMISEMI / NECTA) ────────────────────────
            Route::get('academic/subjects', [SubjectController::class, 'subjectsIndex']);
            Route::post('academic/subjects', [SubjectController::class, 'subjectsStore']);
            Route::patch('academic/subjects/{id}', [SubjectController::class, 'subjectsUpdate'])->whereNumber('id');
            Route::delete('academic/subjects/{id}', [SubjectController::class, 'subjectsDestroy'])->whereNumber('id');

            Route::get('academic/subject-categories', [SubjectController::class, 'categoriesIndex']);
            Route::post('academic/subject-categories', [SubjectController::class, 'categoriesStore']);
            Route::patch('academic/subject-categories/{id}', [SubjectController::class, 'categoriesUpdate'])->whereNumber('id');
            Route::delete('academic/subject-categories/{id}', [SubjectController::class, 'categoriesDestroy'])->whereNumber('id');

            Route::get('academic/subject-combinations', [SubjectController::class, 'combinationsIndex']);
            Route::post('academic/subject-combinations', [SubjectController::class, 'combinationsStore']);
            Route::patch('academic/subject-combinations/{id}', [SubjectController::class, 'combinationsUpdate'])->whereNumber('id');
            Route::delete('academic/subject-combinations/{id}', [SubjectController::class, 'combinationsDestroy'])->whereNumber('id');

            Route::get('academic/class-subjects', [SubjectController::class, 'classAssignmentsIndex']);
            Route::post('academic/class-subjects', [SubjectController::class, 'classAssignmentsStore']);
            Route::patch('academic/class-subjects/{id}', [SubjectController::class, 'classAssignmentsUpdate'])->whereNumber('id');
            Route::delete('academic/class-subjects/{id}', [SubjectController::class, 'classAssignmentsDestroy'])->whereNumber('id');

            Route::get('academic/teacher-allocations', [SubjectController::class, 'teacherAllocationsIndex']);
            Route::post('academic/teacher-allocations', [SubjectController::class, 'teacherAllocationsStore']);
            Route::patch('academic/teacher-allocations/{id}', [SubjectController::class, 'teacherAllocationsUpdate'])->whereNumber('id');
            Route::delete('academic/teacher-allocations/{id}', [SubjectController::class, 'teacherAllocationsDestroy'])->whereNumber('id');

            // ── Finance / fees ────────────────────────────────────
            Route::get('finance/categories', [FinanceController::class, 'categoriesIndex']);
            Route::post('finance/categories', [FinanceController::class, 'categoriesStore']);
            Route::get('finance/structures', [FinanceController::class, 'structuresIndex']);
            Route::post('finance/structures', [FinanceController::class, 'structuresStore']);
            Route::get('finance/invoices', [FinanceController::class, 'invoicesIndex']);
            Route::post('finance/invoices/generate', [FinanceController::class, 'invoicesGenerate']);
            Route::get('finance/payments', [FinanceController::class, 'paymentsIndex']);
            Route::post('finance/payments', [FinanceController::class, 'paymentsStore']);

            // ── Attendance ────────────────────────────────────────
            Route::get('attendance', [AttendanceController::class, 'index']);
            Route::get('attendance/roster', [AttendanceController::class, 'roster']);
            Route::post('attendance/mark', [AttendanceController::class, 'mark']);

            // ── Examinations + report cards ───────────────────────
            Route::get('examinations/types', [ExaminationController::class, 'typesIndex']);
            Route::get('examinations/assessments', [ExaminationController::class, 'assessmentsIndex']);
            Route::post('examinations/assessments', [ExaminationController::class, 'assessmentsStore']);
            Route::patch('examinations/assessments/{id}', [ExaminationController::class, 'assessmentsUpdate'])->whereNumber('id');
            Route::delete('examinations/assessments/{id}', [ExaminationController::class, 'assessmentsDestroy'])->whereNumber('id');
            Route::get('examinations/assessments/{id}/results', [ExaminationController::class, 'resultsIndex'])->whereNumber('id');
            Route::post('examinations/assessments/{id}/results', [ExaminationController::class, 'resultsStore'])->whereNumber('id');
            Route::post('examinations/assessments/{id}/publish', [ExaminationController::class, 'publish'])->whereNumber('id');
            Route::post('examinations/assessments/{id}/unpublish', [ExaminationController::class, 'unpublish'])->whereNumber('id');
            Route::post('examinations/assessments/{id}/lock', [ExaminationController::class, 'lock'])->whereNumber('id');
            Route::post('examinations/assessments/{id}/unlock', [ExaminationController::class, 'unlock'])->whereNumber('id');
            Route::get('examinations/ranking', [ExaminationController::class, 'ranking']);
            Route::get('examinations/subject-analysis', [ExaminationController::class, 'subjectAnalysis']);
            Route::get('report-cards', [ExaminationController::class, 'reportCard']);
            Route::get('report-cards/html', [ExaminationController::class, 'reportCardHtml']);

            // Scaffold fallback for remaining modules
            Route::prefix('tenant')->group(function () {
                $moduleKeys = [
                    'platform', 'school-profile', 'parents', 'teachers', 'staff',
                    'behaviour', 'promotions', 'accounting', 'inventory', 'library',
                    'transport', 'hostel', 'timetable', 'homework', 'learning',
                    'communication', 'events', 'sports', 'documents', 'reports',
                    'users', 'compliance', 'admin',
                ];
                foreach ($moduleKeys as $module) {
                    Route::get($module, [ModuleResourceController::class, 'index'])->defaults('module', $module);
                    Route::post($module, [ModuleResourceController::class, 'store'])->defaults('module', $module);
                    Route::get("{$module}/features/{feature}", [ModuleResourceController::class, 'feature'])->defaults('module', $module);
                    Route::get("{$module}/{id}", [ModuleResourceController::class, 'show'])->defaults('module', $module)->where('id', '[^/]+');
                    Route::put("{$module}/{id}", [ModuleResourceController::class, 'update'])->defaults('module', $module)->where('id', '[^/]+');
                    Route::patch("{$module}/{id}", [ModuleResourceController::class, 'update'])->defaults('module', $module)->where('id', '[^/]+');
                    Route::delete("{$module}/{id}", [ModuleResourceController::class, 'destroy'])->defaults('module', $module)->where('id', '[^/]+');
                }
            });
        });
    });
