<?php

namespace App\Domain\Shipping;

use App\Models\ShippingCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class RajaOngkirService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $apiKey = '')
    {
        $this->baseUrl = config('services.rajaongkir.base_url', 'https://api.rajaongkir.com/starter');
        // Accept injected key (per-business) or fall back to global config
        $this->apiKey  = $apiKey ?: config('services.rajaongkir.api_key', '');
    }

    /**
     * Create a per-business instance using the business's decrypted API key.
     */
    public static function forBusiness(\App\Models\Business $business): self
    {
        return new self($business->getRajaOngkirApiKeyDecrypted() ?? '');
    }

    public function getProvinces(): array
    {
        return Cache::remember('rajaongkir.provinces', 86400, function () {
            $response = Http::withHeaders([
                'key' => $this->apiKey
            ])->get("{$this->baseUrl}/province");

            if ($response->successful()) {
                return $response->json()['rajaongkir']['results'];
            }

            return [];
        });
    }

    public function getCities(string $provinceId = null): array
    {
        $cacheKey = $provinceId 
            ? "rajaongkir.cities.{$provinceId}" 
            : 'rajaongkir.cities';

        return Cache::remember($cacheKey, 86400, function () use ($provinceId) {
            $url = "{$this->baseUrl}/city";
            if ($provinceId) {
                $url .= "?province={$provinceId}";
            }

            $response = Http::withHeaders([
                'key' => $this->apiKey
            ])->get($url);

            if ($response->successful()) {
                return $response->json()['rajaongkir']['results'];
            }

            return [];
        });
    }

    public function cacheCities(): void
    {
        $cities = $this->getCities();
        
        foreach ($cities as $city) {
            ShippingCache::updateOrCreate(
                ['city_id' => $city['city_id']],
                [
                    'province_id' => $city['province_id'],
                    'province_name' => $city['province'],
                    'city_name' => $city['city_name'],
                    'city_type' => $city['type'],
                    'postal_code' => $city['postal_code'] ?? null,
                ]
            );
        }
    }

    public function searchCity(string $query): \Illuminate\Database\Eloquent\Collection
    {
        return ShippingCache::where('city_name', 'like', "%{$query}%")
            ->orWhere('province_name', 'like', "%{$query}%")
            ->limit(10)
            ->get();
    }

    public function calculateShipping(string $origin, string $destination, int $weight): array
    {
        $response = Http::withHeaders([
            'key' => $this->apiKey
        ])->post("{$this->baseUrl}/cost", [
            'origin' => $origin,
            'destination' => $destination,
            'weight' => $weight,
            'courier' => 'jne,jnt,sicepat' // Default couriers
        ]);

        if ($response->successful()) {
            $results = $response->json()['rajaongkir']['results'];
            $options = [];

            foreach ($results as $courier) {
                foreach ($courier['costs'] as $cost) {
                    $options[] = [
                        'courier' => strtoupper($courier['code']),
                        'service' => $cost['service'],
                        'description' => $cost['description'],
                        'cost' => $cost['cost'][0]['value'],
                        'etd' => $cost['cost'][0]['etd'],
                    ];
                }
            }

            return $options;
        }

        return [];
    }

}