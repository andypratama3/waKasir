<?php

namespace App\Domain\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Domain\Payment\MidtransService;

class PaymentService
{
    public function __construct(
        private MidtransService $midtransService
    ) {}

    public function createPayment(Order $order, array $customerData): Payment
    {
        // Use per-business Midtrans keys if configured, otherwise fall back to global
        $business = $order->business;
        if ($business && $business->getMidtransServerKeyDecrypted()) {
            $midtrans = new MidtransService(
                serverKey:    $business->getMidtransServerKeyDecrypted(),
                clientKey:    $business->getMidtransClientKeyDecrypted() ?? '',
                isProduction: config('services.midtrans.is_production', false),
            );
        } else {
            $midtrans = $this->midtransService;
        }

        $items = $order->items->map(function ($item) {
            return [
                'id'       => $item->product_id,
                'name'     => $item->product?->name ?? 'Produk',
                'price'    => (int) $item->price_at_order,
                'quantity' => $item->qty,
            ];
        })->toArray();

        // Add shipping as item only when shipping cost > 0
        if ((int) $order->shipping_cost > 0) {
            $items[] = [
                'id'       => 'SHIPPING',
                'name'     => 'Ongkos Kirim',
                'price'    => (int) $order->shipping_cost,
                'quantity' => 1,
            ];
        }

        $midtransData = [
            'order_id' => $order->order_number,
            'amount' => (int) $order->total_amount,
            'customer_name' => $customerData['name'] ?? 'Customer',
            'customer_email' => $customerData['email'] ?? 'customer@example.com',
            'customer_phone' => $customerData['phone'] ?? '',
            'items' => $items,
        ];

        try {
            $midtransResponse = $midtrans->createTransaction($midtransData);

            $payment = Payment::create([
                'order_id' => $order->id,
                'midtrans_transaction_id' => $midtransResponse['transaction_id'] ?? null,
                'payment_type' => 'qris',
                'qr_code_url' => $midtransResponse['qr_code_url'] ?? null,
                'status' => 'pending',
                'amount' => $order->total_amount,
                'expires_at' => now()->addMinutes(15),
                'payment_details' => $midtransResponse,
            ]);

            return $payment;

        } catch (\Exception $e) {
            \Log::error('Payment creation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function handleWebhook(array $webhookData): bool
    {
        // Validate required fields before accessing them
        $orderId           = $webhookData['order_id']            ?? null;
        $transactionStatus = $webhookData['transaction_status']  ?? null;
        $statusCode        = $webhookData['status_code']         ?? null;
        $grossAmount       = $webhookData['gross_amount']        ?? null;
        $signatureKey      = $webhookData['signature_key']       ?? null;
        $fraudStatus       = $webhookData['fraud_status']        ?? null;

        if (!$orderId || !$transactionStatus || !$statusCode || !$grossAmount || !$signatureKey) {
            \Log::warning('PaymentService: incomplete webhook payload', [
                'keys_present' => array_keys($webhookData),
            ]);
            return false;
        }

        // Resolve order FIRST — need it for per-business key and null check
        $order = Order::where('order_number', $orderId)->first();

        if (!$order) {
            \Log::warning('Order not found for webhook', ['order_id' => $orderId]);
            return false;
        }

        $business = $order->business;

        $midtrans = ($business && $business->getMidtransServerKeyDecrypted())
            ? new MidtransService(
                serverKey: $business->getMidtransServerKeyDecrypted(),
                clientKey: $business->getMidtransClientKeyDecrypted() ?? '',
                isProduction: config('services.midtrans.is_production', false),
              )
            : $this->midtransService;

        // Verify signature using per-business key when available
        if (!$midtrans->verifyWebhookSignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
            \Log::warning('Invalid webhook signature', ['order_id' => $orderId]);
            return false;
        }

        $payment = $order->payment()->first();
        if (!$payment) {
            \Log::warning('Payment not found for order', ['order_id' => $orderId]);
            return false;
        }

        return $this->updatePaymentStatus($payment, $transactionStatus, $fraudStatus);
    }

    private function updatePaymentStatus(Payment $payment, string $transactionStatus, ?string $fraudStatus): bool
    {
        switch ($transactionStatus) {
            case 'capture':
                if ($fraudStatus === 'accept') {
                    $payment->update(['status' => 'paid', 'paid_at' => now()]);
                    return true;
                }
                break;
            case 'settlement':
                $payment->update(['status' => 'paid', 'paid_at' => now()]);
                return true;
            case 'pending':
                $payment->update(['status' => 'pending']);
                return true;
            case 'deny':
                $payment->update(['status' => 'failed']);
                return true;
            case 'expire':
                $payment->update(['status' => 'expired']);
                return true;
            case 'cancel':
                $payment->update(['status' => 'cancelled']);
                return true;
        }
        return false;
    }
}