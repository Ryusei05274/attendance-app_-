<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\BreakLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BasicFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'user', string $email = 'user@example.test'): User
    {
        $user = new User;
        $user->forceFill(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password123'), 'role' => $role, 'email_verified_at' => now()]);
        $user->save();
        return $user;
    }

    private function attendance(User $user, string $date = '2026-10-02', array $values = []): Attendance
    {
        return Attendance::create(array_merge(['user_id' => $user->id, 'date' => $date, 'clock_in' => '09:00', 'clock_out' => '18:00'], $values));
    }

    public function test_registration_validates_required_fields_password_rules_and_persists_a_user(): void
    {
        $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password', 'password_confirmation']);
        $this->post('/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors(['password']);
        $this->post('/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'password123', 'password_confirmation' => 'different'])->assertSessionHasErrors(['password_confirmation']);
        $this->post('/register', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('users', ['name' => 'A', 'email' => 'a@example.test', 'role' => 'user', 'email_verified_at' => null]);
        $this->assertGuest();
        $this->assertSessionHas('verification.pending_user_id');
    }

    public function test_user_login_validates_and_authenticates(): void
    {
        $this->user();
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'password123'])->assertRedirect(route('attendance.register'));
        $this->assertAuthenticated();
    }

    public function test_admin_login_validates_credentials_and_routes_admin(): void
    {
        $this->user('admin', 'admin@example.test');
        $this->post('/admin/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/admin/login', ['email' => 'no@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'password123'])->assertRedirect('/admin/attendance/list');
        $this->assertAuthenticated();
    }

    public function test_attendance_clock_uses_frozen_time_and_shows_all_four_statuses(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(8, 5));
        $user = $this->user();
        $this->actingAs($user)->get('/attendance')->assertOk()->assertSee('2026年10月02日')->assertSee('08:05')->assertSee('勤務外');
        $attendance = $this->attendance($user, '2026-10-02', ['clock_out' => null]);
        $this->get('/attendance')->assertSee('出勤中');
        BreakLog::create(['attendance_id' => $attendance->id, 'break_in' => '09:30']);
        $this->get('/attendance')->assertSee('休憩中');
        $attendance->update(['clock_out' => '18:00']);
        $this->get('/attendance')->assertSee('退勤済');
        $this->travelBack();
    }

    public function test_clock_in_breaks_and_clock_out_persist_once_and_appear_in_list(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(9, 0));
        $user = $this->user();
        $this->actingAs($user)->post('/attendance', ['action' => 'clock_in'])->assertRedirect(route('attendance.register'));
        $this->assertDatabaseHas('attendances', ['user_id' => $user->id, 'date' => '2026-10-02', 'clock_in' => '09:00:00']);
        $this->post('/attendance', ['action' => 'break_in']);
        $this->travel(30)->minutes();
        $this->post('/attendance', ['action' => 'break_out']);
        $this->post('/attendance', ['action' => 'break_in']);
        $this->travel(15)->minutes();
        $this->post('/attendance', ['action' => 'break_out']);
        $this->travel(8)->hours();
        $this->post('/attendance', ['action' => 'clock_out']);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('break_logs', 2);
        $this->assertDatabaseHas('attendances', ['user_id' => $user->id, 'clock_out' => '17:45:00']);
        $this->get('/attendance/list?date=2026-10')->assertOk()->assertSee('09:00')->assertSee('17:45');
        $this->travelBack();
    }

    public function test_clock_transitions_hide_unavailable_actions_and_reject_duplicate_clock_in(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(9, 0));
        $user = $this->user();
        $this->actingAs($user)->get('/attendance')->assertSee('value="clock_in">出勤</button>', false);
        $this->post('/attendance', ['action' => 'clock_in']);
        $this->post('/attendance', ['action' => 'clock_in'])->assertStatus(422);
        $this->get('/attendance')->assertSee('休憩入')->assertSee('退勤')->assertDontSee('休憩戻');
        $this->post('/attendance', ['action' => 'break_in']);
        $this->get('/attendance')->assertSee('休憩戻')->assertDontSee('休憩入');
        $this->post('/attendance', ['action' => 'break_out']);
        $this->post('/attendance', ['action' => 'break_in']);
        $this->post('/attendance', ['action' => 'break_out']);
        $this->post('/attendance', ['action' => 'clock_out']);
        $this->get('/attendance')->assertSee('退勤済')->assertDontSee('value="clock_in">出勤</button>', false);
        $this->travelBack();
    }

    public function test_user_attendance_month_navigation_details_and_correction_requests(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $user = $this->user();
        $other = $this->user('user', 'other@example.test');
        $attendance = $this->attendance($user);
        $this->attendance($user, '2026-09-20');
        $this->attendance($other);
        BreakLog::create(['attendance_id' => $attendance->id, 'break_in' => '12:00', 'break_out' => '13:00']);
        $this->actingAs($user)->get('/attendance/list')->assertOk()->assertSee('2026/10')->assertSee('09:00');
        $this->get('/attendance/list?date=2026-09')->assertSee('09:00');
        $this->get('/attendance/list?date=2026-11')->assertSee('2026/11');
        $this->get('/attendance/'.$attendance->id)->assertOk()->assertSee('09:00')->assertSee('18:00')->assertSee('12:00');
        $this->get('/attendance/'.$other->attendances()->first()->id)->assertNotFound();
        $bad = ['new_clock_in' => '19:00', 'new_clock_out' => '18:00', 'comment' => '訂正'];
        $this->post('/attendance/'.$attendance->id, $bad)->assertSessionHasErrors(['new_clock_out']);
        $this->post('/attendance/'.$attendance->id, ['new_clock_in' => '09:00', 'new_clock_out' => '18:00', 'new_break_in' => ['12:00'], 'new_break_out' => ['19:00'], 'comment' => '訂正'])->assertSessionHasErrors(['new_break_out.0']);
        $this->post('/attendance/'.$attendance->id, ['new_clock_in' => '09:00', 'new_clock_out' => '18:00', 'new_break_in' => ['12:00'], 'new_break_out' => ['13:00'], 'comment' => '訂正'])->assertRedirect('/attendance/'.$attendance->id);
        $correction = AttendanceCorrection::firstOrFail();
        $this->get('/attendance_correction_request/list')->assertOk()->assertSee('訂正')->assertSee('承認待ち');
        $this->get('/attendance/'.$attendance->id)->assertSee('09:00');
        $this->travelBack();
    }

    public function test_user_and_admin_correction_forms_validate_notes_and_time_order(): void
    {
        $user = $this->user();
        $attendance = $this->attendance($user);
        $this->actingAs($user)->post('/attendance/'.$attendance->id, [
            'new_clock_in' => '09:00', 'new_clock_out' => '18:00', 'comment' => '',
        ])->assertSessionHasErrors(['comment' => '備考を記入してください']);

        $admin = $this->user('admin', 'admin@example.test');
        $this->actingAs($admin)->put('/admin/attendance/'.$attendance->id, [
            'new_clock_in' => '19:00', 'new_clock_out' => '18:00', 'comment' => '修正',
        ])->assertSessionHasErrors(['new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です']);
        $this->put('/admin/attendance/'.$attendance->id, [
            'new_clock_in' => '09:00', 'new_clock_out' => '18:00', 'comment' => '',
        ])->assertSessionHasErrors(['comment' => '備考を記入してください']);
    }

    public function test_admin_can_review_and_approve_corrections_and_list_staff_attendance(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('user', 'staff@example.test');
        $attendance = $this->attendance($staff);
        $correction = AttendanceCorrection::create([
            'attendance_id' => $attendance->id, 'user_id' => $staff->id, 'original_date' => '2026-10-02',
            'new_clock_in' => '08:30', 'new_clock_out' => '17:30', 'new_breaks' => [['break_in' => '12:00', 'break_out' => '12:45']],
            'comment' => '時刻訂正', 'status' => 0,
        ]);
        $this->actingAs($admin)->get('/admin/attendance/list?date=2026-10-02')->assertOk()->assertSee('2026/10/02')->assertSee('User');
        $this->get('/admin/attendance/list?date=2026-10-01')->assertSee('2026/10/01');
        $this->get('/admin/attendance/list?date=2026-10-03')->assertSee('2026/10/03');
        $this->get('/admin/attendance/'.$attendance->id)->assertOk()->assertSee('09:00')->assertSee('18:00');
        $this->get('/admin/staff/list')->assertOk()->assertSee('staff@example.test')->assertDontSee('admin@example.test');
        $this->get('/admin/attendance/staff/'.$staff->id.'?date=2026-10')->assertOk()->assertSee('10/02');
        $this->get('/attendance_correction_request/list?tab=pending')->assertOk()->assertSee('時刻訂正');
        $this->get('/admin/attendance_correction_request/approve/'.$correction->id)->assertOk()->assertSee('時刻訂正');
        $this->post('/admin/attendance_correction_request/approve/'.$correction->id)->assertRedirect(route('attendance_correction.list'));
        $this->assertDatabaseHas('attendances', ['id' => $attendance->id, 'clock_in' => '08:30', 'clock_out' => '17:30']);
        $this->assertDatabaseHas('attendance_corrections', ['id' => $correction->id, 'status' => 1]);
        $this->assertDatabaseHas('break_logs', ['attendance_id' => $attendance->id, 'break_in' => '12:00', 'break_out' => '12:45']);
        $this->get('/attendance_correction_request/list?tab=approved')->assertSee('時刻訂正');
    }
}
