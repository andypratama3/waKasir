<?php

namespace App\Jobs;

use App\Domain\Payment\PaymentService;
use App\Models\Order;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMidtransWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $webhookData;

    public function __construct(array $webhookData)
    {
        $this->webhookData = $webhookData;
    }

    public function handle(PaymentService $paymentService): void
    {
        try {
            $result = $paymentService->handleWebhook($this->webhookData);

            if ($result) {
                $orderId = $this->webhookData['order_id'];
                $transactionStatus = $this->webhookData['transaction_status'];

                $order = Order::where('order_number', $orderId)->first();
                
                if ($order && $transactionStatus === 'settlement') {
                    // Update order status
                    $order->update([
                        'status' => 'paid',
                        'paid_at' => now()
                    ]);

                    // Get customer and conversation
                    $customer = $order->customer;
                    $conversation = $customer->conversation()->first();

                    if ($conversation) {
                        // Update conversation state
                        $conversation->update([
                            'current_state' => 'PAID_AWAITING_ADDRESS'
                        ]);

                        // Send WhatsApp notification to customer
                        dispatch(new SendWhatsAppNotification(
                            $customer->wa_number,
                            "✅ Pembayaran diterima! Sekarang kirim alamat lengkap (nama jalan, RT/RW, patokan) untuk pengiriman:",
                            $order->business_id
                        ));
                    }

                    // Send notification to business owner
                    $business = $order->business;
                    if ($business->wa_phone_number) {
                        dispatch(new SendWhatsAppNotification(
                            $business->wa_phone_number,
                            "🔔 Order baru #{$order->order_number} - Rp" . number_format($order->total_amount, 0, ',', '.') . " - LUNAS",
                            $business->id
                        ));
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to process Midtrans webhook', [
                'error' => $e->getMessage(),
                'webhook_data' => $this->webhookData,
            ]);
        }
    }
}