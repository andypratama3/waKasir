<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'wa_phone_id',
        'wa_phone_number',
        'midtrans_server_key',
        'midtrans_client_key',
        'midtrans_merchant_id',
        'rajaongkir_api_key',
        'origin_city_id',
        'origin_subdistrict_id',
        'origin_address',
        'subscription_plan',
        'status',
        'subscription_ends_at',
        'bot_settings',
        'operating_hours',
    ];

    protected $casts = [
        'bot_settings' => 'array',
        'operating_hours' => 'array',
        'subscription_ends_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }
}
