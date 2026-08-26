<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\MidtransService;
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
            ->latest('due_date');

        return response()->json(['invoices' => $query->get()]);
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
            $payment->update(['status' => 'settlement', 'paid_at' => now(), 'raw_payload' => $parsed]);
            $payment->invoice->update(['status' => 'lunas']);
            // TODO: dispatch push notification "konfirmasi pembayaran berhasil" (FR-BE-4.6).
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

        return response()->json([
            'invoice_number' => $invoice->invoice_number,
            'student_name' => $invoice->student->name,
            'period' => $invoice->period,
            'amount' => $invoice->amount,
            'status' => $invoice->status,
            'paid_at' => $invoice->payments()->where('status', 'settlement')->value('paid_at'),
        ]);
    }

    /**
     * FR-BE-4.8 — dashboard keuangan ringkas.
     */
    public function dashboard()
    {
        return response()->json([
            'total_invoices' => Invoice::count(),
            'total_paid' => Invoice::where('status', 'lunas')->sum('amount'),
            'total_outstanding' => Invoice::where('status', 'belum_bayar')->sum('amount'),
            'overdue_count' => Invoice::where('status', 'belum_bayar')->where('due_date', '<', now())->count(),
        ]);
    }
}
