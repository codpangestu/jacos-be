<?php

namespace App\Services;

use App\Models\DismissalSetting;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * FR-BE-4.2 — generate 1 invoice per siswa aktif untuk periode bulan berjalan.
 * Dipakai bareng oleh scheduled command (invoices:generate-monthly) dan aksi
 * manual Admin ("Generate Invoices" di dashboard keuangan) — idempotent, invoice
 * yang sudah ada untuk periode yang sama tidak dibuat ulang.
 */
class InvoiceGenerationService
{
    public function run(): array
    {
        $period = now()->format('Y-m');
        $dueDateDays = DismissalSetting::query()->value('invoice_due_date_days') ?? 10;
        $dueDate = now()->addDays($dueDateDays)->toDateString();

        $students = Student::where('status', 'active')->with('classroom.gradeLevel', 'parents')->get();
        $created = 0;
        $skipped = [];

        foreach ($students as $student) {
            if (Invoice::where('student_id', $student->id)->where('period', $period)->exists()) {
                continue;
            }

            $gradeLevelId = $student->classroom?->grade_level_id;
            $amount = FeeStructure::where('grade_level_id', $gradeLevelId)->value('monthly_amount');

            if (! $amount) {
                $skipped[] = $student->name;

                continue;
            }

            $invoice = DB::transaction(fn () => Invoice::create([
                'student_id' => $student->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'period' => $period,
                'amount' => $amount,
                'status' => 'belum_bayar',
                'due_date' => $dueDate,
            ]));

            NotificationService::sendMany(
                $student->parents,
                'Tagihan Baru Terbit',
                "Tagihan SPP {$student->name} periode {$period} sebesar Rp".number_format((float) $amount, 0, ',', '.').
                    ' telah terbit. Jatuh tempo '.$invoice->due_date->translatedFormat('d M Y').'.',
                null,
                '/ortu/invoices'
            );

            $created++;
        }

        return ['period' => $period, 'created' => $created, 'skipped' => $skipped];
    }

    private function nextInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');

        $lastSequence = Invoice::where('invoice_number', 'like', "INV/{$year}/{$month}/%")->count();
        $sequence = str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);

        return "INV/{$year}/{$month}/{$sequence}";
    }
}
