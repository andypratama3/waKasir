<?php

namespace App\Jobs;

use App\Domain\Bot\MessageHandler;
use App\Domain\Tenant\SubscriptionService;
use App\Models\MessageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 10;

    public function __construct(
        private string $waNumber,
        private string $message,
        private string $businessId,
        private array  $metadata = [],
    ) {}

    public function handle(MessageHandler $messageHandler, SubscriptionService $subscriptionService): void
    {
        // ── 1. Log inbound message ────────────────────────────────────────
        MessageLog::create([
            'business_id'  => $this->businessId,
            'wa_number'    => $this->waNumber,
            'direction'    => 'in',
            'content'      => $this->message,
            'message_type' => $this->metadata['type'] ?? 'text',
            'metadata'     => $this->metadata,
            'timestamp'    => now(),
        ]);

        try {
            // ── 2. Run through bot engine ─────────────────────────────────
            $response = $messageHandler->handle($this->message, $this->waNumber, $this->businessId);

            // ── 3. Send reply via WhatsApp ────────────────────────────────
            if (!empty($response['qr_url'])) {
                // QR Code: send as WhatsApp image with order summary as caption
                dispatch(new SendWhatsAppNotification(
                    $this->waNumber,
                    $response['text'] ?? '',
                    $this->businessId,
                    $response['qr_url'],   // imageUrl — delivered as WA image message
                ));
            } elseif (!empty($response['text'])) {
                // Regular text reply
                dispatch(new SendWhatsAppNotification($this->waNumber, $response['text'], $this->businessId));
            }

            // ── 4. Log outbound message ───────────────────────────────────
            if (!empty($response['text'])) {
                MessageLog::create([
                    'business_id'  => $this->businessId,
                    'wa_number'    => $this->waNumber,
                    'direction'    => 'out',
                    'content'      => $response['text'],
                    'message_type' => 'text',
                    'metadata'     => [
                        'state'    => $response['state'] ?? null,
                        'qr_url'   => $response['qr_url'] ?? null,
                    ],
                    'timestamp'    => now(),
                ]);
            }

            // ── 5. Count towards subscription quota ───────────────────────
            $subscriptionService->recordUsage($this->businessId);

        } catch (\Throwable $e) {
            Log::error('ProcessIncomingWhatsAppMessage: bot processing failed', [
                'error'       => $e->getMessage(),
                'wa_number'   => $this->waNumber,
                'business_id' => $this->businessId,
                'trace'       => $e->getTraceAsString(),
            ]);

            // Send a graceful fallback so customer doesn't get silence
            dispatch(new SendWhatsAppNotification(
                $this->waNumber,
                'Maaf, terjadi gangguan teknis. Admin kami akan segera membantu Anda. 🙏',
                $this->businessId
            ));

            throw $e; // re-throw so queue retries up to $tries times
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessIncomingWhatsAppMessage permanently failed after retries', [
            'wa_number'   => $this->waNumber,
            'business_id' => $this->businessId,
            'error'       => $exception->getMessage(),
        ]);
    }
}
