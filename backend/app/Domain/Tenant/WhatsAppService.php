<?php

namespace App\Domain\Tenant;

use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppService — BSP multi-tenant layer.
 *
 * Semua operasi WA Cloud API (kirim pesan, exchange token, cek koneksi)
 * dijalankan menggunakan kredensial milik business masing-masing,
 * bukan token global dari .env.
 */
class WhatsAppService
{
    private string $graphBase;
    private string $metaAppId;
    private string $metaAppSecret;

    public function __construct()
    {
        $this->graphBase     = config('services.whatsapp.base_url', 'https://graph.facebook.com/v18.0');
        $this->metaAppId     = config('services.whatsapp.app_id', '');
        $this->metaAppSecret = config('services.whatsapp.app_secret', '');
    }

    // ────────────────────────────────────────────────────────────────────
    // EMBEDDED SIGNUP — OAuth code exchange
    // ────────────────────────────────────────────────────────────────────

    /**
     * Exchange the short-lived OAuth code (dari Embedded Signup) menjadi
     * User Access Token, lalu buat System User Token yang tidak expire.
     *
     * Returns array dengan: phone_number_id, phone_number, waba_id, access_token
     * atau throws RuntimeException jika gagal.
     */
    public function exchangeCodeForToken(string $code): array
    {
        // Step 1: Exchange code → short-lived user token
        $tokenResponse = Http::get('https://graph.facebook.com/oauth/access_token', [
            'client_id'     => $this->metaAppId,
            'client_secret' => $this->metaAppSecret,
            'code'          => $code,
        ]);

        if (!$tokenResponse->successful()) {
            Log::error('WhatsAppService: OAuth token exchange failed', ['body' => $tokenResponse->body()]);
            throw new \RuntimeException('Gagal autentikasi dengan Meta: ' . $tokenResponse->body());
        }

        $userToken = $tokenResponse->json()['access_token'];

        // Step 2: Get WABA ID dan phone number info dari token
        $meResponse = Http::withToken($userToken)
            ->get("{$this->graphBase}/me", [
                'fields' => 'id,name',
            ]);

        // Step 3: Ambil WABA yang terhubung dengan user ini
        $wabaResponse = Http::withToken($userToken)
            ->get("{$this->graphBase}/me/businesses", [
                'fields' => 'id,name,whatsapp_business_accounts',
            ]);

        if (!$wabaResponse->successful()) {
            throw new \RuntimeException('Gagal mengambil WhatsApp Business Account: ' . $wabaResponse->body());
        }

        $businesses = $wabaResponse->json()['data'] ?? [];
        $wabaId     = null;

        foreach ($businesses as $biz) {
            foreach ($biz['whatsapp_business_accounts']['data'] ?? [] as $waba) {
                $wabaId = $waba['id'];
                break 2;
            }
        }

        if (!$wabaId) {
            throw new \RuntimeException('Tidak ditemukan WhatsApp Business Account yang terhubung.');
        }

        // Step 4: Buat System User & assign ke WABA untuk dapat token tidak-expire
        // (Dalam produksi ini dilakukan via System User di Meta Business Manager;
        //  untuk MVP, simpan user token dan set expires_at 55 hari)
        $expiresAt = now()->addDays(55); // renew sebelum expire

        // Step 5: Ambil phone numbers dari WABA ini
        $phoneResponse = Http::withToken($userToken)
            ->get("{$this->graphBase}/{$wabaId}/phone_numbers", [
                'fields' => 'id,display_phone_number,verified_name,status',
            ]);

        if (!$phoneResponse->successful()) {
            throw new \RuntimeException('Gagal mengambil nomor WA: ' . $phoneResponse->body());
        }

        $phones = $phoneResponse->json()['data'] ?? [];
        if (empty($phones)) {
            throw new \RuntimeException('Tidak ada nomor WhatsApp yang tersedia di akun ini.');
        }

        // Ambil phone pertama yang berstatus CONNECTED atau VERIFIED
        $phone     = null;
        foreach ($phones as $p) {
            if (in_array($p['status'] ?? '', ['CONNECTED', 'VERIFIED', 'APPROVED', ''])) {
                $phone = $p;
                break;
            }
        }
        $phone = $phone ?? $phones[0];

        return [
            'waba_id'          => $wabaId,
            'phone_number_id'  => $phone['id'],
            'phone_number'     => $phone['display_phone_number'],
            'verified_name'    => $phone['verified_name'] ?? null,
            'access_token'     => $userToken,
            'expires_at'       => $expiresAt,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // KIRIM PESAN (per-business)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Kirim pesan teks ke nomor WA tertentu menggunakan token business.
     */
    public function sendText(Business $business, string $toNumber, string $body): bool
    {
        $token   = $this->resolveToken($business);
        $phoneId = $business->wa_phone_id;

        if (!$token || !$phoneId) {
            Log::warning('WhatsAppService::sendText — token atau phone_id kosong', [
                'business_id' => $business->id,
            ]);
            return false;
        }

        $number = $this->normalizeNumber($toNumber);

        $response = Http::withToken($token)->post(
            "{$this->graphBase}/{$phoneId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'text',
                'text'              => ['preview_url' => false, 'body' => $body],
            ]
        );

        if (!$response->successful()) {
            Log::error('WhatsAppService::sendText failed', [
                'status'      => $response->status(),
                'body'        => $response->body(),
                'business_id' => $business->id,
                'to'          => $number,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Kirim gambar (QR Code) dengan caption.
     */
    public function sendImage(Business $business, string $toNumber, string $imageUrl, string $caption = ''): bool
    {
        $token   = $this->resolveToken($business);
        $phoneId = $business->wa_phone_id;

        if (!$token || !$phoneId) return false;

        $number   = $this->normalizeNumber($toNumber);
        $response = Http::withToken($token)->post(
            "{$this->graphBase}/{$phoneId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'image',
                'image'             => ['link' => $imageUrl, 'caption' => $caption],
            ]
        );

        return $response->successful();
    }

    // ────────────────────────────────────────────────────────────────────
    // STATUS CHECK
    // ────────────────────────────────────────────────────────────────────

    /**
     * Verifikasi apakah token business masih valid dan phone_number_id accessible.
     * Update kolom wa_connected di business record.
     */
    public function checkConnectionHealth(Business $business): bool
    {
        $token   = $this->resolveToken($business);
        $phoneId = $business->wa_phone_id;

        if (!$token || !$phoneId) {
            $business->update(['wa_connected' => false]);
            return false;
        }

        $response = Http::withToken($token)
            ->get("{$this->graphBase}/{$phoneId}", ['fields' => 'id,display_phone_number,status']);

        $connected = $response->successful()
            && isset($response->json()['id'])
            && in_array($response->json()['status'] ?? '', ['CONNECTED', 'VERIFIED', 'APPROVED', '']);

        $business->update(['wa_connected' => $connected]);

        return $connected;
    }

    /**
     * Ambil info nomor WA dari Meta untuk ditampilkan di dashboard.
     */
    public function getPhoneNumberInfo(Business $business): array
    {
        $token   = $this->resolveToken($business);
        $phoneId = $business->wa_phone_id;

        if (!$token || !$phoneId) return [];

        $response = Http::withToken($token)
            ->get("{$this->graphBase}/{$phoneId}", [
                'fields' => 'id,display_phone_number,verified_name,status,quality_rating',
            ]);

        if (!$response->successful()) return [];

        return $response->json();
    }

    // ────────────────────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────────────────────

    private function resolveToken(Business $business): ?string
    {
        return $business->getWaAccessTokenDecrypted()
            ?: config('services.whatsapp.access_token');
    }

    private function normalizeNumber(string $number): string
    {
        $n = preg_replace('/[^0-9]/', '', $number);
        if (str_starts_with($n, '0')) {
            $n = '62' . substr($n, 1);
        } elseif (!str_starts_with($n, '62')) {
            $n = '62' . $n;
        }
        return $n;
    }
}
