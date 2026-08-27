<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\AuthorizedPickup;
use App\Models\DismissalSetting;
use App\Models\PickupLog;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickupController extends Controller
{
    /**
     * FR-BE-2.1 — daftar penjemput sah untuk 1 anak (semua status, biar ortu lihat
     * yang masih Pending Approval juga — bukan cuma yang aktif).
     */
    public function index(Request $request, Student $student)
    {
        $this->authorize('view', $student);

        return response()->json([
            'pickups' => $student->authorizedPickups()->whereNull('revoked_at')->get(),
        ]);
    }

    /**
     * FR-BE-2.1 / FR-BE-2.2 — tambah penjemput sah. Status awal PENDING APPROVAL
     * (ported dari jacos-react REQUIREMENTS.md §4): QR belum aktif dipakai sampai
     * Admin memverifikasi dokumen identitas & approve.
     */
    public function store(Request $request, Student $student)
    {
        $this->authorize('view', $student);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', 'string', 'max:100'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('pickup-photos', 'public')
            : null;

        $pickup = $student->authorizedPickups()->create([
            'name' => $data['name'],
            'relationship' => $data['relationship'],
            'photo_path' => $photoPath,
        ]);

        NotificationService::sendMany(
            User::where('role', 'admin')->get(),
            'Penjemput Baru Menunggu Persetujuan',
            "{$data['name']} ({$data['relationship']}) didaftarkan sebagai penjemput {$student->name}, menunggu verifikasi dokumen.",
            null,
            '/admin/pickup-approvals'
        );

        return response()->json(['pickup' => $pickup], 201);
    }

    /**
     * Approve penjemput (Admin, setelah verifikasi dokumen identitas manual di
     * kantor TU) — baru di sini QR benar-benar bisa dipakai. valid_until default
     * sampai akhir tahun ajaran aktif.
     */
    public function approve(Request $request, AuthorizedPickup $pickup)
    {
        abort_if($pickup->status !== 'pending_approval', 422, 'Penjemput ini bukan status Pending Approval.');

        $validityDays = DismissalSetting::query()->value('pickup_qr_validity_days');
        $validUntil = $validityDays
            ? now()->addDays($validityDays)->toDateString()
            : AcademicYear::where('is_active', true)->value('end_date');

        $pickup->update([
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'valid_until' => $validUntil,
        ]);

        AuditLog::record($request->user()->id, 'pickup_person.approved', AuthorizedPickup::class, $pickup->id, null, ['approved_by' => $request->user()->id]);

        return response()->json(['pickup' => $pickup->fresh()]);
    }

    /**
     * FR-BE-2.10 — revoke penjemput, QR langsung tidak valid.
     */
    public function destroy(Request $request, AuthorizedPickup $pickup)
    {
        $this->authorize('view', $pickup->student);

        $pickup->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Penjemput dihapus, QR tidak berlaku lagi.']);
    }

    /**
     * FR-BE-2.3 — decode QR token, kembalikan info untuk konfirmasi visual staff.
     * Ported dari jacos-react Scanner.jsx: alasan penolakan spesifik (bukan cuma
     * generik) — PENDING_APPROVAL/QR_EXPIRED/REVOKED/ALREADY_USED/NOT_AUTHORIZED.
     */
    public function scan(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $pickup = AuthorizedPickup::where('qr_token', $data['token'])->with('student')->first();

        if (! $pickup) {
            return response()->json(['ok' => false, 'reason' => 'NOT_AUTHORIZED', 'message' => 'QR tidak dikenali.'], 422);
        }

        return $this->respondScanResult($pickup);
    }

    /**
     * FR-BE-2.3 — konfirmasi checkout via QR.
     */
    public function confirm(Request $request, AuthorizedPickup $pickup)
    {
        if (! $pickup->isActive()) {
            return response()->json(['message' => 'Penjemput tidak terdaftar / tidak aktif.'], 422);
        }
        if ($this->alreadyPickedUpToday($pickup->student_id)) {
            return response()->json(['message' => 'Siswa ini sudah tercatat dijemput hari ini.'], 422);
        }

        $log = $this->createLog($pickup->student_id, $pickup->id, 'qr', null, $request->user()->id);

        $this->notifyParentsOfPickup($pickup->student, $pickup->name, $pickup->relationship);

        return response()->json(['message' => 'Checkout berhasil.', 'log' => $log]);
    }

    /**
     * FR-BE-2.4 / FR-BE-2.7 — fallback manual, wajib catatan, tolak jika penjemput tidak terdaftar untuk siswa itu.
     */
    public function manual(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'authorized_pickup_id' => ['required', 'exists:authorized_pickups,id'],
            'note' => ['required', 'string'],
        ]);

        $pickup = AuthorizedPickup::where('id', $data['authorized_pickup_id'])
            ->where('student_id', $data['student_id'])
            ->first();

        if (! $pickup || ! $pickup->isActive()) {
            return response()->json(['message' => 'Penjemput tidak terdaftar / tidak aktif.'], 422);
        }
        if ($this->alreadyPickedUpToday($data['student_id'])) {
            return response()->json(['message' => 'Siswa ini sudah tercatat dijemput hari ini.'], 422);
        }

        $log = $this->createLog($data['student_id'], $pickup->id, 'manual', $data['note'], $request->user()->id);

        $this->notifyParentsOfPickup($pickup->student, $pickup->name, $pickup->relationship);

        return response()->json(['message' => 'Checkout manual berhasil.', 'log' => $log]);
    }

    /**
     * Eskalasi penjemput ditolak ke Admin (ported dari jacos-react — "Denied attempt
     * must notify admin AND parent in real time, this is a safety feature"). Dipanggil
     * FE saat guru/staff menekan "Hubungi Administrator" di layar hasil scan gagal.
     */
    public function escalate(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'note' => ['required', 'string'],
        ]);

        $student = Student::findOrFail($data['student_id']);

        AuditLog::record(
            $request->user()->id,
            'pickup.denied_escalated',
            Student::class,
            $student->id,
            null,
            ['note' => $data['note'], 'reported_by' => $request->user()->id]
        );

        $officerName = $request->user()->name;
        NotificationService::sendMany(
            User::where('role', 'admin')->get(),
            'Penjemput Tidak Terdaftar — Dieskalasi',
            "{$officerName} melaporkan percobaan jemput yang ditolak untuk {$student->name}. Catatan: {$data['note']}",
            null,
            '/admin/audit-log'
        );
        NotificationService::sendMany(
            $student->parents,
            'Percobaan Penjemputan Ditolak',
            "Ada percobaan menjemput {$student->name} oleh orang yang tidak terdaftar di daftar penjemput sah. Pihak sekolah sudah menindaklanjuti.",
            null,
            '/ortu/pickup-history'
        );

        return response()->json(['message' => 'Administrator & orang tua telah diberi tahu.']);
    }

    /**
     * PRD §8.4 #33 — riwayat jemput anak, sisi Ortu (beda dari adminIndex yang
     * lintas-siswa). Scoped ke anak yang terhubung ke akun ortu login.
     */
    public function logsForChild(Request $request, Student $student)
    {
        $this->authorize('view', $student);

        $logs = PickupLog::with(['authorizedPickup:id,name,relationship', 'verifiedBy:id,name'])
            ->where('student_id', $student->id)
            ->latest('checked_out_at')
            ->paginate(20);

        return response()->json($logs);
    }

    /**
     * FR-BE-2.8 — log jemput untuk audit Admin.
     */
    public function adminIndex(Request $request)
    {
        $query = PickupLog::with(['student:id,name', 'authorizedPickup:id,name', 'verifiedBy:id,name'])
            ->when($request->query('date'), fn ($q, $d) => $q->where('date', $d))
            ->when($request->query('student_id'), fn ($q, $id) => $q->where('student_id', $id))
            ->when($request->query('pickup_id'), fn ($q, $id) => $q->where('authorized_pickup_id', $id))
            ->latest('checked_out_at');

        return response()->json($query->paginate(20));
    }

    /**
     * Daftar penjemput sah PENDING APPROVAL lintas-siswa — dipakai Admin di layar
     * Pengaturan Jemput utk verifikasi dokumen identitas sebelum approve.
     */
    public function pendingApprovals(Request $request)
    {
        $pending = AuthorizedPickup::whereNull('approved_at')
            ->whereNull('revoked_at')
            ->with('student:id,name')
            ->latest()
            ->get();

        return response()->json(['pickups' => $pending]);
    }

    /**
     * FR-FE-2.4 — daftar penjemput sah AKTIF 1 siswa, dipakai dropdown fallback manual di
     * Verifikasi Jemput (Guru/Staff/Admin) — beda dari index() yang scoped utk Ortu.
     */
    public function authorizedFor(Request $request, Student $student)
    {
        $pickups = $student->authorizedPickups()->whereNull('revoked_at')->get()
            ->filter(fn (AuthorizedPickup $p) => $p->isActive())
            ->values();

        return response()->json(['pickups' => $pickups]);
    }

    /**
     * Siswa aktif hari ini yang belum checkout — dipakai dashboard Admin/Guru (FR-BE-2.9 dasar).
     */
    public function notPickedUpToday(Request $request)
    {
        $today = now()->toDateString();

        $query = Student::where('status', 'active')
            ->whereDoesntHave('pickupLogs', fn ($q) => $q->where('date', $today));

        if ($request->query('classroom_id')) {
            $query->where('classroom_id', $request->query('classroom_id'));
        }

        return response()->json(['students' => $query->with('classroom:id,name')->get(['id', 'name', 'classroom_id'])]);
    }

    private function respondScanResult(AuthorizedPickup $pickup)
    {
        $base = [
            'authorized_pickup_id' => $pickup->id,
            'pickup_name' => $pickup->name,
            'pickup_photo_path' => $pickup->photo_path,
            'relationship' => $pickup->relationship,
            'student' => ['id' => $pickup->student->id, 'name' => $pickup->student->name],
        ];

        if ($pickup->status === 'revoked') {
            return response()->json([...$base, 'ok' => false, 'reason' => 'REVOKED', 'message' => 'Penjemput sudah tidak terdaftar (dihapus ortu/admin).'], 422);
        }
        if ($pickup->status === 'pending_approval') {
            return response()->json([...$base, 'ok' => false, 'reason' => 'PENDING_APPROVAL', 'message' => 'Penjemput ini belum disetujui Admin — belum bisa dipakai jemput.'], 422);
        }
        if ($pickup->status === 'expired') {
            return response()->json([...$base, 'ok' => false, 'reason' => 'QR_EXPIRED', 'message' => 'Masa berlaku QR sudah habis, minta ortu perbarui di aplikasi.'], 422);
        }
        if ($this->alreadyPickedUpToday($pickup->student_id)) {
            return response()->json([...$base, 'ok' => false, 'reason' => 'ALREADY_USED', 'message' => 'Siswa ini sudah tercatat dijemput hari ini.'], 422);
        }

        return response()->json([...$base, 'ok' => true]);
    }

    private function alreadyPickedUpToday(int $studentId): bool
    {
        return PickupLog::where('student_id', $studentId)->where('date', now()->toDateString())->exists();
    }

    private function notifyParentsOfPickup(Student $student, string $pickupName, string $relationship): void
    {
        NotificationService::sendMany(
            $student->parents,
            'Anak Sudah Dijemput',
            "{$student->name} telah dijemput pukul ".now()->format('H:i')." oleh {$pickupName} ({$relationship}).",
            null,
            '/ortu/pickup-history'
        );
    }

    private function createLog(int $studentId, int $pickupId, string $method, ?string $note, int $verifiedBy): PickupLog
    {
        return DB::transaction(fn () => PickupLog::create([
            'student_id' => $studentId,
            'authorized_pickup_id' => $pickupId,
            'verified_by' => $verifiedBy,
            'method' => $method,
            'note' => $note,
            'date' => now()->toDateString(),
            'checked_out_at' => now(),
        ]));
    }
}
