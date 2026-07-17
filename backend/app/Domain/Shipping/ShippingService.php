<?php

namespace App\Domain\Shipping;

use App\Domain\Shipping\RajaOngkirService;

class ShippingService
{
    public function __construct(
        private RajaOngkirService $rajaOngkirService
    ) {}

    public function searchCity(string $query): \Illuminate\Database\Eloquent\Collection
    {
        return $this->rajaOngkirService->searchCity($query);
    }

    public function calculateShipping(string $originCityId, string $destinationCityId, int $weight): array
    {
        return $this->rajaOngkirService->calculateShipping($originCityId, $destinationCityId, $weight);
    }

    public function cacheShippingData(): void
    {
        $this->rajaOngkirService->cacheCities();
    }
}