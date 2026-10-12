<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * PG07: 管理者ログイン画面の表示
     * @return View 表示する画面とそのデータ。
     */
    public function create(): View
    {
        return view('admin.admin-login');
    }

    /**
     * FN014〜FN016: 管理者ログインの実行（正規の認証ロジック）
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function store(Request $request): RedirectResponse
    {
        // 1. バリデーション
        $credentials = $request->validate([
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ], [
            'email.required'    => 'メールアドレスを入力してください',
            'email.email'       => 'メールアドレスはメール形式で入力してください',
            'password.required' => 'パスワードを入力してください',
        ]);

        // 2. 認証処理（管理者権限 role => admin の条件も追加）
        $credentials['role'] = 'admin';

        // 💡 【重要】Auth::guard('admin')->attempt に修正し、管理者の鍵で厳格にログインさせます
        if (Auth::attempt($credentials)) { 
            // 認証成功時、セッションを再生成してリダイレクト
            $request->session()->regenerate();
            return redirect('/admin/attendance/list'); 
        }

        // 3. 認証失敗時のエラー返却
        return back()->withErrors([
            'email' => 'ログイン情報が登録されていません',
        ])->withInput($request->only('email'));
    }

    /**
     * FN017: 管理者ログアウト処理
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
