<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarHoliday;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentLeaveRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class StudentLeaveRequestController extends Controller
{
    /**
     * Riwayat izin/sakit anak yang diajukan Orang Tua sendiri, difilter opsional per anak.
     */
    public function indexForParent(Request $request)
    {
        $query = StudentLeaveRequest::with('student:id,name')
            ->where('submitted_by', $request->user()->id)
            ->when($request->query('student_id'), fn ($q, $id) => $q->where('student_id', $id));

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Ajukan izin/sakit anak (Orang Tua) — notifikasi otomatis ke wali kelas terkait.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'type' => ['required', 'in:izin,sakit'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'max:4096'],
        ]);

        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store('student-leave-attachments', 'public')
            : null;

        $leaveRequest = StudentLeaveRequest::create([
            'student_id' => $student->id,
            'submitted_by' => $request->user()->id,
            'type' => $data['type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'attachment_path' => $attachmentPath,
            'status' => 'pending',
        ]);

        $teacherUser = $student->classroom?->homeroomTeacher?->user;
        if ($teacherUser) {
            NotificationService::send(
                $teacherUser,
                'Pengajuan Izin Siswa',
                "{$student->name} diajukan {$data['type']} (".
                    $leaveRequest->start_date->translatedFormat('d M Y').' - '.$leaveRequest->end_date->translatedFormat('d M Y').
                    ') oleh orang tua.',
                null,
                '/guru/student-leave-requests'
            );
        }

        return response()->json(['leave_request' => $leaveRequest], 201);
    }

    /**
     * Daftar pengajuan izin siswa di kelas yang diampu (Wali Kelas), filterable status.
     */
    public function indexForTeacher(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(! $staff, 422, 'Akun ini tidak terhubung ke data staff.');

        $query = StudentLeaveRequest::with('student:id,name,classroom_id')
            ->whereHas('student.classroom', fn ($q) => $q->where('homeroom_teacher_id', $staff->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Approve/reject oleh Wali Kelas. Approve otomatis mengisi absensi (izin/sakit)
     * untuk setiap tanggal dalam rentang yang diajukan (kecuali hari libur), supaya
     * guru tidak perlu input manual lagi saat membuka layar absensi hari itu.
     */
    public function review(Request $request, StudentLeaveRequest $studentLeaveRequest)
    {
        $this->authorize('review', $studentLeaveRequest);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['required_if:status,rejected', 'nullable', 'string'],
        ]);

        $studentLeaveRequest->update([
            'status' => $data['status'],
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($data['status'] === 'approved') {
            $this->fillAttendanceForRange($studentLeaveRequest, $request->user()->id);
        }

        $parent = $studentLeaveRequest->submittedBy;
        if ($parent) {
            $titles = ['approved' => 'Pengajuan Izin Disetujui', 'rejected' => 'Pengajuan Izin Ditolak'];
            NotificationService::send(
                $parent,
                $titles[$data['status']],
                "Pengajuan {$studentLeaveRequest->type} untuk {$studentLeaveRequest->student->name} ".
                    ($data['review_note'] ? "dengan catatan: {$data['review_note']}" : 'telah diproses.'),
                null,
                '/ortu/leave-requests'
            );
        }

        return response()->json(['leave_request' => $studentLeaveRequest]);
    }

    private function fillAttendanceForRange(StudentLeaveRequest $leaveRequest, int $reviewerId): void
    {
        $student = $leaveRequest->student;
        $academicYearId = $student->classroom?->academic_year_id;

        $cursor = $leaveRequest->start_date->copy();
        $end = $leaveRequest->end_date->copy();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $isHoliday = $academicYearId
                && AcademicCalendarHoliday::where('academic_year_id', $academicYearId)->where('date', $date)->exists();

            if (! $isHoliday) {
                StudentAttendance::updateOrCreate(
                    ['student_id' => $student->id, 'date' => $date],
                    [
                        'status' => $leaveRequest->type,
                        'note' => "Diajukan orang tua: {$leaveRequest->reason}",
                        'recorded_by' => $reviewerId,
                    ]
                );
            }

            $cursor->addDay();
        }
    }
}
