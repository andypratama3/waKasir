<?php

namespace App\Jobs;

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

    private string $waNumber;
    private string $message;
    private string $businessId;

    public function __construct(string $waNumber, string $message, string $businessId)
    {
        $this->waNumber = $waNumber;
        $this->message = $message;
        $this->businessId = $businessId;
    }

    public function handle(): void
    {
        try {
            $business = \App\Models\Business::find($this->businessId);
            
            if (!$business || !$business->wa_phone_id) {
                Log::warning('Cannot send WhatsApp: Business or phone ID not found', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            $phoneId = $business->wa_phone_id;
            $accessToken = config('services.whatsapp.access_token');
            $baseUrl = config('services.whatsapp.base_url', 'https://graph.facebook.com/v18.0');

            // Format phone number (remove + if present and ensure format)
            $phoneNumber = preg_replace('/[^0-9]/', '', $this->waNumber);
            if (!str_starts_with($phoneNumber, '62')) {
                if (str_starts_with($phoneNumber, '0')) {
                    $phoneNumber = '62' . substr($phoneNumber, 1);
                }
            }

            $response = Http::withToken($accessToken)->post("{$baseUrl}/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'text',
                'text' => [
                    'body' => $this->message
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Failed to send WhatsApp message', [
                    'response' => $response->body(),
                    'phone_number' => $phoneNumber,
                    'business_id' => $this->businessId,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Exception in SendWhatsAppNotification', [
                'error' => $e->getMessage(),
                'business_id' => $this->businessId,
                'phone_number' => $this->waNumber,
            ]);
        }
    }
}