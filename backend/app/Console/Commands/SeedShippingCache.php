<?php

namespace App\Console\Commands;

use App\Domain\Shipping\RajaOngkirService;
use App\Models\ShippingCache;
use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('shipping:seed {--force : Truncate and re-seed all cities}')]
#[Description('Seed the shipping_cache table from RajaOngkir API (provinces + cities). Safe to run repeatedly.')]
class SeedShippingCache extends Command
{
    public function handle(RajaOngkirService $rajaOngkir): int
    {
        $this->info('Fetching data from RajaOngkir...');

        if ($this->option('force')) {
            ShippingCache::truncate();
            $this->warn('  → shipping_cache truncated (--force)');
        }

        // 1. Fetch all provinces
        $provinces = $rajaOngkir->getProvinces();
        if (empty($provinces)) {
            $this->error('Failed to fetch provinces. Check your RAJAONGKIR_API_KEY.');
            return self::FAILURE;
        }
        $this->info('  → ' . count($provinces) . ' provinces fetched.');

        $provinceMap = collect($provinces)->keyBy('province_id');

        // 2. Fetch all cities and upsert into shipping_cache
        $cities = $rajaOngkir->getCities();
        if (empty($cities)) {
            $this->error('Failed to fetch cities. Check your RAJAONGKIR_API_KEY.');
            return self::FAILURE;
        }

        $this->info('  → ' . count($cities) . ' cities fetched. Upserting...');
        $bar = $this->output->createProgressBar(count($cities));
        $bar->start();

        $upserted = 0;
        foreach ($cities as $city) {
            $province = $provinceMap->get($city['province_id']);
            ShippingCache::updateOrCreate(
                ['city_id' => $city['city_id']],
                [
                    'province_id'   => $city['province_id'],
                    'province_name' => $province['province'] ?? ($city['province'] ?? ''),
                    'city_name'     => $city['city_name'],
                    'city_type'     => $city['type'] ?? 'Kabupaten',
                    'postal_code'   => $city['postal_code'] ?? null,
                ]
            );
            $upserted++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ Done! {$upserted} cities seeded into shipping_cache.");

        return self::SUCCESS;
    }
}
