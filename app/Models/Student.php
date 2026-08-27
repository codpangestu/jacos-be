<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'classroom_id', 'nis', 'name', 'birth_date', 'gender', 'status', 'inactive_at',
        'blood_type', 'address', 'emergency_contact', 'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'inactive_at' => 'date',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'parent_student', 'student_id', 'parent_id')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    public function consents(): HasMany
    {
        return $this->hasMany(StudentConsent::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }

    public function authorizedPickups(): HasMany
    {
        return $this->hasMany(AuthorizedPickup::class);
    }

    public function pickupLogs(): HasMany
    {
        return $this->hasMany(PickupLog::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function hasActiveConsentFor(int $parentId): bool
    {
        return $this->consents()
            ->where('parent_id', $parentId)
            ->whereNotNull('consented_at')
            ->whereNull('withdrawn_at')
            ->exists();
    }
}
