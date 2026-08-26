<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupLog extends Model
{
    protected $fillable = [
        'student_id', 'authorized_pickup_id', 'verified_by', 'method', 'note', 'date', 'checked_out_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'checked_out_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function authorizedPickup(): BelongsTo
    {
        return $this->belongsTo(AuthorizedPickup::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
