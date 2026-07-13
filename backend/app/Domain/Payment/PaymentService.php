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
        $items = $order->items->map(function ($item) {
            return [
                'id' => $item->product_id,
                'name' => $item->product->name,
                'price' => (int) $item->price_at_order,
                'quantity' => $item->qty,
            ];
        })->toArray();

        // Add shipping as item
        $items[] = [
            'id' => 'SHIPPING',
            'name' => 'Ongkos Kirim',
            'price' => (int) $order->shipping_cost,
            'quantity' => 1,
        ];

        $midtransData = [
            'order_id' => $order->order_number,
            'amount' => (int) $order->total_amount,
            'customer_name' => $customerData['name'] ?? 'Customer',
            'customer_email' => $customerData['email'] ?? 'customer@example.com',
            'customer_phone' => $customerData['phone'] ?? '',
            'items' => $items,
        ];

        try {
            $midtransResponse = $this->midtransService->createTransaction($midtransData);

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
        $orderId = $webhookData['order_id'];
        $transactionStatus = $webhookData['transaction_status'];
        $fraudStatus = $webhookData['fraud_status'] ?? null;
        $signatureKey = $webhookData['signature_key'];

        // Verify signature
        if (!$this->midtransService->verifyWebhookSignature(
            $orderId,
            $webhookData['status_code'],
            $webhookData['gross_amount'],
            $signatureKey
        )) {
            \Log::warning('Invalid webhook signature', ['order_id' => $orderId]);
            return false;
        }

        $order = Order::where('order_number', $orderId)->first();
        if (!$order) {
            \Log::warning('Order not found for webhook', ['order_id' => $orderId]);
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
                    $payment->update([
                        'status' => 'paid',
                        'paid_at' => now()
                    ]);
                    return true;
                }
                break;

            case 'settlement':
                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now()
                ]);
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

    public function checkPaymentStatus(string $orderId): array
    {
        return $this->midtransService->getTransactionStatus($orderId);
    }

    public function cancelPayment(string $orderId): bool
    {
        return $this->midtransService->cancelTransaction($orderId);
    }
}