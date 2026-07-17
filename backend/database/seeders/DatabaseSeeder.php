<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Address;
use App\Models\Subscription;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Safety guards ─────────────────────────────────────────────────
        // Refuse to run in production to avoid wiping live data
        if (app()->environment('production')) {
            $this->command->error('❌ DatabaseSeeder refused: cannot run in production environment.');
            return;
        }

        // PRAGMA foreign_keys is SQLite-only. Skip on MySQL/PostgreSQL.
        $isSqlite = config('database.default') === 'sqlite'
            || str_contains(config('database.connections.' . config('database.default') . '.driver', ''), 'sqlite');

        // ── Clean slate ───────────────────────────────────────────────────
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        Address::truncate();
        Payment::truncate();
        OrderItem::truncate();
        Order::truncate();
        Customer::truncate();
        ProductVariant::truncate();
        Product::truncate();
        Subscription::truncate();
        Business::truncate();
        User::truncate();

        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        // ── Seed credentials from env (fallback to demo values for local dev) ──
        $ownerEmail    = env('SEED_OWNER_EMAIL',    'admin@wakasir.dev');
        $ownerPassword = env('SEED_OWNER_PASSWORD', 'password');
        $ownerName     = env('SEED_OWNER_NAME',     'Admin WaKasir');

        // ── 1. Business ───────────────────────────────────────────
        $business = Business::create([
            'name'              => 'Toko Batik Nusantara',
            'wa_phone_id'       => '123456789012345',
            'wa_phone_number'   => '6281234567890',
            'subscription_plan' => 'growth',
            'status'            => 'active',
            'origin_city_id'    => '151',
            'origin_address'    => 'Jl. Malioboro No. 123, Yogyakarta',
            'bot_settings'      => [
                'greeting_message' => "Halo! Selamat datang di Toko Batik Nusantara 👋\n\nSilahkan pilih:\n1️⃣ Lihat Katalog\n2️⃣ Cek Status Pesanan\n3️⃣ Hubungi Admin",
                'fallback_message' => 'Maaf, saya tidak mengerti pesan Anda. Ketik "menu" untuk kembali ke menu utama.',
            ],
            'operating_hours'   => [
                'enabled'  => true,
                'start'    => '08:00',
                'end'      => '21:00',
                'timezone' => 'Asia/Jakarta',
            ],
        ]);

        // ── 2. Owner user ─────────────────────────────────────────
        $owner = User::create([
            'name'        => $ownerName,
            'email'       => $ownerEmail,
            'password'    => Hash::make($ownerPassword),
            'business_id' => $business->id,
            'role'        => 'owner',
        ]);

        // ── 3. Subscription ───────────────────────────────────────
        Subscription::create([
            'business_id'        => $business->id,
            'plan'               => 'growth',
            'quota_conversation' => 600,
            'quota_used'         => 142,
            'max_products'       => 200,
            'renewed_at'         => now()->subDays(12),
            'ends_at'            => now()->addDays(18),
            'status'             => 'active',
        ]);

        // ── 4. Products ───────────────────────────────────────────
        $products = [
            [
                'name'        => 'Batik Tulis Solo Motif Parang',
                'description' => 'Batik tulis halus motif parang klasik, cocok untuk acara formal',
                'price'       => 350000,
                'stock'       => 24,
                'weight_gram' => 400,
                'category'    => 'Pakaian',
                'is_active'   => true,
                'variants'    => [
                    ['variant_name' => 'S',  'stock_override' => 6,  'price_override' => null],
                    ['variant_name' => 'M',  'stock_override' => 10, 'price_override' => null],
                    ['variant_name' => 'L',  'stock_override' => 6,  'price_override' => null],
                    ['variant_name' => 'XL', 'stock_override' => 2,  'price_override' => null],
                ],
            ],
            [
                'name'        => 'Batik Cap Mega Mendung',
                'description' => 'Batik cap motif mega mendung dari Cirebon, warna biru natural',
                'price'       => 175000,
                'stock'       => 48,
                'weight_gram' => 380,
                'category'    => 'Pakaian',
                'is_active'   => true,
                'variants'    => [
                    ['variant_name' => 'M',  'stock_override' => 15, 'price_override' => null],
                    ['variant_name' => 'L',  'stock_override' => 20, 'price_override' => null],
                    ['variant_name' => 'XL', 'stock_override' => 13, 'price_override' => null],
                ],
            ],
            [
                'name'        => 'Kemeja Batik Pria Lengan Panjang',
                'description' => 'Kemeja batik formal pria, bahan katun premium anti-kusut',
                'price'       => 285000,
                'stock'       => 30,
                'weight_gram' => 450,
                'category'    => 'Pakaian',
                'is_active'   => true,
                'variants'    => [
                    ['variant_name' => 'M',  'stock_override' => 10, 'price_override' => null],
                    ['variant_name' => 'L',  'stock_override' => 12, 'price_override' => null],
                    ['variant_name' => 'XL', 'stock_override' => 8,  'price_override' => null],
                ],
            ],
            [
                'name'        => 'Kain Batik Primissima 2 Meter',
                'description' => 'Kain batik bahan primissima siap jahit, motif kawung',
                'price'       => 220000,
                'stock'       => 15,
                'weight_gram' => 600,
                'category'    => 'Kain',
                'is_active'   => true,
                'variants'    => [],
            ],
            [
                'name'        => 'Tas Batik Kanvas',
                'description' => 'Tas tote bag kanvas motif batik, cocok untuk daily use',
                'price'       => 95000,
                'stock'       => 3,   // low stock
                'weight_gram' => 300,
                'category'    => 'Aksesoris',
                'is_active'   => true,
                'variants'    => [],
            ],
            [
                'name'        => 'Daster Batik Wanita',
                'description' => 'Daster batik wanita bahan rayon adem, motif flora',
                'price'       => 135000,
                'stock'       => 0,   // out of stock
                'weight_gram' => 350,
                'category'    => 'Pakaian',
                'is_active'   => false,
                'variants'    => [],
            ],
        ];

        $productModels = [];
        foreach ($products as $pData) {
            $variants = $pData['variants'];
            unset($pData['variants']);

            $product = Product::create(array_merge($pData, ['business_id' => $business->id]));

            foreach ($variants as $v) {
                ProductVariant::create(array_merge($v, ['product_id' => $product->id]));
            }

            $productModels[] = $product;
        }

        // ── 5. Customers ──────────────────────────────────────────
        $customersData = [
            ['wa_number' => '6281111111111', 'name' => 'Siti Rahayu'],
            ['wa_number' => '6282222222222', 'name' => 'Budi Santoso'],
            ['wa_number' => '6283333333333', 'name' => 'Dewi Lestari'],
            ['wa_number' => '6284444444444', 'name' => 'Ahmad Fauzi'],
            ['wa_number' => '6285555555555', 'name' => 'Rina Wulandari'],
        ];

        $customers = [];
        foreach ($customersData as $cd) {
            $customers[] = Customer::create(array_merge($cd, ['business_id' => $business->id]));
        }

        // ── 6. Orders (variety of statuses) ──────────────────────
        $ordersData = [
            // completed orders for revenue charts
            [
                'customer'  => $customers[0],
                'product'   => $productModels[0],
                'variant'   => 'L',
                'qty'       => 2,
                'status'    => 'completed',
                'courier'   => 'JNE',
                'service'   => 'REG',
                'shipping'  => 25000,
                'city'      => 'Jakarta Selatan',
                'days_ago'  => 1,
            ],
            [
                'customer'  => $customers[1],
                'product'   => $productModels[1],
                'variant'   => 'M',
                'qty'       => 1,
                'status'    => 'completed',
                'courier'   => 'J&T',
                'service'   => 'Express',
                'shipping'  => 28000,
                'city'      => 'Surabaya',
                'days_ago'  => 2,
            ],
            [
                'customer'  => $customers[2],
                'product'   => $productModels[2],
                'variant'   => 'XL',
                'qty'       => 1,
                'status'    => 'shipped',
                'courier'   => 'SiCepat',
                'service'   => 'REG',
                'shipping'  => 22000,
                'city'      => 'Bandung',
                'days_ago'  => 3,
                'tracking'  => 'SICEPATYYY123456',
            ],
            [
                'customer'  => $customers[3],
                'product'   => $productModels[3],
                'variant'   => null,
                'qty'       => 2,
                'status'    => 'processing',
                'courier'   => 'JNE',
                'service'   => 'YES',
                'shipping'  => 98000,
                'city'      => 'Medan',
                'days_ago'  => 0,
            ],
            [
                'customer'  => $customers[4],
                'product'   => $productModels[0],
                'variant'   => 'M',
                'qty'       => 1,
                'status'    => 'paid',
                'courier'   => 'Pos Indonesia',
                'service'   => 'Paket Biasa',
                'shipping'  => 15000,
                'city'      => 'Semarang',
                'days_ago'  => 0,
            ],
            [
                'customer'  => $customers[0],
                'product'   => $productModels[4],
                'variant'   => null,
                'qty'       => 3,
                'status'    => 'pending',
                'courier'   => 'JNE',
                'service'   => 'REG',
                'shipping'  => 25000,
                'city'      => 'Yogyakarta',
                'days_ago'  => 0,
            ],
            // more completed for chart variety
            [
                'customer'  => $customers[1],
                'product'   => $productModels[2],
                'variant'   => 'M',
                'qty'       => 2,
                'status'    => 'completed',
                'courier'   => 'J&T',
                'service'   => 'Express',
                'shipping'  => 30000,
                'city'      => 'Jakarta Pusat',
                'days_ago'  => 5,
            ],
            [
                'customer'  => $customers[2],
                'product'   => $productModels[1],
                'variant'   => 'L',
                'qty'       => 1,
                'status'    => 'completed',
                'courier'   => 'JNE',
                'service'   => 'REG',
                'shipping'  => 25000,
                'city'      => 'Jakarta Barat',
                'days_ago'  => 7,
            ],
            [
                'customer'  => $customers[3],
                'product'   => $productModels[0],
                'variant'   => 'S',
                'qty'       => 1,
                'status'    => 'completed',
                'courier'   => 'SiCepat',
                'service'   => 'REG',
                'shipping'  => 22000,
                'city'      => 'Surabaya',
                'days_ago'  => 10,
            ],
        ];

        foreach ($ordersData as $idx => $od) {
            $price    = $od['product']->price;
            $subtotal = $price * $od['qty'];
            $total    = $subtotal + $od['shipping'];
            $date     = Carbon::now()->subDays($od['days_ago'])->subHours(rand(0, 8));

            $orderNum = 'ORD-' . $date->format('Ymd') . str_pad($idx + 1, 4, '0', STR_PAD_LEFT);

            $order = Order::create([
                'business_id'     => $business->id,
                'customer_id'     => $od['customer']->id,
                'order_number'    => $orderNum,
                'subtotal'        => $subtotal,
                'shipping_cost'   => $od['shipping'],
                'total_amount'    => $total,
                'status'          => $od['status'],
                'courier_name'    => $od['courier'],
                'courier_service' => $od['service'],
                'tracking_number' => $od['tracking'] ?? null,
                'paid_at'         => in_array($od['status'], ['paid','processing','shipped','completed']) ? $date->addMinutes(30) : null,
                'shipped_at'      => in_array($od['status'], ['shipped','completed']) ? $date->addHours(24) : null,
                'completed_at'    => $od['status'] === 'completed' ? $date->addDays(3) : null,
                'created_at'      => $date,
                'updated_at'      => $date,
            ]);

            // Order item
            $variantId = null;
            if ($od['variant']) {
                $variantId = $od['product']->variants()->where('variant_name', $od['variant'])->first()?->id;
            }

            OrderItem::create([
                'order_id'       => $order->id,
                'product_id'     => $od['product']->id,
                'variant_id'     => $variantId,
                'qty'            => $od['qty'],
                'price_at_order' => $price,
                'variant_name'   => $od['variant'],
            ]);

            // Address
            Address::create([
                'order_id'        => $order->id,
                'city_name'       => $od['city'],
                'full_address'    => 'Jl. Contoh No. ' . rand(1, 99) . ', ' . $od['city'],
                'recipient_name'  => $od['customer']->name,
                'recipient_phone' => $od['customer']->wa_number,
                'postal_code'     => '1' . rand(1000, 9999),
            ]);

            // Payment for paid+ orders
            if (in_array($od['status'], ['paid', 'processing', 'shipped', 'completed'])) {
                Payment::create([
                    'order_id'                => $order->id,
                    'midtrans_transaction_id' => 'TXN-' . strtoupper(substr(md5($order->id), 0, 12)),
                    'amount'                  => $total,
                    'qr_code_url'             => null,
                    'status'                  => 'settlement',
                    'paid_at'                 => $date->copy()->addMinutes(30),
                ]);
            }
        }

        $this->command->info('✅ Seeder selesai!');
        $this->command->info('');
        $this->command->info('🔑 Login credentials:');
        $this->command->info("   Email    : {$ownerEmail}");
        $this->command->info("   Password : {$ownerPassword}");
        $this->command->info('');
        $this->command->info('📊 Data demo:');
        $this->command->info('   - 1 toko: Toko Batik Nusantara');
        $this->command->info('   - 6 produk (termasuk stok rendah & nonaktif)');
        $this->command->info('   - 5 pelanggan');
        $this->command->info('   - 9 pesanan (berbagai status)');
        $this->command->info('');
        $this->command->warn('⚠  Atur SEED_OWNER_EMAIL dan SEED_OWNER_PASSWORD di .env untuk credentials custom.');
    }
}
