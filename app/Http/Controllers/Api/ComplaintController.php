<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    /**
     * Daftar pengaduan milik Orang Tua yang login, filterable status.
     */
    public function indexForParent(Request $request)
    {
        $query = Complaint::with('student:id,name')
            ->where('submitted_by', $request->user()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('student_id'), fn ($q, $id) => $q->where('student_id', $id));

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Ajukan pengaduan (Orang Tua) — nomor tiket dibuat otomatis oleh model,
     * notifikasi langsung dikirim ke semua Admin (Tata Usaha).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'category' => ['required', 'in:'.implode(',', Complaint::CATEGORIES)],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'max:4096'],
        ]);

        // Orang tua hanya boleh menautkan pengaduan ke anaknya sendiri.
        //
        // Sengaja TIDAK pakai `$this->authorize('view', $student)` (StudentPolicy)
        // seperti StudentLeaveRequestController: policy itu menambahkan syarat
        // consent data anak masih aktif, dan consent mengatur pemrosesan data
        // (foto dsb) — bukan hak orang tua untuk menyampaikan keluhan soal anaknya.
        // Kalau ikut policy, orang tua yang menarik consent jadi tidak bisa
        // mengadukan anaknya sendiri, yang jelas bukan maksud consent itu.
        if (! empty($data['student_id'])) {
            $student = Student::findOrFail($data['student_id']);
            abort_unless(
                $request->user()->children()->where('students.id', $student->id)->exists(),
                403,
                'Anak ini tidak terhubung ke akun Anda.'
            );
        }

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store('complaint-attachments', 'public')
            : null;

        $complaint = Complaint::create([
            'student_id' => $data['student_id'] ?? null,
            'submitted_by' => $request->user()->id,
            'category' => $data['category'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'attachment_path' => $attachmentPath,
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => Complaint::slaDueAt('normal'),
        ]);

        NotificationService::sendMany(
            User::where('role', 'admin')->get(),
            'Pengaduan Baru',
            "{$complaint->ticket_no} — {$complaint->subject} (dari {$request->user()->name}).",
            null,
            "/admin/complaints/{$complaint->id}"
        );

        AuditLog::record($request->user()->id, 'complaint.created', Complaint::class, $complaint->id, null, [
            'ticket_no' => $complaint->ticket_no,
            'category' => $complaint->category,
        ]);

        return response()->json(['complaint' => $complaint], 201);
    }

    /**
     * Daftar pengaduan untuk Tata Usaha. `counts` dikirim menempel di response
     * (pola yang sama dengan `unread_count` di NotificationController@index)
     * supaya kartu ringkasan di halaman Admin tidak perlu request kedua.
     */
    public function adminIndex(Request $request)
    {
        $query = Complaint::with(['student:id,name', 'submittedBy:id,name', 'assignedTo:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->query('assigned_to'), fn ($q, $u) => $q->where('assigned_to', $u))
            ->when($request->query('overdue'), fn ($q) => $q
                ->whereNotIn('status', Complaint::CLOSED_STATUSES)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w
                    ->where('ticket_no', 'like', "%{$term}%")
                    ->orWhere('subject', 'like', "%{$term}%")
                    ->orWhere('body', 'like', "%{$term}%"));
            });

        $paginated = $query->latest()->paginate(20);

        $counts = Complaint::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $overdueCount = Complaint::whereNotIn('status', Complaint::CLOSED_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        return response()->json([
            ...$paginated->toArray(),
            'counts' => [
                ...array_fill_keys(Complaint::STATUSES, 0),
                ...$counts,
                'overdue' => $overdueCount,
            ],
        ]);
    }

    /**
     * Calon penanggung jawab tiket: Admin, Guru, dan Staff.
     *
     * Endpoint terpisah, bukan numpang `/admin/staff`, karena StaffController
     * hanya mengembalikan baris tabel `staff` (tanpa akun Admin) dan dipaginasi
     * 20 per halaman — daftar assignee yang terpotong diam-diam lebih buruk
     * daripada satu query kecil di sini.
     */
    public function assignees()
    {
        return response()->json([
            'assignees' => User::whereIn('role', ['admin', 'guru', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }

    /**
     * Detail tiket + seluruh percakapannya. Bisa diakses Admin dan pengadu.
     */
    public function show(Request $request, Complaint $complaint)
    {
        $this->authorize('view', $complaint);

        return response()->json([
            'complaint' => $complaint->load([
                'student:id,name,classroom_id',
                'submittedBy:id,name,role',
                'assignedTo:id,name',
                'replies.user:id,name,role',
            ]),
        ]);
    }

    /**
     * Balas tiket. Balasan Admin → notifikasi ke pengadu; balasan pengadu →
     * notifikasi ke penanggung jawab (atau semua Admin kalau belum di-assign).
     */
    public function reply(Request $request, Complaint $complaint)
    {
        $this->authorize('reply', $complaint);

        $data = $request->validate(['body' => ['required', 'string']]);

        $reply = $complaint->replies()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        if ($request->user()->role === 'admin') {
            NotificationService::send(
                $complaint->submittedBy,
                'Balasan Pengaduan',
                "{$complaint->ticket_no} — {$complaint->subject} dibalas oleh Tata Usaha.",
                null,
                "/ortu/complaints/{$complaint->id}"
            );
        } else {
            $targets = $complaint->assignedTo
                ? collect([$complaint->assignedTo])
                : User::where('role', 'admin')->get();

            NotificationService::sendMany(
                $targets,
                'Balasan Pengaduan',
                "{$complaint->ticket_no} — {$complaint->subject} dibalas oleh {$request->user()->name}.",
                null,
                "/admin/complaints/{$complaint->id}"
            );
        }

        return response()->json(['reply' => $reply], 201);
    }

    /**
     * Triase tiket oleh Admin: status, priority (+ hitung ulang SLA), penanggung
     * jawab, dan catatan penyelesaian.
     */
    public function update(Request $request, Complaint $complaint)
    {
        $this->authorize('update', $complaint);

        $data = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', Complaint::STATUSES)],
            'priority' => ['sometimes', 'in:'.implode(',', Complaint::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            // Menutup tiket sebagai selesai wajib disertai keterangan — tanpa itu
            // orang tua tidak tahu apa yang sebenarnya dikerjakan.
            'resolution_note' => ['nullable', 'string', 'required_if:status,resolved'],
        ]);

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $assignee = User::findOrFail($data['assigned_to']);
            // Pengadu tidak boleh jadi penanggung jawab tiketnya sendiri.
            abort_if($assignee->role === 'orang_tua', 422, 'Penanggung jawab harus Admin, Guru, atau Staff.');
        }

        $before = $complaint->only(['status', 'priority', 'assigned_to', 'resolution_note']);
        $statusChanged = isset($data['status']) && $data['status'] !== $complaint->status;

        if (isset($data['priority']) && $data['priority'] !== $complaint->priority) {
            // SLA mengikuti priority baru, dihitung dari sekarang.
            $data['due_at'] = Complaint::slaDueAt($data['priority']);
        }

        if (isset($data['status'])) {
            $data['resolved_at'] = in_array($data['status'], Complaint::CLOSED_STATUSES, true)
                ? ($complaint->resolved_at ?? now())
                : null;
        }

        $complaint->update($data);

        AuditLog::record($request->user()->id, 'complaint.updated', Complaint::class, $complaint->id, $before, $complaint->only([
            'status', 'priority', 'assigned_to', 'resolution_note',
        ]));

        if ($statusChanged) {
            $labels = [
                'open' => 'dibuka kembali',
                'in_progress' => 'sedang diproses',
                'resolved' => 'dinyatakan selesai',
                'rejected' => 'ditolak',
            ];

            NotificationService::send(
                $complaint->submittedBy,
                'Status Pengaduan Diperbarui',
                "{$complaint->ticket_no} — {$complaint->subject} {$labels[$complaint->status]}.",
                null,
                "/ortu/complaints/{$complaint->id}"
            );
        }

        return response()->json(['complaint' => $complaint]);
    }

    /**
     * Hapus tiket (Admin). Balasan ikut terhapus lewat cascade di DB.
     */
    public function destroy(Request $request, Complaint $complaint)
    {
        $this->authorize('delete', $complaint);

        AuditLog::record($request->user()->id, 'complaint.deleted', Complaint::class, $complaint->id, [
            'ticket_no' => $complaint->ticket_no,
            'subject' => $complaint->subject,
            'status' => $complaint->status,
        ], null);

        $complaint->delete();

        return response()->json(['message' => 'Pengaduan dihapus.']);
    }
}
