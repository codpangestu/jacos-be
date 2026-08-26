<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('check_in_time')->nullable();
            $table->string('check_in_photo_path')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->string('check_out_photo_path')->nullable();
            $table->boolean('corrected_by_admin')->default(false);
            $table->timestamps();

            $table->unique(['staff_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendances');
    }
};
