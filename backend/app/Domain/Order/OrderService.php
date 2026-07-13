<?php

namespace App\Domain\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Customer;
use App\Models\Address;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function createOrder(array $data, string $businessId): Order
    {
        return DB::transaction(function () use ($data, $businessId) {
            $customer = $this->getOrCreateCustomer($data['customer'], $businessId);
            
            $orderNumber = $this->generateOrderNumber($businessId);

            $order = Order::create([
                'business_id' => $businessId,
                'customer_id' => $customer->id,
                'order_number' => $orderNumber,
                'subtotal' => $data['subtotal'],
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'total_amount' => $data['total_amount'],
                'status' => 'pending',
                'courier_name' => $data['courier_name'] ?? null,
                'courier_service' => $data['courier_service'] ?? null,
            ]);

            // Create order items
            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'qty' => $item['qty'],
                    'price_at_order' => $item['price'],
                    'variant_name' => $item['variant_name'] ?? null,
                ]);
            }

            // Create address if provided
            if (isset($data['address'])) {
                Address::create([
                    'order_id' => $order->id,
                    'city_id' => $data['address']['city_id'] ?? null,
                    'subdistrict_id' => $data['address']['subdistrict_id'] ?? null,
                    'city_name' => $data['address']['city_name'] ?? null,
                    'subdistrict_name' => $data['address']['subdistrict_name'] ?? null,
                    'full_address' => $data['address']['full_address'],
                    'recipient_name' => $data['address']['recipient_name'],
                    'recipient_phone' => $data['address']['recipient_phone'],
                    'postal_code' => $data['address']['postal_code'] ?? null,
                    'notes' => $data['address']['notes'] ?? null,
                ]);
            }

            return $order->load(['items.product', 'customer', 'address']);
        });
    }

    public function getOrderById(string $orderId, string $businessId): ?Order
    {
        return Order::with(['items.product', 'items.variant', 'customer', 'address', 'payment'])
            ->where('id', $orderId)
            ->where('business_id', $businessId)
            ->first();
    }

    public function getOrderByNumber(string $orderNumber, string $businessId): ?Order
    {
        return Order::with(['items.product', 'items.variant', 'customer', 'address', 'payment'])
            ->where('order_number', $orderNumber)
            ->where('business_id', $businessId)
            ->first();
    }

    public function getOrdersByBusiness(string $businessId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Order::with(['items.product', 'customer', 'payment'])
            ->where('business_id', $businessId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('wa_number', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function updateOrderStatus(string $orderId, string $status, string $businessId): Order
    {
        $order = $this->getOrderById($orderId, $businessId);
        
        if (!$order) {
            throw new \Exception('Order not found');
        }

        $order->update(['status' => $status]);

        if ($status === 'shipped') {
            $order->update(['shipped_at' => now()]);
        }

        if ($status === 'completed') {
            $order->update(['completed_at' => now()]);
        }

        return $order->fresh();
    }

    public function updateTrackingNumber(string $orderId, string $trackingNumber, string $businessId): Order
    {
        $order = $this->getOrderById($orderId, $businessId);
        
        if (!$order) {
            throw new \Exception('Order not found');
        }

        $order->update([
            'tracking_number' => $trackingNumber,
            'status' => 'shipped',
            'shipped_at' => now()
        ]);

        return $order->fresh();
    }

    public function cancelOrder(string $orderId, string $businessId): bool
    {
        $order = $this->getOrderById($orderId, $businessId);
        
        if (!$order) {
            return false;
        }

        // Restore stock
        foreach ($order->items as $item) {
            $item->product->increment('stock', $item->qty);
        }

        return $order->update(['status' => 'cancelled']);
    }

    public function getOrderStats(string $businessId): array
    {
        $orders = Order::where('business_id', $businessId);

        return [
            'total' => $orders->count(),
            'pending' => $orders->where('status', 'pending')->count(),
            'paid' => $orders->where('status', 'paid')->count(),
            'shipped' => $orders->where('status', 'shipped')->count(),
            'completed' => $orders->where('status', 'completed')->count(),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
            'revenue' => $orders->where('status', 'completed')->sum('total_amount'),
        ];
    }

    private function getOrCreateCustomer(array $customerData, string $businessId): Customer
    {
        return Customer::firstOrCreate(
            [
                'wa_number' => $customerData['wa_number'],
                'business_id' => $businessId
            ],
            [
                'name' => $customerData['name'] ?? null,
                'email' => $customerData['email'] ?? null,
            ]
        );
    }

    private function generateOrderNumber(string $businessId): string
    {
        $prefix = 'ORD-' . date('Ymd');
        $lastOrder = Order::where('business_id', $businessId)
            ->where('order_number', 'like', "{$prefix}%")
            ->orderBy('order_number', 'desc')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->order_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $newNumber;
    }
}