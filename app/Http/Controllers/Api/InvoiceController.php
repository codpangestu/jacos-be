<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GradeLevel;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\InvoiceGenerationService;
use App\Services\MidtransService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * FR-BE-4.3 — daftar invoice anak (Orang Tua), scoped ke anak yang terhubung.
     */
    public function forChild(Request $request, Student $student)
    {
        $this->authorize('view', $student);

        $query = $student->invoices()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['payments' => fn ($q) => $q->where('status', 'settlement')->latest('paid_at')->limit(1)])
            ->latest('due_date');

        $invoices = $query->get()->map(function (Invoice $invoice) {
            $settled = $invoice->payments->first();
            $data = $invoice->toArray();
            unset($data['payments']);
            $data['paid_at'] = $settled?->paid_at;
            $data['payment_method'] = $settled?->method;

            return $data;
        });

        return response()->json(['invoices' => $invoices]);
    }

    /**
     * FR-BE-4.8 — daftar semua invoice (Admin), filterable.
     */
    public function index(Request $request)
    {
        $query = Invoice::with('student:id,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        return response()->json($query->latest('due_date')->paginate(20));
    }

    /**
     * FR-BE-4.4 — buat transaksi pembayaran untuk nominal penuh invoice.
     */
    public function pay(Request $request, Invoice $invoice, MidtransService $midtrans)
    {
        $this->authorize('view', $invoice->student);

        if ($invoice->status !== 'belum_bayar') {
            return response()->json(['message' => 'Invoice ini tidak bisa dibayar (status: '.$invoice->status.').'], 422);
        }

        $transaction = $midtrans->createTransaction($invoice);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'status' => 'pending',
            'gateway_reference' => $transaction['token'],
        ]);

        return response()->json($transaction);
    }

    /**
     * FR-BE-4.5 — webhook Midtrans, idempotent.
     */
    public function webhook(Request $request, MidtransService $midtrans)
    {
        $parsed = $midtrans->parseWebhook($request->all());

        $payment = Payment::where('gateway_reference', $parsed['order_id'])->latest()->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment reference not found.'], 404);
        }

        if ($payment->status === 'settlement') {
            return response()->json(['message' => 'Already processed.']);
        }

        if ($parsed['transaction_status'] === 'settlement') {
            $payment->update([
                'status' => 'settlement',
                'method' => $parsed['payment_type'] ?? $payment->method,
                'paid_at' => now(),
                'raw_payload' => $parsed,
            ]);
            $payment->invoice->update(['status' => 'lunas']);

            $invoice = $payment->invoice->load('student.parents');
            NotificationService::sendMany(
                $invoice->student->parents,
                'Pembayaran Berhasil',
                "Pembayaran tagihan {$invoice->invoice_number} periode {$invoice->period} sebesar Rp".
                    number_format((float) $invoice->amount, 0, ',', '.').' telah dikonfirmasi. Terima kasih.',
                null,
                '/ortu/payments/history'
            );
        } elseif (in_array($parsed['transaction_status'], ['expire', 'cancel', 'deny'], true)) {
            $payment->update(['status' => 'failed', 'raw_payload' => $parsed]);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * FR-BE-4.9 — tandai lunas manual (pembayaran offline/tunai).
     */
    public function markPaid(Request $request, Invoice $invoice)
    {
        if ($request->user()->role !== 'admin') {
            abort(403);
        }

        $data = $request->validate(['note' => ['required', 'string']]);

        $before = $invoice->only('status');

        $invoice->update([
            'status' => 'lunas',
            'manual_note' => $data['note'],
            'marked_paid_by' => $request->user()->id,
        ]);

        AuditLog::record($request->user()->id, 'invoice.mark_paid_manual', Invoice::class, $invoice->id, $before, $invoice->only('status'));

        return response()->json(['invoice' => $invoice]);
    }

    /**
     * FR-BE-4.7 — kwitansi sederhana (JSON dulu; render PDF menyusul format final dikonfirmasi).
     */
    public function receipt(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice->student);

        $settledPayment = $invoice->payments()->where('status', 'settlement')->latest('paid_at')->first();

        return response()->json([
            'invoice_number' => $invoice->invoice_number,
            'student_name' => $invoice->student->name,
            'period' => $invoice->period,
            'amount' => $invoice->amount,
            'status' => $invoice->status,
            'paid_at' => $settledPayment?->paid_at,
            'method' => $settledPayment?->method,
        ]);
    }

    /**
     * Aksi manual Admin "Generate Invoices" (ported dari jacos-react — melengkapi
     * cron `invoices:generate-monthly` untuk kasus perlu generate ulang di luar jadwal).
     * Idempotent: invoice yang sudah ada untuk periode berjalan tidak dibuat ulang.
     */
    public function generate(Request $request, InvoiceGenerationService $service)
    {
        $result = $service->run();

        AuditLog::record($request->user()->id, 'invoice.generate_manual', Invoice::class, null, null, $result);

        return response()->json($result);
    }

    /**
     * FR-BE-4.8 — dashboard keuangan, dilengkapi dengan analitik (ported dari
     * jacos-react finance/Dashboard.jsx): collection rate, breakdown per tingkat,
     * breakdown per kanal pembayaran, dan tren 6 bulan terakhir.
     */
    public function dashboard()
    {
        $totalInvoices = Invoice::count();
        $totalBilled = (float) Invoice::sum('amount');
        $totalPaid = (float) Invoice::where('status', 'lunas')->sum('amount');
        $totalOutstanding = (float) Invoice::where('status', 'belum_bayar')->sum('amount');
        $overdueCount = Invoice::where('status', 'belum_bayar')->where('due_date', '<', now())->count();

        $byGrade = GradeLevel::query()
            ->get()
            ->map(function (GradeLevel $grade) {
                $studentIds = Student::whereHas('classroom', fn ($q) => $q->where('grade_level_id', $grade->id))->pluck('id');
                $billed = Invoice::whereIn('student_id', $studentIds)->sum('amount');
                $paid = Invoice::whereIn('student_id', $studentIds)->where('status', 'lunas')->sum('amount');

                return [
                    'grade_level' => $grade->name,
                    'billed' => (float) $billed,
                    'paid' => (float) $paid,
                    'collection_rate' => $billed > 0 ? round($paid / $billed * 100, 1) : 0,
                ];
            })
            ->filter(fn ($row) => $row['billed'] > 0)
            ->values();

        $byChannel = Payment::where('status', 'settlement')
            ->selectRaw('method, count(*) as count, sum(amount) as total')
            ->groupBy('method')
            ->get()
            ->map(fn ($row) => ['method' => $row->method, 'count' => $row->count, 'total' => (float) $row->total]);

        $trend = collect(range(5, 0))->map(function (int $monthsAgo) {
            $period = now()->subMonths($monthsAgo)->format('Y-m');
            $billed = (float) Invoice::where('period', $period)->sum('amount');
            $paid = (float) Invoice::where('period', $period)->where('status', 'lunas')->sum('amount');

            return [
                'period' => $period,
                'billed' => $billed,
                'paid' => $paid,
                'collection_rate' => $billed > 0 ? round($paid / $billed * 100, 1) : 0,
            ];
        })->values();

        return response()->json([
            'total_invoices' => $totalInvoices,
            'total_paid' => $totalPaid,
            'total_outstanding' => $totalOutstanding,
            'overdue_count' => $overdueCount,
            'collection_rate' => $totalBilled > 0 ? round($totalPaid / $totalBilled * 100, 1) : 0,
            'by_grade' => $byGrade,
            'by_channel' => $byChannel,
            'trend' => $trend,
        ]);
    }
}
