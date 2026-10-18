<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendanceRecordPolicy
{
    /** Grant administrators all abilities handled by this policy. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->admin_status ? true : null;
    }

    public function create(User $user, int $targetUserId): bool
    {
        return $user->id === $targetUserId;
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->id === $attendance->user_id;
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return $user->id === $attendance->user_id;
    }
}