<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versi asli migrasi ini memakai `ALTER TABLE ... MODIFY ... ENUM` yang hanya
     * ada di MySQL. Dampaknya: setiap test yang memakai `RefreshDatabase` gagal
     * sebelum sempat jalan, karena `phpunit.xml` mengarahkan DB ke SQLite
     * (`DB_CONNECTION=sqlite`, `:memory:`). Test suite jadi praktis tidak bisa
     * dipakai selama ini. Sekarang bercabang per driver; perilaku di MySQL sama
     * persis seperti sebelumnya.
     */
    public function up(): void
    {
        $this->setStatus(['pending', 'approved', 'rejected', 'revision_requested']);
    }

    public function down(): void
    {
        $this->setStatus(['pending', 'approved', 'rejected']);
    }

    /** @param list<string> $values */
    private function setStatus(array $values): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite menyimpan enum sebagai varchar + CHECK constraint, jadi
            // daftar nilainya hanya bisa diganti dengan membangun ulang kolomnya.
            // Laravel menangani rebuild tabelnya di balik `->change()`.
            Schema::table('leave_requests', function (Blueprint $table) use ($values) {
                $table->enum('status', $values)->default('pending')->change();
            });

            return;
        }

        $list = implode("', '", $values);
        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('{$list}') DEFAULT 'pending'");
    }
};
