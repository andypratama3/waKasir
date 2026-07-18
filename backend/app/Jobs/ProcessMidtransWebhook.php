<?php

namespace App\Jobs;

use App\Domain\Payment\PaymentService;
use App\Domain\Bot\StateMachine;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMidtransWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private array $webhookData) {}

    public function handle(PaymentService $paymentService): void
    {
        $orderId           = $this->webhookData['order_id'] ?? null;
        $transactionStatus = $this->webhookData['transaction_status'] ?? null;

        if (!$orderId || !$transactionStatus) {
            Log::warning('ProcessMidtransWebhook: missing order_id or transaction_status', $this->webhookData);
            return;
        }

        try {
            // PaymentService handles signature verification + payment record update.
            // It returns true only when a meaningful status change happened.
            $updated = $paymentService->handleWebhook($this->webhookData);

            if (!$updated) {
                return;
            }

            // Only trigger downstream actions on settlement/capture (actual payment received)
            if (!in_array($transactionStatus, ['settlement', 'capture'])) {
                return;
            }

            $order = Order::with(['customer', 'business'])
                ->where('order_number', $orderId)
                ->first();

            if (!$order) {
                Log::warning('ProcessMidtransWebhook: order not found', ['order_number' => $orderId]);
                return;
            }

            // Update order status (PaymentService already updated the Payment record)
            $order->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

            $customer     = $order->customer;
            $business     = $order->business;

            // Notify customer — move to PAID_AWAITING_ADDRESS state via conversation
            if ($customer?->wa_number) {
                $total = 'Rp' . number_format($order->total_amount, 0, ',', '.');
                $msg   = "✅ *Pembayaran diterima!*\n\n"
                       . "Order *#{$order->order_number}* sebesar *{$total}* telah terkonfirmasi.\n\n"
                       . "Sekarang kirim *alamat lengkap* Anda (nama jalan, nomor rumah, RT/RW, kelurahan, kota, kode pos) untuk kami proses pengirimannya:";

                dispatch(new SendWhatsAppNotification($customer->wa_number, $msg, $order->business_id));

                // Update conversation state to PAID_AWAITING_ADDRESS
                $customer->conversations()->latest()->first()?->update([
                    'current_state' => StateMachine::STATES['PAID_AWAITING_ADDRESS'],
                ]);
            }

            // Notify business owner
            if ($business?->wa_phone_number) {
                $total   = 'Rp' . number_format($order->total_amount, 0, ',', '.');
                $buyerWa = $customer?->wa_number ?? '-';
                $ownerMsg = "🔔 *Order baru LUNAS!*\n\n"
                           . "Order: *#{$order->order_number}*\n"
                           . "Total: *{$total}*\n"
                           . "Pembeli: {$buyerWa}\n\n"
                           . "Segera proses pengiriman setelah alamat diterima.";

                dispatch(new SendWhatsAppNotification($business->wa_phone_number, $ownerMsg, $order->business_id));
            }

        } catch (\Throwable $e) {
            Log::error('ProcessMidtransWebhook failed', [
                'error'        => $e->getMessage(),
                'order_id'     => $orderId,
                'webhook_data' => $this->webhookData,
            ]);

            throw $e; // re-throw so queue retries
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessMidtransWebhook permanently failed', [
            'error'        => $exception->getMessage(),
            'webhook_data' => $this->webhookData,
        ]);
    }
}
