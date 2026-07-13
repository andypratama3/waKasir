<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product') ? $this->product->name : null,
            'variant_id' => $this->variant_id,
            'variant_name' => $this->variant_name,
            'qty' => $this->qty,
            'price_at_order' => (float) $this->price_at_order,
            'subtotal' => (float) ($this->qty * $this->price_at_order),
        ];
    }
}