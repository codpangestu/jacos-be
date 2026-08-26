<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarHoliday;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    public function index()
    {
        return response()->json(['academic_years' => AcademicYear::withCount('holidays')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
        ]);

        if ($data['is_active'] ?? false) {
            AcademicYear::where('is_active', true)->update(['is_active' => false]);
        }

        $year = AcademicYear::create($data);

        return response()->json(['academic_year' => $year], 201);
    }

    public function holidays(AcademicYear $academicYear)
    {
        return response()->json(['holidays' => $academicYear->holidays()->orderBy('date')->get()]);
    }

    public function storeHoliday(Request $request, AcademicYear $academicYear)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $holiday = $academicYear->holidays()->create($data);

        return response()->json(['holiday' => $holiday], 201);
    }

    public function destroyHoliday(AcademicCalendarHoliday $holiday)
    {
        $holiday->delete();

        return response()->json(['message' => 'Holiday removed.']);
    }
}
