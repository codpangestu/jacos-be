<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StudentConsent;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    private const CURRENT_CONSENT_VERSION = 'v1';

    /**
     * FR-BE-5.5 — status consent semua anak milik ortu yang login.
     */
    public function index(Request $request)
    {
        $consents = StudentConsent::where('parent_id', $request->user()->id)
            ->with('student:id,name')
            ->get();

        return response()->json(['consents' => $consents]);
    }

    /**
     * FR-BE-5.5 — beri consent untuk seorang anak.
     */
    public function store(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'exists:students,id']]);

        $consent = StudentConsent::updateOrCreate(
            ['student_id' => $data['student_id'], 'parent_id' => $request->user()->id],
            ['consented_at' => now(), 'consent_version' => self::CURRENT_CONSENT_VERSION, 'withdrawn_at' => null]
        );

        return response()->json(['consent' => $consent]);
    }

    /**
     * Tarik persetujuan — dicatat di audit trail, Admin ditindaklanjuti manual.
     */
    public function withdraw(Request $request, StudentConsent $consent)
    {
        abort_if($consent->parent_id !== $request->user()->id, 403);

        $consent->update(['withdrawn_at' => now()]);

        AuditLog::record($request->user()->id, 'consent.withdrawn', StudentConsent::class, $consent->id, null, ['withdrawn_at' => $consent->withdrawn_at]);

        // TODO: dispatch notifikasi ke Admin untuk tindak lanjut manual (kebijakan retensi UU PDP).

        return response()->json(['consent' => $consent]);
    }

    /**
     * FR-BE-5.5 — status consent per siswa untuk Admin.
     */
    public function adminIndex()
    {
        return response()->json([
            'consents' => StudentConsent::with(['student:id,name', 'parent:id,name'])->get(),
        ]);
    }
}
