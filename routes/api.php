<?php

use App\Http\Controllers\Api\V1\AttendanceRecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        // Public read endpoints.
        Route::get(
            '/attendance-records',
            [AttendanceRecordController::class, 'index']
        )->name('attendance-records.index');

        Route::get(
            '/attendance-records/{attendanceRecord}',
            [AttendanceRecordController::class, 'show']
        )->name('attendance-records.show');

        // Write endpoints require a Sanctum token.
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post(
                '/attendance-records',
                [AttendanceRecordController::class, 'store']
            )->name('attendance-records.store');

            Route::put(
                '/attendance-records/{attendanceRecord}',
                [AttendanceRecordController::class, 'update']
            )->name('attendance-records.update');

            Route::patch(
                '/attendance-records/{attendanceRecord}',
                [AttendanceRecordController::class, 'update']
            )->name('attendance-records.patch');

            Route::delete(
                '/attendance-records/{attendanceRecord}',
                [AttendanceRecordController::class, 'destroy']
            )->name('attendance-records.destroy');
        });
    });
