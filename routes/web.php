<?php

use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Admin\AttendanceCsvController;
use App\Http\Controllers\Admin\LoginController as AdminLoginController;
use App\Http\Controllers\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AttendanceCorrectionController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (!auth()->check()) {
        return redirect()->route('login');
    }

    return auth()->user()->role === 'admin'
        ? redirect()->route('admin.attendance.list')
        : redirect()->route('attendance.register');
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/admin/login', [AdminLoginController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [AdminLoginController::class, 'store']);
});

Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
    ->middleware('throttle:6,1')
    ->name('verification.send');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware('verified')->group(function (): void {
        Route::get('/attendance', [AttendanceController::class, 'create'])->name('attendance.register');
        Route::get('/attendance/report', [AttendanceReportController::class, 'show'])->name('attendance.report');
        Route::post('/attendance', [AttendanceController::class, 'updateStatus'])->name('attendance.update');
        Route::get('/attendance/list', [AttendanceController::class, 'index'])->name('attendance.list');
        Route::get('/attendance/{attendance_id}', [AttendanceCorrectionController::class, 'show'])->name('attendance.detail');
        Route::post('/attendance/{attendance_id}', [AttendanceCorrectionController::class, 'store'])->name('attendance.detail.store');
    });

    // This shared list checks verification for regular users while allowing administrators through.
    Route::get('/attendance_correction_request/list', [AttendanceCorrectionController::class, 'index'])->name('attendance_correction.list');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::post('/logout', [AdminLoginController::class, 'destroy'])->name('logout');
        Route::get('/attendance/list', [AdminAttendanceController::class, 'index'])->name('attendance.list');
        Route::get('/attendance/staff/{id}', [AdminAttendanceController::class, 'staff'])->name('attendance.staff');
        Route::post('/attendance/staff/{user_id}/csv', [AttendanceCsvController::class, 'export'])->name('attendance.staff.csv');
        Route::get('/attendance/{attendance_id}', [AdminAttendanceController::class, 'show'])->name('attendance.detail');
        Route::put('/attendance/{attendance_id}', [AdminAttendanceController::class, 'update'])->name('attendance.update');
        Route::get('/staff/list', [AdminStaffController::class, 'index'])->name('staff.list');
        Route::get('/attendance_correction_request/approve/{attendance_correct_request_id}', [AttendanceCorrectionController::class, 'showApprove'])->name('correction.approve');
        Route::post('/attendance_correction_request/approve/{attendance_correct_request_id}', [AttendanceCorrectionController::class, 'approve'])->name('correction.approve.store');
    });
});
