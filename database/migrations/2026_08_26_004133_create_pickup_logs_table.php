<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('authorized_pickup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verified_by')->constrained('users')->cascadeOnDelete();
            $table->enum('method', ['qr', 'manual']);
            $table->text('note')->nullable();
            $table->date('date');
            $table->timestamp('checked_out_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_logs');
    }
};
