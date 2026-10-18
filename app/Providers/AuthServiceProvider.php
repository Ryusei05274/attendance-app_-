<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Policies\AttendanceRecordPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [Attendance::class => AttendanceRecordPolicy::class];

    public function boot(): void
    {
        Gate::policy(Attendance::class, AttendanceRecordPolicy::class);
    }
}
