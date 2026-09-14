<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Announcement::with('creator:id,name')->latest()->paginate(20));
    }

    /**
     * Feed pengumuman untuk dashboard tiap role — hanya yang ditujukan ke role
     * pengguna atau berlaku untuk semua (target_role null), dan belum kedaluwarsa.
     */
    public function feed(Request $request)
    {
        $announcements = Announcement::where(fn ($q) => $q->whereNull('target_role')->orWhere('target_role', $request->user()->role))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()))
            ->latest()
            ->limit(5)
            ->get();

        return response()->json(['announcements' => $announcements]);
    }

    /**
     * Riwayat pengumuman penuh untuk role pengguna (termasuk yang sudah
     * kedaluwarsa) — dipakai halaman "Riwayat Pengumuman" per role.
     */
    public function history(Request $request)
    {
        $announcements = Announcement::where(fn ($q) => $q->whereNull('target_role')->orWhere('target_role', $request->user()->role))
            ->latest()
            ->paginate(15);

        return response()->json($announcements);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'target_role' => ['nullable', 'in:guru,orang_tua,staff'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $announcement = Announcement::create([...$data, 'created_by' => $request->user()->id]);

        $historyUrl = ['guru' => '/guru/announcements', 'staff' => '/staff/announcements', 'orang_tua' => '/ortu/announcements'];
        $roles = $data['target_role'] ?? null ? [$data['target_role']] : array_keys($historyUrl);

        foreach ($roles as $role) {
            NotificationService::sendMany(
                User::where('role', $role)->get(),
                'Pengumuman: '.$announcement->title,
                $announcement->body,
                null,
                $historyUrl[$role]
            );
        }

        return response()->json(['announcement' => $announcement], 201);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'target_role' => ['nullable', 'in:guru,orang_tua,staff'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $announcement->update($data);

        return response()->json(['announcement' => $announcement]);
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return response()->json(['message' => 'Pengumuman dihapus.']);
    }
}
