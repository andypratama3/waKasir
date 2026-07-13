<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'order_number' => $this->order_number,
            'subtotal' => (float) $this->subtotal,
            'shipping_cost' => (float) $this->shipping_cost,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'courier_name' => $this->courier_name,
            'courier_service' => $this->courier_service,
            'tracking_number' => $this->tracking_number,
            'paid_at' => $this->paid_at,
            'shipped_at' => $this->shipped_at,
            'completed_at' => $this->completed_at,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'address' => new AddressResource($this->whenLoaded('address')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}