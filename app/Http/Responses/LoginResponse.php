<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Support\Facades\Auth;

class LoginResponse implements LoginResponseContract
{
    /**
     * 💡 ログイン成功時に Fortify が自動で呼び出す処理
     */
    public function toResponse($request)
    {
        $user = Auth::user();

        // 👑 【本物仕様に変更】ログインしたユーザーの admin_status が true の場合
        if ($user && $user->admin_status) { 
            // 確定したルート名（管理者用の日次勤怠一覧画面）へ自動で転送します
            return redirect()->intended(route('admin.attendance.list'));
        }

        // 👤 一般ユーザー（スタッフ）の場合の遷移先（※ご自身の一般側トップURLに合わせてください）
        return redirect()->intended('/attendance'); 
    }
}