<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BreakLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DummyDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_users_and_six_month_report_values(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'user1@example.com', 'role' => 'user']);
        $this->assertDatabaseHas('users', ['email' => 'user2@example.com', 'role' => 'user']);
        $this->assertDatabaseHas('users', ['email' => 'user3@example.com', 'role' => 'admin', 'is_admin' => 1]);

        $this->assertSame(3, User::whereIn('email', [
            'user1@example.com', 'user2@example.com', 'user3@example.com',
        ])->whereNotNull('email_verified_at')->count());
        $this->assertSame(276, Attendance::count());
        $this->assertSame(276, BreakLog::count());

        $user1 = User::where('email', 'user1@example.com')->firstOrFail();
        $this->assertNotNull($user1->email_verified_at);
        $this->assertTrue(Hash::check('password', $user1->password));
        $this->assertSame(92, Attendance::where('user_id', $user1->id)->count());
        $this->assertSame(92, BreakLog::whereIn('attendance_id', $user1->attendances()->select('id'))->count());
        $this->assertTrue(User::where('email', 'user3@example.com')->firstOrFail()->admin_status);

        // Re-running the seeder replaces this fixture's attendance rows instead of duplicating them.
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(3, User::whereIn('email', [
            'user1@example.com', 'user2@example.com', 'user3@example.com',
        ])->count());
        $this->assertSame(276, Attendance::count());
        $this->assertSame(276, BreakLog::count());

        $this->actingAs($user1)->get('/attendance/report')->assertOk()
            ->assertViewHas('summary.total_work_minutes', 744 * 60)
            ->assertViewHas('summary.total_overtime_minutes', 10 * 60)
            ->assertViewHas('summary.avg_work_minutes', 8 * 60 + 5)
            ->assertViewHas('monthlyTrend', fn ($trend) => $trend->count() === 6
                && $trend->take(5)->every(fn (array $month): bool => $month['work_minutes'] === 120 * 60 && $month['overtime_minutes'] === 0)
                && $trend->last()['work_minutes'] === 144 * 60
                && $trend->last()['overtime_minutes'] === 10 * 60)
            ->assertViewHas('anomalies.late_count', 2)
            ->assertViewHas('anomalies.early_leave_count', 1)
            ->assertViewHas('anomalies.long_work_count', 1);

        $this->travelBack();
    }

    public function test_report_displays_zero_values_when_the_user_has_no_attendance(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get('/attendance/report')->assertOk()
            ->assertViewHas('summary', [
                'total_work_minutes' => 0,
                'total_overtime_minutes' => 0,
                'avg_work_minutes' => 0,
            ])
            ->assertViewHas('monthlyTrend', fn ($trend): bool => $trend->count() === 6
                && $trend->every(fn (array $month): bool => $month['work_minutes'] === 0 && $month['overtime_minutes'] === 0))
            ->assertViewHas('anomalies', [
                'late_count' => 0,
                'early_leave_count' => 0,
                'long_work_count' => 0,
            ]);
    }

    public function test_report_redirects_guests_to_login(): void
    {
        $this->get('/attendance/report')->assertRedirect(route('login'));
    }
}
