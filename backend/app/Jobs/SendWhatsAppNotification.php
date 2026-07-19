<?php

namespace App\Jobs;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60; // seconds between retries

    /**
     * @param string $waNumber    Recipient WhatsApp number
     * @param string $message     Text message body
     * @param string $businessId  Business whose WA token & phone_id to use
     * @param string|null $imageUrl  Optional image URL to send (e.g. QR code). If set, sends image first then text caption
     * @param string $messageType 'text' | 'image'
     */
    public function __construct(
        private string  $waNumber,
        private string  $message,
        private string  $businessId,
        private ?string $imageUrl    = null,
        private string  $messageType = 'text',
    ) {}

    public function handle(): void
    {
        try {
            $business = Business::find($this->businessId);

            if (!$business || !$business->wa_phone_id) {
                Log::warning('SendWhatsAppNotification: Business or wa_phone_id not found', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            $phoneId  = $business->wa_phone_id;
            $baseUrl  = config('services.whatsapp.base_url', 'https://graph.facebook.com/v18.0');

            // ── Resolve access token: prefer per-client token, fall back to global ──
            $accessToken = $business->getWaAccessTokenDecrypted()
                ?? config('services.whatsapp.access_token', '');

            if (empty($accessToken)) {
                Log::warning('SendWhatsAppNotification: No access token available', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            // ── Normalize phone number ────────────────────────────────────────
            $phoneNumber = preg_replace('/[^0-9]/', '', $this->waNumber);
            if (!str_starts_with($phoneNumber, '62')) {
                if (str_starts_with($phoneNumber, '0')) {
                    $phoneNumber = '62' . substr($phoneNumber, 1);
                } else {
                    $phoneNumber = '62' . $phoneNumber;
                }
            }

            $endpoint = "{$baseUrl}/{$phoneId}/messages";
            $headers  = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ];

            // ── Send image first (if provided) ───────────────────────────────
            if (!empty($this->imageUrl)) {
                $imagePayload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phoneNumber,
                    'type'              => 'image',
                    'image'             => [
                        'link'    => $this->imageUrl,
                        'caption' => $this->message, // Use message as caption
                    ],
                ];

                $imgResponse = Http::withHeaders($headers)->post($endpoint, $imagePayload);

                if (!$imgResponse->successful()) {
                    Log::error('SendWhatsAppNotification: Failed to send image, falling back to text', [
                        'status'      => $imgResponse->status(),
                        'response'    => $imgResponse->body(),
                        'business_id' => $this->businessId,
                        'image_url'   => $this->imageUrl,
                    ]);
                    // Fall back to sending text only
                    try {
                        $this->sendTextMessage($endpoint, $headers, $phoneNumber);
                    } catch (\Exception $e) {
                        Log::error('SendWhatsAppNotification: Text fallback also failed', [
                            'error'       => $e->getMessage(),
                            'business_id' => $this->businessId,
                        ]);
                        throw $e;
                    }
                }

                return; // Image sent with caption — done
            }

            // ── Send plain text message ───────────────────────────────────────
            $this->sendTextMessage($endpoint, $headers, $phoneNumber);

        } catch (\Exception $e) {
            Log::error('SendWhatsAppNotification: Exception', [
                'error'       => $e->getMessage(),
                'business_id' => $this->businessId,
                'phone'       => $this->waNumber,
            ]);
            throw $e; // allow queue retry
        }
    }

    private function sendTextMessage(string $endpoint, array $headers, string $phoneNumber): void
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phoneNumber,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $this->message,
            ],
        ];

        $response = Http::withHeaders($headers)->post($endpoint, $payload);

        if (!$response->successful()) {
            Log::error('SendWhatsAppNotification: Failed to send text', [
                'response'    => $response->body(),
                'phone'       => $phoneNumber,
                'business_id' => $this->businessId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('SendWhatsAppNotification permanently failed', [
            'error'       => $exception->getMessage(),
            'business_id' => $this->businessId,
            'phone'       => $this->waNumber,
        ]);
    }
}