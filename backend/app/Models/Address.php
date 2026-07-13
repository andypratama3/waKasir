<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $fillable = [
        'order_id',
        'city_id',
        'subdistrict_id',
        'city_name',
        'subdistrict_name',
        'full_address',
        'recipient_name',
        'recipient_phone',
        'postal_code',
        'notes',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
