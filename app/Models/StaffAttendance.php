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
}
