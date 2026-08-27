<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending', 'approved', 'rejected', 'revision_requested') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
    }
};
