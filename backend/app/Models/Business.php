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
        'wa_access_token',
        'wa_waba_id',
        'wa_token_expires_at',
        'wa_connected',
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

    protected $hidden = [
        'wa_access_token',       // never leak tokens in API responses
        'midtrans_server_key',
        'midtrans_client_key',
        'rajaongkir_api_key',
    ];

    protected $casts = [
        'bot_settings'        => 'array',
        'operating_hours'     => 'array',
        'subscription_ends_at'=> 'datetime',
        'wa_token_expires_at' => 'datetime',
        'wa_connected'        => 'boolean',
    ];

    /** Returns the decrypted access token, or null if not set. */
    public function getWaAccessTokenDecrypted(): ?string
    {
        if (!$this->wa_access_token) return null;
        try {
            return decrypt($this->wa_access_token);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Returns decrypted Midtrans server key, or null. */
    public function getMidtransServerKeyDecrypted(): ?string
    {
        return $this->decryptField($this->midtrans_server_key);
    }

    /** Returns decrypted Midtrans client key, or null. */
    public function getMidtransClientKeyDecrypted(): ?string
    {
        return $this->decryptField($this->midtrans_client_key);
    }

    /** Returns decrypted RajaOngkir API key, or null. */
    public function getRajaOngkirApiKeyDecrypted(): ?string
    {
        return $this->decryptField($this->rajaongkir_api_key);
    }

    private function decryptField(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return decrypt($value);
        } catch (\Throwable) {
            // Value might be plain-text (pre-migration) — return as-is
            return $value;
        }
    }

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
