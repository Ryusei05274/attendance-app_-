<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * 💡 【US010】管理者用：日次勤怠一覧画面の表示処理
     * Blade側の @foreach ($users as $user) および $attendanceRecords という名前に100%連動させます。
     */
    /** Show every staff member's attendance for the selected day.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return View|RedirectResponse 一覧画面、または入力が不正な場合の遷移先。
     */
    public function index(Request $request): View|RedirectResponse
    {
        $dateParam = $request->query('date', Carbon::today()->toDateString());
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $dateParam);
        } catch (\Throwable $exception) {
            return redirect()->route('admin.attendance.list');
        }
        abort_unless($date && $date->format('Y-m-d') === $dateParam, 404);

        // 前日と翌日のボタン用の日付を計算（YYYY-MM-DD 形式）
        $previousDay = $date->copy()->subDay()->format('Y-m-d');
        $nextDay = $date->copy()->addDay()->format('Y-m-d');

        // 💡 【超重要】既存のBladeが求めている変数名「$users」にスタッフ全員を格納
        $users = User::where('role', 'user')->orderBy('name')->get();

        // 💡 【超重要】既存のBladeが求めている変数名「$attendanceRecords」にその日の勤怠データを格納
        // 休憩計算が裏で走るため、Eager Loading（with）を仕込んで超高速化させます
        $attendanceRecords = Attendance::with(['breakLogs'])
            ->whereDate('date', $date->format('Y-m-d'))
            ->get()
            ->keyBy('user_id');

        $attendanceRecords->each(function (Attendance $attendance): void {
            $breakSeconds = $attendance->breakLogs->sum(function ($break) use ($attendance): int {
                if (!$break->break_in || !$break->break_out) return 0;
                $start = Carbon::parse($attendance->date.' '.$break->break_in);
                $end = Carbon::parse($attendance->date.' '.$break->break_out);
                if ($end->lt($start)) $end->addDay();
                return $start->diffInSeconds($end);
            });
            $attendance->total_break_time = $attendance->clock_out ? sprintf('%d:%02d', intdiv($breakSeconds, 3600), intdiv($breakSeconds % 3600, 60)) : '';
            if ($attendance->clock_in && $attendance->clock_out) {
                $start = Carbon::parse($attendance->date.' '.$attendance->clock_in);
                $end = Carbon::parse($attendance->date.' '.$attendance->clock_out);
                if ($end->lt($start)) $end->addDay();
                $workSeconds = max(0, $start->diffInSeconds($end) - $breakSeconds);
                $attendance->total_time = sprintf('%d:%02d', intdiv($workSeconds, 3600), intdiv($workSeconds % 3600, 60));
            } else {
                $attendance->total_time = '';
            }
        });

        // 4. 実在する管理者用の日次勤怠一覧Blade（admin-attendance-list）へデータを渡します
        // 💡 配列のキー名を、お手元のBladeが使っている変数名に完全に一致させました
        return view('admin.admin-attendance-list', [
            'users'             => $users,
            'attendanceRecords' => $attendanceRecords,
            'date'              => $date,
            'previousDay'       => $previousDay,
            'nextDay'           => $nextDay,
        ]);
    }

    /**
     * 💡 【US011 / PG09】管理者用：勤怠詳細画面の表示処理
     */
    /** Show a selected attendance record with its owner and eager-loaded breaks.
     * @param int $attendance_id 対象レコードの識別子。
     * @return View 表示する画面とそのデータ。
     */
    public function show(int $attendance_id): View
    {
        $attendance = Attendance::with(['user', 'breakLogs'])->findOrFail($attendance_id);
        $user = $attendance->user;

        $dateObj = \Carbon\Carbon::parse($attendance->date);

        // 休憩ログをループして配列に格納
        $breaks = $attendance->breakLogs->map(static fn ($break): array => [
                'break_in'  => $break->break_in ? \Carbon\Carbon::parse($break->break_in)->format('H:i') : '',
                'break_out' => $break->break_out ? \Carbon\Carbon::parse($break->break_out)->format('H:i') : '',
            ])->all();

        $attendanceRecord = [
            'id'        => $attendance->id,
            'year'      => $dateObj->format('Y年'),
            'date'      => $dateObj->format('n月j日'),
            'clock_in'  => $attendance->clock_in ? \Carbon\Carbon::parse($attendance->clock_in)->format('H:i') : '',
            'clock_out' => $attendance->clock_out ? \Carbon\Carbon::parse($attendance->clock_out)->format('H:i') : '',
            'breaks'    => $breaks,
            'comment'   => $attendance->comment ?? '',
        ];

        return view('admin.admin-detail', compact('attendanceRecord', 'user'));
    }

    /**
     * 💡 【US011 / FN039〜FN040】管理者用：勤怠データの直接修正処理（保存）
     */
    /** Validate and save an administrator's attendance edit.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @param int $attendance_id 対象レコードの識別子。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function update(Request $request, int $attendance_id): RedirectResponse
    {
        $validated = $request->validate([
            'new_clock_in' => 'required|date_format:H:i',
            'new_clock_out' => 'required|date_format:H:i',
            'new_break_in' => 'array',
            'new_break_in.*' => 'nullable|date_format:H:i',
            'new_break_out' => 'array',
            'new_break_out.*' => 'nullable|date_format:H:i',
            'comment' => 'required|string|max:255',
        ], [
            'comment.required' => '備考を記入してください',
        ]);

        $in = Carbon::createFromFormat('H:i', $validated['new_clock_in']);
        $out = Carbon::createFromFormat('H:i', $validated['new_clock_out']);
        if ($out->lte($in)) {
            return back()->withErrors(['new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です'])->withInput();
        }

        $breakError = null;
        $breaks = collect($validated['new_break_in'] ?? [])->map(function (?string $breakIn, int $index) use ($validated, $in, $out, &$breakError): ?array {
            $breakOut = $validated['new_break_out'][$index] ?? null;
            if (!$breakIn && !$breakOut) return null;
            if (!$breakIn || !$breakOut) {
                $breakError = ["new_break_in.$index" => '休憩時間が不適切な値です'];
                return null;
            }
            $start = Carbon::createFromFormat('H:i', $breakIn);
            $end = Carbon::createFromFormat('H:i', $breakOut);
            if ($end->gt($out)) {
                $breakError = ["new_break_out.$index" => '休憩時間もしくは退勤時間が不適切な値です'];
                return null;
            }
            if ($start->lt($in) || $end->lte($start)) {
                $breakError = ["new_break_in.$index" => '休憩時間が不適切な値です'];
                return null;
            }
            return ['break_in' => $breakIn, 'break_out' => $breakOut];
        })->filter()->sortBy('break_in')->values();
        if ($breakError !== null) {
            return back()->withErrors($breakError)->withInput();
        }
        $overlap = $breaks->sliding(2)->first(function ($pair): bool {
            $pair = $pair->values();
            return $pair[1]['break_in'] < $pair[0]['break_out'];
        });
        if ($overlap !== null) {
            return back()->withErrors(['new_break_in' => '休憩時間が不適切な値です'])->withInput();
        }

        $attendance = Attendance::findOrFail($attendance_id);
        DB::transaction(function () use ($attendance, $validated, $breaks): void {
            $attendance->update([
                'clock_in' => $validated['new_clock_in'],
                'clock_out' => $validated['new_clock_out'],
                'comment' => $validated['comment'],
            ]);
            $attendance->breakLogs()->delete();
            $breaks->each(fn (array $break): mixed => $attendance->breakLogs()->create($break));
        });

        // 💡 1枚目の画像の一覧画面ルート名「admin.attendance.list」に合わせてリダイレクト先を修正
        return redirect()->route('admin.attendance.list', ['date' => $attendance->date])
                         ->with('success', '勤怠情報を直接修正しました。');
    }

     /**
     * 💡 【US013 / PG11】管理者用：スタッフ別勤怠一覧画面（Blade完全適合・最終確定版）
     * 💡 お手元のBladeの変数名（formattedAttendanceRecords, date, previousMonth, nextMonth）とクエリパラメータ（?date=）に200%完全同期させます！
     */
    /** Show a staff member's monthly attendance calendar.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @param int $user_id 対象レコードの識別子。
     * @return View 表示する画面とそのデータ。
     */
    public function staff(Request $request, int $user_id): View
    {
        // 🔒 1. 右上のヘッダーメニューをお手本通りに出現させる
        if (auth()->check()) {
            auth()->user()->admin_status = true;
        }

        // 対象の一般スタッフ情報を取得
        $user = \App\Models\User::where('role', 'user')->findOrFail($user_id);

        // 💡 【月次切り替えバグ完全解決（FN044）】
        // お手元のBladeの15行目・17行目の「href="?date=..."」に合わせて、クエリ名を「date」で厳格にキャッチします！
        $dateParam = $request->query('date', \Carbon\Carbon::today()->format('Y-m'));

        // Y-m の形式で届いた際もCarbonが安全に日付パースできるように「-01」を末尾に自動補填します
        $date = \Carbon\Carbon::parse(strlen($dateParam) === 7 ? $dateParam . '-01' : $dateParam);

        // お手元のBladeの15行目・17行目の変数（$previousMonth, $nextMonth）へ渡す前月・翌月の値を算出
        $previousMonth = $date->copy()->subMonth()->format('Y-m');
        $nextMonth = $date->copy()->addMonth()->format('Y-m');

        // FN043: 対象の月（1日〜末日）の全勤怠データを一括取得
        $attendances = Attendance::with(['breakLogs'])
            ->where('user_id', $user_id)
            ->whereBetween('date', [
                $date->copy()->startOfMonth()->toDateString(),
                $date->copy()->endOfMonth()->toDateString()
            ])
            ->get()
            ->keyBy('date'); // 日付（YYYY-MM-DD）をキーにして検索しやすくする

        // 💡 1日から末日までのカレンダーをループで生成し、打刻データをマッピング
        $daysInMonth = $date->daysInMonth; // その月が何日あるか（28〜31日）
        $calendar = collect(range(1, $daysInMonth))->map(function (int $day) use ($date, $attendances): array {
            $dateStr = $date->copy()->day($day)->toDateString();
            $dateObj = \Carbon\Carbon::parse($dateStr);

            // その日の勤怠データがあるか探す
            $attendance = $attendances->get($dateStr);

            // お手本の「06/01(木)」という日付・曜日の形に完全一致させます
            $weekDays = ['日', '月', '火', '水', '木', '金', '土'];
            $dayOfWeekString = $weekDays[$dateObj->dayOfWeek];
            $formattedDate = $dateObj->format('m/d') . '(' . $dayOfWeekString . ')';

            $clockIn = '';
            $clockOut = '';
            $totalBreakTime = '';
            $totalTime = '';
            $attendanceId = null;

            if ($attendance) {
                $attendanceId = $attendance->id;
                $clockIn = $attendance->clock_in ? \Carbon\Carbon::parse($attendance->clock_in)->format('H:i') : '';
                $clockOut = $attendance->clock_out ? \Carbon\Carbon::parse($attendance->clock_out)->format('H:i') : '';

                // 休憩時間の合計（秒数）を計算
                $totalBreakSeconds = $attendance->breakLogs
                    ->filter(fn ($breakLog): bool => $breakLog->break_in && $breakLog->break_out)
                    ->sum(fn ($breakLog): int => (int) \Carbon\Carbon::parse($breakLog->break_in)->diffInSeconds(\Carbon\Carbon::parse($breakLog->break_out)));

                if ($totalBreakSeconds > 0) {
                    $bHours = floor($totalBreakSeconds / 3600);
                    $bMinutes = floor(($totalBreakSeconds / 60) % 60);
                    $totalBreakTime = sprintf('%02d:%02d', $bHours, $bMinutes); // 💡 Bladeの \Carbon\Carbon::parse に耐えられるよう 00:00 形式にします
                }

                // 実稼働時間（合計時間 ＝ 退勤 － 出勤 － 休憩秒）の算出
                if ($attendance->clock_in && $attendance->clock_out) {
                    $workSeconds = \Carbon\Carbon::parse($attendance->clock_in)->diffInSeconds(\Carbon\Carbon::parse($attendance->clock_out));
                    $actualWorkSeconds = $workSeconds - $totalBreakSeconds;

                    if ($actualWorkSeconds > 0) {
                        $wHours = floor($actualWorkSeconds / 3600);
                        $wMinutes = floor(($actualWorkSeconds / 60) % 60);
                        $totalTime = sprintf('%02d:%02d', $wHours, $wMinutes);
                    }
                }
            }

            // 💡 お手元のBladeの44行目「@foreach ($formattedAttendanceRecords as $attendanceRecords)」の配列構造と完全同期
            return [
                'id'               => $attendanceId, // ⭕ 実在する勤怠レコードの背番号（ID）を格納
                'date'             => $formattedDate,
                'clock_in'         => $clockIn,
                'clock_out'        => $clockOut,
                'total_break_time' => $totalBreakTime,
                'total_time'       => $totalTime,
            ];
        })->all();

        // 💡 【未定義エラー完全解決】お手元のBladeが待っている4つの正確な変数名で100%確実に引き渡します！
        return view('admin.staff-attendance-list', [
            'user'                       => $user,
            'date'                       => $date,                       // ⭕ 16行目（$date->format）に結線
            'previousMonth'              => $previousMonth,              // ⭕ 15行目（$previousMonth）に結線
            'nextMonth'                  => $nextMonth,                  // ⭕ 17行目（$nextMonth）に結線
            'formattedAttendanceRecords' => $calendar,                   // ⭕ 44行目のループに一発でガチッと結線！
        ]);
    }
}
