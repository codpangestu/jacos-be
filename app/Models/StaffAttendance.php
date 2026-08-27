<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendance extends Model
{
    protected $fillable = [
        'staff_id', 'date', 'check_in_time', 'check_in_photo_path',
        'check_out_time', 'check_out_photo_path', 'corrected_by_admin',
    ];

    protected $appends = ['is_late'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_time' => 'datetime',
            'check_out_time' => 'datetime',
            'corrected_by_admin' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Klasifikasi terlambat (ported dari jacos-react) — bandingkan jam check-in
     * terhadap `staff_check_in_deadline` di pengaturan sekolah. Dihitung dinamis,
     * bukan kolom tersimpan, supaya perubahan pengaturan berlaku surut ke histori.
     */
    public function getIsLateAttribute(): ?bool
    {
        if (! $this->check_in_time) {
            return null;
        }

        $deadline = DismissalSetting::query()->value('staff_check_in_deadline') ?? '07:30:00';

        return $this->check_in_time->format('H:i:s') > $deadline;
    }
}
