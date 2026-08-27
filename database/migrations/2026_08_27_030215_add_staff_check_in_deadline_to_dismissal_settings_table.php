<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dismissal_settings', function (Blueprint $table) {
            $table->time('staff_check_in_deadline')->default('07:30:00')->after('cutoff_time');
        });
    }

    public function down(): void
    {
        Schema::table('dismissal_settings', function (Blueprint $table) {
            $table->dropColumn('staff_check_in_deadline');
        });
    }
};
