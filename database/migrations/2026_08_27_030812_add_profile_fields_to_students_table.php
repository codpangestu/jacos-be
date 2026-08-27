<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('blood_type', 5)->nullable()->after('gender');
            $table->string('address')->nullable()->after('blood_type');
            $table->string('emergency_contact')->nullable()->after('address');
            $table->string('photo_path')->nullable()->after('emergency_contact');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['blood_type', 'address', 'emergency_contact', 'photo_path']);
        });
    }
};
