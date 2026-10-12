<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\View\View;

class AttendanceCorrectionController extends Controller
{
    /**
     * 💡 【US014 / PG12】管理者用：修正申請一覧画面の表示処理
     * 要件FN047・FN048に基づき、「承認待ち」と「承認済み」のタブ切り替えに対応します
     */
    /** List correction requests by approval status with users and attendances eager loaded.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return View 表示する画面とそのデータ。
     */
    public function index(Request $request): View
    {
        if (auth()->check()) {
            auth()->user()->admin_status = true; // 右上メニュー用ガード
        }

        // タブの判定（指定がない場合は「承認待ち: status = 0」とする。承認済みは status = 1 と仮定）
        // ※設計の数値ルールに合わせて 0 や 1 を調整してください
        $tab = $request->query('tab', 'pending');
        $statusLook = ($tab === 'approved') ? 1 : 0;

        // 全一般ユーザーから届いた対象ステータスの申請をまとめて取得（N+1回避）
        $requests = AttendanceCorrection::with(['user', 'attendance'])
            ->where('status', $statusLook)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.admin-application-list', [
            'applications' => $requests, 
            'tab'          => $tab
]);

    }

    /**
     * 💡 【US015 / PG13】管理者用：修正申請承認画面の表示処理
     * 送られてきた申請内容を、以前提示していただいた「承認画面用Blade」に流し込みます
     */
    /** Show the selected correction request and its proposed values.
     * @param int $attendance_correct_request_id 対象レコードの識別子。
     * @return View 表示する画面とそのデータ。
     */
    public function showApprove(int $attendance_correct_request_id): View
    {
        if (auth()->check()) {
            auth()->user()->admin_status = true;
        }

        // 該当の申請データを取得
        $application = AttendanceCorrection::with(['user', 'attendance'])->findOrFail($attendance_correct_request_id);
        $user = $application->user;

        // 💡 以前共有いただいたBladeが「$application->new_date」などのCarbonオブジェクトを求めているため
        // メモリ上で一時的に整形して互換性をもたせます（Bladeを書き換えないための工夫）
        $application->new_date = Carbon::parse($application->ororiginal_date ?? $application->attendance->date);
        
        // 休憩ログ風に見せるためのダミーコレクションを作成（Bladeの@foreachループを突破するため）
        $proposalBreaks = collect();
        if ($application->new_break_in && $application->new_break_out) {
            $proposalBreaks->push((object)[
                'break_in' => $application->new_break_in,
                'break_out' => $application->new_break_out,
            ]);
        }
        $application->proposalBreaks = $proposalBreaks;

        // 以前共有いただいた変数構造 ($application, $user) でそのまま引き渡します
        return view('admin.admin-application-detail', compact('application', 'user'));
    }

    /**
     * 💡 【US015 / FN051】👑 修正申請の承認・本番データ一斉反映ロジック
     * 管理者が「承認」ボタンを押した瞬間、1つのトランザクションで確実にDBを書き換えます
     */
    /** Approve a correction and atomically apply its attendance and break changes.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @param int $attendance_correct_request_id 対象レコードの識別子。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function approve(Request $request, int $attendance_correct_request_id): RedirectResponse
    {
        // データの不整合を防ぐためDBトランザクションを開始
        DB::transaction(function () use ($attendance_correct_request_id) {
            
            // 1. 申請データの取得
            $correctionRequest = AttendanceCorrection::findOrFail($attendance_correct_request_id);

            // 2. 本番の勤怠メインレコード（attendancesテーブル）を申請内容で直接上書き更新
            $attendance = Attendance::findOrFail($correctionRequest->attendance_id);
            $attendance->update([
                'clock_in'  => $correctionRequest->new_clock_in,
                'clock_out' => $correctionRequest->new_clock_out,
                'comment'   => $correctionRequest->comment,
            ]);

            // 3. 本番の休憩レコード（break_logsテーブル）も申請された新しい休憩時間で書き換える
            // 既存の休憩を一度リセット
            $attendance->breakLogs()->delete();
            
            // 申請に休憩時間が入力されていれば再作成
            if ($correctionRequest->new_break_in && $correctionRequest->new_break_out) {
                $attendance->breakLogs()->create([
                    'break_in'  => $correctionRequest->new_break_in,
                    'break_out' => $correctionRequest->new_break_out,
                ]);
            }

            // 4. 申請データのステータスを「承認済み(1)」に更新
            $correctionRequest->update([
                'status'      => 1,
                'approved_at' => Carbon::now(),
            ]);
        });

        // 完了後、申請一覧画面へリダイレクト
          return redirect()->route('attendance_correction.list')
                         ->with('success', '修正申請を承認し、本編データを更新しました。');
    }
}
