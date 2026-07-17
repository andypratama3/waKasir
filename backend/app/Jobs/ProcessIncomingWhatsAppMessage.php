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
use Illuminate\Queue\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithoutOverlapping;

    public int $tries   = 3;
    public int $backoff = 10;
    public int $uniqueFor = 30; // Prevent overlapping for 30 seconds per wa_number+business_id

    public function __construct(
        private string $waNumber,
        private string $message,
        private string $businessId,
        private array  $metadata = [],
    ) {}

    /**
     * Unique lock ID — prevents concurrent processing for same customer+business.
     */
    public function uniqueId(): string
    {
        return "wa_{$this->businessId}_{$this->waNumber}";
    }

    public function handle(MessageHandler $messageHandler, SubscriptionService $subscriptionService): void
    {
        // ── Resolve conversation for logging ──────────────────────────────
        $customer     = \App\Models\Customer::where('wa_number', $this->waNumber)->where('business_id', $this->businessId)->first();
        $conversation = $customer ? \App\Models\Conversation::where('customer_id', $customer->id)->first() : null;

        // ── 1. Check subscription quota BEFORE processing ─────────────────
        $quotaStatus = $subscriptionService->checkQuota($this->businessId);

        if ($quotaStatus['is_exceeded']) {
            Log::warning('ProcessIncomingWhatsAppMessage: quota exceeded, rejecting message', [
                'business_id' => $this->businessId,
                'quota_used'  => $quotaStatus['used'],
                'quota_total' => $quotaStatus['total'],
            ]);

            // Notify the customer gracefully once per rejection
            dispatch(new SendWhatsAppNotification(
                $this->waNumber,
                "Maaf, layanan bot sedang tidak tersedia saat ini. Silakan hubungi admin toko secara langsung. 🙏",
                $this->businessId
            ));

            // Log inbound only — do NOT count against quota (it's already exceeded)
            MessageLog::create([
                'business_id'    => $this->businessId,
                'conversation_id' => $conversation?->id,
                'wa_number'      => $this->waNumber,
                'direction'      => 'in',
                'content'        => mb_substr($this->message, 0, 1000),
                'message_type'   => $this->metadata['type'] ?? 'text',
                'metadata'       => array_merge($this->metadata, ['quota_exceeded' => true]),
                'timestamp'      => now(),
            ]);

            return; // stop — do not run bot, do not increment quota
        }

        // ── 2. Log inbound message ────────────────────────────────────────
        MessageLog::create([
            'business_id'    => $this->businessId,
            'conversation_id' => $conversation?->id,
            'wa_number'      => $this->waNumber,
            'direction'      => 'in',
            'content'        => mb_substr($this->message, 0, 1000),
            'message_type'   => $this->metadata['type'] ?? 'text',
            'metadata'       => $this->metadata,
            'timestamp'      => now(),
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
                    'business_id'    => $this->businessId,
                    'conversation_id' => $conversation?->id,
                    'wa_number'      => $this->waNumber,
                    'direction'      => 'out',
                    'content'        => $response['text'],
                    'message_type'   => 'text',
                    'metadata'       => [
                        'state'    => $response['state'] ?? null,
                        'qr_url'   => $response['qr_url'] ?? null,
                    ],
                    'timestamp'      => now(),
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
