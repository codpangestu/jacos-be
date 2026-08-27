<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    /**
     * Dashboard Guru (§8.3 #23) & Input Absensi (#24) — rombel yang diampu guru login.
     */
    public function index(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(! $staff, 422, 'Akun ini tidak terhubung ke data staff.');

        return response()->json([
            'classrooms' => $staff->homeroomClassrooms()->with('gradeLevel')->get(),
        ]);
    }
}
