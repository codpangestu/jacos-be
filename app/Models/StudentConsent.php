<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentConsent extends Model
{
    protected $fillable = ['student_id', 'parent_id', 'consented_at', 'consent_version', 'withdrawn_at'];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }
}
