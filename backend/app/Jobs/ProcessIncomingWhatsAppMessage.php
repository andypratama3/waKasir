<?php

namespace App\Jobs;

use App\Domain\Bot\MessageHandler;
use App\Models\MessageLog;
use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $waNumber;
    private string $message;
    private string $businessId;
    private array $metadata;

    public function __construct(string $waNumber, string $message, string $businessId, array $metadata = [])
    {
        $this->waNumber = $waNumber;
        $this->message = $message;
        $this->businessId = $businessId;
        $this->metadata = $metadata;
    }

    public function handle(MessageHandler $messageHandler): void
    {
        try {
            // Log incoming message
            MessageLog::create([
                'business_id' => $this->businessId,
                'direction' => 'in',
                'content' => $this->message,
                'message_type' => 'text',
                'metadata' => $this->metadata,
                'timestamp' => now(),
            ]);

            // Process message through bot
            $response = $messageHandler->handle($this->message, $this->waNumber, $this->businessId);

            // Send response via WhatsApp
            if (isset($response['text'])) {
                dispatch(new SendWhatsAppNotification($this->waNumber, $response['text'], $this->businessId));
            }

            // Log outgoing message
            if (isset($response['text'])) {
                MessageLog::create([
                    'business_id' => $this->businessId,
                    'direction' => 'out',
                    'content' => $response['text'],
                    'message_type' => 'text',
                    'metadata' => ['state' => $response['state'] ?? null],
                    'timestamp' => now(),
                ]);
            }

            // Record usage
            $subscriptionService = app(\App\Domain\Tenant\SubscriptionService::class);
            $subscriptionService->recordUsage($this->businessId);

        } catch (\Exception $e) {
            Log::error('Failed to process WhatsApp message', [
                'error' => $e->getMessage(),
                'wa_number' => $this->waNumber,
                'business_id' => $this->businessId,
            ]);
            
            // Send fallback message
            dispatch(new SendWhatsAppNotification(
                $this->waNumber, 
                'Maaf, terjadi kesalahan. Admin kami akan segera membantu Anda.',
                $this->businessId
            ));
        }
    }
}