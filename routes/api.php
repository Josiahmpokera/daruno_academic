<?php

use App\Http\Controllers\Api\V1\AcademicController;
use App\Http\Controllers\Api\V1\AcademicPolicyController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\CommunicationController;
use App\Http\Controllers\Api\V1\ExaminationController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HostelController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LearningController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\TransportController;
use App\Http\Controllers\Api\V1\ModuleCatalogController;
use App\Http\Controllers\Api\V1\ModuleResourceController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\PlatformReportsController;
use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\Api\V1\SchoolProfileController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\AdmissionController;
use App\Http\Controllers\Api\V1\StudentIdentityController;
use App\Http\Controllers\Api\V1\StudentLifecycleController;
use App\Http\Controllers\Api\V1\StudentRecordsController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SystemController;
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
            // Public school registry search (for registration typeahead)
            Route::get('register-school/search', [AuthController::class, 'searchRegistrySchools']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
            Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
            Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
        });

        Route::get('modules', [ModuleCatalogController::class, 'index']);
        Route::get('modules/{key}', [ModuleCatalogController::class, 'show']);

        // Public pricing (marketing site) — active plans only
        Route::get('pricing/plans', [PlatformController::class, 'publicPlansIndex']);
        Route::get('public/site', [PlatformController::class, 'publicSiteContent']);

        // Public join / admission applications (by school code or slug)
        Route::get('public/schools/{code}', [AdmissionController::class, 'publicSchoolInfo']);
        Route::post('public/schools/{code}/applications', [AdmissionController::class, 'publicApply']);
        Route::get('public/id-cards/verify/{token}', [StudentIdentityController::class, 'verify']);

        // ── Super Admin SaaS Platform (no school tenant required) ──
        Route::middleware(['auth:api', 'role:super_admin'])
            ->prefix('platform')
            ->group(function () {
                Route::get('dashboard', [PlatformController::class, 'dashboard']);

                Route::get('schools', [PlatformController::class, 'schoolsIndex']);
                Route::post('schools', [PlatformController::class, 'schoolsStore']);
                Route::post('schools/bulk-import', [PlatformController::class, 'schoolsBulkImport']);
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
                Route::put('settings', [PlatformController::class, 'settingsUpdate']);
                Route::patch('settings', [PlatformController::class, 'settingsUpdate']);
                Route::get('audit-logs', [PlatformController::class, 'auditIndex']);

                // ── Platform reports ──────────────────────────────
                Route::prefix('reports')->group(function () {
                    Route::get('/', [PlatformReportsController::class, 'catalog']);
                    Route::get('catalog', [PlatformReportsController::class, 'catalog']);
                    Route::get('overview', [PlatformReportsController::class, 'overview']);
                    Route::get('schools', [PlatformReportsController::class, 'schools']);
                    Route::get('subscriptions', [PlatformReportsController::class, 'subscriptions']);
                    Route::get('revenue', [PlatformReportsController::class, 'revenue']);
                    Route::get('users', [PlatformReportsController::class, 'users']);
                    Route::get('growth', [PlatformReportsController::class, 'growth']);
                    Route::get('tanzania-registry', [PlatformReportsController::class, 'tanzaniaRegistry']);
                    Route::get('audit', [PlatformReportsController::class, 'audit']);
                });
            });

        // School (tenant) API. `tenant` pins the request to the user's own school,
        // `tenant.refs` rejects IDs from other schools, and each group declares its
        // RBAC area (config/daruno_access.php): GET = read, other methods = write.
        Route::middleware(['auth:api', 'tenant', 'tenant.refs'])->group(function () {
            // ── School profile ────────────────────────────────────
            Route::middleware('access:school-profile')->group(function () {
                Route::get('schools/{school}', [SchoolController::class, 'show']);
                Route::patch('schools/{school}', [SchoolController::class, 'update']);
                Route::get('school/profile', [SchoolProfileController::class, 'show']);
                Route::put('school/profile', [SchoolProfileController::class, 'update']);
                Route::patch('school/profile', [SchoolProfileController::class, 'update']);
            });

            // ── Students, parents, records, ID cards, lifecycle ───
            Route::middleware('access:students')->group(function () {
                Route::get('students', [StudentController::class, 'index']);
                Route::post('students', [StudentController::class, 'store']);
                Route::get('students/headcount-by-level', [StudentController::class, 'headcountByLevel']);
                Route::get('guardians', [StudentRecordsController::class, 'guardiansIndex']);
                Route::post('guardians', [StudentRecordsController::class, 'guardiansStore']);
                Route::patch('guardians/{id}', [StudentRecordsController::class, 'guardiansUpdate'])->whereNumber('id');
                Route::put('guardians/{id}', [StudentRecordsController::class, 'guardiansUpdate'])->whereNumber('id');
                Route::delete('guardians/{id}', [StudentRecordsController::class, 'guardiansDestroy'])->whereNumber('id');
                Route::get('students/medical', [StudentRecordsController::class, 'medicalIndex']);
                Route::get('students/{studentId}/medical', [StudentRecordsController::class, 'medicalShow'])->whereNumber('studentId');
                Route::put('students/{studentId}/medical', [StudentRecordsController::class, 'medicalUpsert'])->whereNumber('studentId');
                Route::patch('students/{studentId}/medical', [StudentRecordsController::class, 'medicalUpsert'])->whereNumber('studentId');
                Route::get('students/previous-school', [StudentRecordsController::class, 'previousSchoolIndex']);
                Route::patch('students/{studentId}/previous-school', [StudentRecordsController::class, 'previousSchoolUpdate'])->whereNumber('studentId');
                Route::put('students/{studentId}/previous-school', [StudentRecordsController::class, 'previousSchoolUpdate'])->whereNumber('studentId');
                Route::get('students/documents', [StudentRecordsController::class, 'documentsIndex']);
                Route::post('students/documents', [StudentRecordsController::class, 'documentsStore']);
                Route::patch('students/documents/{id}', [StudentRecordsController::class, 'documentsUpdate'])->whereNumber('id');
                Route::put('students/documents/{id}', [StudentRecordsController::class, 'documentsUpdate'])->whereNumber('id');
                Route::delete('students/documents/{id}', [StudentRecordsController::class, 'documentsDestroy'])->whereNumber('id');
                Route::get('students/status-summary', [StudentRecordsController::class, 'statusSummary']);
                Route::post('students/bulk-status', [StudentRecordsController::class, 'statusBulkUpdate']);
                Route::get('students/identity/templates', [StudentIdentityController::class, 'templatesIndex']);
                Route::get('students/identity/settings', [StudentIdentityController::class, 'settingsShow']);
                Route::patch('students/identity/settings', [StudentIdentityController::class, 'settingsUpdate']);
                Route::get('students/identity/roster', [StudentIdentityController::class, 'roster']);
                Route::get('students/identity/cards', [StudentIdentityController::class, 'cardsIndex']);
                Route::post('students/identity/cards/issue', [StudentIdentityController::class, 'cardsIssue']);
                Route::post('students/identity/cards/mark-printed', [StudentIdentityController::class, 'cardsMarkPrinted']);
                Route::get('students/identity/cards/{id}', [StudentIdentityController::class, 'cardsShow'])->whereNumber('id');
                Route::patch('students/identity/cards/{id}', [StudentIdentityController::class, 'cardsUpdate'])->whereNumber('id');
                Route::get('students/transfers', [StudentLifecycleController::class, 'transfersIndex']);
                Route::post('students/transfers', [StudentLifecycleController::class, 'transfersStore']);
                Route::patch('students/transfers/{id}', [StudentLifecycleController::class, 'transfersUpdate'])->whereNumber('id');
                Route::put('students/transfers/{id}', [StudentLifecycleController::class, 'transfersUpdate'])->whereNumber('id');
                Route::delete('students/transfers/{id}', [StudentLifecycleController::class, 'transfersDestroy'])->whereNumber('id');
                Route::get('students/graduations', [StudentLifecycleController::class, 'graduationsIndex']);
                Route::post('students/graduations', [StudentLifecycleController::class, 'graduationsStore']);
                Route::patch('students/graduations/{id}', [StudentLifecycleController::class, 'graduationsUpdate'])->whereNumber('id');
                Route::put('students/graduations/{id}', [StudentLifecycleController::class, 'graduationsUpdate'])->whereNumber('id');
                Route::delete('students/graduations/{id}', [StudentLifecycleController::class, 'graduationsDestroy'])->whereNumber('id');
                Route::get('students/alumni', [StudentLifecycleController::class, 'alumniIndex']);
                Route::patch('students/alumni/{studentId}', [StudentLifecycleController::class, 'alumniUpdate'])->whereNumber('studentId');
                Route::put('students/alumni/{studentId}', [StudentLifecycleController::class, 'alumniUpdate'])->whereNumber('studentId');
                Route::get('students/{id}', [StudentController::class, 'show'])->whereNumber('id');
                Route::put('students/{id}', [StudentController::class, 'update'])->whereNumber('id');
                Route::patch('students/{id}', [StudentController::class, 'update'])->whereNumber('id');
                Route::delete('students/{id}', [StudentController::class, 'destroy'])->whereNumber('id');
            });

            // ── Admissions / join applications (school inbox) ─────
            Route::middleware('access:admissions')->group(function () {
                Route::get('admissions', [AdmissionController::class, 'index']);
                Route::post('admissions', [AdmissionController::class, 'store']);
                Route::get('admissions/{id}', [AdmissionController::class, 'show'])->whereNumber('id');
                Route::patch('admissions/{id}', [AdmissionController::class, 'update'])->whereNumber('id');
                Route::put('admissions/{id}', [AdmissionController::class, 'update'])->whereNumber('id');
                Route::post('admissions/{id}/approve', [AdmissionController::class, 'approve'])->whereNumber('id');
                Route::post('admissions/{id}/reject', [AdmissionController::class, 'reject'])->whereNumber('id');
            });

            // ── Staff HR ──────────────────────────────────────────
            Route::middleware('access:staff')->group(function () {
                Route::get('staff/overview', [StaffController::class, 'overview']);
                Route::get('staff', [StaffController::class, 'index']);
                Route::post('staff', [StaffController::class, 'store']);
                Route::post('staff/onboard', [StaffController::class, 'onboard']);
                Route::get('staff/{id}', [StaffController::class, 'show'])->whereNumber('id');
                Route::patch('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
                Route::put('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
                Route::delete('staff/{id}', [StaffController::class, 'destroy'])->whereNumber('id');
                Route::get('staff/contracts', [StaffController::class, 'contractsIndex']);
                Route::post('staff/contracts', [StaffController::class, 'contractsStore']);
                Route::patch('staff/contracts/{id}', [StaffController::class, 'contractsUpdate'])->whereNumber('id');
                Route::get('staff/documents', [StaffController::class, 'documentsIndex']);
                Route::post('staff/documents', [StaffController::class, 'documentsStore']);
                Route::delete('staff/documents/{id}', [StaffController::class, 'documentsDestroy'])->whereNumber('id');
                Route::get('staff/leave', [StaffController::class, 'leaveIndex']);
                Route::post('staff/leave', [StaffController::class, 'leaveStore']);
                Route::post('staff/leave/{id}/approve', [StaffController::class, 'leaveApprove'])->whereNumber('id');
                Route::post('staff/leave/{id}/reject', [StaffController::class, 'leaveReject'])->whereNumber('id');
                Route::get('staff/attendance', [StaffController::class, 'attendanceIndex']);
                Route::get('staff/attendance/roster', [StaffController::class, 'attendanceRoster']);
                Route::post('staff/attendance/mark', [StaffController::class, 'attendanceMark']);
                Route::get('staff/payroll', [StaffController::class, 'payrollSummary']);
                Route::get('staff-departments', [StaffController::class, 'departmentsIndex']);
                Route::post('staff-departments', [StaffController::class, 'departmentsStore']);
                Route::patch('staff-departments/{id}', [StaffController::class, 'departmentsUpdate'])->whereNumber('id');
                Route::delete('staff-departments/{id}', [StaffController::class, 'departmentsDestroy'])->whereNumber('id');
                Route::get('staff-positions', [StaffController::class, 'positionsIndex']);
                Route::post('staff-positions', [StaffController::class, 'positionsStore']);
                Route::patch('staff-positions/{id}', [StaffController::class, 'positionsUpdate'])->whereNumber('id');
                Route::delete('staff-positions/{id}', [StaffController::class, 'positionsDestroy'])->whereNumber('id');
            });

            // ── Academic setup, subjects, grading, curriculum ─────
            Route::middleware('access:academic')->group(function () {
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
                Route::get('academic/grading', [AcademicPolicyController::class, 'gradingIndex']);
                Route::post('academic/grading', [AcademicPolicyController::class, 'gradingStore']);
                Route::patch('academic/grading/{id}', [AcademicPolicyController::class, 'gradingUpdate'])->whereNumber('id');
                Route::delete('academic/grading/{id}', [AcademicPolicyController::class, 'gradingDestroy'])->whereNumber('id');
                Route::post('academic/grading/{id}/default', [AcademicPolicyController::class, 'gradingSetDefault'])->whereNumber('id');
                Route::post('academic/grading/preview', [AcademicPolicyController::class, 'gradingPreview']);
                Route::get('academic/curriculum', [AcademicPolicyController::class, 'curriculumIndex']);
                Route::post('academic/curriculum', [AcademicPolicyController::class, 'curriculumStore']);
                Route::get('academic/curriculum/{id}', [AcademicPolicyController::class, 'curriculumShow'])->whereNumber('id');
                Route::patch('academic/curriculum/{id}', [AcademicPolicyController::class, 'curriculumUpdate'])->whereNumber('id');
                Route::delete('academic/curriculum/{id}', [AcademicPolicyController::class, 'curriculumDestroy'])->whereNumber('id');
                Route::post('academic/curriculum/{id}/topics', [AcademicPolicyController::class, 'curriculumTopicStore'])->whereNumber('id');
                Route::patch('academic/curriculum/topics/{topicId}', [AcademicPolicyController::class, 'curriculumTopicUpdate'])->whereNumber('topicId');
                Route::delete('academic/curriculum/topics/{topicId}', [AcademicPolicyController::class, 'curriculumTopicDestroy'])->whereNumber('topicId');
            });

            // ── Finance / fees ────────────────────────────────────
            Route::middleware('access:finance')->group(function () {
                Route::get('finance/overview', [FinanceController::class, 'overview']);
                Route::get('finance/setup-status', [FinanceController::class, 'setupStatus']);
                Route::get('finance/categories', [FinanceController::class, 'categoriesIndex']);
                Route::post('finance/categories', [FinanceController::class, 'categoriesStore']);
                Route::get('finance/structures', [FinanceController::class, 'structuresIndex']);
                Route::post('finance/structures', [FinanceController::class, 'structuresStore']);
                Route::patch('finance/structures/{id}', [FinanceController::class, 'structuresUpdate'])->whereNumber('id');
                Route::put('finance/structures/{id}', [FinanceController::class, 'structuresUpdate'])->whereNumber('id');
                Route::get('finance/invoices', [FinanceController::class, 'invoicesIndex']);
                Route::post('finance/invoices/generate', [FinanceController::class, 'invoicesGenerate']);
                Route::get('finance/payments', [FinanceController::class, 'paymentsIndex']);
                Route::post('finance/payments', [FinanceController::class, 'paymentsStore']);
                Route::get('finance/methods', [FinanceController::class, 'methodsIndex']);
                Route::post('finance/methods', [FinanceController::class, 'methodsStore']);
                Route::patch('finance/methods/{id}', [FinanceController::class, 'methodsUpdate'])->whereNumber('id');
                Route::put('finance/methods/{id}', [FinanceController::class, 'methodsUpdate'])->whereNumber('id');
                Route::delete('finance/methods/{id}', [FinanceController::class, 'methodsDestroy'])->whereNumber('id');
                Route::get('finance/student-fees', [FinanceController::class, 'studentFeesIndex']);
                Route::get('finance/student-fees/{id}', [FinanceController::class, 'studentFeesShow'])->whereNumber('id');
                Route::get('finance/receipts', [FinanceController::class, 'receiptsIndex']);
                Route::get('finance/refunds', [FinanceController::class, 'refundsIndex']);
                Route::post('finance/refunds', [FinanceController::class, 'refundsStore']);
                Route::get('finance/adjustments', [FinanceController::class, 'adjustmentsIndex']);
                Route::post('finance/adjustments', [FinanceController::class, 'adjustmentsStore']);
                Route::post('finance/adjustments/{id}/void', [FinanceController::class, 'adjustmentsVoid'])->whereNumber('id');
                Route::get('finance/outstanding', [FinanceController::class, 'outstandingIndex']);
                Route::get('finance/reports', [FinanceController::class, 'financialReports']);
                Route::get('finance/statements', [FinanceController::class, 'outstandingIndex']);
            });

            // ── Accounting ────────────────────────────────────────
            Route::middleware('access:accounting')->group(function () {
                Route::get('accounting/coa', [AccountingController::class, 'coaIndex']);
                Route::post('accounting/coa', [AccountingController::class, 'coaStore']);
                Route::patch('accounting/coa/{id}', [AccountingController::class, 'coaUpdate'])->whereNumber('id');
                Route::get('accounting/banks', [AccountingController::class, 'banksIndex']);
                Route::post('accounting/banks', [AccountingController::class, 'banksStore']);
                Route::patch('accounting/banks/{id}', [AccountingController::class, 'banksUpdate'])->whereNumber('id');
                Route::get('accounting/budgets', [AccountingController::class, 'budgetsIndex']);
                Route::post('accounting/budgets', [AccountingController::class, 'budgetsStore']);
                Route::patch('accounting/budgets/{id}', [AccountingController::class, 'budgetsUpdate'])->whereNumber('id');
                Route::get('accounting/cash', [AccountingController::class, 'cashIndex']);
                Route::post('accounting/cash', [AccountingController::class, 'cashStore']);
                Route::get('accounting/journals', [AccountingController::class, 'journalsIndex']);
                Route::post('accounting/journals', [AccountingController::class, 'journalsStore']);
                Route::get('accounting/ledger', [AccountingController::class, 'ledger']);
                Route::get('accounting/trial-balance', [AccountingController::class, 'trialBalance']);
                Route::get('accounting/pnl', [AccountingController::class, 'profitAndLoss']);
                Route::get('accounting/balance-sheet', [AccountingController::class, 'balanceSheet']);
            });

            // ── Inventory ─────────────────────────────────────────
            Route::middleware('access:inventory')->group(function () {
                Route::get('inventory/categories', [InventoryController::class, 'categoriesIndex']);
                Route::post('inventory/categories', [InventoryController::class, 'categoriesStore']);
                Route::patch('inventory/categories/{id}', [InventoryController::class, 'categoriesUpdate'])->whereNumber('id');
                Route::get('inventory/suppliers', [InventoryController::class, 'suppliersIndex']);
                Route::post('inventory/suppliers', [InventoryController::class, 'suppliersStore']);
                Route::patch('inventory/suppliers/{id}', [InventoryController::class, 'suppliersUpdate'])->whereNumber('id');
                Route::get('inventory/assets', [InventoryController::class, 'assetsIndex']);
                Route::post('inventory/assets', [InventoryController::class, 'assetsStore']);
                Route::patch('inventory/assets/{id}', [InventoryController::class, 'assetsUpdate'])->whereNumber('id');
                Route::get('inventory/stock-items', [InventoryController::class, 'stockItemsIndex']);
                Route::post('inventory/stock-items', [InventoryController::class, 'stockItemsStore']);
                Route::patch('inventory/stock-items/{id}', [InventoryController::class, 'stockItemsUpdate'])->whereNumber('id');
                Route::get('inventory/movements', [InventoryController::class, 'movementsIndex']);
                Route::post('inventory/movements', [InventoryController::class, 'movementsStore']);
                Route::get('inventory/purchase-orders', [InventoryController::class, 'purchaseOrdersIndex']);
                Route::post('inventory/purchase-orders', [InventoryController::class, 'purchaseOrdersStore']);
                Route::patch('inventory/purchase-orders/{id}', [InventoryController::class, 'purchaseOrdersUpdate'])->whereNumber('id');
                Route::get('inventory/alerts', [InventoryController::class, 'alertsIndex']);
                Route::get('inventory/reports', [InventoryController::class, 'reportsIndex']);
            });

            // ── Library ───────────────────────────────────────────
            Route::middleware('access:library')->group(function () {
                Route::get('library/categories', [LibraryController::class, 'categoriesIndex']);
                Route::post('library/categories', [LibraryController::class, 'categoriesStore']);
                Route::patch('library/categories/{id}', [LibraryController::class, 'categoriesUpdate'])->whereNumber('id');
                Route::get('library/books', [LibraryController::class, 'booksIndex']);
                Route::post('library/books', [LibraryController::class, 'booksStore']);
                Route::patch('library/books/{id}', [LibraryController::class, 'booksUpdate'])->whereNumber('id');
                Route::get('library/loans', [LibraryController::class, 'loansIndex']);
                Route::post('library/loans', [LibraryController::class, 'loansStore']);
                Route::post('library/loans/{id}/return', [LibraryController::class, 'loansReturn'])->whereNumber('id');
                Route::get('library/reservations', [LibraryController::class, 'reservationsIndex']);
                Route::post('library/reservations', [LibraryController::class, 'reservationsStore']);
                Route::patch('library/reservations/{id}', [LibraryController::class, 'reservationsUpdate'])->whereNumber('id');
                Route::get('library/overdue', [LibraryController::class, 'overdueIndex']);
                Route::get('library/fines', [LibraryController::class, 'finesIndex']);
                Route::get('library/reports', [LibraryController::class, 'reportsIndex']);
            });

            // ── Transport ─────────────────────────────────────────
            Route::middleware('access:transport')->group(function () {
                Route::get('transport/overview', [TransportController::class, 'overview']);
                Route::get('transport/vehicles', [TransportController::class, 'vehiclesIndex']);
                Route::post('transport/vehicles', [TransportController::class, 'vehiclesStore']);
                Route::patch('transport/vehicles/{id}', [TransportController::class, 'vehiclesUpdate'])->whereNumber('id');
                Route::get('transport/drivers', [TransportController::class, 'driversIndex']);
                Route::post('transport/drivers', [TransportController::class, 'driversStore']);
                Route::patch('transport/drivers/{id}', [TransportController::class, 'driversUpdate'])->whereNumber('id');
                Route::get('transport/routes', [TransportController::class, 'routesIndex']);
                Route::post('transport/routes', [TransportController::class, 'routesStore']);
                Route::patch('transport/routes/{id}', [TransportController::class, 'routesUpdate'])->whereNumber('id');
                Route::get('transport/stops', [TransportController::class, 'stopsIndex']);
                Route::post('transport/stops', [TransportController::class, 'stopsStore']);
                Route::patch('transport/stops/{id}', [TransportController::class, 'stopsUpdate'])->whereNumber('id');
                Route::get('transport/allocations', [TransportController::class, 'allocationsIndex']);
                Route::post('transport/allocations', [TransportController::class, 'allocationsStore']);
                Route::delete('transport/allocations/{id}', [TransportController::class, 'allocationsDestroy'])->whereNumber('id');
                Route::get('transport/maintenance', [TransportController::class, 'maintenanceIndex']);
                Route::post('transport/maintenance', [TransportController::class, 'maintenanceStore']);
                Route::get('transport/fees', [TransportController::class, 'feesIndex']);
                Route::post('transport/fees', [TransportController::class, 'feesStore']);
                Route::patch('transport/fees/{id}', [TransportController::class, 'feesUpdate'])->whereNumber('id');
                Route::get('transport/assignments', [TransportController::class, 'assignmentsIndex']);
            });

            // ── Hostel ────────────────────────────────────────────
            Route::middleware('access:hostel')->group(function () {
                Route::get('hostel/overview', [HostelController::class, 'overview']);
                Route::get('hostel/hostels', [HostelController::class, 'hostelsIndex']);
                Route::post('hostel/hostels', [HostelController::class, 'hostelsStore']);
                Route::patch('hostel/hostels/{id}', [HostelController::class, 'hostelsUpdate'])->whereNumber('id');
                Route::get('hostel/rooms', [HostelController::class, 'roomsIndex']);
                Route::post('hostel/rooms', [HostelController::class, 'roomsStore']);
                Route::patch('hostel/rooms/{id}', [HostelController::class, 'roomsUpdate'])->whereNumber('id');
                Route::get('hostel/beds', [HostelController::class, 'bedsIndex']);
                Route::post('hostel/beds', [HostelController::class, 'bedsStore']);
                Route::get('hostel/allocations', [HostelController::class, 'allocationsIndex']);
                Route::post('hostel/check-in', [HostelController::class, 'checkIn']);
                Route::post('hostel/allocations/{id}/check-out', [HostelController::class, 'checkOut'])->whereNumber('id');
                Route::get('hostel/fees', [HostelController::class, 'feesIndex']);
                Route::post('hostel/fees', [HostelController::class, 'feesStore']);
                Route::get('hostel/occupancy', [HostelController::class, 'occupancyReport']);
            });

            // ── Learning + homework (same assignment engine) ──────
            Route::middleware('access:learning')->group(function () {
                Route::get('learning/overview', [LearningController::class, 'overview']);
                Route::get('learning/reports', [LearningController::class, 'reportsIndex']);
                Route::get('learning/resources', [LearningController::class, 'resourcesIndex']);
                Route::post('learning/resources', [LearningController::class, 'resourcesStore']);
                Route::patch('learning/resources/{id}', [LearningController::class, 'resourcesUpdate'])->whereNumber('id');
                Route::delete('learning/resources/{id}', [LearningController::class, 'resourcesDestroy'])->whereNumber('id');
                Route::post('learning/resources/{id}/track', [LearningController::class, 'resourcesTrack'])->whereNumber('id');
                Route::get('learning/courses', [LearningController::class, 'coursesIndex']);
                Route::post('learning/courses', [LearningController::class, 'coursesStore']);
                Route::get('learning/courses/{id}', [LearningController::class, 'coursesShow'])->whereNumber('id');
                Route::patch('learning/courses/{id}', [LearningController::class, 'coursesUpdate'])->whereNumber('id');
                Route::post('learning/lessons', [LearningController::class, 'lessonsStore']);
                Route::patch('learning/lessons/{id}', [LearningController::class, 'lessonsUpdate'])->whereNumber('id');
                Route::delete('learning/lessons/{id}', [LearningController::class, 'lessonsDestroy'])->whereNumber('id');
                Route::get('learning/assignments', [LearningController::class, 'assignmentsIndex']);
                Route::post('learning/assignments', [LearningController::class, 'assignmentsStore']);
                Route::get('learning/assignments/{id}', [LearningController::class, 'assignmentsShow'])->whereNumber('id');
                Route::patch('learning/assignments/{id}', [LearningController::class, 'assignmentsUpdate'])->whereNumber('id');
                Route::get('learning/submissions', [LearningController::class, 'submissionsIndex']);
                Route::post('learning/submissions', [LearningController::class, 'submissionsStore']);
                Route::post('learning/submissions/{id}/grade', [LearningController::class, 'submissionsGrade'])->whereNumber('id');
                Route::get('homework/overview', [LearningController::class, 'overview']);
                Route::get('homework/assignments', [LearningController::class, 'assignmentsIndex']);
                Route::post('homework/assignments', [LearningController::class, 'assignmentsStore']);
                Route::get('homework/assignments/{id}', [LearningController::class, 'assignmentsShow'])->whereNumber('id');
                Route::patch('homework/assignments/{id}', [LearningController::class, 'assignmentsUpdate'])->whereNumber('id');
                Route::get('homework/submissions', [LearningController::class, 'submissionsIndex']);
                Route::post('homework/submissions', [LearningController::class, 'submissionsStore']);
                Route::post('homework/submissions/{id}/grade', [LearningController::class, 'submissionsGrade'])->whereNumber('id');
                Route::get('homework/reports', [LearningController::class, 'reportsIndex']);
            });

            // ── Promotions (TAMISEMI end-of-year) ─────────────────
            Route::middleware('access:promotions')->group(function () {
                Route::get('promotions/overview', [PromotionController::class, 'overview']);
                Route::get('promotions/rules', [PromotionController::class, 'rulesIndex']);
                Route::post('promotions/rules', [PromotionController::class, 'rulesStore']);
                Route::patch('promotions/rules/{id}', [PromotionController::class, 'rulesUpdate'])->whereNumber('id');
                Route::delete('promotions/rules/{id}', [PromotionController::class, 'rulesDestroy'])->whereNumber('id');
                Route::get('promotions/candidates', [PromotionController::class, 'candidates']);
                Route::post('promotions/run/automatic', [PromotionController::class, 'runAutomatic']);
                Route::post('promotions/run/manual', [PromotionController::class, 'runManual']);
                Route::post('promotions/run/repeat', [PromotionController::class, 'runRepeat']);
                Route::get('promotions/runs', [PromotionController::class, 'runsIndex']);
                Route::get('promotions/runs/{id}', [PromotionController::class, 'runsShow'])->whereNumber('id');
                Route::get('promotions/reports', [PromotionController::class, 'reports']);
            });

            // ── Attendance ────────────────────────────────────────
            Route::middleware('access:attendance')->group(function () {
                Route::get('attendance', [AttendanceController::class, 'index']);
                Route::get('attendance/roster', [AttendanceController::class, 'roster']);
                Route::post('attendance/mark', [AttendanceController::class, 'mark']);
            });

            // ── Examinations (assessments, marks entry, analysis) ───
            Route::middleware('access:examinations')->group(function () {
                Route::get('examinations/types', [ExaminationController::class, 'typesIndex']);
                Route::get('examinations/assessments', [ExaminationController::class, 'assessmentsIndex']);
                Route::post('examinations/assessments', [ExaminationController::class, 'assessmentsStore']);
                Route::patch('examinations/assessments/{id}', [ExaminationController::class, 'assessmentsUpdate'])->whereNumber('id');
                Route::get('examinations/assessments/{id}/results', [ExaminationController::class, 'resultsIndex'])->whereNumber('id');
                Route::post('examinations/assessments/{id}/results', [ExaminationController::class, 'resultsStore'])->whereNumber('id');
                Route::get('examinations/ranking', [ExaminationController::class, 'ranking']);
                Route::get('examinations/subject-analysis', [ExaminationController::class, 'subjectAnalysis']);
            });

            // ── Examinations: publish / lock / delete (academic leadership) ───
            Route::middleware('access:exam-control')->group(function () {
                Route::delete('examinations/assessments/{id}', [ExaminationController::class, 'assessmentsDestroy'])->whereNumber('id');
                Route::post('examinations/assessments/{id}/publish', [ExaminationController::class, 'publish'])->whereNumber('id');
                Route::post('examinations/assessments/{id}/unpublish', [ExaminationController::class, 'unpublish'])->whereNumber('id');
                Route::post('examinations/assessments/{id}/lock', [ExaminationController::class, 'lock'])->whereNumber('id');
                Route::post('examinations/assessments/{id}/unlock', [ExaminationController::class, 'unlock'])->whereNumber('id');
            });

            // ── Report cards ──────────────────────────────────────
            Route::middleware('access:report-cards')->group(function () {
                Route::get('report-cards', [ExaminationController::class, 'reportCard']);
                Route::get('report-cards/html', [ExaminationController::class, 'reportCardHtml']);
            });

            // ── School reports ────────────────────────────────────
            Route::middleware('access:reports')->group(function () {
                Route::get('school/reports/overview', [SystemController::class, 'reportsOverview']);
                Route::get('school/reports/students', [SystemController::class, 'reportsStudents']);
                Route::get('school/reports/attendance', [SystemController::class, 'reportsAttendance']);
                Route::get('school/reports/exams', [SystemController::class, 'reportsExams']);
                Route::get('school/reports/teachers', [SystemController::class, 'reportsTeachers']);
                Route::get('school/reports/finance', [SystemController::class, 'reportsFinance']);
                Route::get('school/reports/inventory', [SystemController::class, 'reportsInventory']);
            });

            // ── Users, roles, audit, security policy ──────────────
            Route::middleware('access:users')->group(function () {
                Route::get('school/users', [SystemController::class, 'usersIndex']);
                Route::post('school/users', [SystemController::class, 'usersStore']);
                Route::patch('school/users/{id}', [SystemController::class, 'usersUpdate'])->whereNumber('id');
                Route::get('school/roles', [SystemController::class, 'rolesIndex']);
                Route::patch('school/roles/{id}', [SystemController::class, 'rolesUpdate'])->whereNumber('id');
                Route::get('school/permissions', [SystemController::class, 'permissionsIndex']);
                Route::get('school/audit-logs', [SystemController::class, 'auditIndex']);
                Route::get('school/login-history', [SystemController::class, 'loginHistoryIndex']);
                Route::get('school/password-policy', [SystemController::class, 'passwordPolicyShow']);
                Route::put('school/password-policy', [SystemController::class, 'passwordPolicyUpdate']);
                Route::patch('school/password-policy', [SystemController::class, 'passwordPolicyUpdate']);
                Route::get('school/2fa', [SystemController::class, 'twoFactorShow']);
                Route::put('school/2fa', [SystemController::class, 'twoFactorUpdate']);
                Route::patch('school/2fa', [SystemController::class, 'twoFactorUpdate']);
            });

            // ── Government compliance ─────────────────────────────
            Route::middleware('access:compliance')->group(function () {
                Route::get('school/compliance/overview', [SystemController::class, 'complianceOverview']);
                Route::get('school/compliance/census', [SystemController::class, 'complianceCensus']);
                Route::get('school/compliance/enrollment', [SystemController::class, 'complianceEnrollment']);
                Route::get('school/compliance/profile', [SystemController::class, 'complianceProfile']);
                Route::get('school/compliance/export', [SystemController::class, 'complianceExport']);
                Route::get('school/compliance/necta', [SystemController::class, 'complianceEnrollment']);
                Route::get('school/compliance/ca', [SystemController::class, 'reportsExams']);
                Route::get('school/compliance/promotions', [SystemController::class, 'complianceEnrollment']);
                Route::get('school/compliance/ministry', [SystemController::class, 'complianceOverview']);
            });

            // ── School administration ─────────────────────────────
            Route::middleware('access:admin')->group(function () {
                Route::get('school/admin/settings', [SystemController::class, 'adminSettingsShow']);
                Route::put('school/admin/settings', [SystemController::class, 'adminSettingsUpdate']);
                Route::patch('school/admin/settings', [SystemController::class, 'adminSettingsUpdate']);
                Route::get('school/admin/gateways/{channel}', [SystemController::class, 'gatewayShow'])
                    ->whereIn('channel', ['email', 'sms', 'whatsapp']);
                Route::put('school/admin/gateways/{channel}', [SystemController::class, 'gatewayUpdate'])
                    ->whereIn('channel', ['email', 'sms', 'whatsapp']);
                Route::patch('school/admin/gateways/{channel}', [SystemController::class, 'gatewayUpdate'])
                    ->whereIn('channel', ['email', 'sms', 'whatsapp']);
                Route::get('school/admin/templates', [SystemController::class, 'templatesIndex']);
                Route::post('school/admin/templates', [SystemController::class, 'templatesStore']);
                Route::get('school/admin/webhooks', [SystemController::class, 'webhooksIndex']);
                Route::post('school/admin/webhooks', [SystemController::class, 'webhooksStore']);
                Route::patch('school/admin/webhooks/{id}', [SystemController::class, 'webhooksUpdate'])->whereNumber('id');
                Route::delete('school/admin/webhooks/{id}', [SystemController::class, 'webhooksDestroy'])->whereNumber('id');
                Route::get('school/admin/backup', [SystemController::class, 'backupStatus']);
                Route::post('school/admin/backup/request', [SystemController::class, 'backupRequest']);
                Route::get('school/admin/logs', [SystemController::class, 'systemLogs']);
                Route::get('school/admin/api-keys', [SystemController::class, 'apiKeysShow']);
                Route::post('school/admin/api-keys/rotate', [SystemController::class, 'apiKeysRotate']);
            });

            // ── Communication: parent messaging ────────────────────
            Route::middleware('access:messaging')->group(function () {
                Route::get('communication/overview', [CommunicationController::class, 'overview']);
                Route::get('communication/channels', [CommunicationController::class, 'channels']);
                Route::get('communication/delivery-log', [CommunicationController::class, 'deliveryLog']);
                Route::get('communication/recipients', [CommunicationController::class, 'recipients']);
                Route::get('communication/threads', [CommunicationController::class, 'threadsIndex']);
                Route::post('communication/threads', [CommunicationController::class, 'threadsStore']);
                Route::get('communication/threads/{id}', [CommunicationController::class, 'threadsShow'])->whereNumber('id');
                Route::patch('communication/threads/{id}', [CommunicationController::class, 'threadsUpdate'])->whereNumber('id');
                Route::post('communication/threads/{id}/messages', [CommunicationController::class, 'messagesStore'])->whereNumber('id');
                Route::post('communication/threads/{id}/inbound', [CommunicationController::class, 'messagesInbound'])->whereNumber('id');
            });

            // ── Communication: announcements & notice board ────────
            Route::middleware('access:announcements')->group(function () {
                Route::get('communication/notice-board', [CommunicationController::class, 'noticeBoard']);
                Route::get('communication/audience-preview', [CommunicationController::class, 'audiencePreview']);
                Route::get('communication/announcements', [CommunicationController::class, 'announcementsIndex']);
                Route::post('communication/announcements', [CommunicationController::class, 'announcementsStore']);
                Route::get('communication/announcements/{id}', [CommunicationController::class, 'announcementsShow'])->whereNumber('id');
                Route::patch('communication/announcements/{id}', [CommunicationController::class, 'announcementsUpdate'])->whereNumber('id');
                Route::delete('communication/announcements/{id}', [CommunicationController::class, 'announcementsDestroy'])->whereNumber('id');
                Route::post('communication/announcements/{id}/publish', [CommunicationController::class, 'announcementsPublish'])->whereNumber('id');
                Route::post('communication/announcements/{id}/archive', [CommunicationController::class, 'announcementsArchive'])->whereNumber('id');
            });

            // Scaffold fallback for remaining modules
            Route::middleware('access:modules')->group(function () {
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
    });
