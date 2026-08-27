<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ported from jacos-react REQUIREMENTS.md §4 (Pickup & Child Safety):
     * penjemput baru harus disetujui Admin (verifikasi dokumen ID) dulu sebelum
     * QR benar-benar bisa dipakai, dan QR punya masa berlaku (bukan permanen).
     */
    public function up(): void
    {
        Schema::table('authorized_pickups', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('relationship');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->date('valid_until')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('authorized_pickups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'valid_until']);
        });
    }
};
