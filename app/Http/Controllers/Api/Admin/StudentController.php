<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::with('classroom:id,name')
            ->when($request->query('classroom_id'), fn ($q, $id) => $q->where('classroom_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        return response()->json($query->paginate(20));
    }

    public function show(Student $student)
    {
        return response()->json(['student' => $student->load('classroom', 'parents')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nis' => ['required', 'string', 'unique:students,nis'],
            'name' => ['required', 'string', 'max:255'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female'],
        ]);

        $student = Student::create($data);

        return response()->json(['student' => $student], 201);
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female'],
        ]);

        $student->update($data);

        return response()->json(['student' => $student]);
    }

    /**
     * Ubah status aktif/nonaktif — FR-BE-4.10: auto-batalkan invoice belum bayar bulan berjalan.
     */
    public function updateStatus(Request $request, Student $student)
    {
        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);

        $before = $student->only('status');

        $student->update([
            'status' => $data['status'],
            'inactive_at' => $data['status'] === 'inactive' ? now() : null,
        ]);

        if ($data['status'] === 'inactive') {
            $currentPeriod = now()->format('Y-m');

            Invoice::where('student_id', $student->id)
                ->where('status', 'belum_bayar')
                ->where('period', $currentPeriod)
                ->update(['status' => 'dibatalkan']);
        }

        AuditLog::record($request->user()->id, 'student.status_change', Student::class, $student->id, $before, $student->only('status'));

        return response()->json(['student' => $student]);
    }
}
