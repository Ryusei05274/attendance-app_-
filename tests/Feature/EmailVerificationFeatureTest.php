<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_a_verification_email_and_shows_mailpit_link(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => '検証ユーザー',
            'email' => 'verification@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $user = User::query()->where('email', 'verification@example.test')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertGuest();

        $this->withSession(['verification.pending_user_id' => $user->id])
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('http://localhost:8025', false);
    }

    public function test_signed_verification_link_verifies_and_redirects_to_attendance(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $this->withSession(['verification.pending_user_id' => $user->id])
            ->get($verificationUrl)
            ->assertRedirect(route('attendance.register'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        $this->get(route('attendance.register'))->assertOk();
    }

    public function test_pending_user_can_resend_the_verification_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->withSession(['verification.pending_user_id' => $user->id])
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}