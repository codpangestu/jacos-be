<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedPickup;
use App\Models\PickupLog;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickupController extends Controller
{
    /**
     * FR-BE-2.1 — daftar penjemput sah untuk 1 anak.
     */
    public function index(Request $request, Student $student)
    {
        $this->authorize('view', $student);

        return response()->json([
            'pickups' => $student->authorizedPickups()->whereNull('revoked_at')->get(),
        ]);
    }

    /**
     * FR-BE-2.1 / FR-BE-2.2 — tambah penjemput sah, generate QR token unik per (pickup, siswa).
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

        return response()->json(['pickup' => $pickup], 201);
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
     */
    public function scan(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $pickup = AuthorizedPickup::where('qr_token', $data['token'])
            ->whereNull('revoked_at')
            ->with('student')
            ->first();

        if (! $pickup) {
            return response()->json(['message' => 'QR tidak valid / penjemput sudah tidak terdaftar.'], 422);
        }

        return response()->json([
            'authorized_pickup_id' => $pickup->id,
            'pickup_name' => $pickup->name,
            'pickup_photo_path' => $pickup->photo_path,
            'relationship' => $pickup->relationship,
            'student' => ['id' => $pickup->student->id, 'name' => $pickup->student->name],
        ]);
    }

    /**
     * FR-BE-2.3 — konfirmasi checkout via QR.
     */
    public function confirm(Request $request, AuthorizedPickup $pickup)
    {
        if (! $pickup->isActive()) {
            return response()->json(['message' => 'Penjemput tidak terdaftar.'], 422);
        }

        $log = $this->createLog($pickup->student_id, $pickup->id, 'qr', null, $request->user()->id);

        // TODO: dispatch push notification job to student's parents (FR-BE-2.6).

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
            ->whereNull('revoked_at')
            ->first();

        if (! $pickup) {
            return response()->json(['message' => 'Penjemput tidak terdaftar.'], 422);
        }

        $log = $this->createLog($data['student_id'], $pickup->id, 'manual', $data['note'], $request->user()->id);

        return response()->json(['message' => 'Checkout manual berhasil.', 'log' => $log]);
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
