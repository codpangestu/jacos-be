<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->staff && $leaveRequest->staff_id === $user->staff->id;
    }

    public function review(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->role === 'admin';
    }
}
