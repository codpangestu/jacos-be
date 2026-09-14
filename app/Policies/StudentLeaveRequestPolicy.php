<?php

namespace App\Policies;

use App\Models\StudentLeaveRequest;
use App\Models\User;

class StudentLeaveRequestPolicy
{
    public function view(User $user, StudentLeaveRequest $leaveRequest): bool
    {
        return match ($user->role) {
            'admin' => true,
            'orang_tua' => $leaveRequest->submitted_by === $user->id,
            'guru' => $this->isHomeroomTeacherOf($user, $leaveRequest),
            default => false,
        };
    }

    /**
     * Approve/reject — wali kelas siswa yang bersangkutan (Admin bisa override,
     * konsisten dengan pola bypass Admin di AttendanceController::authorizeClassroom).
     */
    public function review(User $user, StudentLeaveRequest $leaveRequest): bool
    {
        return $user->role === 'admin' || $this->isHomeroomTeacherOf($user, $leaveRequest);
    }

    private function isHomeroomTeacherOf(User $user, StudentLeaveRequest $leaveRequest): bool
    {
        return $user->role === 'guru'
            && $user->staff
            && $leaveRequest->student->classroom
            && $leaveRequest->student->classroom->homeroom_teacher_id === $user->staff->id;
    }
}
