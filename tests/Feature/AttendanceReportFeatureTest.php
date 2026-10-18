<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BreakLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function attendance(User $user, string $date, string $clockIn, string $clockOut): Attendance
    {
        return Attendance::query()->create([
            'user_id' => $user->id,
            'date' => $date,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);
    }

    public function test_guest_is_redirected_to_login_from_the_report(): void
    {
        $this->get('/attendance/report')->assertRedirect(route('login'));
    }

    public function test_report_calculates_six_month_summary_trend_and_current_month_anomalies(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $user = User::factory()->create();

        $may = $this->attendance($user, '2026-05-01', '09:00:00', '18:00:00');
        BreakLog::query()->create([
            'attendance_id' => $may->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $longDay = $this->attendance($user, '2026-10-02', '08:00:00', '21:00:00');
        BreakLog::query()->create([
            'attendance_id' => $longDay->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $lateAndEarly = $this->attendance($user, '2026-10-03', '09:30:00', '17:00:00');
        BreakLog::query()->create([
            'attendance_id' => $lateAndEarly->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $this->attendance($user, '2026-04-30', '09:00:00', '18:00:00');

        $this->actingAs($user)
            ->get('/attendance/report')
            ->assertOk()
            ->assertViewHas('summary', [
                'total_work_minutes' => 1590,
                'total_overtime_minutes' => 240,
                'avg_work_minutes' => 530,
            ])
            ->assertViewHas('monthlyTrend', function ($trend): bool {
                return $trend->count() === 6
                    && $trend[0]['work_minutes'] === 480
                    && $trend[1]['work_minutes'] === 0
                    && $trend[5]['work_minutes'] === 1110
                    && $trend[5]['overtime_minutes'] === 240;
            })
            ->assertViewHas('anomalies', [
                'late_count' => 1,
                'early_leave_count' => 1,
                'long_work_count' => 1,
            ]);

        $this->travelBack();
    }

    public function test_report_for_user_without_attendances_returns_zero_values_and_six_empty_months(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00'));
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/attendance/report')
            ->assertOk()
            ->assertViewHas('summary', [
                'total_work_minutes' => 0,
                'total_overtime_minutes' => 0,
                'avg_work_minutes' => 0,
            ])
            ->assertViewHas('monthlyTrend', function ($trend): bool {
                return $trend->count() === 6
                    && $trend->every(fn (array $month): bool =>
                        $month['work_minutes'] === 0 && $month['overtime_minutes'] === 0
                    );
            })
            ->assertViewHas('anomalies', [
                'late_count' => 0,
                'early_leave_count' => 0,
                'long_work_count' => 0,
            ]);

        $this->travelBack();
    }
}