<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Tenant\SubscriptionService;
use App\Domain\Catalog\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    private SubscriptionService $subscriptionService;
    private CategoryService $categoryService;

    public function __construct(
        SubscriptionService $subscriptionService,
        CategoryService $categoryService
    ) {
        $this->subscriptionService = $subscriptionService;
        $this->categoryService = $categoryService;
    }

    public function botSettings(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $business = \App\Models\Business::findOrFail($businessId);
        $botSettings    = $business->bot_settings    ?? [];
        $operatingHours = $business->operating_hours ?? [];

        return response()->json([
            'greeting_message'         => $botSettings['greeting_message']   ?? "Halo! Selamat datang di toko kami 👋\n\nSilahkan pilih:\n1️⃣ Lihat Katalog\n2️⃣ Cek Status Pesanan\n3️⃣ Hubungi Admin",
            'fallback_message'         => $botSettings['fallback_message']    ?? 'Maaf, saya tidak mengerti. Ketik "menu" untuk kembali ke menu utama.',
            'order_confirmation_template' => $botSettings['order_confirmation_template'] ?? '',
            'operating_hours'          => [
                'enabled'  => $operatingHours['enabled'] ?? false,
                'start'    => $operatingHours['start']   ?? '08:00',
                'end'      => $operatingHours['end']     ?? '21:00',
                'timezone' => $operatingHours['timezone'] ?? 'Asia/Jakarta',
            ],
        ]);
    }

    public function updateBotSettings(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $business = \App\Models\Business::findOrFail($businessId);

        $botSettings = $business->bot_settings ?? [];
        if ($request->has('greeting_message'))  $botSettings['greeting_message']  = $request->greeting_message;
        if ($request->has('fallback_message'))  $botSettings['fallback_message']  = $request->fallback_message;
        if ($request->has('order_confirmation_template')) $botSettings['order_confirmation_template'] = $request->order_confirmation_template;

        $operatingHours = $business->operating_hours ?? [];
        if ($request->has('operating_hours')) {
            $oh = $request->operating_hours;
            $operatingHours['enabled']  = $oh['enabled']  ?? $operatingHours['enabled']  ?? false;
            $operatingHours['start']    = $oh['start']    ?? $operatingHours['start']    ?? '08:00';
            $operatingHours['end']      = $oh['end']      ?? $operatingHours['end']      ?? '21:00';
            $operatingHours['timezone'] = $oh['timezone'] ?? $operatingHours['timezone'] ?? 'Asia/Jakarta';
        }

        $business->update([
            'bot_settings'   => $botSettings,
            'operating_hours' => $operatingHours,
        ]);

        return response()->json(['message' => 'Bot settings updated successfully']);
    }

    public function subscription(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $subscription = $this->subscriptionService->getSubscriptionByBusiness($businessId);
        $quotaStatus = $this->subscriptionService->checkQuota($businessId);
        $planDetails = $this->subscriptionService->getPlanDetails($subscription->plan);

        return response()->json([
            'subscription' => $subscription,
            'quota_status' => $quotaStatus,
            'plan_details' => $planDetails,
        ]);
    }

    public function upgradeSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'plan' => 'required|in:starter,growth,pro',
        ]);

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $subscription = $this->subscriptionService->upgradePlan($businessId, $request->plan);
            
            return response()->json([
                'subscription' => $subscription,
                'message' => 'Subscription upgraded successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}