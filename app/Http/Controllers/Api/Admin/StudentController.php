<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\CsvExport;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /**
     * Ported dari jacos-react admin/Students.jsx: pencarian nama/NIS, filter
     * tingkat/rombel/gender/status SPP, plus kolom terhitung (status hadir hari
     * ini, tingkat kehadiran tahun ajaran berjalan, status SPP bulan berjalan) —
     * biar Admin bisa scan kondisi tiap siswa tanpa buka detail satu-satu.
     */
    public function index(Request $request)
    {
        $paginated = $this->filteredQuery($request)->paginate(20);
        $paginated->getCollection()->transform(fn (Student $s) => $this->presentRow($s));

        return response()->json($paginated);
    }

    /**
     * Export CSV — pakai filter yang sama persis dengan index(), tapi ambil
     * SEMUA baris yang cocok (bukan cuma 1 halaman) supaya file yang diunduh
     * konsisten dengan apa yang Admin lihat di layar (filter aktif).
     */
    public function export(Request $request)
    {
        $rows = $this->filteredQuery($request)->get()->map(fn (Student $s) => $this->presentRow($s));

        return CsvExport::download(
            'data-siswa-'.now()->format('Y-m-d').'.csv',
            ['NIS', 'Nama', 'Kelas', 'Tingkat', 'Orang Tua/Wali', 'Jenis Kelamin', 'Status', 'Hadir Hari Ini', 'Tingkat Kehadiran (%)', 'Status SPP'],
            $rows,
            fn (array $r) => [
                $r['nis'],
                $r['name'],
                $r['classroom']['name'] ?? '-',
                $r['classroom']['grade_level']['name'] ?? '-',
                $r['parent_name'] ?? '-',
                $r['gender'] === 'female' ? 'Perempuan' : 'Laki-laki',
                $r['status'] === 'active' ? 'Aktif' : 'Nonaktif',
                $r['today_status'] ?? '-',
                $r['attendance_rate'] ?? '-',
                $r['spp_status'] ?? '-',
            ]
        );
    }

    private function filteredQuery(Request $request)
    {
        $today = now()->toDateString();
        $currentPeriod = now()->format('Y-m');
        $yearStart = AcademicYear::where('is_active', true)->value('start_date')?->toDateString()
            ?? now()->startOfYear()->toDateString();

        return Student::with(['classroom.gradeLevel:id,name', 'parents:id,name'])
            ->when($request->query('classroom_id'), fn ($q, $id) => $q->where('classroom_id', $id))
            ->when($request->query('grade_level_id'), fn ($q, $id) => $q->whereHas(
                'classroom',
                fn ($cq) => $cq->where('grade_level_id', $id)
            ))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('gender'), fn ($q, $g) => $q->where('gender', $g))
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$term}%")->orWhere('nis', 'like', "%{$term}%")
            ))
            ->when($request->query('spp_status'), fn ($q, $s) => $q->whereHas(
                'invoices',
                fn ($iq) => $iq->where('period', $currentPeriod)->where('status', $s)
            ))
            ->with(['attendances' => fn ($q) => $q->where('date', $today)])
            ->with(['invoices' => fn ($q) => $q->where('period', $currentPeriod)])
            ->withCount(['attendances as attendance_total' => fn ($q) => $q->where('date', '>=', $yearStart)])
            ->withCount(['attendances as attendance_hadir' => fn ($q) => $q->where('date', '>=', $yearStart)->where('status', 'hadir')]);
    }

    private function presentRow(Student $student): array
    {
        return [
            ...$student->only(['id', 'nis', 'name', 'gender', 'status', 'classroom_id', 'photo_path']),
            'classroom' => $student->classroom ? [
                'id' => $student->classroom->id,
                'name' => $student->classroom->name,
                'grade_level' => $student->classroom->gradeLevel,
            ] : null,
            'parent_name' => $student->parents->first()?->name,
            'today_status' => $student->attendances->first()?->status,
            'attendance_rate' => $student->attendance_total > 0
                ? round($student->attendance_hadir / $student->attendance_total * 100)
                : null,
            'spp_status' => $student->invoices->first()?->status,
        ];
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
            'blood_type' => ['nullable', 'string', 'max:5'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('student-photos', 'public');
        }
        unset($data['photo']);

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
            'blood_type' => ['nullable', 'string', 'max:5'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('student-photos', 'public');
        }
        unset($data['photo']);

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
