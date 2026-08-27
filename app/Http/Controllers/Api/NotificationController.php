<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * FR-FE-1.5/2.6/3.5/4.6 — daftar notifikasi milik user login (Notification Center).
     */
    public function index(Request $request)
    {
        $paginated = $request->user()->notifications()->paginate(20);

        return response()->json([
            ...$paginated->toArray(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Notifikasi ditandai terbaca.']);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'Semua notifikasi ditandai terbaca.']);
    }
}
