<?php

namespace App\Http\Controllers\Api\Ortu;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;

class ChildController extends Controller
{
    /**
     * FR-FE-5.5 / FR-FE §2.4 — daftar anak milik akun ortu login, dipakai child
     * switcher (#29) & consent gate (#6). Backend sebelumnya tidak punya endpoint
     * ini sama sekali (cuma endpoint per-anak yang butuh student_id di tangan).
     *
     * Ported dari jacos-react parent/Dashboard.jsx: ringkasan hari-ini per anak
     * (absensi/jemput/SPP) supaya Dashboard Ortu bisa tampilkan overview semua
     * anak sekaligus, bukan cuma anak yang lagi aktif dipilih.
     */
    public function index(Request $request)
    {
        $parent = $request->user();
        $today = now()->toDateString();
        $currentPeriod = now()->format('Y-m');

        $children = $parent->children()
            ->with('classroom:id,name')
            ->with(['attendances' => fn ($q) => $q->where('date', $today)])
            ->with(['pickupLogs' => fn ($q) => $q->where('date', $today)])
            ->with(['invoices' => fn ($q) => $q->where('period', $currentPeriod)])
            ->get()
            ->map(function ($student) use ($parent) {
                $invoice = $student->invoices->first();

                return [
                    'id' => $student->id,
                    'nis' => $student->nis,
                    'name' => $student->name,
                    'gender' => $student->gender,
                    'photo_path' => $student->photo_path,
                    'classroom' => $student->classroom,
                    'relationship' => $student->pivot->relationship,
                    'needs_consent' => ! $student->hasActiveConsentFor($parent->id),
                    'today_status' => $student->attendances->first()?->status,
                    'picked_up_at' => $student->pickupLogs->first()?->checked_out_at,
                    'invoice' => $invoice ? [
                        'id' => $invoice->id,
                        'status' => $invoice->status,
                        'amount' => $invoice->amount,
                    ] : null,
                ];
            });

        return response()->json(['children' => $children]);
    }

    /**
     * Ported dari jacos-react parent/ChildProfile.jsx: detail 1 anak (data
     * pribadi + wali kelas) utk layar "Profil Anak" — sebelumnya ortu cuma bisa
     * lihat field ringkas dari index(). Reuse `StudentPolicy::view` yang sudah
     * menegakkan consent (sama seperti endpoint ortu lain).
     */
    public function show(Student $student)
    {
        $this->authorize('view', $student);

        return response()->json([
            'student' => $student->load('classroom.gradeLevel', 'classroom.homeroomTeacher'),
        ]);
    }
}
