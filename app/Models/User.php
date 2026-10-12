<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'attendance_status', 
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];

    /** Read the persisted administrator flag through the view-facing alias.
     * @return bool 判定結果。
     */
    public function getAdminStatusAttribute(): bool
    {
        return (bool) ($this->attributes['is_admin'] ?? false);
    }

    /** Persist the view-facing administrator alias to the existing schema column.
     * @param mixed $value 処理に使用する値。
     * @return void 戻り値なし。
     */
    public function setAdminStatusAttribute(mixed $value): void
    {
        $this->attributes['is_admin'] = (bool) $value;
    }

    /** @return HasMany<Attendance, $this> Attendance records owned by this user.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** @return HasMany<AttendanceCorrection, $this> Correction requests submitted by this user.
     */
    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }
    
}

  
