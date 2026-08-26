<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_calendar_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('label');
            $table->timestamps();

            $table->unique(['academic_year_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_calendar_holidays');
    }
};
