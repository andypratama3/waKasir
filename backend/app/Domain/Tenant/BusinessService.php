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

        $updates = array_filter([
            'name'             => $data['name']             ?? null,
            'wa_phone_id'      => $data['wa_phone_id']      ?? null,
            'wa_phone_number'  => $data['wa_phone_number']  ?? null,
            'origin_city_id'   => $data['origin_city_id']   ?? null,
            'origin_subdistrict_id' => $data['origin_subdistrict_id'] ?? null,
            'origin_address'   => $data['origin_address']   ?? null,
            'bot_settings'     => $data['bot_settings']     ?? null,
            'operating_hours'  => $data['operating_hours']  ?? null,
            'midtrans_merchant_id' => $data['midtrans_merchant_id'] ?? null,
        ], fn ($v) => $v !== null);

        // Encrypt sensitive keys before storing
        if (!empty($data['midtrans_server_key'])) {
            $updates['midtrans_server_key'] = encrypt($data['midtrans_server_key']);
        }
        if (!empty($data['midtrans_client_key'])) {
            $updates['midtrans_client_key'] = encrypt($data['midtrans_client_key']);
        }
        if (!empty($data['rajaongkir_api_key'])) {
            $updates['rajaongkir_api_key'] = encrypt($data['rajaongkir_api_key']);
        }

        $business->update($updates);
        return $business->fresh();
    }

    public function getBusinessById(string $businessId): Business
    {
        return Business::with(['subscription', 'users', 'products', 'orders'])->findOrFail($businessId);
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
                'midtrans_server_key'  => isset($credentials['server_key'])
                    ? encrypt($credentials['server_key']) : null,
                'midtrans_client_key'  => isset($credentials['client_key'])
                    ? encrypt($credentials['client_key']) : null,
                'midtrans_merchant_id' => $credentials['midtrans_merchant_id'] ?? $credentials['merchant_id'] ?? null,
            ]),
            'rajaongkir' => array_filter([
                'rajaongkir_api_key'   => isset($credentials['api_key'])
                    ? encrypt($credentials['api_key']) : null,
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
            'products_count'  => $business->products()->where('is_active', true)->count(),
            'orders_count'    => $business->orders()->count(),
            'customers_count' => $business->customers()->count(),
            'revenue'         => (float) $business->orders()->whereIn('status', ['paid', 'completed'])->sum('total_amount'),
            'subscription'    => [
                'plan'               => $business->subscription?->plan,
                'quota_used'         => $business->subscription?->quota_used ?? 0,
                'quota_conversation' => $business->subscription?->quota_conversation ?? 0,
                'max_products'       => $business->subscription?->max_products ?? 0,
                'ends_at'            => $business->subscription?->ends_at,
                'status'             => $business->subscription?->status,
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