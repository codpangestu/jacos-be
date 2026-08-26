<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Thin wrapper around the Midtrans Snap/Core API (FR-BE-4.4, FR-BE-4.5).
 *
 * TODO: wire real Midtrans credentials (MIDTRANS_SERVER_KEY / MIDTRANS_CLIENT_KEY)
 * and replace the stub below with an actual call to the midtrans/midtrans-php SDK
 * once the gateway contract is confirmed (see PRD.md §9 asumsi — belum final).
 */
class MidtransService
{
    public function createTransaction(Invoice $invoice): array
    {
        // Stub response shape mirrors what the frontend (FR-FE-4.4) expects:
        // a redirect/token it can hand to Snap.js.
        return [
            'token' => 'stub-snap-token-'.$invoice->invoice_number,
            'redirect_url' => null,
        ];
    }

    /**
     * Verify + normalize a Midtrans webhook payload.
     * TODO: verify the `signature_key` against MIDTRANS_SERVER_KEY before trusting this.
     */
    public function parseWebhook(array $payload): array
    {
        return [
            'order_id' => $payload['order_id'] ?? null,
            'transaction_status' => $payload['transaction_status'] ?? null,
            'payment_type' => $payload['payment_type'] ?? null,
            'gross_amount' => $payload['gross_amount'] ?? null,
        ];
    }
}
