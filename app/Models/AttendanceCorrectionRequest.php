<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionRequest extends Model
{
    use HasFactory;

    // 💡 仕様書の画像に書かれている本物のテーブル名を指定します
    protected $table = 'attendance_corrections';

    // 安全に一括保存（更新）できるようにカラム名を登録します
    protected $fillable = [
        'user_id',
        'attendance_id',
        'status',
        'ororiginal_date',
        'new_clock_in',
        'new_clock_out',
        'new_break_in',
        'new_break_out',
        'comment',
        'approved_at',
    ];

    /** @return BelongsTo<User, $this> The user who submitted this request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Attendance, $this> The attendance being corrected.
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
