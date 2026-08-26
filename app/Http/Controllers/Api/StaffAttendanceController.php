<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StaffAttendance;
use Illuminate\Http\Request;

class StaffAttendanceController extends Controller
{
    /**
     * FR-BE-3.2 — self check-in dengan foto wajib.
     */
    public function checkIn(Request $request)
    {
        $staff = $this->staffFor($request);

        $data = $request->validate(['photo' => ['required', 'image', 'max:4096']]);

        $today = now()->toDateString();
        $existing = StaffAttendance::where('staff_id', $staff->id)->where('date', $today)->first();

        if ($existing && $existing->check_in_time && ! $existing->check_out_time) {
            return response()->json(['message' => 'Anda sudah check-in, belum check-out.'], 422);
        }

        $photoPath = $request->file('photo')->store('attendance-photos', 'public');

        $attendance = StaffAttendance::updateOrCreate(
            ['staff_id' => $staff->id, 'date' => $today],
            ['check_in_time' => now(), 'check_in_photo_path' => $photoPath, 'check_out_time' => null, 'check_out_photo_path' => null]
        );

        return response()->json(['attendance' => $attendance]);
    }

    public function checkOut(Request $request)
    {
        $staff = $this->staffFor($request);

        $request->validate(['photo' => ['required', 'image', 'max:4096']]);

        $today = now()->toDateString();
        $attendance = StaffAttendance::where('staff_id', $staff->id)->where('date', $today)->first();

        if (! $attendance || ! $attendance->check_in_time) {
            return response()->json(['message' => 'Belum check-in hari ini.'], 422);
        }

        $photoPath = $request->file('photo')->store('attendance-photos', 'public');

        $attendance->update(['check_out_time' => now(), 'check_out_photo_path' => $photoPath]);

        return response()->json(['attendance' => $attendance]);
    }

    public function today(Request $request)
    {
        $staff = $this->staffFor($request);

        $attendance = StaffAttendance::where('staff_id', $staff->id)->where('date', now()->toDateString())->first();

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * FR-BE-3.3 — koreksi manual oleh Admin.
     */
    public function correct(Request $request, StaffAttendance $attendance)
    {
        if ($request->user()->role !== 'admin') {
            abort(403);
        }

        $data = $request->validate([
            'check_in_time' => ['nullable', 'date'],
            'check_out_time' => ['nullable', 'date'],
        ]);

        $before = $attendance->only(['check_in_time', 'check_out_time']);

        $attendance->update(array_filter($data) + ['corrected_by_admin' => true]);

        AuditLog::record(
            $request->user()->id,
            'staff_attendance.admin_override',
            StaffAttendance::class,
            $attendance->id,
            $before,
            $attendance->only(['check_in_time', 'check_out_time'])
        );

        return response()->json(['attendance' => $attendance]);
    }

    public function index(Request $request)
    {
        $query = StaffAttendance::with('staff:id,name')
            ->when($request->query('staff_id'), fn ($q, $id) => $q->where('staff_id', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('date', '<=', $d))
            ->latest('date');

        return response()->json($query->paginate(30));
    }

    private function staffFor(Request $request)
    {
        $staff = $request->user()->staff;

        abort_if(! $staff, 422, 'Akun ini tidak terhubung ke data staff.');

        return $staff;
    }
}
