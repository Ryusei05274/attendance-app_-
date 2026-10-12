<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffController extends Controller
{
    /**
     * 💡 【US041 / PG10】管理者用：スタッフ一覧画面の表示処理
     */
    /** List all general users for administrator review. */
    public function index(): View
    {
        // 🔒 セキュリティ・ガード（メモリ上でadmin_statusを有効化してお手本メニューを表示）
        if (auth()->check()) {
            auth()->user()->admin_status = true;
        }

        // 要件FN041: 全一般ユーザー（管理者以外）の「氏名」「メールアドレス」を取得
        // ※管理者を見分ける条件（roleやadmin_statusなど）に合わせて調整してください
        $users = User::where('role', '!=', 'admin')->get();

        // スタッフ一覧専用のBladeファイル（admin-staff-list）へデータを渡します
        return view('admin.staff-list', compact('users'));
    }

}
