<?php

namespace App\Console\Commands;

use App\Services\InvoiceGenerationService;
use Illuminate\Console\Command;

/**
 * FR-BE-4.2 — generate 1 invoice per siswa aktif, sekali per bulan.
 */
class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'invoices:generate-monthly';

    protected $description = 'Generate invoice SPP bulanan untuk seluruh siswa aktif (FR-BE-4.2)';

    public function handle(InvoiceGenerationService $service): int
    {
        $result = $service->run();

        foreach ($result['skipped'] as $name) {
            $this->warn("Skip {$name}: belum ada fee structure untuk tingkatnya.");
        }

        $this->info("Selesai. {$result['created']} invoice dibuat untuk periode {$result['period']}.");

        return self::SUCCESS;
    }
}
