<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'target_role', 'expires_at', 'created_by'];

    protected $casts = [
        'expires_at' => 'date',
    ];

    protected $appends = ['is_expired'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * `expires_at` berarti "berlaku sampai tanggal ini" (inklusif) — baru dianggap
     * kedaluwarsa mulai keesokan harinya, bukan begitu jam berganti di hari yang sama.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now()->startOfDay());
    }
}
