<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ParentController extends Controller
{
    public function index()
    {
        return response()->json(User::where('role', 'orang_tua')->with('children:id,name')->paginate(20));
    }

    /**
     * §3.1 PRD — Admin membuat akun ortu (invite-only) + kaitkan ke siswa.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:students,id'],
            'relationship' => ['nullable', 'string', 'max:100'],
        ]);

        $temporaryPassword = Str::password(12);

        $parent = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $temporaryPassword,
            'role' => 'orang_tua',
        ]);

        foreach ($data['student_ids'] as $studentId) {
            $parent->children()->attach($studentId, ['relationship' => $data['relationship'] ?? null]);
        }

        // TODO: email kredensial awal ke $parent->email.

        return response()->json(['parent' => $parent->load('children'), 'temporary_password' => $temporaryPassword], 201);
    }

    public function linkChild(Request $request, User $parent)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'relationship' => ['nullable', 'string', 'max:100'],
        ]);

        $parent->children()->syncWithoutDetaching([$data['student_id'] => ['relationship' => $data['relationship'] ?? null]]);

        return response()->json(['parent' => $parent->load('children')]);
    }
}
