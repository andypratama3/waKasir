<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'customer_id',
        'current_state',
        'cart_data',
        'selected_city_id',
        'selected_city_name',
        'selected_courier',
        'last_activity_at',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'selected_courier' => 'array',
        'last_activity_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }
}
