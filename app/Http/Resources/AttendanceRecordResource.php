<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    /**
     * Format an attendance record and include only requested relationships.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'date' => substr((string) $this->getRawOriginal('date'), 0, 10),
            'clock_in' => $this->clock_in,
            'clock_out' => $this->clock_out,
            'total_time' => $this->totalTime(),
            'total_break_time' => $this->totalBreakTime(),
            'comment' => $this->comment,
            'breaks' => $this->when(
                ! $request->routeIs('api.v1.attendance-records.index'),
                fn () => $this->whenLoaded(
                    'breaks',
                    fn () => AttendanceBreakResource::collection($this->breaks)
                )
            ),
            'applications' => ApplicationResource::collection(
                $this->whenLoaded('applications')
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /** Calculate net worked time in H:MM format. */
    private function totalTime(): string
    {
        if (! $this->clock_in || ! $this->clock_out) {
            return '';
        }

        $date = (string) $this->getRawOriginal('date');
        $workedSeconds = $this->durationSeconds($date, $this->clock_in, $this->clock_out);

        return $this->formatDuration(max(0, $workedSeconds - $this->breakSeconds($date)));
    }

    /** Calculate the sum of completed breaks in H:MM format. */
    private function totalBreakTime(): string
    {
        if (! $this->clock_out) {
            return '';
        }

        return $this->formatDuration(
            $this->breakSeconds((string) $this->getRawOriginal('date'))
        );
    }

    /** Sum loaded break intervals without issuing additional queries. */
    private function breakSeconds(string $date): int
    {
        if (! $this->resource->relationLoaded('breaks')) {
            return 0;
        }

        return $this->breaks->sum(function ($break) use ($date): int {
            if (! $break->break_in || ! $break->break_out) {
                return 0;
            }

            return $this->durationSeconds($date, $break->break_in, $break->break_out);
        });
    }

    /** Return the duration between two times, including overnight intervals. */
    private function durationSeconds(string $date, string $start, string $end): int
    {
        $from = Carbon::parse($date.' '.$start);
        $to = Carbon::parse($date.' '.$end);

        if ($to->lessThan($from)) {
            $to->addDay();
        }

        return (int) $from->diffInSeconds($to);
    }

    /** Format seconds as hours and minutes. */
    private function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}