@extends('layouts.app')

@section('content')
<section class="attendance-report" style="max-width: 960px; margin: 40px auto; padding: 0 24px;">
    <h1>勤怠レポート</h1>
    <p>{{ $periodStart->format('Y年n月') }}〜{{ $periodEnd->format('Y年n月') }}</p>

    <h2>過去6ヶ月</h2>
    <dl>
        <dt>勤務日数</dt><dd>{{ $attendanceDays }}日</dd>
        <dt>総労働時間</dt><dd>{{ intdiv($totalWorkMinutes, 60) }}時間{{ sprintf('%02d', $totalWorkMinutes % 60) }}分</dd>
        <dt>総残業時間（1日8時間超）</dt><dd>{{ intdiv($overtimeMinutes, 60) }}時間{{ sprintf('%02d', $overtimeMinutes % 60) }}分</dd>
        <dt>平均労働時間 / 日</dt><dd>{{ intdiv($averageWorkMinutes, 60) }}時間{{ sprintf('%02d', $averageWorkMinutes % 60) }}分</dd>
    </dl>

    <h2>当月</h2>
    <dl>
        <dt>遅刻（9:00超）</dt><dd>{{ $lateCount }}回</dd>
        <dt>早退（18:00前）</dt><dd>{{ $earlyLeaveCount }}回</dd>
        <dt>長時間労働（10時間超）</dt><dd>{{ $longWorkCount }}日</dd>
    </dl>
</section>
@endsection
