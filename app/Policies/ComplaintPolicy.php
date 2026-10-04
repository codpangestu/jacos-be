<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    /**
     * Lihat tiket: Admin semua, Orang Tua hanya tiket yang dia ajukan sendiri.
     */
    public function view(User $user, Complaint $complaint): bool
    {
        return $user->role === 'admin' || $complaint->submitted_by === $user->id;
    }

    /**
     * Balas tiket — kedua pihak yang berkepentingan boleh membalas.
     */
    public function reply(User $user, Complaint $complaint): bool
    {
        return $this->view($user, $complaint);
    }

    /**
     * Ubah status/priority/penanggung jawab & tulis catatan penyelesaian — Admin saja.
     */
    public function update(User $user, Complaint $complaint): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, Complaint $complaint): bool
    {
        return $user->role === 'admin';
    }
}
