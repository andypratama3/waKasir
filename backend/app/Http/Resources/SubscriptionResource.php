<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan,
            'quota_conversation' => $this->quota_conversation,
            'quota_used' => $this->quota_used,
            'max_products' => $this->max_products,
            'renewed_at' => $this->renewed_at,
            'ends_at' => $this->ends_at,
            'status' => $this->status,
            'payment_history' => $this->payment_history,
        ];
    }
}