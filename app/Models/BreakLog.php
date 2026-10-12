<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'break_in',
        'break_out',
    ];

    /** @return BelongsTo<Attendance, $this> The attendance that owns this break.
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
