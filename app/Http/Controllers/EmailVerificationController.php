<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    /** Display the email verification instructions for a pending or authenticated user.
     * @param Request $request Current authenticated request.
     * @return View|RedirectResponse Verification instructions or the attendance page.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user() ?? User::find(session('verification.pending_user_id'));
        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return $request->user()
                ? redirect()->route('attendance.register')
                : redirect()->route('login');
        }

        return view('auth.verify-email');
    }

    /** Verify the account using Laravel's signed verification link and sign the user in.
     * @param Request $request Signed verification request.
     * @param int $id User identifier from the signed URL.
     * @param string $hash Email hash from the signed URL.
     * @return RedirectResponse Redirect to attendance after successful verification.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (!$user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('verification.pending_user_id');

        return redirect()->route('attendance.register')->with('verified', true);
    }

    /** Send another verification email when the address is still unverified.
     * @param Request $request Current authenticated request.
     * @return RedirectResponse Return to the instruction page with a status message.
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $request->user() ?? User::find(session('verification.pending_user_id'));
        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return $request->user()
                ? redirect()->route('attendance.register')
                : redirect()->route('login');
        }

        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice')->with('message', '認証メールを再送しました。メールをご確認ください。');
    }
}
