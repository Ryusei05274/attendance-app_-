<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'clock_in',
        'clock_out',
        'comment',
    ];

    /** @return BelongsTo<User, $this> The user who owns this attendance. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BreakLog, $this> Break logs belonging to this attendance. */
    public function breakLogs(): HasMany
    {
        return $this->hasMany(BreakLog::class, 'attendance_id');
    }

    /** @return HasMany<BreakLog, $this> Breaks associated with this attendance. */
    public function breaks(): HasMany
    {
        return $this->hasMany(BreakLog::class, 'attendance_id');
    }

    /** @return HasMany<AttendanceCorrection, $this> Correction requests for this attendance. */
    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class, 'attendance_id');
    }
}
