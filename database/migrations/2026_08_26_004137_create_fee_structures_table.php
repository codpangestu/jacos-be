<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('SPP Bulanan');
            $table->decimal('monthly_amount', 12, 2);
            $table->timestamps();

            $table->unique(['grade_level_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};
