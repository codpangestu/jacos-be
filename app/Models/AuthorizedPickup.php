<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AuthorizedPickup extends Model
{
    protected $fillable = [
        'student_id', 'name', 'photo_path', 'relationship', 'qr_token',
        'approved_at', 'approved_by', 'valid_until', 'revoked_at',
    ];

    protected $appends = ['status'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'valid_until' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pickup) {
            $pickup->qr_token ??= (string) Str::uuid();
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function pickupLogs(): HasMany
    {
        return $this->hasMany(PickupLog::class);
    }

    /** pending_approval | active | revoked | expired — dipakai FE utk badge & gating aksi. */
    public function getStatusAttribute(): string
    {
        if ($this->revoked_at) {
            return 'revoked';
        }
        if (! $this->approved_at) {
            return 'pending_approval';
        }
        if ($this->valid_until && $this->valid_until->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    /** Dipakai backend (scan/manual/confirm) — sumber kebenaran, BUKAN accessor status di atas. */
    public function isActive(): bool
    {
        return is_null($this->revoked_at)
            && ! is_null($this->approved_at)
            && (! $this->valid_until || ! $this->valid_until->isPast());
    }
}
