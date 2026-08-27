<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    /**
     * Whether $user may view/manage attendance & profile data for $student.
     * Admin: always. Guru: only if $student is in a classroom they teach.
     * Orang tua: only if $student is a linked child AND consent aktif (FR-BE-5.5)
     * — gate utamanya di frontend (redirect setelah login), ini lapis kedua.
     */
    public function view(User $user, Student $student): bool
    {
        return match ($user->role) {
            'admin' => true,
            'guru' => $user->staff
                && $student->classroom
                && $student->classroom->homeroom_teacher_id === $user->staff->id,
            'orang_tua' => $user->children()->where('students.id', $student->id)->exists()
                && $student->hasActiveConsentFor($user->id),
            default => false,
        };
    }

    public function update(User $user, Student $student): bool
    {
        return $user->role === 'admin';
    }

    public function manage(User $user, Student $student): bool
    {
        return $this->view($user, $student);
    }
}
