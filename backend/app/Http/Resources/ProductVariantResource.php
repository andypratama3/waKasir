<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
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
            'variant_name' => $this->variant_name,
            'stock_override' => $this->stock_override,
            'price_override' => $this->price_override ? (float) $this->price_override : null,
            'is_active' => $this->is_active,
        ];
    }
}