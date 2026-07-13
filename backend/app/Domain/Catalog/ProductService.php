<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function getActiveProducts(string $businessId): \Illuminate\Database\Eloquent\Collection
    {
        return Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->get();
    }

    public function getProductById(string $productId, string $businessId): ?Product
    {
        return Product::where('id', $productId)
            ->where('business_id', $businessId)
            ->first();
    }

    public function createProduct(array $data, string $businessId): Product
    {
        return DB::transaction(function () use ($data, $businessId) {
            $product = Product::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price' => $data['price'],
                'stock' => $data['stock'] ?? 0,
                'weight_gram' => $data['weight_gram'] ?? 0,
                'category' => $data['category'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Handle image upload if provided
            if (isset($data['image'])) {
                $product->addMedia($data['image'])
                    ->toMediaCollection('product_images');
            }

            // Handle variants if provided
            if (isset($data['variants']) && is_array($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'variant_name' => $variant['name'],
                        'stock_override' => $variant['stock'] ?? null,
                        'price_override' => $variant['price'] ?? null,
                        'is_active' => $variant['is_active'] ?? true,
                    ]);
                }
            }

            return $product;
        });
    }

    public function updateProduct(string $productId, array $data, string $businessId): Product
    {
        $product = $this->getProductById($productId, $businessId);
        
        if (!$product) {
            throw new \Exception('Product not found');
        }

        return DB::transaction(function () use ($product, $data) {
            $product->update([
                'name' => $data['name'] ?? $product->name,
                'description' => $data['description'] ?? $product->description,
                'price' => $data['price'] ?? $product->price,
                'stock' => $data['stock'] ?? $product->stock,
                'weight_gram' => $data['weight_gram'] ?? $product->weight_gram,
                'category' => $data['category'] ?? $product->category,
                'is_active' => $data['is_active'] ?? $product->is_active,
            ]);

            // Handle image update
            if (isset($data['image'])) {
                $product->clearMediaCollection('product_images');
                $product->addMedia($data['image'])
                    ->toMediaCollection('product_images');
            }

            // Handle variants update
            if (isset($data['variants']) && is_array($data['variants'])) {
                $product->variants()->delete();
                foreach ($data['variants'] as $variant) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'variant_name' => $variant['name'],
                        'stock_override' => $variant['stock'] ?? null,
                        'price_override' => $variant['price'] ?? null,
                        'is_active' => $variant['is_active'] ?? true,
                    ]);
                }
            }

            return $product->fresh();
        });
    }

    public function deleteProduct(string $productId, string $businessId): bool
    {
        $product = $this->getProductById($productId, $businessId);
        
        if (!$product) {
            return false;
        }

        return $product->delete();
    }

    public function searchProducts(string $query, string $businessId): \Illuminate\Database\Eloquent\Collection
    {
        return Product::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('category', 'like', "%{$query}%");
            })
            ->get();
    }

    public function getProductsByCategory(string $category, string $businessId): \Illuminate\Database\Eloquent\Collection
    {
        return Product::where('business_id', $businessId)
            ->where('category', $category)
            ->where('is_active', true)
            ->get();
    }

    public function getProductWithVariants(string $productId, string $businessId): ?Product
    {
        return Product::with('variants')
            ->where('id', $productId)
            ->where('business_id', $businessId)
            ->first();
    }

    public function updateStock(string $productId, int $quantity, string $businessId): bool
    {
        $product = $this->getProductById($productId, $businessId);
        
        if (!$product) {
            return false;
        }

        return $product->update([
            'stock' => $product->stock - $quantity
        ]);
    }
}