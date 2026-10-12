<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\BreakLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = collect([
            ['name' => 'ユーザー1', 'email' => 'user1@example.com', 'role' => 'user', 'is_admin' => false],
            ['name' => 'ユーザー2', 'email' => 'user2@example.com', 'role' => 'user', 'is_admin' => false],
            ['name' => 'ユーザー3', 'email' => 'user3@example.com', 'role' => 'admin', 'is_admin' => true],
        ])->map(function (array $attributes) {
            $user = User::firstOrNew(['email' => $attributes['email']]);
            $user->forceFill(array_merge($attributes, [
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'admin_status' => $attributes['is_admin'],
            ]));
            $user->save();
            return $user;
        });

        Attendance::whereIn('user_id', $users->pluck('id'))->delete();

        $months = [];
        for ($monthsAgo = 5; $monthsAgo >= 1; $monthsAgo--) {
            $months[] = [
                'month' => now()->startOfMonth()->subMonths($monthsAgo),
                'days' => 15,
            ];
        }
        $months[] = ['month' => now()->startOfMonth(), 'days' => 17];

        foreach ($users as $userIndex => $user) {
            foreach ($months as $monthIndex => $monthData) {
                $workDates = $this->firstWeekdays($monthData['month'], $monthData['days']);

                foreach ($workDates as $dayIndex => $date) {
                    [$clockIn, $clockOut] = $this->timesFor($userIndex, $monthIndex, $dayIndex);
                    $attendance = Attendance::create([
                        'user_id' => $user->id,
                        'date' => $date->toDateString(),
                        'clock_in' => $clockIn,
                        'clock_out' => $clockOut,
                    ]);

                    BreakLog::create([
                        'attendance_id' => $attendance->id,
                        'break_in' => '12:00:00',
                        'break_out' => '13:00:00',
                    ]);
                }
            }
        }
    }

    /** @return array<int, Carbon> */
    private function firstWeekdays(Carbon $month, int $count): array
    {
        $dates = [];
        $date = $month->copy()->startOfMonth();

        while ($date->month === $month->month && count($dates) < $count) {
            if ($date->isWeekday()) {
                $dates[] = $date->copy();
            }
            $date->addDay();
        }

        return $dates;
    }

    /** @return array{string, string} */
    private function timesFor(int $userIndex, int $monthIndex, int $dayIndex): array
    {
        if ($userIndex === 0 && $monthIndex === 5) {
            return match (true) {
                $dayIndex < 10 => ['09:00:00', '18:00:00'],
                $dayIndex < 13 => ['09:00:00', '20:00:00'],
                $dayIndex < 15 => ['09:30:00', '18:00:00'],
                $dayIndex === 15 => ['09:00:00', '17:00:00'],
                default => ['08:00:00', '21:00:00'],
            };
        }

        return match ($userIndex) {
            1 => ['09:05:00', '18:05:00'],
            default => ['08:45:00', '17:45:00'],
        };
    }
}
