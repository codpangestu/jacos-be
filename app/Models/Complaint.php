<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    /**
     * Tiket pengaduan dari Orang Tua/Wali ke Tata Usaha.
     *
     * Alur status: open → in_progress → resolved | rejected.
     */
    public const STATUSES = ['open', 'in_progress', 'resolved', 'rejected'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    /** Kategori yang dikenali FE — dipakai untuk dropdown filter & form. */
    public const CATEGORIES = ['akademik', 'keuangan', 'sarana', 'disiplin', 'lainnya'];

    /** Target penyelesaian (hari) per priority — dasar kolom `due_at`. */
    public const SLA_DAYS = ['high' => 1, 'normal' => 3, 'low' => 7];

    /** Status yang dianggap tiketnya sudah tidak aktif (tidak dihitung telat). */
    public const CLOSED_STATUSES = ['resolved', 'rejected'];

    protected $fillable = [
        'ticket_no', 'student_id', 'submitted_by', 'category', 'subject', 'body',
        'attachment_path', 'status', 'priority', 'due_at', 'assigned_to',
        'resolved_at', 'resolution_note',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Nomor tiket diturunkan dari primary key, bukan dari hitungan baris
        // bulan berjalan — supaya dua pengaduan yang masuk bersamaan tidak
        // pernah dapat nomor yang sama.
        static::created(function (self $complaint): void {
            $complaint->forceFill([
                'ticket_no' => sprintf('PGD-%s-%05d', $complaint->created_at->format('Y'), $complaint->id),
            ])->saveQuietly();
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ComplaintReply::class);
    }

    /**
     * Tiket lewat target SLA. Tiket yang sudah resolved/rejected tidak pernah
     * dianggap telat walau due_at-nya sudah lewat.
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_at !== null
            && ! in_array($this->status, self::CLOSED_STATUSES, true)
            && $this->due_at->isPast();
    }

    public static function slaDueAt(string $priority): \Illuminate\Support\Carbon
    {
        return now()->addDays(self::SLA_DAYS[$priority] ?? self::SLA_DAYS['normal']);
    }
}
