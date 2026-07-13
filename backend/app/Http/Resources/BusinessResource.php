<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
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
            'name' => $this->name,
            'wa_phone_id' => $this->wa_phone_id,
            'wa_phone_number' => $this->wa_phone_number,
            'subscription_plan' => $this->subscription_plan,
            'status' => $this->status,
            'subscription_ends_at' => $this->subscription_ends_at,
            'bot_settings' => $this->bot_settings,
            'operating_hours' => $this->operating_hours,
            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}