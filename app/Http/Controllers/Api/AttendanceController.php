<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarHoliday;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\DismissalSetting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Services\NotificationService;
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

        $statusLabels = ['izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa/Tanpa Keterangan'];
        $notified = 0;

        foreach ($data['records'] as $record) {
            StudentAttendance::updateOrCreate(
                ['student_id' => $record['student_id'], 'date' => $data['date']],
                [
                    'status' => $record['status'],
                    'note' => $record['note'] ?? null,
                    'recorded_by' => $request->user()->id,
                ]
            );

            // FR-BE-1.5: notify parents when status is not "hadir".
            if ($record['status'] !== 'hadir') {
                $student = Student::with('parents')->find($record['student_id']);
                NotificationService::sendMany(
                    $student->parents,
                    'Absensi: '.$statusLabels[$record['status']],
                    "{$student->name} tercatat {$statusLabels[$record['status']]} pada ".Carbon::parse($data['date'])->translatedFormat('d M Y').
                        ($record['note'] ? '. Catatan: '.$record['note'] : '.'),
                    null,
                    '/ortu/attendance'
                );
                $notified++;
            }
        }

        return response()->json(['message' => 'Absensi tersimpan.', 'notified' => $notified]);
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

        $statusLabels = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa/Tanpa Keterangan'];
        $student = Student::with('parents')->find($data['student_id']);
        NotificationService::sendMany(
            $student->parents,
            'Koreksi Absensi',
            "Absensi {$student->name} pada ".Carbon::parse($data['date'])->translatedFormat('d M Y').
                " dikoreksi menjadi {$statusLabels[$data['status']]}.",
            null,
            '/ortu/attendance'
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

    /**
     * Papan status pengisian absensi lintas-rombel untuk Admin (ported dari
     * jacos-react — dashboard Admin perlu tahu wali kelas mana yang belum
     * input absensi hari ini, tanpa harus buka tiap rombel satu-satu).
     */
    public function adminSubmissionStatus(Request $request)
    {
        $date = $request->query('date', now()->toDateString());

        $classrooms = Classroom::with('homeroomTeacher.user:id,name')
            ->withCount(['students' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->map(function (Classroom $classroom) use ($date) {
                $isHoliday = $this->isHoliday($classroom, $date);
                $marked = $isHoliday ? 0 : StudentAttendance::where('date', $date)
                    ->whereIn('student_id', $classroom->students()->where('status', 'active')->pluck('id'))
                    ->count();

                return [
                    'classroom_id' => $classroom->id,
                    'classroom_name' => $classroom->name,
                    'homeroom_teacher' => $classroom->homeroomTeacher?->user?->name,
                    'homeroom_teacher_id' => $classroom->homeroomTeacher?->user_id,
                    'total_students' => $classroom->students_count,
                    'marked' => $marked,
                    'is_holiday' => $isHoliday,
                    'status' => $isHoliday
                        ? 'holiday'
                        : ($classroom->students_count === 0
                            ? 'no_students'
                            : ($marked === 0 ? 'not_started' : ($marked < $classroom->students_count ? 'partial' : 'complete'))),
                ];
            });

        return response()->json(['date' => $date, 'classrooms' => $classrooms]);
    }

    /**
     * Kirim notifikasi pengingat ke wali kelas yang belum/belum selesai input absensi.
     */
    public function remindTeacher(Request $request, Classroom $classroom)
    {
        $classroom->loadMissing('homeroomTeacher.user');
        $teacherUser = $classroom->homeroomTeacher?->user;

        abort_if(! $teacherUser, 422, 'Rombel ini belum punya wali kelas.');

        NotificationService::send(
            $teacherUser,
            'Pengingat Input Absensi',
            "Absensi rombel {$classroom->name} hari ini belum lengkap. Mohon segera diisi.",
            null,
            '/guru/attendance'
        );

        return response()->json(['message' => 'Pengingat terkirim ke wali kelas.']);
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
