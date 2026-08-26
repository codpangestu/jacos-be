<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AuthorizedPickup extends Model
{
    protected $fillable = ['student_id', 'name', 'photo_path', 'relationship', 'qr_token', 'revoked_at'];

    protected function casts(): array
    {
        return [
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

    public function pickupLogs(): HasMany
    {
        return $this->hasMany(PickupLog::class);
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at);
    }
}
