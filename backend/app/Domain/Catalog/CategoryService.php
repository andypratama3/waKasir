<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    public function getCategories(string $businessId): array
    {
        return Product::where('business_id', $businessId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category')
            ->toArray();
    }

    public function getCategoryStats(string $businessId): array
    {
        return Product::where('business_id', $businessId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category', DB::raw('count(*) as product_count'))
            ->groupBy('category')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->category,
                    'count' => $item->product_count,
                ];
            })
            ->toArray();
    }
}