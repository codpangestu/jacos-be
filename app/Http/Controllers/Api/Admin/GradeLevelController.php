<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;

class GradeLevelController extends Controller
{
    /**
     * Data referensi tetap (Kelas 1-6) — dipakai dropdown Pengaturan Biaya SPP
     * & Manajemen Kelas/Rombel. Bukan CRUD admin, cuma listing.
     */
    public function index()
    {
        return response()->json(['grade_levels' => GradeLevel::orderBy('level_number')->get()]);
    }
}
