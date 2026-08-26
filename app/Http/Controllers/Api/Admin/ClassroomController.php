<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function index(Request $request)
    {
        $query = Classroom::with(['gradeLevel:id,name', 'academicYear:id,label', 'homeroomTeacher:id,name'])
            ->withCount('students')
            ->when($request->query('academic_year_id'), fn ($q, $id) => $q->where('academic_year_id', $id));

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'homeroom_teacher_id' => ['nullable', 'exists:staff,id'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $classroom = Classroom::create($data);

        return response()->json(['classroom' => $classroom], 201);
    }

    public function update(Request $request, Classroom $classroom)
    {
        $data = $request->validate([
            'homeroom_teacher_id' => ['nullable', 'exists:staff,id'],
            'name' => ['sometimes', 'string', 'max:100'],
        ]);

        $classroom->update($data);

        return response()->json(['classroom' => $classroom]);
    }
}
