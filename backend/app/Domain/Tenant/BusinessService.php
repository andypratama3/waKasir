<?php

namespace App\Domain\Tenant;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessService
{
    public function createBusiness(array $data, User $owner): Business
    {
        return DB::transaction(function () use ($data, $owner) {
            $business = Business::create([
                'name' => $data['name'],
                'subscription_plan' => $data['subscription_plan'] ?? 'starter',
                'status' => 'active',
                'bot_settings' => $data['bot_settings'] ?? [],
                'operating_hours' => $data['operating_hours'] ?? [],
            ]);

            // Update owner to be associated with business
            $owner->update([
                'business_id' => $business->id,
                'role' => 'owner'
            ]);

            // Create subscription
            $business->subscription()->create([
                'plan' => $data['subscription_plan'] ?? 'starter',
                'quota_conversation' => $this->getPlanQuota($data['subscription_plan'] ?? 'starter'),
                'quota_used' => 0,
                'max_products' => $this->getPlanMaxProducts($data['subscription_plan'] ?? 'starter'),
                'renewed_at' => now(),
                'ends_at' => now()->addMonth(),
                'status' => 'active',
            ]);

            return $business->load('subscription');
        });
    }

    public function updateBusiness(string $businessId, array $data): Business
    {
        $business = Business::findOrFail($businessId);
        
        $business->update([
            'name' => $data['name'] ?? $business->name,
            'wa_phone_id' => $data['wa_phone_id'] ?? $business->wa_phone_id,
            'wa_phone_number' => $data['wa_phone_number'] ?? $business->wa_phone_number,
            'midtrans_server_key' => $data['midtrans_server_key'] ?? $business->midtrans_server_key,
            'midtrans_client_key' => $data['midtrans_client_key'] ?? $business->midtrans_client_key,
            'midtrans_merchant_id' => $data['midtrans_merchant_id'] ?? $business->midtrans_merchant_id,
            'rajaongkir_api_key' => $data['rajaongkir_api_key'] ?? $business->rajaongkir_api_key,
            'origin_city_id' => $data['origin_city_id'] ?? $business->origin_city_id,
            'origin_subdistrict_id' => $data['origin_subdistrict_id'] ?? $business->origin_subdistrict_id,
            'origin_address' => $data['origin_address'] ?? $business->origin_address,
            'bot_settings' => $data['bot_settings'] ?? $business->bot_settings,
            'operating_hours' => $data['operating_hours'] ?? $business->operating_hours,
        ]);

        return $business->fresh();
    }

    public function getBusinessById(string $businessId): Business
    {
        return Business::with(['subscription', 'users', 'products', 'orders'])->findOrFail($businessId);
    }

    public function getBusinessByUserId(string $userId): ?Business
    {
        $user = User::with('business')->find($userId);
        return $user?->business;
    }

    public function updateIntegration(string $businessId, string $integration, array $credentials): Business
    {
        $business = Business::findOrFail($businessId);

        $updateData = match($integration) {
            'whatsapp' => array_filter([
                'wa_phone_id'     => $credentials['wa_phone_id']     ?? $credentials['phone_id']     ?? null,
                'wa_phone_number' => $credentials['wa_phone_number']  ?? $credentials['phone_number'] ?? null,
            ]),
            'midtrans' => array_filter([
                'midtrans_server_key'  => $credentials['server_key']       ?? null,
                'midtrans_client_key'  => $credentials['client_key']       ?? null,
                'midtrans_merchant_id' => $credentials['midtrans_merchant_id'] ?? $credentials['merchant_id'] ?? null,
            ]),
            'rajaongkir' => array_filter([
                'rajaongkir_api_key'   => $credentials['api_key']          ?? null,
                'origin_city_id'       => $credentials['origin_city_id']   ?? null,
                'origin_address'       => $credentials['origin_address']   ?? null,
            ]),
            default => []
        };

        $business->update($updateData);

        return $business->fresh();
    }

    public function getBusinessStats(string $businessId): array
    {
        $business = Business::with(['products', 'orders', 'customers', 'subscription'])->findOrFail($businessId);

        return [
            'products_count' => $business->products()->where('is_active', true)->count(),
            'orders_count' => $business->orders()->count(),
            'customers_count' => $business->customers()->count(),
            'revenue' => $business->orders()->where('status', 'completed')->sum('total_amount'),
            'subscription' => [
                'plan' => $business->subscription->plan,
                'quota_used' => $business->subscription->quota_used,
                'quota_total' => $business->subscription->quota_conversation,
                'ends_at' => $business->subscription->ends_at,
            ],
        ];
    }

    private function getPlanQuota(string $plan): int
    {
        return match($plan) {
            'starter' => 200,
            'growth' => 600,
            'pro' => 1500,
            default => 200,
        };
    }

    private function getPlanMaxProducts(string $plan): int
    {
        return match($plan) {
            'starter' => 30,
            'growth' => 200,
            'pro' => -1, // unlimited
            default => 30,
        };
    }
}