<?php

namespace App\Domain\Bot;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Business;
use App\Domain\Catalog\ProductService;
use App\Domain\Shipping\ShippingService;
use App\Domain\Payment\PaymentService;

class MessageHandler
{
    public function __construct(
        private ProductService $productService,
        private ShippingService $shippingService,
        private PaymentService $paymentService
    ) {}

    public function handle(string $message, string $waNumber, string $businessId): array
    {
        $business = Business::findOrFail($businessId);
        $customer = $this->getOrCreateCustomer($waNumber, $businessId);
        $conversation = $this->getOrCreateConversation($customer->id);

        $currentState = $conversation->current_state;
        $intent = IntentParser::parse($message);

        return $this->processState($currentState, $intent, $message, $conversation, $business);
    }

    private function getOrCreateCustomer(string $waNumber, string $businessId): Customer
    {
        return Customer::firstOrCreate(
            ['wa_number' => $waNumber, 'business_id' => $businessId],
            ['business_id' => $businessId]
        );
    }

    private function getOrCreateConversation(string $customerId): Conversation
    {
        return Conversation::firstOrCreate(
            ['customer_id' => $customerId],
            ['current_state' => StateMachine::getInitialState()]
        );
    }

    private function processState(string $currentState, string $intent, string $message, Conversation $conversation, Business $business): array
    {
        switch ($currentState) {
            case StateMachine::STATES['IDLE']:
                return $this->handleIdle($intent, $conversation, $business);
            
            case StateMachine::STATES['BROWSING']:
                return $this->handleBrowsing($message, $conversation, $business);
            
            case StateMachine::STATES['SELECTING_QTY']:
                return $this->handleSelectingQty($message, $conversation);
            
            case StateMachine::STATES['CART_REVIEW']:
                return $this->handleCartReview($message, $conversation);
            
            case StateMachine::STATES['SELECTING_CITY']:
                return $this->handleSelectingCity($message, $conversation, $business);
            
            case StateMachine::STATES['SELECTING_COURIER']:
                return $this->handleSelectingCourier($message, $conversation, $business);
            
            case StateMachine::STATES['AWAITING_PAYMENT']:
                return $this->handleAwaitingPayment($conversation);
            
            case StateMachine::STATES['PAID_AWAITING_ADDRESS']:
                return $this->handlePaidAwaitingAddress($message, $conversation);
            
            default:
                return $this->handleFallback($conversation);
        }
    }

    private function handleIdle(string $intent, Conversation $conversation, Business $business): array
    {
        $response = [
            'text' => "Halo! Selamat datang di {$business->name} 👋\n\n1️⃣ Lihat Katalog\n2️⃣ Tanya Produk\n3️⃣ Cek Status Pesanan",
            'state' => StateMachine::STATES['IDLE']
        ];

        if ($intent === 'view_catalog') {
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return $this->handleBrowsing('', $conversation, $business);
        }

        return $response;
    }

    private function handleBrowsing(string $message, Conversation $conversation, Business $business): array
    {
        $products = $this->productService->getActiveProducts($business->id);
        
        if ($products->isEmpty()) {
            return [
                'text' => 'Maaf, belum ada produk yang tersedia saat ini.',
                'state' => StateMachine::STATES['BROWSING']
            ];
        }

        $productList = $products->map(function ($product, $index) {
            return ($index + 1) . ". {$product->name} - Rp" . number_format($product->price, 0, ',', '.');
        })->implode("\n");

        return [
            'text' => "📦 Katalog Produk:\n\n{$productList}\n\nKetik nomor atau nama produk untuk memilih:",
            'state' => StateMachine::STATES['BROWSING']
        ];
    }

    private function handleSelectingQty(string $message, Conversation $conversation): array
    {
        $qty = IntentParser::extractQuantity($message);
        
        if (!$qty || $qty < 1) {
            return [
                'text' => 'Mohon masukkan jumlah yang valid (minimal 1)',
                'state' => StateMachine::STATES['SELECTING_QTY']
            ];
        }

        // Update cart with quantity
        $cart = $conversation->cart_data ?? [];
        $cart['qty'] = $qty;
        $conversation->update(['cart_data' => $cart]);

        $conversation->update(['current_state' => StateMachine::STATES['CART_REVIEW']]);
        
        return $this->handleCartReview('', $conversation);
    }

    private function handleCartReview(string $message, Conversation $conversation): array
    {
        $cart = $conversation->cart_data ?? [];
        
        if (empty($cart)) {
            return [
                'text' => 'Keranjang masih kosong. Silakan pilih produk terlebih dahulu.',
                'state' => StateMachine::STATES['BROWSING']
            ];
        }

        $response = "Ringkasan Pesanan:\n";
        $response .= "{$cart['product_name']} x{$cart['qty']} = Rp" . number_format($cart['subtotal'], 0, ',', '.');
        $response .= "\n\nTambah produk lain? (Ya/Tidak)";

        if (strtolower($message) === 'ya' || strtolower($message) === 'y') {
            $conversation->update(['current_state' => StateMachine::STATES['BROWSING']]);
            return [
                'text' => 'Silakan pilih produk lain:',
                'state' => StateMachine::STATES['BROWSING']
            ];
        }

        if (strtolower($message) === 'tidak' || strtolower($message) === 't' || strtolower($message) === 'n') {
            $conversation->update(['current_state' => StateMachine::STATES['SELECTING_CITY']]);
            return [
                'text' => 'Kirim ke kota mana? (Ketik nama kota)',
                'state' => StateMachine::STATES['SELECTING_CITY']
            ];
        }

        return [
            'text' => $response,
            'state' => StateMachine::STATES['CART_REVIEW']
        ];
    }

    private function handleSelectingCity(string $message, Conversation $conversation, Business $business): array
    {
        $cityName = IntentParser::extractCity($message);
        
        if (!$cityName) {
            return [
                'text' => 'Mohon ketik nama kota yang valid',
                'state' => StateMachine::STATES['SELECTING_CITY']
            ];
        }

        // Search for city in cache
        $cities = $this->shippingService->searchCity($cityName);
        
        if ($cities->isEmpty()) {
            return [
                'text' => 'Kota tidak ditemukan. Mohon ketik nama kota lain.',
                'state' => StateMachine::STATES['SELECTING_CITY']
            ];
        }

        // If multiple matches, ask for clarification
        if ($cities->count() > 1) {
            $cityList = $cities->take(3)->map(function ($city, $index) {
                return ($index + 1) . ". {$city->city_name}";
            })->implode("\n");
            
            return [
                'text' => "Beberapa kota ditemukan:\n\n{$cityList}\n\nPilih nomor:",
                'state' => StateMachine::STATES['SELECTING_CITY']
            ];
        }

        $selectedCity = $cities->first();
        $conversation->update([
            'selected_city_id' => $selectedCity->city_id,
            'selected_city_name' => $selectedCity->city_name,
            'current_state' => StateMachine::STATES['SELECTING_COURIER']
        ]);

        return $this->handleSelectingCourier('', $conversation, $business);
    }

    private function handleSelectingCourier(string $message, Conversation $conversation, Business $business): array
    {
        $cart = $conversation->cart_data ?? [];
        $cityId = $conversation->selected_city_id;
        
        if (!$cityId) {
            return [
                'text' => 'Mohon pilih kota terlebih dahulu',
                'state' => StateMachine::STATES['SELECTING_CITY']
            ];
        }

        // Calculate shipping cost
        $shippingOptions = $this->shippingService->calculateShipping(
            $business->origin_city_id,
            $cityId,
            $cart['total_weight'] ?? 1000
        );

        if ($shippingOptions->isEmpty()) {
            return [
                'text' => 'Maaf, tidak ada layanan pengiriman ke kota tersebut.',
                'state' => StateMachine::STATES['SELECTING_CITY']
            ];
        }

        $courierList = $shippingOptions->map(function ($option, $index) {
            $cost = number_format($option['cost'], 0, ',', '.');
            return ($index + 1) . ". {$option['courier']} - {$option['service']} - Rp{$cost} ({$option['etd']})";
        })->implode("\n");

        // Add self-pickup option
        $courierList .= "\n4. Ambil Sendiri di Toko - Gratis";

        return [
            'text' => "Pilihan pengiriman:\n\n{$courierList}\n\nPilih nomor:",
            'state' => StateMachine::STATES['SELECTING_COURIER']
        ];
    }

    private function handleAwaitingPayment(Conversation $conversation): array
    {
        return [
            'text' => 'Menunggu pembayaran. QR Code telah dikirimkan.',
            'state' => StateMachine::STATES['AWAITING_PAYMENT']
        ];
    }

    private function handlePaidAwaitingAddress(string $message, Conversation $conversation): array
    {
        if (strlen($message) < 10) {
            return [
                'text' => 'Mohon masukkan alamat lengkap (nama jalan, RT/RW, patokan)',
                'state' => StateMachine::STATES['PAID_AWAITING_ADDRESS']
            ];
        }

        // Process address and complete order
        $conversation->update(['current_state' => StateMachine::STATES['COMPLETED']]);
        
        return [
            'text' => 'Alamat diterima. Pesanan Anda sedang diproses. Terima kasih!',
            'state' => StateMachine::STATES['COMPLETED']
        ];
    }

    private function handleFallback(Conversation $conversation): array
    {
        $conversation->update(['current_state' => StateMachine::STATES['FALLBACK_CS']]);
        
        return [
            'text' => 'Maaf, saya tidak mengerti. Admin kami akan segera membantu Anda.',
            'state' => StateMachine::STATES['FALLBACK_CS']
        ];
    }
}