<?php

namespace App\Http\Controllers\Api\Ortu;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChildController extends Controller
{
    /**
     * FR-FE-5.5 / FR-FE §2.4 — daftar anak milik akun ortu login, dipakai child
     * switcher (#29) & consent gate (#6). Backend sebelumnya tidak punya endpoint
     * ini sama sekali (cuma endpoint per-anak yang butuh student_id di tangan).
     */
    public function index(Request $request)
    {
        $parent = $request->user();

        $children = $parent->children()
            ->with('classroom:id,name')
            ->get()
            ->map(function ($student) use ($parent) {
                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'classroom' => $student->classroom,
                    'relationship' => $student->pivot->relationship,
                    'needs_consent' => ! $student->hasActiveConsentFor($parent->id),
                ];
            });

        return response()->json(['children' => $children]);
    }
}
