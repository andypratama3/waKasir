<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'business_id',
        'plan',
        'quota_conversation',
        'quota_used',
        'max_products',
        'renewed_at',
        'ends_at',
        'status',
        'payment_history',
    ];

    protected $casts = [
        'renewed_at' => 'datetime',
        'ends_at' => 'datetime',
        'payment_history' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
