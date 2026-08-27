<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dismissal_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('pickup_qr_validity_days')->nullable()->after('staff_check_in_deadline');
            $table->unsignedTinyInteger('invoice_due_date_days')->default(10)->after('pickup_qr_validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('dismissal_settings', function (Blueprint $table) {
            $table->dropColumn(['pickup_qr_validity_days', 'invoice_due_date_days']);
        });
    }
};
