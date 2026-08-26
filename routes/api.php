<?php

use App\Http\Controllers\Api\Admin\AcademicYearController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\ClassroomController;
use App\Http\Controllers\Api\Admin\DismissalSettingController;
use App\Http\Controllers\Api\Admin\FeeStructureController;
use App\Http\Controllers\Api\Admin\ParentController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\StudentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\PickupController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\StaffAttendanceController;
use Illuminate\Support\Facades\Route;

// ── Public ───────────────────────────────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/webhooks/midtrans', [InvoiceController::class, 'webhook']);

// ── Authenticated (any role) ─────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'destroy']);

    // FR-BE-2.3/2.4 — verifikasi jemput, bisa dilakukan Guru, Staff, atau Admin.
    Route::middleware('role:admin,guru,staff')->group(function () {
        Route::post('/verify/pickup/scan', [PickupController::class, 'scan']);
        Route::post('/verify/pickup/{pickup}/confirm', [PickupController::class, 'confirm']);
        Route::post('/verify/pickup/manual', [PickupController::class, 'manual']);
        Route::get('/students/not-picked-up', [PickupController::class, 'notPickedUpToday']);

        Route::post('/staff/attendance/check-in', [StaffAttendanceController::class, 'checkIn']);
        Route::post('/staff/attendance/check-out', [StaffAttendanceController::class, 'checkOut']);
        Route::get('/staff/attendance/today', [StaffAttendanceController::class, 'today']);

        Route::get('/staff/leave-requests', [LeaveRequestController::class, 'index']);
        Route::post('/staff/leave-requests', [LeaveRequestController::class, 'store']);
    });

    // ── Guru & Admin: absensi kelas ──────────────────────────────────────
    Route::middleware('role:admin,guru')->group(function () {
        Route::get('/classrooms/{classroom}/attendance', [AttendanceController::class, 'index']);
        Route::post('/classrooms/{classroom}/attendance', [AttendanceController::class, 'store']);
        Route::patch('/classrooms/{classroom}/attendance', [AttendanceController::class, 'update']);
    });

    // ── Orang Tua ─────────────────────────────────────────────────────────
    Route::middleware('role:orang_tua')->prefix('ortu')->group(function () {
        Route::get('/children/{student}/attendance', [AttendanceController::class, 'forChild']);
        Route::get('/children/{student}/pickups', [PickupController::class, 'index']);
        Route::post('/children/{student}/pickups', [PickupController::class, 'store']);
        Route::delete('/pickups/{pickup}', [PickupController::class, 'destroy']);
        Route::get('/children/{student}/invoices', [InvoiceController::class, 'forChild']);
        Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay']);
        Route::get('/invoices/{invoice}/receipt', [InvoiceController::class, 'receipt']);
        Route::get('/consents', [ConsentController::class, 'index']);
        Route::post('/consents', [ConsentController::class, 'store']);
        Route::post('/consents/{consent}/withdraw', [ConsentController::class, 'withdraw']);
    });

    // ── Admin ─────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::apiResource('students', StudentController::class)->except(['destroy']);
        Route::patch('/students/{student}/status', [StudentController::class, 'updateStatus']);

        Route::get('/parents', [ParentController::class, 'index']);
        Route::post('/parents', [ParentController::class, 'store']);
        Route::post('/parents/{parent}/children', [ParentController::class, 'linkChild']);

        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/staff/{staff}', [StaffController::class, 'show']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);

        Route::get('/classrooms', [ClassroomController::class, 'index']);
        Route::post('/classrooms', [ClassroomController::class, 'store']);
        Route::put('/classrooms/{classroom}', [ClassroomController::class, 'update']);

        Route::get('/academic-years', [AcademicYearController::class, 'index']);
        Route::post('/academic-years', [AcademicYearController::class, 'store']);
        Route::get('/academic-years/{academicYear}/holidays', [AcademicYearController::class, 'holidays']);
        Route::post('/academic-years/{academicYear}/holidays', [AcademicYearController::class, 'storeHoliday']);
        Route::delete('/holidays/{holiday}', [AcademicYearController::class, 'destroyHoliday']);

        Route::get('/staff-attendances', [StaffAttendanceController::class, 'index']);
        Route::patch('/staff-attendances/{attendance}', [StaffAttendanceController::class, 'correct']);

        Route::patch('/leave-requests/{leaveRequest}/review', [LeaveRequestController::class, 'review']);

        Route::get('/pickup-logs', [PickupController::class, 'adminIndex']);
        Route::get('/settings/dismissal-cutoff', [DismissalSettingController::class, 'show']);
        Route::put('/settings/dismissal-cutoff', [DismissalSettingController::class, 'update']);

        Route::get('/fee-structures', [FeeStructureController::class, 'index']);
        Route::post('/fee-structures', [FeeStructureController::class, 'store']);
        Route::put('/fee-structures/{feeStructure}', [FeeStructureController::class, 'update']);

        Route::get('/finance/dashboard', [InvoiceController::class, 'dashboard']);
        Route::get('/finance/invoices', [InvoiceController::class, 'index']);
        Route::patch('/finance/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);

        Route::get('/audit-log', [AuditLogController::class, 'index']);
        Route::get('/consents', [ConsentController::class, 'adminIndex']);
    });
});
