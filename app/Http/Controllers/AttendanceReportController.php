<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\BreakLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AttendanceReportController extends Controller
{
    /**
     * Build the authenticated user's six-month attendance summary and current-month anomalies.
     *
     * @return View The report page with summary, monthly trend, and anomaly counts.
     */
    public function show(): View
    {
        $currentMonth = Carbon::today()->startOfMonth();
        $periodStart = $currentMonth->copy()->subMonths(5);
        $periodEnd = $currentMonth->copy()->endOfMonth();

        /** @var Collection<int, Attendance> $records */
        $records = Attendance::query()
            ->with('breakLogs')
            ->where('user_id', auth()->id())
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();

        $workMinutes = $records->mapWithKeys(fn (Attendance $attendance): array => [
            $attendance->id => $this->workMinutes($attendance),
        ]);
        $recordsByMonth = $records->groupBy(fn (Attendance $attendance): string =>
            Carbon::parse($attendance->date)->format('Y-m')
        );

        $monthlyTrend = collect(range(5, 0))->map(function (int $monthsAgo) use ($currentMonth, $recordsByMonth, $workMinutes): array {
            $month = $currentMonth->copy()->subMonths($monthsAgo);
            $monthRecords = $recordsByMonth->get($month->format('Y-m'), collect());
            $monthMinutes = $monthRecords->sum(fn (Attendance $attendance): int => $workMinutes->get($attendance->id, 0));

            return [
                'month' => $month->format('Y年n月'),
                'work_minutes' => $monthMinutes,
                'overtime_minutes' => $monthRecords->sum(fn (Attendance $attendance): int =>
                    max(0, $workMinutes->get($attendance->id, 0) - 8 * 60)
                ),
            ];
        });

        $currentMonthRecords = $recordsByMonth->get($currentMonth->format('Y-m'), collect());
        $totalWorkMinutes = $workMinutes->sum();

        return view('reports.index', [
            'summary' => [
                'total_work_minutes' => $totalWorkMinutes,
                'total_overtime_minutes' => $workMinutes->sum(fn (int $minutes): int => max(0, $minutes - 8 * 60)),
                'avg_work_minutes' => $records->isEmpty() ? 0 : (int) floor($totalWorkMinutes / $records->count()),
            ],
            'monthlyTrend' => $monthlyTrend,
            'anomalies' => [
                'late_count' => $currentMonthRecords->filter(fn (Attendance $attendance): bool => $attendance->clock_in !== null && $attendance->clock_in > '09:00:00')->count(),
                'early_leave_count' => $currentMonthRecords->filter(fn (Attendance $attendance): bool => $attendance->clock_out !== null && $attendance->clock_out < '18:00:00')->count(),
                'long_work_count' => $currentMonthRecords->filter(fn (Attendance $attendance): bool =>
                    $workMinutes->get($attendance->id, 0) > 10 * 60
                )->count(),
            ],
        ]);
    }

    /**
     * Calculate net work time after subtracting completed breaks.
     *
     * @param Attendance $attendance An attendance record with break logs eager loaded.
     * @return int Net work duration in minutes.
     */
    private function workMinutes(Attendance $attendance): int
    {
        if (!$attendance->clock_in || !$attendance->clock_out) {
            return 0;
        }

        $start = Carbon::parse($attendance->date.' '.$attendance->clock_in);
        $end = Carbon::parse($attendance->date.' '.$attendance->clock_out);
        if ($end->lt($start)) {
            $end->addDay();
        }

        $breakMinutes = $attendance->breakLogs->sum(function (BreakLog $break) use ($attendance): int {
            if (!$break->break_in || !$break->break_out) {
                return 0;
            }

            $breakStart = Carbon::parse($attendance->date.' '.$break->break_in);
            $breakEnd = Carbon::parse($attendance->date.' '.$break->break_out);
            if ($breakEnd->lt($breakStart)) {
                $breakEnd->addDay();
            }

            return $breakStart->diffInMinutes($breakEnd);
        });

        return max(0, $start->diffInMinutes($end) - $breakMinutes);
    }
}
