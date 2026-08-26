<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

/**
 * FR-BE-4.6 — reminder H-3 jatuh tempo invoice yang masih belum_bayar.
 */
class SendInvoiceDueReminders extends Command
{
    protected $signature = 'invoices:send-due-reminders';

    protected $description = 'Kirim reminder untuk invoice belum_bayar yang jatuh tempo dalam 3 hari';

    public function handle(): int
    {
        $target = now()->addDays(3)->toDateString();

        $invoices = Invoice::where('status', 'belum_bayar')->where('due_date', $target)->with('student.parents')->get();

        foreach ($invoices as $invoice) {
            // TODO: dispatch push notification job ke seluruh parent siswa terkait.
            $this->line("Reminder queued for invoice {$invoice->invoice_number}");
        }

        $this->info("{$invoices->count()} reminder diproses untuk jatuh tempo {$target}.");

        return self::SUCCESS;
    }
}
