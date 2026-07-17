<?php

namespace App\Domain\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    private string $serverKey;
    private string $clientKey;
    private bool $isProduction;

    /** Base URL for Core API (used for QRIS charge) */
    private string $coreBaseUrl;

    /** Base URL for transaction status/cancel/expire (v2) */
    private string $apiBaseUrl;

    public function __construct(
        string $serverKey = '',
        string $clientKey = '',
        bool $isProduction = false
    ) {
        // Support per-business key injection OR fall back to global config
        $this->serverKey    = $serverKey ?: config('services.midtrans.server_key', '');
        $this->clientKey    = $clientKey ?: config('services.midtrans.client_key', '');
        $this->isProduction = $isProduction ?: config('services.midtrans.is_production', false);

        $this->coreBaseUrl = $this->isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';

        $this->apiBaseUrl = $this->coreBaseUrl;
    }

    /**
     * Create a QRIS charge via Core API.
     * Returns ['transaction_id', 'qr_code_url', 'payment_type', ...] on success.
     */
    public function createTransaction(array $data): array
    {
        $payload = [
            'payment_type'    => 'qris',
            'transaction_details' => [
                'order_id'    => $data['order_id'],
                'gross_amount'=> (int) $data['amount'],
            ],
            'item_details' => array_map(fn ($item) => [
                'id'       => (string) $item['id'],
                'name'     => mb_substr($item['name'], 0, 50), // Midtrans max 50 chars
                'price'    => (int) $item['price'],
                'quantity' => (int) $item['quantity'],
            ], $data['items']),
            'customer_details' => [
                'first_name' => $data['customer_name'] ?? 'Customer',
                'email'      => $data['customer_email'] ?? 'customer@example.com',
                'phone'      => $data['customer_phone'] ?? '',
            ],
            'qris' => [
                'acquirer' => 'gopay', // default acquirer; gopay supports all e-wallets
            ],
            'custom_expiry' => [
                'order_time'      => now()->format('Y-m-d H:i:s O'),
                'expiry_duration' => 15,
                'unit'            => 'minute',
            ],
        ];

        $response = Http::withHeaders([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization'=> 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->post("{$this->coreBaseUrl}/charge", $payload);

        if ($response->successful()) {
            $body = $response->json();

            // Midtrans Core API returns 'actions' array with QR image URL
            $qrUrl = null;
            foreach ($body['actions'] ?? [] as $action) {
                if ($action['name'] === 'generate-qr-code') {
                    $qrUrl = $action['url'];
                    break;
                }
            }

            return [
                'transaction_id'   => $body['transaction_id'] ?? null,
                'qr_code_url'      => $qrUrl,
                'payment_type'     => $body['payment_type'] ?? 'qris',
                'transaction_status' => $body['transaction_status'] ?? 'pending',
                'raw'              => $body,
            ];
        }

        Log::error('Midtrans Core API Error', [
            'status'  => $response->status(),
            'body'    => $response->body(),
            'payload' => array_merge($payload, ['order_id' => $data['order_id']]),
        ]);

        throw new \RuntimeException(
            'Failed to create Midtrans QRIS transaction: ' . $response->body()
        );
    }

    /**
     * Get transaction status from Core API.
     */
    public function getTransactionStatus(string $orderId): array
    {
        $response = Http::withHeaders([
            'Accept'        => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->get("{$this->apiBaseUrl}/{$orderId}/status");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Midtrans Status Check Error', [
            'order_id' => $orderId,
            'status'   => $response->status(),
            'body'     => $response->body(),
        ]);

        return [];
    }

    /**
     * Cancel a pending/authorize transaction.
     */
    public function cancelTransaction(string $orderId): bool
    {
        $response = Http::withHeaders([
            'Accept'        => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->post("{$this->apiBaseUrl}/{$orderId}/cancel");

        return $response->successful();
    }

    /**
     * Verify Midtrans webhook notification signature.
     * Formula: SHA512(order_id + status_code + gross_amount + server_key)
     */
    public function verifyWebhookSignature(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $incomingSignature
    ): bool {
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);
        return hash_equals($expected, $incomingSignature);
    }

    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    public function getServerKey(): string
    {
        return $this->serverKey;
    }
}
