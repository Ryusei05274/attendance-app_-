<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisterController extends Controller
{
    /**
     * PG01: 会員登録画面の表示
     * @return View 表示する画面とそのデータ。
     */
    public function create(): View
    {
        // 💡こちらも実際の配置に合わせてView名を正しく指定します
        // 例: 'user.register' または 'register'
        return view('user.register'); 
    }

    /**
     * FN001〜FN005: 会員登録の実行 ＆ 指定文言エラー
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|string|same:password',
        ], [
            'name.required'     => 'お名前を入力してください',
            'email.required'    => 'メールアドレスを入力してください',
            'email.email'       => 'メールアドレスはメール形式で入力してください',
            'password.required' => 'パスワードを入力してください',
            'password.min'      => 'パスワードは8文字以上で入力してください',
            'password_confirmation.required' => '確認用パスワードを入力してください',
            'password_confirmation.same'     => 'パスワードと一致しません',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'user', // 初期値は一般ユーザー
        ]);

        // Keep the pending account in the session so the verification page can be shown without login.
        session(['verification.pending_user_id' => $user->id]);
        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }
}
