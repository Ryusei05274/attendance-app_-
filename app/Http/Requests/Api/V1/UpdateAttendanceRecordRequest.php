<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Attendance;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRecordRequest extends FormRequest
{
    /**
     * Authorization is handled by AttendanceRecordPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules for the supplied fields.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Attendance $attendance */
        $attendance = $this->route('attendanceRecord');

        return [
            'date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('attendances')
                    ->where(
                        fn (QueryBuilder $query): QueryBuilder => $query->where(
                            'user_id',
                            $attendance->user_id
                        )
                    )
                    ->ignore($attendance->id),
            ],
            'clock_in' => [
                'sometimes',
                'required',
                'date_format:H:i:s',
            ],
            'clock_out' => [
                'sometimes',
                'nullable',
                'date_format:H:i:s',
            ],
            'comment' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Validate that the resulting clock-out time is later than clock-in.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Attendance $attendance */
            $attendance = $this->route('attendanceRecord');
            $clockIn = $this->input('clock_in', $attendance->clock_in);
            $clockOut = $this->exists('clock_out')
                ? $this->input('clock_out')
                : $attendance->clock_out;

            if ($clockOut !== null && $clockIn !== null && $clockOut <= $clockIn) {
                $validator->errors()->add(
                    'clock_out',
                    '退勤時刻は出勤時刻より後の時刻を指定してください。'
                );
            }
        });
    }

    /**
     * Get Japanese validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => '勤怠日は必須です。',
            'date.date_format' => '勤怠日は YYYY-MM-DD 形式で指定してください。',
            'date.unique' => 'この日付の勤怠は既に登録されています。',
            'clock_in.required' => '出勤時刻は必須です。',
            'clock_in.date_format' => '出勤時刻は HH:MM:SS 形式で指定してください。',
            'clock_out.date_format' => '退勤時刻は HH:MM:SS 形式で指定してください。',
            'comment.max' => '備考は 255 文字以内で入力してください。',
        ];
    }
}