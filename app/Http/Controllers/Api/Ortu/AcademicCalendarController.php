<?php

namespace App\Http\Controllers\Api\Ortu;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarHoliday;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

class AcademicCalendarController extends Controller
{
    /**
     * Agenda mendatang (hari libur/perayaan) untuk tahun ajaran aktif — dipakai
     * kartu "Agenda" di dashboard Orang Tua. Read-only, sumber data tetap
     * `academic_calendar_holidays` yang dikelola Admin.
     */
    public function upcoming(Request $request)
    {
        $academicYear = AcademicYear::where('is_active', true)->first();

        if (! $academicYear) {
            return response()->json(['holidays' => []]);
        }

        $holidays = AcademicCalendarHoliday::where('academic_year_id', $academicYear->id)
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->limit((int) $request->query('limit', 5))
            ->get(['id', 'date', 'label']);

        return response()->json(['holidays' => $holidays]);
    }
}
