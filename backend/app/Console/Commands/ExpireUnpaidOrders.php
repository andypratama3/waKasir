<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Log;

#[Signature('orders:expire')]
#[Description('Expire pending orders whose QRIS payment window (15 min) has passed. Notifies customers via WhatsApp and updates conversation state to EXPIRED.')]
class ExpireUnpaidOrders extends Command
{
    public function handle(): int
    {
        // Find orders still in 'pending' status, created >15 minutes ago
        $cutoff = now()->subMinutes(15);

        $expiredOrders = Order::with(['customer', 'business', 'payment'])
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('No expired orders found.');
            return self::SUCCESS;
        }

        $this->info("Found {$expiredOrders->count()} expired order(s). Processing...");

        foreach ($expiredOrders as $order) {
            try {
                // 1. Update order status
                $order->update(['status' => 'expired']);

                // 2. Expire the payment record
                if ($order->payment) {
                    $order->payment->update(['status' => 'expired']);
                }

                // 3. Update conversation state → EXPIRED
                $customer = $order->customer;
                if ($customer) {
                    $conversation = $customer->conversations()->latest()->first();
                    if ($conversation && $conversation->current_state === 'AWAITING_PAYMENT') {
                        $conversation->update(['current_state' => 'EXPIRED']);
                    }
                }

                // 4. Notify customer via WhatsApp
                if ($customer?->wa_number && $order->business_id) {
                    $msg = "⏰ *QR Code Kedaluwarsa*\n\n"
                         . "QR pembayaran untuk order *#{$order->order_number}* telah kedaluwarsa setelah 15 menit.\n\n"
                         . "Ketik *menu* di chat ini untuk membuat pesanan baru. 🙏";

                    dispatch(new SendWhatsAppNotification(
                        $customer->wa_number,
                        $msg,
                        $order->business_id,
                    ));
                }

                $this->line("  ✓ Order #{$order->order_number} expired.");

                Log::info('ExpireUnpaidOrders: expired order', [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'business_id'  => $order->business_id,
                ]);

            } catch (\Throwable $e) {
                $this->error("  ✗ Failed to expire order #{$order->order_number}: " . $e->getMessage());
                Log::error('ExpireUnpaidOrders: failed', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
