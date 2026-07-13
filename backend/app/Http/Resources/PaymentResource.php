<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'midtrans_transaction_id' => $this->midtrans_transaction_id,
            'payment_type' => $this->payment_type,
            'qr_code_url' => $this->qr_code_url,
            'status' => $this->status,
            'amount' => (float) $this->amount,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'payment_details' => $this->payment_details,
        ];
    }
}