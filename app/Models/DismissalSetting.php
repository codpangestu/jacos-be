<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DismissalSetting extends Model
{
    protected $fillable = ['school_id', 'cutoff_time', 'attendance_edit_tolerance_days'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
