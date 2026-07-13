<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
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
            'city_id' => $this->city_id,
            'subdistrict_id' => $this->subdistrict_id,
            'city_name' => $this->city_name,
            'subdistrict_name' => $this->subdistrict_name,
            'full_address' => $this->full_address,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'postal_code' => $this->postal_code,
            'notes' => $this->notes,
        ];
    }
}