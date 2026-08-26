<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    /**
     * FR-BE-3.1 — data master staff (guru & non-guru).
     */
    public function index(Request $request)
    {
        $query = Staff::with('user:id,email')
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type));

        return response()->json($query->paginate(20));
    }

    public function show(Staff $staff)
    {
        return response()->json(['staff' => $staff->load('user:id,email', 'homeroomClassrooms')]);
    }

    /**
     * Membuat staff + akun login (invite-only, §3.1 PRD).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'type' => ['required', 'in:guru,non_guru'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'joined_at' => ['nullable', 'date'],
        ]);

        $temporaryPassword = Str::password(12);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $temporaryPassword,
            'role' => $data['type'] === 'guru' ? 'guru' : 'staff',
        ]);

        $staff = Staff::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'joined_at' => $data['joined_at'] ?? null,
        ]);

        // TODO: email kredensial awal (link set password) ke $user->email.

        return response()->json(['staff' => $staff, 'temporary_password' => $temporaryPassword], 201);
    }

    public function update(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'leave_quota' => ['nullable', 'integer', 'min:0'],
        ]);

        $staff->update($data);

        return response()->json(['staff' => $staff]);
    }
}
