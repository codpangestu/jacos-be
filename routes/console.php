<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-BE-4.2 — generate invoice SPP tiap tanggal 1.
Schedule::command('invoices:generate-monthly')->monthlyOn(1, '01:00');

// FR-BE-4.6 — reminder jatuh tempo, dicek harian.
Schedule::command('invoices:send-due-reminders')->dailyAt('08:00');

// NFR §7.1 — retensi foto bukti, dicek tahunan (akhir tahun ajaran, disesuaikan manual per kalender akademik).
Schedule::command('photos:purge-expired')->yearlyOn(7, 1, '02:00');
