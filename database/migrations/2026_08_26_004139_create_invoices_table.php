<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number')->unique(); // INV/YYYY/MM/0001
            $table->string('period'); // e.g. "2026-08"
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['belum_bayar', 'lunas', 'terlambat', 'dibatalkan'])->default('belum_bayar');
            $table->date('due_date');
            $table->text('manual_note')->nullable();
            $table->foreignId('marked_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
