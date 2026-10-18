<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\BreakLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicAttendanceApiFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function attendance(User $user, string $date, array $attributes = []): Attendance
    {
        return Attendance::query()->create(array_merge([
            'user_id' => $user->id,
            'date' => $date,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => null,
        ], $attributes));
    }

    public function test_public_index_filters_and_returns_paginated_json_without_break_details(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $older = $this->attendance($user, '2026-10-01');
        $newer = $this->attendance($user, '2026-10-02');
        $this->attendance($otherUser, '2026-10-03');
        BreakLog::query()->create([
            'attendance_id' => $newer->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $this->getJson('/api/v1/attendance-records?user_id='.$user->id.'&month=2026-10&per_page=1&page=2')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'date', 'clock_in', 'clock_out', 'total_time', 'total_break_time']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.breaks');

        $this->getJson('/api/v1/attendance-records?date=2026-10-02')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/attendance-records?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('errors.per_page.0', '1ページあたりの件数は100以下で指定してください。');
    }

    public function test_public_show_includes_user_breaks_and_correction_applications(): void
    {
        $user = User::factory()->create();
        $attendance = $this->attendance($user, '2026-10-04');
        BreakLog::query()->create([
            'attendance_id' => $attendance->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        AttendanceCorrection::query()->create([
            'attendance_id' => $attendance->id,
            'user_id' => $user->id,
            'original_date' => '2026-10-04',
            'new_clock_in' => '09:15:00',
            'new_clock_out' => '18:15:00',
            'new_breaks' => [['break_in' => '12:00:00', 'break_out' => '13:00:00']],
            'comment' => '時刻修正',
            'status' => 0,
        ]);

        $this->getJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.breaks.0.break_in', '12:00:00')
            ->assertJsonPath('data.applications.0.comment', '時刻修正')
            ->assertJsonPath('data.total_time', '8:00')
            ->assertJsonPath('data.total_break_time', '1:00');
    }

    public function test_unknown_read_and_write_ids_return_the_documented_404_json(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/attendance-records/99999')
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/attendance-records/99999', ['comment' => '更新'])
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);

        $this->deleteJson('/api/v1/attendance-records/99999')
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);
    }

    public function test_authenticated_post_creates_record_and_assigns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/attendance-records', [
            'date' => '2026-10-05',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => 'API登録',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.comment', 'API登録');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'date' => '2026-10-05',
            'clock_in' => '09:00:00',
        ]);
    }

    public function test_post_and_put_return_japanese_validation_errors(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/attendance-records', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', '勤怠日は必須です。')
            ->assertJsonPath('errors.clock_in.0', '出勤時刻は必須です。');

        $this->postJson('/api/v1/attendance-records', [
            'date' => '2026/10/06',
            'clock_in' => '9am',
            'clock_out' => '08:00:00',
            'comment' => str_repeat('あ', 256),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', '勤怠日は YYYY-MM-DD 形式で指定してください。')
            ->assertJsonPath('errors.clock_in.0', '出勤時刻は HH:MM:SS 形式で指定してください。')
            ->assertJsonPath('errors.comment.0', '備考は 255 文字以内で入力してください。');

        $attendance = $this->attendance($user, '2026-10-07');
        $this->putJson('/api/v1/attendance-records/'.$attendance->id, [
            'clock_out' => '08:00:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.clock_out.0', '退勤時刻は出勤時刻より後の時刻を指定してください。');
    }

    public function test_authenticated_partial_update_returns_resource_and_preserves_omitted_fields(): void
    {
        $user = User::factory()->create();
        $attendance = $this->attendance($user, '2026-10-08');
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/attendance-records/'.$attendance->id, [
            'comment' => '一部更新',
        ])
            ->assertOk()
            ->assertJsonPath('data.comment', '一部更新')
            ->assertJsonPath('data.clock_in', '09:00:00')
            ->assertJsonPath('data.clock_out', '18:00:00');

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'comment' => '一部更新',
            'clock_in' => '09:00:00',
        ]);
    }

    public function test_authenticated_owner_can_delete_and_get_204(): void
    {
        $user = User::factory()->create();
        $attendance = $this->attendance($user, '2026-10-09');
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)->assertNoContent();
        $this->assertDatabaseMissing('attendances', ['id' => $attendance->id]);
    }

    public function test_write_endpoints_return_401_without_a_sanctum_user(): void
    {
        $user = User::factory()->create();
        $attendance = $this->attendance($user, '2026-10-10');

        $this->postJson('/api/v1/attendance-records', [
            'date' => '2026-10-11',
            'clock_in' => '09:00:00',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

        $this->putJson('/api/v1/attendance-records/'.$attendance->id, [
            'comment' => '未認証',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_regular_user_cannot_update_or_delete_another_users_attendance(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $attendance = $this->attendance($owner, '2026-10-12');
        Sanctum::actingAs($otherUser);

        $expected = ['error' => 'この操作を実行する権限がありません。'];

        $this->putJson('/api/v1/attendance-records/'.$attendance->id, [
            'comment' => '他人の勤怠',
        ])->assertForbidden()->assertExactJson($expected);

        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertForbidden()
            ->assertExactJson($expected);

        $this->assertDatabaseHas('attendances', ['id' => $attendance->id]);
    }
}