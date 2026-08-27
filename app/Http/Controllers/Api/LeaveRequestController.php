<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    /**
     * FR-BE-3.6 — riwayat cuti milik staff sendiri, atau semua (Admin, filterable status).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = LeaveRequest::with('staff:id,name');

        if ($user->role !== 'admin') {
            $staff = $user->staff;
            abort_if(! $staff, 422, 'Akun ini tidak terhubung ke data staff.');
            $query->where('staff_id', $staff->id);
        } elseif ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * FR-BE-3.4 — ajukan cuti/izin.
     */
    public function store(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(! $staff, 422, 'Akun ini tidak terhubung ke data staff.');

        $data = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'max:4096'],
        ]);

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store('leave-attachments', 'public')
            : null;

        $leaveRequest = LeaveRequest::create([
            'staff_id' => $staff->id,
            'type' => $data['type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'attachment_path' => $attachmentPath,
            'status' => 'pending',
        ]);

        NotificationService::sendMany(
            User::where('role', 'admin')->get(),
            'Pengajuan Cuti Baru',
            "{$staff->name} mengajukan {$data['type']} ({$leaveRequest->start_date->translatedFormat('d M Y')} - {$leaveRequest->end_date->translatedFormat('d M Y')}).",
            null,
            '/admin/leave-requests'
        );

        return response()->json(['leave_request' => $leaveRequest], 201);
    }

    /**
     * FR-BE-3.5 — Approve/Reject oleh Admin.
     */
    public function review(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('review', $leaveRequest);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,revision_requested'],
            'review_note' => ['required_unless:status,approved', 'nullable', 'string'],
        ]);

        $leaveRequest->update([
            'status' => $data['status'],
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $titles = [
            'approved' => 'Pengajuan Cuti Disetujui',
            'rejected' => 'Pengajuan Cuti Ditolak',
            'revision_requested' => 'Pengajuan Cuti Perlu Revisi',
        ];
        $staffUser = $leaveRequest->staff->user;
        if ($staffUser) {
            NotificationService::send(
                $staffUser,
                $titles[$data['status']],
                "Pengajuan cuti Anda ({$leaveRequest->start_date->translatedFormat('d M Y')} - {$leaveRequest->end_date->translatedFormat('d M Y')}) ".
                    ($data['review_note'] ? 'dengan catatan: '.$data['review_note'] : 'telah diproses.'),
                null,
                $staffUser->role === 'guru' ? '/guru/leave-requests' : '/staff/leave-requests'
            );
        }

        return response()->json(['leave_request' => $leaveRequest]);
    }
}
