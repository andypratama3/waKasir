<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Tenant\BusinessService;
use App\Domain\Tenant\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BusinessController extends Controller
{
    private BusinessService $businessService;
    private WhatsAppService $whatsAppService;

    public function __construct(BusinessService $businessService, WhatsAppService $whatsAppService)
    {
        $this->businessService = $businessService;
        $this->whatsAppService = $whatsAppService;
    }

    public function show(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $business = $this->businessService->getBusinessById($businessId);

        return response()->json([
            'business' => $business,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'bot_settings' => 'sometimes|array',
            'operating_hours' => 'sometimes|array',
        ]);

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $business = $this->businessService->updateBusiness($businessId, $request->all());
            
            return response()->json([
                'business' => $business,
                'message' => 'Business updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateIntegration(Request $request, string $integration): JsonResponse
    {
        $validIntegrations = ['whatsapp', 'midtrans', 'rajaongkir'];
        
        if (!in_array($integration, $validIntegrations)) {
            return response()->json(['error' => 'Invalid integration'], 400);
        }

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $business = $this->businessService->updateIntegration($businessId, $integration, $request->all());
            
            return response()->json([
                'business' => $business,
                'message' => ucfirst($integration) . ' integration updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $stats = $this->businessService->getBusinessStats($businessId);

        return response()->json([
            'stats' => $stats,
        ]);
    }

    // ── WhatsApp BSP Endpoints ───────────────────────────────────────────

    /**
     * POST /api/business/whatsapp/connect
     * Terima authorization_code dari Embedded Signup, exchange ke token,
     * simpan phone_number_id + token terenkripsi ke business record.
     */
    public function connectWhatsApp(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $result   = $this->whatsAppService->exchangeCodeForToken($request->code);
            $business = \App\Models\Business::findOrFail($businessId);

            $business->update([
                'wa_phone_id'         => $result['phone_number_id'],
                'wa_phone_number'     => $result['phone_number'],
                'wa_access_token'     => encrypt($result['access_token']),
                'wa_waba_id'          => $result['waba_id'],
                'wa_token_expires_at' => $result['expires_at'],
                'wa_connected'        => true,
            ]);

            return response()->json([
                'message'         => 'WhatsApp berhasil dihubungkan!',
                'phone_number'    => $result['phone_number'],
                'verified_name'   => $result['verified_name'],
                'waba_id'         => $result['waba_id'],
            ]);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('connectWhatsApp failed', [
                'error'       => $e->getMessage(),
                'business_id' => $businessId,
            ]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/business/whatsapp/status
     * Cek status koneksi WA — hit Meta API untuk verifikasi token & phone masih aktif.
     */
    public function waStatus(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $business  = \App\Models\Business::findOrFail($businessId);
        $connected = $this->whatsAppService->checkConnectionHealth($business);
        $info      = $connected ? $this->whatsAppService->getPhoneNumberInfo($business) : [];

        return response()->json([
            'connected'      => $connected,
            'phone_number'   => $business->wa_phone_number,
            'phone_number_id'=> $business->wa_phone_id,
            'waba_id'        => $business->wa_waba_id,
            'quality_rating' => $info['quality_rating'] ?? null,
            'status'         => $info['status'] ?? null,
            'verified_name'  => $info['verified_name'] ?? null,
            'token_expires_at'=> $business->wa_token_expires_at?->toDateTimeString(),
        ]);
    }

    /**
     * DELETE /api/business/whatsapp/disconnect
     * Hapus token dan phone info dari business record.
     */
    public function disconnectWhatsApp(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        \App\Models\Business::where('id', $businessId)->update([
            'wa_access_token'     => null,
            'wa_phone_id'         => null,
            'wa_phone_number'     => null,
            'wa_waba_id'          => null,
            'wa_token_expires_at' => null,
            'wa_connected'        => false,
        ]);

        return response()->json(['message' => 'WhatsApp berhasil diputus.']);
    }
}