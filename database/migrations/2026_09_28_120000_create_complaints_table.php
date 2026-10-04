<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            // Nomor tiket yang bisa disebut orang tua/TU di telepon, mis. PGD-2026-00042.
            // Diisi di event `created` (lihat App\Models\Complaint) supaya tidak ada
            // balapan nomor urut antar request yang masuk bersamaan.
            $table->string('ticket_no')->nullable()->unique();
            // Anak yang jadi topik pengaduan. Nullable: tidak semua pengaduan
            // menempel ke satu anak (mis. soal kantin atau fasilitas umum).
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->string('category'); // akademik, keuangan, sarana, disiplin, lainnya
            $table->string('subject');
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved', 'rejected'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            // Target penyelesaian (SLA). Dihitung dari priority saat tiket dibuat.
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
