<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceCorrectionController extends Controller
{
    /** List the authenticated user's correction requests, or all requests for an administrator.
     * @return View|RedirectResponse 申請一覧、または未認証ユーザーの認証案内。
     */
    public function index(): View|RedirectResponse
    {
        $user = auth()->user();
        if ($user->role !== 'admin' && $user instanceof MustVerifyEmail && !$user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $corrections = AttendanceCorrection::with(['user', 'attendance'])
            ->when($user->role !== 'admin', fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->get();

        if ($user->role === 'admin') {
            $applications = $corrections->each(function ($correction) {
                $correction->approval_status = (int) $correction->status === 0 ? '承認待ち' : '承認済み';
                $correction->application_date = $correction->created_at?->format('Y/m/d');
                $correction->AttendanceRecord = $correction->attendance;
                $correction->User = $correction->user;
            });

            return view('admin.admin-application-list', compact('applications'));
        }

        $formattedApplications = $corrections->map(fn ($correction) => [
            'id' => $correction->id,
            'attendance_id' => $correction->attendance_id,
            'date' => Carbon::parse($correction->attendance->date)->format('Y/m/d'),
            'comment' => $correction->comment,
            'application_date' => $correction->created_at?->format('Y/m/d'),
            'approval_status' => (int) $correction->status === 0 ? '承認待ち' : '承認済み',
        ]);

        return view('user.user-application-list', compact('formattedApplications', 'user'));
    }

    /** Show an attendance record and any pending proposed edits.
     * @param int $attendance_id 対象レコードの識別子。
     * @return View 表示する画面とそのデータ。
     */
    public function show(int $attendance_id): View
    {
        $user = auth()->user();
        abort_if($user->role === 'admin', 404);

        $attendance = Attendance::with('breakLogs')
            ->where('user_id', $user->id)
            ->findOrFail($attendance_id);
        $pending = AttendanceCorrection::where('attendance_id', $attendance->id)
            ->where('user_id', $user->id)
            ->where('status', 0)
            ->latest()
            ->first();
        $date = Carbon::parse($attendance->date);
        $breaks = $pending && $pending->new_breaks !== null
            ? collect($pending->new_breaks)
            : $attendance->breakLogs->map(fn ($break) => ['break_in' => $break->break_in, 'break_out' => $break->break_out]);

        $data = [
            'id' => $attendance->id,
            'date' => $attendance->date,
            'year' => $date->format('Y年'),
            'date_md' => $date->format('n月j日'),
            'clock_in' => ($pending ? $pending->new_clock_in : $attendance->clock_in)
                ? Carbon::parse($pending ? $pending->new_clock_in : $attendance->clock_in)->format('H:i') : '',
            'clock_out' => ($pending ? $pending->new_clock_out : $attendance->clock_out)
                ? Carbon::parse($pending ? $pending->new_clock_out : $attendance->clock_out)->format('H:i') : '',
            'comment' => $pending ? $pending->comment : ($attendance->comment ?? ''),
            'application' => $pending,
            'breaks' => $breaks->map(fn ($break) => [
                'break_in' => isset($break['break_in']) ? Carbon::parse($break['break_in'])->format('H:i') : '',
                'break_out' => !empty($break['break_out']) ? Carbon::parse($break['break_out'])->format('H:i') : '',
            ])->values()->all(),
        ];

        return view('user.user-detail', [
            'user' => $user,
            'data' => $data,
            'formattedDate' => $date->locale('ja')->isoFormat('YYYY年MM月DD日(ddd)'),
        ]);
    }

    /** Validate and submit an attendance correction request.
     * @param Request $request 入力と認証情報を含むリクエスト。
     * @param int $attendance_id 対象レコードの識別子。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function store(Request $request, int $attendance_id): RedirectResponse
    {
        $user = auth()->user();
        abort_if($user->role === 'admin', 404);
        $attendance = Attendance::where('user_id', $user->id)->findOrFail($attendance_id);

        if (AttendanceCorrection::where('attendance_id', $attendance->id)->where('user_id', $user->id)->where('status', 0)->exists()) {
            return back()->withErrors(['attendance' => '承認待ちの申請があるため、修正できません。']);
        }

        $validated = $request->validate([
            'new_clock_in' => 'required|date_format:H:i',
            'new_clock_out' => 'required|date_format:H:i',
            'new_break_in' => 'array',
            'new_break_in.*' => 'nullable|date_format:H:i',
            'new_break_out' => 'array',
            'new_break_out.*' => 'nullable|date_format:H:i',
            'comment' => 'required|string|max:255',
        ], [
            'new_clock_in.required' => '出勤時間を入力してください',
            'new_clock_out.required' => '退勤時間を入力してください',
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
            if (!$breakIn && !$breakOut) {
                return null;
            }
            if (!$breakIn || !$breakOut) {
                $breakError = ["new_break_in.$index" => '休憩時間が不適切な値です'];
                return null;
            }
            $breakStart = Carbon::createFromFormat('H:i', $breakIn);
            $breakEnd = Carbon::createFromFormat('H:i', $breakOut);
            if ($breakEnd->gt($out)) {
                $breakError = ["new_break_out.$index" => '休憩時間もしくは退勤時間が不適切な値です'];
                return null;
            }
            if ($breakStart->lt($in) || $breakEnd->lte($breakStart)) {
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

        AttendanceCorrection::create([
            'attendance_id' => $attendance->id,
            'user_id' => $user->id,
            'original_date' => $attendance->date,
            'new_clock_in' => $validated['new_clock_in'],
            'new_clock_out' => $validated['new_clock_out'],
            'new_breaks' => $breaks->all(),
            'comment' => $validated['comment'],
            'status' => 0,
        ]);

        return redirect()->route('attendance.detail', $attendance->id)->with('success', '修正申請を提出しました。');
    }

    /** Prepare a correction request for its approval detail page.
     * @param int $attendance_correct_request_id 対象レコードの識別子。
     * @return View 表示する画面とそのデータ。
     */
    public function showApprove(int $attendance_correct_request_id): View
    {
        $correction = AttendanceCorrection::with(['user', 'attendance'])->findOrFail($attendance_correct_request_id);
        $correction->approval_status = (int) $correction->status === 0 ? '承認待ち' : '承認済み';
        $correction->new_date = Carbon::parse($correction->attendance->date);
        $correction->new_clock_in = Carbon::parse($correction->new_clock_in)->format('H:i');
        $correction->new_clock_out = Carbon::parse($correction->new_clock_out)->format('H:i');
        $correction->proposalBreaks = collect($correction->new_breaks ?? [])->map(fn ($break) => (object) $break);

        return view('admin.admin-application-detail', [
            'application' => $correction,
            'user' => $correction->user,
        ]);
    }

    /** Atomically approve a correction and apply its proposed values.
     * @param int $attendance_correct_request_id 対象レコードの識別子。
     * @return RedirectResponse 処理後の遷移先。
     */
    public function approve(int $attendance_correct_request_id): RedirectResponse
    {
        $correction = AttendanceCorrection::with('attendance')->lockForUpdate()->findOrFail($attendance_correct_request_id);
        if ((int) $correction->status !== 0) {
            return redirect()->route('attendance_correction.list')->with('success', 'この申請はすでに承認済みです。');
        }

        DB::transaction(function () use ($correction) {
            $attendance = Attendance::lockForUpdate()->findOrFail($correction->attendance_id);
            $attendance->update([
                'clock_in' => $correction->new_clock_in,
                'clock_out' => $correction->new_clock_out,
                'comment' => $correction->comment,
            ]);
            $attendance->breakLogs()->delete();
            collect($correction->new_breaks ?? [])->each(
                fn (array $break): mixed => $attendance->breakLogs()->create($break)
            );
            $correction->update(['status' => 1, 'approved_at' => now()]);
        });

        return redirect()->route('attendance_correction.list')->with('success', '修正申請を承認しました。');
    }
}
