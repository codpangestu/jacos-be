<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Titik tunggal buat nulis baris ke tabel `notifications` (Laravel default,
 * dibaca NotificationController@index / NotificationBell FE — lihat context.md
 * "Notification Center sudah jadi tapi backend belum pernah dispatch notifikasi
 * asli"). Dipakai di titik-titik event yang secara eksplisit disebut butuh
 * notifikasi di prd-backend.md (FR-BE-1.5/2.6/3.5/4.6) & REQUIREMENTS.md jacos-react.
 *
 * Bukan job/queue asli (masih synchronous insert) — cukup utk MVP single-tenant,
 * upgrade ke queued job kalau volume besar.
 */
class NotificationService
{
    /**
     * $url — path frontend relatif (mis. `/ortu/pickup-history`) tempat notifikasi
     * ini "mengarah" kalau diklik di Notification Drawer/Center. Opsional: kalau
     * tidak ada tujuan spesifik yang masuk akal (mis. broadcast lintas-role),
     * biarkan null — FE fallback ke Pusat Notifikasi biasa.
     */
    public static function send(User $user, string $title, string $message, ?string $type = null, ?string $url = null): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => $type ?? 'App\\Notifications\\SystemNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'message' => $message, 'url' => $url]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param iterable<User> $users */
    public static function sendMany(iterable $users, string $title, string $message, ?string $type = null, ?string $url = null): void
    {
        foreach ($users as $user) {
            self::send($user, $title, $message, $type, $url);
        }
    }
}
