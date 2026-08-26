<?php

namespace App\Console\Commands;

use App\Models\AuthorizedPickup;
use App\Models\StaffAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * NFR §7.1 — retensi foto bukti (selfie absensi staff, foto penjemput) 1 tahun ajaran berjalan.
 * Dijalankan tahunan (akhir tahun ajaran).
 */
class PurgeExpiredAttendancePhotos extends Command
{
    protected $signature = 'photos:purge-expired {--months=12}';

    protected $description = 'Hapus foto bukti absensi staff & foto penjemput yang lebih tua dari retensi';

    public function handle(): int
    {
        $cutoff = now()->subMonths((int) $this->option('months'));
        $purged = 0;

        StaffAttendance::where('date', '<', $cutoff->toDateString())
            ->whereNotNull('check_in_photo_path')
            ->orWhereNotNull('check_out_photo_path')
            ->chunkById(100, function ($attendances) use (&$purged) {
                foreach ($attendances as $attendance) {
                    foreach (['check_in_photo_path', 'check_out_photo_path'] as $field) {
                        if ($attendance->$field) {
                            Storage::disk('public')->delete($attendance->$field);
                            $attendance->update([$field => null]);
                            $purged++;
                        }
                    }
                }
            });

        AuthorizedPickup::whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->whereNotNull('photo_path')
            ->chunkById(100, function ($pickups) use (&$purged) {
                foreach ($pickups as $pickup) {
                    Storage::disk('public')->delete($pickup->photo_path);
                    $pickup->update(['photo_path' => null]);
                    $purged++;
                }
            });

        $this->info("Selesai. {$purged} file foto dihapus.");

        return self::SUCCESS;
    }
}
