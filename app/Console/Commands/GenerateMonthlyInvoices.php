<?php

namespace App\Console\Commands;

use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FR-BE-4.2 — generate 1 invoice per siswa aktif, sekali per bulan.
 */
class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'invoices:generate-monthly';

    protected $description = 'Generate invoice SPP bulanan untuk seluruh siswa aktif (FR-BE-4.2)';

    public function handle(): int
    {
        $period = now()->format('Y-m');
        $dueDate = now()->addDays(10)->toDateString();

        $students = Student::where('status', 'active')->with('classroom.gradeLevel')->get();
        $created = 0;

        foreach ($students as $student) {
            if (Invoice::where('student_id', $student->id)->where('period', $period)->exists()) {
                continue; // already generated this month
            }

            $gradeLevelId = $student->classroom?->grade_level_id;
            $amount = FeeStructure::where('grade_level_id', $gradeLevelId)->value('monthly_amount');

            if (! $amount) {
                $this->warn("Skip {$student->name}: belum ada fee structure untuk tingkatnya.");

                continue;
            }

            DB::transaction(function () use ($student, $period, $dueDate, $amount) {
                Invoice::create([
                    'student_id' => $student->id,
                    'invoice_number' => $this->nextInvoiceNumber(),
                    'period' => $period,
                    'amount' => $amount,
                    'status' => 'belum_bayar',
                    'due_date' => $dueDate,
                ]);
            });

            $created++;
            // TODO: dispatch push notification "tagihan baru terbit" (FR-BE-4.6).
        }

        $this->info("Selesai. {$created} invoice dibuat untuk periode {$period}.");

        return self::SUCCESS;
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
