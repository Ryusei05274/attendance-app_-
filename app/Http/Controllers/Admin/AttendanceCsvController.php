<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\BreakLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceCsvController extends Controller
{
    /** Download a staff member's attendance records for the selected month.
     * @param Request $request Selected month input.
     * @param int $user_id Staff member whose records are being exported.
     * @return StreamedResponse UTF-8 BOM CSV download.
     */
    public function export(Request $request, int $user_id): StreamedResponse
    {
        $validated = $request->validate([
            'year_month' => ['required', 'date_format:Y-m'],
        ]);
        $month = Carbon::createFromFormat('!Y-m-d', $validated['year_month'].'-01');
        $user = User::query()->where('role', 'user')->findOrFail($user_id);

        $records = Attendance::query()
            ->with('breakLogs')
            ->where('user_id', $user->id)
            ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderBy('date')
            ->get();

        $rows = $records->map(function (Attendance $attendance): array {
            $date = Carbon::parse($attendance->date);
            $breakSeconds = $attendance->breakLogs
                ->filter(fn (BreakLog $break): bool => $break->break_in && $break->break_out)
                ->sum(function (BreakLog $break) use ($date): int {
                    $start = Carbon::parse($date->toDateString().' '.$break->break_in);
                    $end = Carbon::parse($date->toDateString().' '.$break->break_out);
                    if ($end->lt($start)) {
                        $end->addDay();
                    }

                    return (int) $start->diffInSeconds($end);
                });

            $workSeconds = 0;
            if ($attendance->clock_in && $attendance->clock_out) {
                $start = Carbon::parse($date->toDateString().' '.$attendance->clock_in);
                $end = Carbon::parse($date->toDateString().' '.$attendance->clock_out);
                if ($end->lt($start)) {
                    $end->addDay();
                }
                $workSeconds = max(0, (int) $start->diffInSeconds($end) - $breakSeconds);
            }

            return [
                $date->format('Y/m/d'),
                $attendance->clock_in ? Carbon::parse($attendance->clock_in)->format('H:i') : '',
                $attendance->clock_out ? Carbon::parse($attendance->clock_out)->format('H:i') : '',
                $this->formatMinutes((int) floor($breakSeconds / 60)),
                $this->formatMinutes((int) floor($workSeconds / 60)),
            ];
        });

        $filename = sprintf('attendance_user%d_%s.csv', $user->id, $month->format('Ym'));

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('CSV output stream could not be opened.');
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['日付', '出勤', '退勤', '休憩', '合計'], ',', '"', '');
            $rows->each(static fn (array $row): int|false => fputcsv($stream, $row, ',', '"', ''));
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Format a number of minutes as hours and minutes.
     * @param int $minutes Duration in minutes.
     * @return string HH:MM duration.
     */
    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
