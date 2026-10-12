<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\BreakLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    /** Render today's attendance status and the current local date and time.
     * @return View 表示する画面とそのデータ。
     */
    public function create(): View
    {
        $user = Auth::user();
        $now = Carbon::now();
        $attendance = Attendance::with('breakLogs')
            ->where('user_id', $user->id)
            ->whereDate('date', $now->toDateString())
            ->first();

        $status = '勤務外';
        if ($attendance) {
            if ($attendance->clock_out) {
                $status = '退勤済';
            } elseif ($attendance->breakLogs->contains(fn ($break) => $break->break_out === null)) {
                $status = '休憩中';
            } else {
                $status = '出勤中';
            }
        }

        $user->attendance_status = $status;

        return view('user.attendance-register', [
            'user' => $user,
            'formattedDate' => $now->locale('ja')->isoFormat('YYYY年MM月DD日(ddd)'),
            'formattedTime' => $now->format('H:i'),
        ]);
    }

    /** Apply one valid punch transition for the authenticated user.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function updateStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:clock_in,break_in,break_out,clock_out',
        ]);

        $user = Auth::user();
        $now = Carbon::now();
        $today = $now->toDateString();

        $message = DB::transaction(function () use ($user, $now, $today, $validated) {
            // Serialize this user's punch actions to prevent duplicate transitions.
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();
            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $today)
                ->lockForUpdate()
                ->first();
            $action = $validated['action'];

            if ($action === 'clock_in') {
                if ($attendance) {
                    abort(422, '本日はすでに出勤しています。');
                }

                Attendance::create([
                    'user_id' => $user->id,
                    'date' => $today,
                    'clock_in' => $now->format('H:i:s'),
                ]);

                return null;
            }

            abort_unless($attendance && !$attendance->clock_out, 422, '不正な操作です。');
            $openBreak = BreakLog::where('attendance_id', $attendance->id)
                ->whereNull('break_out')
                ->lockForUpdate()
                ->first();

            if ($action === 'break_in') {
                abort_if($openBreak, 422, '不正な操作です。');
                $attendance->breakLogs()->create(['break_in' => $now->format('H:i:s')]);
                return null;
            }

            if ($action === 'break_out') {
                abort_unless($openBreak, 422, '不正な操作です。');
                $openBreak->update(['break_out' => $now->format('H:i:s')]);
                return null;
            }

            abort_if($openBreak, 422, '休憩中は退勤できません。');
            $attendance->update(['clock_out' => $now->format('H:i:s')]);

            return 'お疲れ様でした。';
        });

        $redirect = redirect()->route('attendance.register');
        return $message ? $redirect->with('status_message', $message) : $redirect;
    }

    /** Render the authenticated user's attendance records for a requested month.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @return View|RedirectResponse 一覧画面、または入力が不正な場合の遷移先。
     */
    public function index(Request $request): View|RedirectResponse
    {
        $month = $request->query('date', Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return redirect()->route('attendance.list');
        }

        $date = Carbon::createFromFormat('!Y-m', $month);
        $attendances = Attendance::with('breakLogs')
            ->where('user_id', Auth::id())
            ->whereBetween('date', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])
            ->orderBy('date')
            ->get()
            ->map(function (Attendance $attendance) {
                $breakSeconds = $attendance->breakLogs->sum(function (BreakLog $break) use ($attendance) {
                    if (!$break->break_in || !$break->break_out) {
                        return 0;
                    }
                    return $this->durationSeconds($attendance->date, $break->break_in, $break->break_out);
                });

                $workSeconds = 0;
                if ($attendance->clock_in && $attendance->clock_out) {
                    $workSeconds = max(0, $this->durationSeconds($attendance->date, $attendance->clock_in, $attendance->clock_out) - $breakSeconds);
                }

                return [
                    'id' => $attendance->id,
                    'date' => Carbon::parse($attendance->date)->locale('ja')->isoFormat('MM/DD(ddd)'),
                    'clock_in' => $attendance->clock_in ? Carbon::parse($attendance->clock_in)->format('H:i') : '',
                    'clock_out' => $attendance->clock_out ? Carbon::parse($attendance->clock_out)->format('H:i') : '',
                    'total_break_time' => $attendance->clock_out ? $this->formatDuration($breakSeconds) : '',
                    'total_time' => $attendance->clock_out ? $this->formatDuration($workSeconds) : '',
                ];
            });

        return view('user.user-attendance-list', [
            'previousMonth' => $date->copy()->subMonth()->format('Y-m'),
            'date' => $date,
            'nextMonth' => $date->copy()->addMonth()->format('Y-m'),
            'formattedAttendanceRecords' => $attendances,
        ]);
    }

    /** Return the duration between two clock times, including overnight shifts.
     * @param string $date 処理に使用する値。
     * @param string $start 処理に使用する値。
     * @param string $end 処理に使用する値。
     * @return int 処理結果。
     */
    private function durationSeconds(string $date, string $start, string $end): int
    {
        $from = Carbon::parse($date.' '.$start);
        $to = Carbon::parse($date.' '.$end);
        if ($to->lt($from)) {
            $to->addDay();
        }
        return $from->diffInSeconds($to);
    }

    /** Format a duration in seconds as hours and minutes.
     * @param int $seconds 処理に使用する値。
     * @return string 処理結果。
     */
    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
