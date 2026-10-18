<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAttendanceRecordRequest;
use App\Http\Requests\Api\V1\StoreAttendanceRecordRequest;
use App\Http\Requests\Api\V1\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Attendance;
use App\Models\AttendanceRecord;
use Illuminate\Http\JsonResponse;

class AttendanceRecordController extends Controller
{
    public function index(IndexAttendanceRecordRequest $request)
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        $attendanceRecords = AttendanceRecord::query()
            ->with(['user', 'breaks'])
            ->when(
                $filters['user_id'] ?? null,
                fn ($query, $userId) => $query->where('user_id', $userId)
            )
            ->when(
                $filters['date'] ?? null,
                fn ($query, $date) => $query->whereDate('date', $date)
            )
            ->when(
                $filters['month'] ?? null,
                fn ($query, $month) => $query->whereBetween('date', [
                    $month.'-01',
                    date('Y-m-t', strtotime($month.'-01')),
                ])
            )
            ->latest('date')
            ->paginate($perPage, ['*'], 'page', $page);

        return AttendanceRecordResource::collection($attendanceRecords);
    }

    public function show(AttendanceRecord $attendanceRecord): AttendanceRecordResource
    {
        $attendanceRecord->load([
            'user',
            'breaks',
            'applications',
        ]);

        return new AttendanceRecordResource($attendanceRecord);
    }

    public function store(StoreAttendanceRecordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $attendanceRecord = $request->user()
            ->attendanceRecords()
            ->create($validated)
            ->load([
                'user',
                'breaks',
            ]);

        return (new AttendanceRecordResource($attendanceRecord))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateAttendanceRecordRequest $request,
        Attendance $attendanceRecord
    ): AttendanceRecordResource {
        $this->authorize('update', $attendanceRecord);

        $attendanceRecord->update($request->validated());

        return new AttendanceRecordResource(
            $attendanceRecord->load(['user', 'breaks'])
        );
    }

    public function destroy(Attendance $attendanceRecord): JsonResponse
    {
        $this->authorize('delete', $attendanceRecord);

        $attendanceRecord->delete();

        return response()->json(null, 204);
    }
}
