<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = [
        'staff_id', 'type', 'start_date', 'end_date', 'reason', 'attachment_path',
        'status', 'reviewed_by', 'review_note', 'reviewed_at',
    ];

    protected $appends = ['is_long_leave'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Flag visual cuti panjang (ported dari jacos-react) — lebih dari 3 hari kerja
     * berturut-turut butuh perhatian ekstra Admin sebelum approve.
     */
    public function getIsLongLeaveAttribute(): bool
    {
        return $this->start_date->diffInDays($this->end_date) + 1 > 3;
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
