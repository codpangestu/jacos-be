<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCalendarHoliday extends Model
{
    protected $fillable = ['academic_year_id', 'date', 'label'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
