<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * Format an attendance correction application.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_id' => $this->attendance_id,
            'user_id' => $this->user_id,
            'original_date' => substr((string) $this->getRawOriginal('original_date'), 0, 10),
            'new_clock_in' => $this->new_clock_in,
            'new_clock_out' => $this->new_clock_out,
            'new_breaks' => $this->new_breaks,
            'comment' => $this->comment,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toISOString(),
        ];
    }
}