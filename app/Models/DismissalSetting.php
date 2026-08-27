<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DismissalSetting extends Model
{
    protected $fillable = [
        'school_id', 'cutoff_time', 'staff_check_in_deadline', 'attendance_edit_tolerance_days',
        'pickup_qr_validity_days', 'invoice_due_date_days',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
