<?php

namespace App\Domain\Bot;

use App\Domain\Catalog\ProductService;
use App\Domain\Order\OrderService;
use App\Domain\Payment\PaymentService;
use App\Domain\Shipping\ShippingService;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MessageHandler
{
    /** Session timeout: 30 minutes of inactivity resets to IDLE */
    private const SESSION_TIMEOUT_MINUTES = 30;

    /** How many consecutive unrecognized inputs before FALLBACK_CS */
    private const FALLBACK_THRESHOLD = 2;

    public function __construct(
        private ProductService  $productService,
        private ShippingService $shippingService,
        private PaymentService  $paymentService,
        private OrderService    $orderService,
    ) {}

    /** Maximum message length accepted from WhatsApp. Anything longer is truncated before processing. */
    private const MAX_MESSAGE_LENGTH = 1000;

    /** Minimum address length required before accepting it as valid. */
    private const MIN_ADDRESS_LENGTH = 10;

    /** Maximum address length stored to DB. */
    private const MAX_ADDRESS_LENGTH = 500;

    public function handle(string $message, string $waNumber, string $businessId): array
    {
        // ── Sanitize inbound message ─────────────────────────────────────
        $message = $this->sanitizeInput($message, self::MAX_MESSAGE_LENGTH);
        $business     = Business::findOrFail($businessId);
        $customer     = $this->getOrCreateCustomer($waNumber, $businessId);
        $conversation = $this->getOrCreateConversation($customer->id);

        // ── Resolve per-business ShippingService ──────────────────────────
        $rajaongkirKey = $business->getRajaOngkirApiKeyDecrypted();
        if ($rajaongkirKey) {
            $this->shippingService = new \App\Domain\Shipping\ShippingService(
                new \App\Domain\Shipping\RajaOngkirService($rajaongkirKey)
            );
        }

        // ── Operating-hours gate ─────────────────────────────────────────
        $outsideHours = $this->isOutsideOperatingHours($business);

        // ── Session timeout — reset to IDLE ──────────────────────────────
        if ($this->isSessionExpired($conversation)) {
            $conversation->update([
                'current_state'    => StateMachine::STATES['IDLE'],
                'cart_data'        => null,
                'selected_city_id' => null,
                'selected_courier' => null,
                'fallback_count'   => 0,
                'last_activity_at' => now(),
            ]);
        }

        // ── Always-available commands ─────────────────────────────────────
        $intent = IntentParser::parse($message);

        if ($intent === 'reset') {
            $conversation->update([
                'current_state'    => StateMachine::STATES['IDLE'],
                'cart_data'        => null,
                'selected_city_id' => null,
                'selected_courier' => null,
                'fallback_count'   => 0,
                'last_activity_at' => now(),
            ]);
            return $this->handleIdle($intent, $conversation, $business, $outsideHours);
        }

        // Touch activity timestamp
        $conversation->update(['last_activity_at' => now()]);

        $state = $conversation->current_state;

        $response = $this->processState($state, $intent, $message, $conversation, $business, $outsideHours);

        return $response;
    }

    private function processState(
        string $state,
        string $intent,
        string $message,
        Conversation $conversation,
        Business $business,
        bool $outsideHours
    ): array {
        // FALLBACK_CS — bot is disabled, waiting for admin
        if ($state === StateMachine::STATES['FALLBACK_CS']) {
            return [
                'text'  => 'Admin kami sedang menangani pertanyaan Anda. Mohon tunggu sebentar 🙏',
                'state' => StateMachine::STATES['FALLBACK_CS'],
            ];
        }

        return match ($state) {
            StateMachine::STATES['IDLE']               => $this->handleIdle($intent, $conversation, $business, $outsideHours),
            StateMachine::STATES['BROWSING']           => $this->handleBrowsing($message, $intent, $conversation, $business),
            StateMachine::STATES['SELECTING_VARIANT']  => $this->handleSelectingVariant($message, $conversation),
            StateMachine::STATES['SELECTING_QTY']      => $this->handleSelectingQty($message, $conversation),
            StateMachine::STATES['CART_REVIEW']        => $this->handleCartReview($message, $conversation),
            StateMachine::STATES['SELECTING_CITY']     => $this->handleSelectingCity($message, $conversation, $business),
            StateMachine::STATES['SELECTING_COURIER']  => $this->handleSelectingCourier($message, $conversation, $business),
            StateMachine::STATES['AWAITING_PAYMENT']   => $this->handleAwaitingPayment($conversation, $message),
            StateMachine::STATES['PAID_AWAITING_ADDRESS'] => $this->handlePaidAwaitingAddress($message, $conversation, $business),
            StateMachine::STATES['COMPLETED']          => $this->handleCompleted($intent, $conversation, $business, $outsideHours),
            StateMachine::STATES['EXPIRED']            => $this->handleExpired($message, $conversation, $business),
            StateMachine::STATES['CHECKING_ORDER']     => $this->handleCheckingOrder($message, $conversation, $business),
            default                                    => $this->handleFallback($conversation),
        };
    }

    // ── State handlers ───────────────────────────────────────────────────

    private function handleIdle(string $intent, Conversation $conversation, Business $business, bool $outsideHours): array
    {
        $settings = $business->bot_settings ?? [];
        $greeting = $settings['greeting_message']
            ?? "Halo! Selamat datang di *{$business->name}* 👋\n\n"
             . "Pilih menu:\n"
             . "1️⃣ Lihat Katalog\n"
             . "2️⃣ Cek Status Pesanan\n"
             . "3️⃣ Hubungi Admin";

        $outsideNote = '';
        if ($outsideHours) {
            $hours = $business->operating_hours ?? [];
            $start = $hours['start'] ?? '08:00';
            $end   = $hours['end']   ?? '21:00';
            $outsideNote = "\n\n⏰ Di luar jam operasional ({$start}–{$end}). Pesanan tetap diproses tapi balasan mungkin lebih lambat.";
        }

        if ($intent === 'view_catalog') {
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return $this->handleBrowsing('', $intent, $conversation, $business);
        }

        if ($intent === 'check_order') {
            $conversation->update(['current_state' => StateMachine::STATES['CHECKING_ORDER']]);
            return [
                'text'  => "Silakan kirim *nomor order* Anda (format: ORD-YYYYMMDD-XXXX):",
                'state' => StateMachine::STATES['CHECKING_ORDER'],
            ];
        }

        return [
            'text'  => $greeting . $outsideNote,
            'state' => StateMachine::STATES['IDLE'],
        ];
    }

    private function handleBrowsing(string $message, string $intent, Conversation $conversation, Business $business): array
    {
        $products = $this->productService->getActiveProducts($business->id);

        if ($products->isEmpty()) {
            return [
                'text'  => 'Maaf, belum ada produk yang tersedia saat ini. Silakan coba lagi nanti.',
                'state' => StateMachine::STATES['IDLE'],
            ];
        }

        $names       = $products->pluck('name')->toArray();
        $productList = $products->values()->map(function ($p, $i) {
            $stock = $p->stock > 0 ? '' : ' _(habis)_';
            return ($i + 1) . ". *{$p->name}*{$stock} — Rp" . number_format($p->price, 0, ',', '.');
        })->implode("\n");

        // Try to match a product selection from the message
        $selectedIndex = IntentParser::matchProduct($message, $names);

        if ($selectedIndex !== null) {
            $product = $products->values()[$selectedIndex];

            if ($product->stock <= 0) {
                return [
                    'text'  => "Maaf, *{$product->name}* sedang habis stok.\n\nPilih produk lain:\n\n{$productList}",
                    'state' => StateMachine::STATES['BROWSING'],
                ];
            }

            // Additional stock validation for multi-item cart scenarios
            $cart = $conversation->cart_data ?? [];
            $existingQty = 0;
            if (!empty($cart['items'])) {
                foreach ($cart['items'] as $item) {
                    if ($item['product_id'] == $product->id) {
                        $existingQty += $item['qty'];
                    }
                }
            }
            
            if ($existingQty >= $product->stock) {
                return [
                    'text'  => "Maaf, *{$product->name}* sudah mencapai batas stok di keranjang.\n\nPilih produk lain:\n\n{$productList}",
                    'state' => StateMachine::STATES['BROWSING'],
                ];
            }

            // Has variants? Load explicitly to avoid ternary ambiguity
            if (!$product->relationLoaded('variants')) {
                $product->load('variants');
            }
            $variants = $product->variants;

            $cart = $conversation->cart_data ?? [];
            $cart['product_id']   = $product->id;
            $cart['product_name'] = $product->name;
            $cart['unit_price']   = (float) $product->price;
            $cart['weight_gram']  = $product->weight_gram ?? 500;

            $conversation->update(['cart_data' => $cart]);

            if ($variants->isNotEmpty()) {
                $variantList = $variants->values()->map(fn ($v, $i) =>
                    ($i + 1) . ". {$v->variant_name}"
                    . ($v->price_override ? " (+Rp" . number_format((float)$v->price_override - (float)$product->price, 0, ',', '.') . ")" : "")
                )->implode("\n");

                $conversation->update(['current_state' => StateMachine::STATES['SELECTING_VARIANT']]);

                return [
                    'text'  => "Pilih varian untuk *{$product->name}*:\n\n{$variantList}",
                    'state' => StateMachine::STATES['SELECTING_VARIANT'],
                ];
            }

            $conversation->update(['current_state' => StateMachine::STATES['SELECTING_QTY']]);

            return [
                'text'  => "*{$product->name}*\nHarga: Rp" . number_format($product->price, 0, ',', '.') . "\nStok: {$product->stock}\n\nMau berapa pcs?",
                'state' => StateMachine::STATES['SELECTING_QTY'],
            ];
        }

        return [
            'text'  => "📦 *Katalog Produk {$business->name}:*\n\n{$productList}\n\nKetik nomor atau nama produk untuk memilih:",
            'state' => StateMachine::STATES['BROWSING'],
        ];
    }

    private function handleSelectingVariant(string $message, Conversation $conversation): array
    {
        $cart    = $conversation->cart_data ?? [];
        $product = \App\Models\Product::with('variants')->find($cart['product_id'] ?? null);

        if (!$product) {
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return ['text' => 'Terjadi kesalahan. Silakan pilih produk lagi.', 'state' => StateMachine::STATES['BROWSING']];
        }

        $variants    = $product->variants->values();
        $variantList = $variants->map(fn ($v, $i) => ($i + 1) . ". {$v->variant_name}")->implode("\n");

        $num = IntentParser::extractMenuNumber($message);

        if ($num === null || $num < 1 || $num > $variants->count()) {
            return [
                'text'  => "Pilih nomor varian yang valid:\n\n{$variantList}",
                'state' => StateMachine::STATES['SELECTING_VARIANT'],
            ];
        }

        $selectedVariant = $variants[$num - 1];
        $finalPrice      = $selectedVariant->price_override ?? $product->price;

        $cart['variant_id']   = $selectedVariant->id;
        $cart['variant_name'] = $selectedVariant->variant_name;
        $cart['unit_price']   = (float) $finalPrice;
        $conversation->update(['cart_data' => $cart, 'current_state' => StateMachine::STATES['SELECTING_QTY']]);

        return [
            'text'  => "*{$product->name}* — {$selectedVariant->variant_name}\nHarga: Rp" . number_format($finalPrice, 0, ',', '.') . "\n\nMau berapa pcs?",
            'state' => StateMachine::STATES['SELECTING_QTY'],
        ];
    }

    private function handleSelectingQty(string $message, Conversation $conversation): array
    {
        $qty = IntentParser::extractQuantity($message);

        if (!$qty || $qty < 1 || $qty > 999) {
            return [
                'text'  => 'Mohon masukkan jumlah yang valid (angka 1 atau lebih).',
                'state' => StateMachine::STATES['SELECTING_QTY'],
            ];
        }

        $cart = $conversation->cart_data ?? [];
        $productId = $cart['product_id'] ?? null;
        
        // Validate stock availability
        if ($productId) {
            $product = \App\Models\Product::find($productId);
            if ($product && $qty > $product->stock) {
                return [
                    'text'  => "Maaf, stok *{$product->name}* tidak mencukupi. Tersedia: {$product->stock} pcs. Mohon kurangi jumlah.",
                    'state' => StateMachine::STATES['SELECTING_QTY'],
                ];
            }
        }

        $unitPrice = (float) ($cart['unit_price'] ?? 0);
        $subtotal  = $unitPrice * $qty;

        $cart['qty']         = $qty;
        $cart['subtotal']    = $subtotal;
        $cart['total_weight']= ($cart['weight_gram'] ?? 500) * $qty;

        // Support multi-item cart (items array)
        $items   = $cart['items'] ?? [];
        $items[] = [
            'product_id'   => $cart['product_id'],
            'product_name' => $cart['product_name'],
            'variant_id'   => $cart['variant_id'] ?? null,
            'variant_name' => $cart['variant_name'] ?? null,
            'qty'          => $qty,
            'unit_price'   => $unitPrice,
            'subtotal'     => $subtotal,
            'weight_gram'  => $cart['weight_gram'] ?? 500,
        ];
        $cart['items']         = $items;
        $cart['cart_subtotal'] = collect($items)->sum('subtotal');
        $cart['cart_weight']   = collect($items)->sum(fn ($i) => $i['weight_gram'] * $i['qty']);

        $conversation->update(['cart_data' => $cart, 'current_state' => StateMachine::STATES['CART_REVIEW']]);

        return $this->buildCartReviewResponse($cart);
    }

    private function handleCartReview(string $message, Conversation $conversation): array
    {
        $cart = $conversation->cart_data ?? [];

        if (empty($cart['items'])) {
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return ['text' => 'Keranjang kosong. Silakan pilih produk terlebih dahulu.', 'state' => StateMachine::STATES['BROWSING']];
        }

        $intent = IntentParser::parse($message);

        // Allow navigation intents to break out of cart review
        if ($intent === 'view_catalog') {
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return $this->handleBrowsing($message, $intent, $conversation, $conversation->customer->business);
        }

        if ($intent === 'check_order') {
            $conversation->update(['current_state' => StateMachine::STATES['CHECKING_ORDER']]);
            return [
                'text'  => "Silakan kirim *nomor order* Anda (format: ORD-YYYYMMDD-XXXX):",
                'state' => StateMachine::STATES['CHECKING_ORDER'],
            ];
        }

        if (IntentParser::isYes($message)) {
            // Add more items
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return ['text' => 'Silakan pilih produk lain untuk ditambahkan:', 'state' => StateMachine::STATES['BROWSING']];
        }

        if (IntentParser::isNo($message)) {
            $conversation->update(['current_state' => StateMachine::STATES['SELECTING_CITY']]);
            return [
                'text'  => "Kirim ke kota mana? 🏙️\n\nKetik nama kota tujuan pengiriman:",
                'state' => StateMachine::STATES['SELECTING_CITY'],
            ];
        }

        return $this->buildCartReviewResponse($cart);
    }

    private function buildCartReviewResponse(array $cart): array
    {
        $lines    = collect($cart['items'] ?? [])->map(fn ($item) =>
            "• {$item['product_name']}"
            . ($item['variant_name'] ? " ({$item['variant_name']})" : '')
            . " x{$item['qty']} = Rp" . number_format($item['subtotal'], 0, ',', '.')
        )->implode("\n");

        $subtotal = number_format($cart['cart_subtotal'] ?? 0, 0, ',', '.');

        return [
            'text'  => "🛒 *Keranjang belanja Anda:*\n\n{$lines}\n\n*Subtotal: Rp{$subtotal}*\n\nTambah produk lain? Balas *ya* atau *tidak* untuk lanjut ke pengiriman.",
            'state' => StateMachine::STATES['CART_REVIEW'],
        ];
    }

    private function handleSelectingCity(string $message, Conversation $conversation, Business $business): array
    {
        // Check if user is picking from a disambiguation list
        $cart     = $conversation->cart_data ?? [];
        $cityList = $cart['city_candidates'] ?? null;

        if ($cityList) {
            $num = IntentParser::extractMenuNumber($message);
            if ($num && $num >= 1 && $num <= count($cityList)) {
                $city = $cityList[$num - 1];
                unset($cart['city_candidates']);
                $conversation->update([
                    'cart_data'          => $cart,
                    'selected_city_id'   => $city['city_id'],
                    'selected_city_name' => $city['city_name'],
                    'current_state'      => StateMachine::STATES['SELECTING_COURIER'],
                ]);
                return $this->handleSelectingCourier('', $conversation, $business);
            }
        }

        $cityName = IntentParser::extractCity($message);

        if (!$cityName) {
            return ['text' => 'Ketik nama kota tujuan pengiriman:', 'state' => StateMachine::STATES['SELECTING_CITY']];
        }

        $cities = $this->shippingService->searchCity($cityName);

        if ($cities->isEmpty()) {
            return [
                'text'  => "Kota *{$cityName}* tidak ditemukan. Coba ketik dengan nama yang lebih lengkap:",
                'state' => StateMachine::STATES['SELECTING_CITY'],
            ];
        }

        if ($cities->count() === 1) {
            $city = $cities->first();
            $conversation->update([
                'selected_city_id'   => $city->city_id,
                'selected_city_name' => $city->city_name,
                'current_state'      => StateMachine::STATES['SELECTING_COURIER'],
            ]);
            return $this->handleSelectingCourier('', $conversation, $business);
        }

        // Multiple matches — ask user to disambiguate
        $candidates  = $cities->take(5)->values();
        $candidateList = $candidates->map(fn ($c, $i) => ($i + 1) . ". {$c->city_name} ({$c->province_name})")->implode("\n");

        $cart['city_candidates'] = $candidates->map(fn ($c) => [
            'city_id'   => $c->city_id,
            'city_name' => $c->city_name,
        ])->toArray();

        $conversation->update(['cart_data' => $cart]);

        return [
            'text'  => "Beberapa kota ditemukan:\n\n{$candidateList}\n\nKetik nomor kota yang benar:",
            'state' => StateMachine::STATES['SELECTING_CITY'],
        ];
    }

    private function handleSelectingCourier(string $message, Conversation $conversation, Business $business): array
    {
        $cart   = $conversation->cart_data ?? [];
        $cityId = $conversation->selected_city_id;

        if (!$cityId) {
            $conversation->update(['current_state' => StateMachine::STATES['SELECTING_CITY']]);
            return ['text' => 'Kirim ke kota mana? Ketik nama kota tujuan:', 'state' => StateMachine::STATES['SELECTING_CITY']];
        }

        // If no message / first entry — show options
        $shippingOptions = $this->shippingService->calculateShipping(
            (string) $business->origin_city_id,
            (string) $cityId,
            (int) ($cart['cart_weight'] ?? 1000)
        );

        // shippingOptions is now an array (from ShippingService::calculateShipping)
        if (empty($shippingOptions)) {
            $conversation->update(['current_state' => StateMachine::STATES['SELECTING_CITY']]);
            return [
                'text'  => "Maaf, tidak ada layanan pengiriman tersedia ke kota tersebut.\nSilakan pilih kota lain:",
                'state' => StateMachine::STATES['SELECTING_CITY'],
            ];
        }

        // If message has a number — handle selection
        $num = IntentParser::extractMenuNumber($message);
        $allOptions = $cart['shipping_options'] ?? null;

        // Cache shipping options in cart so we can reference them on selection
        if (!$allOptions) {
            $selfPickup   = [['courier' => 'Ambil Sendiri', 'service' => '', 'cost' => 0, 'etd' => '-']];
            $allOptions   = array_merge($shippingOptions, $selfPickup);
            $cart['shipping_options'] = $allOptions;
            $conversation->update(['cart_data' => $cart]);
        }

        if ($num !== null && $num >= 1 && $num <= count($allOptions)) {
            $chosen         = $allOptions[$num - 1];
            $shippingCost   = (float) $chosen['cost'];
            $cartSubtotal   = (float) ($cart['cart_subtotal'] ?? 0);
            $totalAmount    = $cartSubtotal + $shippingCost;

            $cart['selected_courier'] = $chosen;
            $cart['shipping_cost']    = $shippingCost;
            $cart['total_amount']     = $totalAmount;

            $conversation->update([
                'cart_data'       => $cart,
                'selected_courier'=> $chosen,
                'current_state'   => StateMachine::STATES['AWAITING_PAYMENT'],
            ]);

            // Generate payment
            return $this->generatePayment($cart, $conversation, $business, $chosen);
        }

        // Show courier list
        $lines = collect($allOptions)->map(function ($opt, $i) {
            $cost = $opt['cost'] == 0 ? 'Gratis' : 'Rp' . number_format($opt['cost'], 0, ',', '.');
            $etd  = $opt['etd'] && $opt['etd'] !== '-' ? " ({$opt['etd']} hari)" : '';
            return ($i + 1) . ". {$opt['courier']} {$opt['service']}{$etd} — {$cost}";
        })->implode("\n");

        $subtotal = number_format($cart['cart_subtotal'] ?? 0, 0, ',', '.');

        return [
            'text'  => "🚚 *Pilihan pengiriman ke {$conversation->selected_city_name}:*\n\n{$lines}\n\nSubtotal produk: Rp{$subtotal}\n\nKetik nomor pilihan:",
            'state' => StateMachine::STATES['SELECTING_COURIER'],
        ];
    }

    private function generatePayment(array $cart, Conversation $conversation, Business $business, array $courier): array
    {
        try {
            $customer = $conversation->customer;
            $items    = collect($cart['items'] ?? [])->map(fn ($item) => [
                'id'       => $item['product_id'],
                'name'     => $item['product_name'],
                'price'    => (int) $item['unit_price'],
                'quantity' => $item['qty'],
            ])->toArray();

            // Add shipping line item
            if (($courier['cost'] ?? 0) > 0) {
                $items[] = ['id' => 'SHIPPING', 'name' => 'Ongkos Kirim', 'price' => (int) $courier['cost'], 'quantity' => 1];
            }

            // Create order first
            $orderData = [
                'customer'       => ['wa_number' => $customer->wa_number, 'name' => $customer->name],
                'items'          => collect($cart['items'] ?? [])->map(fn ($i) => [
                    'product_id'  => $i['product_id'],
                    'variant_id'  => $i['variant_id'] ?? null,
                    'variant_name'=> $i['variant_name'] ?? null,
                    'qty'         => $i['qty'],
                    'price'       => $i['unit_price'],
                ])->toArray(),
                'subtotal'       => $cart['cart_subtotal'] ?? 0,
                'shipping_cost'  => $courier['cost'] ?? 0,
                'total_amount'   => $cart['total_amount'] ?? 0,
                'courier_name'   => $courier['courier'] ?? null,
                'courier_service'=> $courier['service'] ?? null,
            ];

            $order   = $this->orderService->createOrder($orderData, $business->id);
            $payment = $this->paymentService->createPayment($order, [
                'name'  => $customer->name ?? 'Customer',
                'email' => $customer->email ?? 'customer@example.com',
                'phone' => $customer->wa_number,
            ]);

            $totalFormatted = number_format($cart['total_amount'] ?? 0, 0, ',', '.');
            $qrUrl  = $payment->qr_code_url ?? null;

            // Store order_id in cart for reference
            $cart['order_id'] = $order->id;
            $conversation->update(['cart_data' => $cart]);

            $qrNote = $qrUrl
                ? "\n\n📱 *QR Code QRIS dikirimkan!* Scan untuk bayar."
                : "\n\n_QR Code sedang disiapkan..._";

            return [
                'text'    => "✅ *Ringkasan pesanan:*\n\n"
                           . "Order: *#{$order->order_number}*\n"
                           . "Total: *Rp{$totalFormatted}*\n"
                           . "(Produk + Ongkir {$courier['courier']})\n"
                           . $qrNote
                           . "\n\n⏰ QR berlaku *15 menit*. Bayar sebelum kedaluwarsa.",
                'qr_url'  => $qrUrl,
                'state'   => StateMachine::STATES['AWAITING_PAYMENT'],
            ];

        } catch (\Throwable $e) {
            Log::error('MessageHandler: generatePayment failed', ['error' => $e->getMessage()]);
            return [
                'text'  => 'Maaf, gagal membuat tagihan. Silakan ketik *menu* dan coba lagi, atau hubungi admin.',
                'state' => StateMachine::STATES['FALLBACK_CS'],
            ];
        }
    }

    private function handleAwaitingPayment(Conversation $conversation, string $message): array
    {
        // Check if payment already settled (webhook may have updated it)
        $cart    = $conversation->cart_data ?? [];
        $orderId = $cart['order_id'] ?? null;

        if ($orderId) {
            $order = \App\Models\Order::find($orderId);
            if ($order && $order->status === 'paid') {
                // Already paid — move forward
                $conversation->update(['current_state' => StateMachine::STATES['PAID_AWAITING_ADDRESS']]);
                return [
                    'text'  => "✅ Pembayaran sudah dikonfirmasi!\n\nSilakan kirim *alamat lengkap* Anda untuk pengiriman:",
                    'state' => StateMachine::STATES['PAID_AWAITING_ADDRESS'],
                ];
            }
        }

        return [
            'text'  => "⏳ Menunggu pembayaran.\n\nKetik *menu* jika ingin memulai ulang, atau tunggu konfirmasi otomatis setelah pembayaran diterima.",
            'state' => StateMachine::STATES['AWAITING_PAYMENT'],
        ];
    }

    private function handlePaidAwaitingAddress(string $message, Conversation $conversation, Business $business): array
    {
        $message = $this->sanitizeInput($message, self::MAX_ADDRESS_LENGTH);

        if (strlen(trim($message)) < self::MIN_ADDRESS_LENGTH) {
            return [
                'text'  => "Mohon kirim alamat *lengkap* (nama jalan, nomor rumah, RT/RW, kelurahan, kota, kode pos):",
                'state' => StateMachine::STATES['PAID_AWAITING_ADDRESS'],
            ];
        }

        // Save address to order
        $cart    = $conversation->cart_data ?? [];
        $orderId = $cart['order_id'] ?? null;

        if ($orderId) {
            $customer = $conversation->customer;
            \App\Models\Address::create([
                'order_id'        => $orderId,
                'city_id'         => $conversation->selected_city_id,
                'city_name'       => $conversation->selected_city_name,
                'full_address'    => $message,
                'recipient_name'  => $customer?->name ?? 'Customer',
                'recipient_phone' => $customer?->wa_number ?? '',
            ]);
        }

        $conversation->update(['current_state' => StateMachine::STATES['COMPLETED']]);

        // Notify business owner
        if ($business->wa_phone_number) {
            $order = \App\Models\Order::find($orderId);
            if ($order) {
                $courier = $cart['selected_courier']['courier'] ?? 'Kurir';
                $service = $cart['selected_courier']['service'] ?? '';
                $total   = 'Rp' . number_format($order->total_amount, 0, ',', '.');
                $ownerMsg = "📦 *Alamat pesanan masuk!*\n\n"
                           . "Order: *#{$order->order_number}*\n"
                           . "Total: {$total}\n"
                           . "Kurir: {$courier} {$service}\n"
                           . "Alamat: {$message}\n\n"
                           . "Segera proses pengiriman!";
                dispatch(new \App\Jobs\SendWhatsAppNotification($business->wa_phone_number, $ownerMsg, $business->id));
            }
        }

        return [
            'text'  => "🎉 *Pesanan #{$orderId} dikonfirmasi!*\n\nAlamat pengiriman telah diterima. Kami akan segera memproses dan mengirim paket Anda.\n\nTerima kasih telah berbelanja di *{$business->name}*! 🙏",
            'state' => StateMachine::STATES['COMPLETED'],
        ];
    }

    private function handleCompleted(string $intent, Conversation $conversation, Business $business, bool $outsideHours): array
    {
        // Reset to IDLE so customer can shop again
        $conversation->update([
            'current_state'    => StateMachine::STATES['IDLE'],
            'cart_data'        => null,
            'selected_city_id' => null,
            'selected_courier' => null,
            'fallback_count'   => 0,
        ]);
        return $this->handleIdle($intent, $conversation, $business, $outsideHours);
    }

    private function handleExpired(string $message, Conversation $conversation, Business $business): array
    {
        if (IntentParser::isYes($message) || IntentParser::extractMenuNumber($message) !== null) {
            // Regenerate QR with same cart
            $cart = $conversation->cart_data ?? [];
            if (!empty($cart['items']) && !empty($cart['selected_courier'])) {
                $conversation->update(['current_state' => StateMachine::STATES['AWAITING_PAYMENT']]);
                return $this->generatePayment($cart, $conversation, $business, $cart['selected_courier']);
            }
        }

        $conversation->update(['current_state' => StateMachine::STATES['IDLE']]);
        return [
            'text'  => "⏰ Sesi pembayaran telah kedaluwarsa.\n\nKetik *menu* untuk memulai pesanan baru.",
            'state' => StateMachine::STATES['EXPIRED'],
        ];
    }

    private function handleCheckingOrder(string $message, Conversation $conversation, Business $business): array
    {
        // Extract order number — accept "ORD-20260716-0001" or just "0001" or "#ORD-xxx"
        $msg         = strtoupper(trim(ltrim($message, '#')));
        $orderNumber = null;

        // Full format match
        if (preg_match('/ORD-\d{8}-\d{4}/', $msg, $m)) {
            $orderNumber = $m[0];
        }

        // Always reset to IDLE after this interaction
        $conversation->update(['current_state' => StateMachine::STATES['IDLE']]);

        if (!$orderNumber) {
            return [
                'text'  => "Format nomor order tidak dikenali. Contoh yang benar: *ORD-20260716-0001*\n\nKetik *menu* untuk kembali ke menu utama.",
                'state' => StateMachine::STATES['IDLE'],
            ];
        }

        $customer = $conversation->customer;

        $order = \App\Models\Order::with(['items.product', 'address', 'payment'])
            ->where('order_number', $orderNumber)
            ->where('business_id', $business->id)
            ->where('customer_id', $customer?->id)
            ->first();

        if (!$order) {
            return [
                'text'  => "Order *#{$orderNumber}* tidak ditemukan.\n\nPastikan nomor order sudah benar, atau ketik *menu* untuk kembali.",
                'state' => StateMachine::STATES['IDLE'],
            ];
        }

        // Status label mapping
        $statusLabels = [
            'pending'    => '⏳ Menunggu Pembayaran',
            'paid'       => '✅ Pembayaran Diterima',
            'processing' => '🔄 Sedang Diproses',
            'shipped'    => '🚚 Dalam Pengiriman',
            'completed'  => '✅ Selesai',
            'cancelled'  => '❌ Dibatalkan',
            'expired'    => '⏰ Kedaluwarsa',
            'refunded'   => '🔙 Direfund',
        ];

        $statusLabel = $statusLabels[$order->status] ?? ucfirst($order->status);
        $total       = 'Rp' . number_format($order->total_amount, 0, ',', '.');
        $courier     = trim(($order->courier_name ?? '') . ' ' . ($order->courier_service ?? ''));

        $text = "📦 *Status Order #{$order->order_number}*\n\n"
              . "Status: *{$statusLabel}*\n"
              . "Total: *{$total}*\n";

        if ($courier) {
            $text .= "Kurir: {$courier}\n";
        }

        if ($order->tracking_number) {
            $text .= "No. Resi: *{$order->tracking_number}*\n";
        }

        if ($order->address) {
            $text .= "Tujuan: {$order->address->city_name}\n";
        }

        $text .= "\nKetik *menu* untuk kembali ke menu utama.";

        return [
            'text'  => $text,
            'state' => StateMachine::STATES['IDLE'],
        ];
    }

    private function handleFallback(Conversation $conversation): array
    {
        $count = ($conversation->fallback_count ?? 0) + 1;
        $conversation->update(['fallback_count' => $count]);

        if ($count >= self::FALLBACK_THRESHOLD) {
            $conversation->update(['current_state' => StateMachine::STATES['FALLBACK_CS'], 'fallback_count' => 0]);
            return [
                'text'  => "Maaf, saya tidak dapat memahami pesan Anda. 😔\n\nAdmin kami akan segera membantu. Mohon tunggu sebentar.",
                'state' => StateMachine::STATES['FALLBACK_CS'],
            ];
        }

        $settings = $conversation->customer?->business?->bot_settings ?? [];
        $fallbackMsg = $settings['fallback_message']
            ?? "Maaf, saya tidak mengerti. Ketik *menu* untuk kembali ke menu utama.";

        return ['text' => $fallbackMsg, 'state' => $conversation->current_state];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function getOrCreateCustomer(string $waNumber, string $businessId): Customer
    {
        return Customer::firstOrCreate(
            ['wa_number' => $waNumber, 'business_id' => $businessId],
            ['name' => null, 'email' => null]
        );
    }

    private function getOrCreateConversation(string $customerId): Conversation
    {
        return Conversation::firstOrCreate(
            ['customer_id' => $customerId],
            [
                'current_state'    => StateMachine::getInitialState(),
                'last_activity_at' => now(),
                'fallback_count'   => 0,
            ]
        );
    }

    private function normalizeNumber(string $number): string
    {
        $n = preg_replace('/[^0-9]/', '', $number);
        if (str_starts_with($n, '0')) {
            $n = '62' . substr($n, 1);
        } elseif (!str_starts_with($n, '62')) {
            $n = '62' . $n;
        }
        return $n;
    }

    private function isSessionExpired(Conversation $conversation): bool
    {
        if (!$conversation->last_activity_at) {
            return false;
        }
        return $conversation->last_activity_at->diffInMinutes(now()) >= self::SESSION_TIMEOUT_MINUTES;
    }

    private function isOutsideOperatingHours(Business $business): bool
    {
        $hours = $business->operating_hours ?? [];

        if (empty($hours['enabled'])) {
            return false;
        }

        $tz    = $hours['timezone'] ?? 'Asia/Jakarta';
        $now   = Carbon::now($tz);
        $start = Carbon::createFromTimeString($hours['start'] ?? '08:00', $tz);
        $end   = Carbon::createFromTimeString($hours['end']   ?? '21:00', $tz);

        // Handle overnight hours (e.g., 21:00–02:00)
        if ($start->greaterThan($end)) {
            // Outside hours when NOT between end and start (overnight period)
            return !$now->between($end, $start);
        }

        return !$now->between($start, $end);
    }

    /**
     * Strip control characters, null bytes, and truncate to $maxLen.
     * Does NOT HTML-encode — output is plain text stored in DB and
     * sent as WhatsApp text, so htmlspecialchars would break emojis.
     */
    private function sanitizeInput(string $input, int $maxLen = 1000): string
    {
        // Remove null bytes and dangerous control chars (keep \n \t)
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);

        if ($cleaned === null) {
            $cleaned = $input;
        }

        // Collapse excessive blank lines (>2 consecutive newlines)
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);

        if ($cleaned === null) {
            $cleaned = $input;
        }

        return mb_substr(trim($cleaned), 0, $maxLen);
    }
}
