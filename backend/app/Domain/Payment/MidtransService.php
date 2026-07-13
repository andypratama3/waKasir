<?php

namespace App\Domain\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    private string $serverKey;
    private string $clientKey;
    private bool $isProduction;
    private string $baseUrl;

    public function __construct()
    {
        $this->serverKey = config('services.midtrans.server_key', '');
        $this->clientKey = config('services.midtrans.client_key', '');
        $this->isProduction = config('services.midtrans.is_production', false);
        $this->baseUrl = $this->isProduction 
            ? 'https://app.midtrans.com/snap/v1' 
            : 'https://app.sandbox.midtrans.com/snap/v1';
    }

    public function createTransaction(array $data): array
    {
        $transactionDetails = [
            'order_id' => $data['order_id'],
            'gross_amount' => $data['amount'],
        ];

        $customerDetails = [
            'first_name' => $data['customer_name'] ?? 'Customer',
            'email' => $data['customer_email'] ?? 'customer@example.com',
            'phone' => $data['customer_phone'] ?? '',
        ];

        $itemDetails = [];
        foreach ($data['items'] as $item) {
            $itemDetails[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
            ];
        }

        $payload = [
            'transaction_details' => $transactionDetails,
            'customer_details' => $customerDetails,
            'item_details' => $itemDetails,
            'enabled_payments' => ['qris'],
            'expiry' => [
                'unit' => 'minutes',
                'duration' => 15,
            ],
        ];

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->post("{$this->baseUrl}/transactions", $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Midtrans API Error', [
            'response' => $response->body(),
            'payload' => $payload
        ]);

        throw new \Exception('Failed to create Midtrans transaction');
    }

    public function getTransactionStatus(string $orderId): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->get("{$this->baseUrl}/transactions/{$orderId}");

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Midtrans Status Check Error', [
            'order_id' => $orderId,
            'response' => $response->body()
        ]);

        return [];
    }

    public function cancelTransaction(string $orderId): bool
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->post("{$this->baseUrl}/transactions/{$orderId}/cancel");

        return $response->successful();
    }

    public function expireTransaction(string $orderId): bool
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->serverKey . ':'),
        ])->post("{$this->baseUrl}/transactions/{$orderId}/expire");

        return $response->successful();
    }

    public function verifyWebhookSignature(string $orderId, string $statusCode, string $grossAmount, string $signatureKey): bool
    {
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);
        
        return hash_equals($expectedSignature, $signatureKey);
    }

    public function getClientKey(): string
    {
        return $this->clientKey;
    }
}