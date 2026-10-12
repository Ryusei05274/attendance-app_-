<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class AttendanceCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * @return bool 判定結果。
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * Define the correction request validation rules.
     *
     * @return array<string, mixed> Validation rules keyed by input name.
     */
    public function rules(): array
    {
        return [
            // 出勤時間と退勤時間は必須入力（H:i 形式）
            'new_clock_in'  => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    $outValue = $this->input('new_clock_out');
                    if ($outValue) {
                        $in  = Carbon::parse($value);
                        $out = Carbon::parse($outValue);
                        // 出勤時間が退勤時間より後になっている場合 (要件2)
                        if ($in->gt($out)) {
                            $fail('出勤時間が退勤時間より後の時間になっています');
                        }
                    }
                }
            ],
            'new_clock_out' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    $inValue = $this->input('new_clock_in');
                    if ($inValue) {
                        $in  = Carbon::parse($inValue);
                        $out = Carbon::parse($value);
                        // 退勤時間が出勤時間より前になっている場合 (要件3)
                        if ($out->lt($in)) {
                            $fail('退勤時間が出勤時間より前の時間になっています');
                        }
                    }
                }
            ],
            // 休憩時間（配列形式）の入力チェック
            'new_break_in.*'  => 'nullable|date_format:H:i',
            'new_break_out.*' => [
                'nullable',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    // 「new_break_out.0」などの文字列から現在のインデックス（0, 1...）を抽出
                    preg_match('/\.(\d+)/', $attribute, $matches);
                    $index = $matches[1] ?? null;
                    
                    if ($index !== null) {
                        $bInStr = $this->input("new_break_in.{$index}");
                        if ($bInStr && Carbon::parse($value)->lt(Carbon::parse($bInStr))) {
                            // 休憩戻る時間が休憩入る時間より前になっている場合 (要件4)
                            $fail('休憩時間が不適切な値です');
                        }
                    }
                }
            ],
            // 備考欄は必須入力 (要件1)
            'comment' => 'required|string',
        ];
    }

    /**
     * Return the user-facing validation messages.
     *
     * @return array<string, string> Messages keyed by validation rule.
     */
    public function messages(): array
    {
        return [
            'new_clock_in.required'  => '出勤時間は必須項目です。',
            'new_clock_out.required' => '退勤時間は必須項目です。',
            'comment.required'       => '備考を記入してください',
        ];
    }
}
