<?php

namespace App\Domain\Tenant;

use App\Models\Subscription;
use App\Models\Business;
use Carbon\Carbon;

class SubscriptionService
{
    public function createSubscription(array $data, string $businessId): Subscription
    {
        return Subscription::create([
            'business_id' => $businessId,
            'plan' => $data['plan'],
            'quota_conversation' => $this->getPlanQuota($data['plan']),
            'quota_used' => 0,
            'max_products' => $this->getPlanMaxProducts($data['plan']),
            'renewed_at' => now(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
        ]);
    }

    public function updateSubscription(string $subscriptionId, array $data): Subscription
    {
        $subscription = Subscription::findOrFail($subscriptionId);

        $subscription->update([
            'plan' => $data['plan'] ?? $subscription->plan,
            'quota_conversation' => $data['quota_conversation'] ?? $subscription->quota_conversation,
            'max_products' => $data['max_products'] ?? $subscription->max_products,
            'ends_at' => $data['ends_at'] ?? $subscription->ends_at,
            'status' => $data['status'] ?? $subscription->status,
        ]);

        return $subscription->fresh();
    }

    public function upgradePlan(string $businessId, string $newPlan): Subscription
    {
        $business = Business::findOrFail($businessId);
        $subscription = $business->subscription;

        $subscription->update([
            'plan' => $newPlan,
            'quota_conversation' => $this->getPlanQuota($newPlan),
            'max_products' => $this->getPlanMaxProducts($newPlan),
            'renewed_at' => now(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        return $subscription->fresh();
    }

    public function recordUsage(string $businessId): bool
    {
        $business = Business::with('subscription')->findOrFail($businessId);
        $subscription = $business->subscription;

        if ($subscription->quota_used >= $subscription->quota_conversation) {
            return false; // Quota exceeded
        }

        $subscription->increment('quota_used');
        return true;
    }

    public function checkQuota(string $businessId): array
    {
        $business = Business::with('subscription')->findOrFail($businessId);
        $subscription = $business->subscription;

        $remaining = $subscription->quota_conversation - $subscription->quota_used;
        $percentage = ($subscription->quota_used / $subscription->quota_conversation) * 100;

        return [
            'used' => $subscription->quota_used,
            'total' => $subscription->quota_conversation,
            'remaining' => $remaining,
            'percentage' => round($percentage, 2),
            'is_exceeded' => $remaining <= 0,
            'is_near_limit' => $percentage >= 80,
        ];
    }

    public function renewSubscription(string $subscriptionId): Subscription
    {
        $subscription = Subscription::findOrFail($subscriptionId);

        $subscription->update([
            'quota_used' => 0,
            'renewed_at' => now(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        return $subscription->fresh();
    }

    public function cancelSubscription(string $subscriptionId): Subscription
    {
        $subscription = Subscription::findOrFail($subscriptionId);

        $subscription->update([
            'status' => 'cancelled',
            'ends_at' => now(), // End immediately
        ]);

        return $subscription->fresh();
    }

    public function getSubscriptionByBusiness(string $businessId): Subscription
    {
        return Subscription::where('business_id', $businessId)->firstOrFail();
    }

    public function checkProductLimit(string $businessId): bool
    {
        $business = Business::with('subscription', 'products')->findOrFail($businessId);
        $subscription = $business->subscription;
        $productCount = $business->products()->where('is_active', true)->count();

        if ($subscription->max_products === -1) {
            return true; // Unlimited
        }

        return $productCount < $subscription->max_products;
    }

    public function getPlanDetails(string $plan): array
    {
        return [
            'starter' => [
                'name' => 'Starter',
                'price' => 99000,
                'quota_conversation' => 200,
                'max_products' => 30,
                'features' => ['Basic bot features', 'Email support'],
            ],
            'growth' => [
                'name' => 'Growth',
                'price' => 249000,
                'quota_conversation' => 600,
                'max_products' => 200,
                'features' => ['Advanced bot features', 'Priority support', 'Analytics'],
            ],
            'pro' => [
                'name' => 'Pro',
                'price' => 499000,
                'quota_conversation' => 1500,
                'max_products' => -1, // unlimited
                'features' => ['All features', 'Dedicated support', 'Custom integrations', 'API access'],
            ],
        ][$plan] ?? [];
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
            'pro' => -1,
            default => 30,
        };
    }
}