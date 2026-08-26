<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarHoliday;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\DismissalSetting;
use App\Models\Student;
use App\Models\StudentAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    /**
     * FR-BE-1.1 — daftar siswa + status kehadiran pada tanggal tertentu.
     */
    public function index(Request $request, Classroom $classroom)
    {
        $this->authorizeClassroom($request, $classroom);

        $date = $request->query('date', now()->toDateString());

        if ($this->isHoliday($classroom, $date)) {
            return response()->json([
                'is_holiday' => true,
                'message' => 'Hari libur, tidak perlu absensi.',
            ], 200);
        }

        $students = $classroom->students()
            ->where('status', 'active')
            ->with(['attendances' => fn ($q) => $q->where('date', $date)])
            ->get()
            ->map(function (Student $student) use ($date) {
                $attendance = $student->attendances->first();

                return [
                    'student_id' => $student->id,
                    'name' => $student->name,
                    'status' => $attendance?->status,
                    'note' => $attendance?->note,
                    'editable' => $attendance
                        ? $attendance->isEditable($this->toleranceDays())
                        : true,
                ];
            });

        return response()->json(['is_holiday' => false, 'date' => $date, 'students' => $students]);
    }

    /**
     * FR-BE-1.1 / FR-BE-1.2 — simpan absensi bulk untuk 1 rombel/tanggal.
     */
    public function store(Request $request, Classroom $classroom)
    {
        $this->authorizeClassroom($request, $classroom);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'exists:students,id'],
            'records.*.status' => ['required', 'in:hadir,izin,sakit,alpa'],
            'records.*.note' => ['nullable', 'string'],
        ]);

        if ($this->isHoliday($classroom, $data['date'])) {
            return response()->json(['message' => 'Hari libur, tidak perlu absensi.'], 422);
        }

        foreach ($data['records'] as $record) {
            StudentAttendance::updateOrCreate(
                ['student_id' => $record['student_id'], 'date' => $data['date']],
                [
                    'status' => $record['status'],
                    'note' => $record['note'] ?? null,
                    'recorded_by' => $request->user()->id,
                ]
            );

            // FR-BE-1.5: notify parents when status is not "hadir" (or always, per school setting)
            if ($record['status'] !== 'hadir') {
                // TODO: dispatch push notification job to student's parents.
            }
        }

        return response()->json(['message' => 'Absensi tersimpan.']);
    }

    /**
     * FR-BE-1.3 — edit absensi, hanya dalam window toleransi.
     */
    public function update(Request $request, Classroom $classroom)
    {
        $this->authorizeClassroom($request, $classroom);

        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:hadir,izin,sakit,alpa'],
            'note' => ['nullable', 'string'],
        ]);

        $attendance = StudentAttendance::where('student_id', $data['student_id'])
            ->where('date', $data['date'])
            ->firstOrFail();

        if (! $attendance->isEditable($this->toleranceDays())) {
            abort(403, 'Periode edit absensi sudah lewat.');
        }

        $before = $attendance->only(['status', 'note']);

        $attendance->update([
            'status' => $data['status'],
            'note' => $data['note'] ?? $attendance->note,
        ]);

        AuditLog::record(
            $request->user()->id,
            'attendance.edit',
            StudentAttendance::class,
            $attendance->id,
            $before,
            $attendance->only(['status', 'note'])
        );

        return response()->json(['message' => 'Absensi diperbarui.']);
    }

    /**
     * FR-BE-1.4 — riwayat absensi anak (untuk orang tua), scoped ke anak yang terhubung.
     */
    public function forChild(Request $request, Student $student)
    {
        $this->authorize('view', $student);

        $month = $request->query('month', now()->format('Y-m'));

        $attendances = $student->attendances()
            ->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month])
            ->orderBy('date')
            ->get(['date', 'status', 'note']);

        return response()->json(['student_id' => $student->id, 'month' => $month, 'attendances' => $attendances]);
    }

    private function isHoliday(Classroom $classroom, string $date): bool
    {
        if (! $classroom->academic_year_id) {
            return false;
        }

        return AcademicCalendarHoliday::where('academic_year_id', $classroom->academic_year_id)
            ->where('date', $date)
            ->exists();
    }

    private function toleranceDays(): int
    {
        return DismissalSetting::query()->value('attendance_edit_tolerance_days') ?? 1;
    }

    private function authorizeClassroom(Request $request, Classroom $classroom): void
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return;
        }

        if ($user->role === 'guru' && $user->staff && $classroom->homeroom_teacher_id === $user->staff->id) {
            return;
        }

        abort(403, 'Anda tidak memiliki akses ke rombel ini.');
    }
}
