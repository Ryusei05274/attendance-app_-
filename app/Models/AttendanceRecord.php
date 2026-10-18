<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * API-facing model name for the existing attendances table.
 */
class AttendanceRecord extends Attendance
{
    protected $table = 'attendances';

    /**
     * Get correction applications associated with the attendance record.
     *
     * @return HasMany<AttendanceCorrection, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class, 'attendance_id');
    }
}
