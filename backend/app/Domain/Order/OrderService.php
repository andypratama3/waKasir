<?php

namespace App\Domain\Order;

use App\Jobs\SendWhatsAppNotification;
use App\Models\Address;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function createOrder(array $data, string $businessId): Order
    {
        return DB::transaction(function () use ($data, $businessId) {
            $customer    = $this->getOrCreateCustomer($data['customer'], $businessId);
            $orderNumber = $this->generateOrderNumber($businessId);

            $order = Order::create([
                'business_id'     => $businessId,
                'customer_id'     => $customer->id,
                'order_number'    => $orderNumber,
                'subtotal'        => $data['subtotal'],
                'shipping_cost'   => $data['shipping_cost'] ?? 0,
                'total_amount'    => $data['total_amount'],
                'status'          => 'pending',
                'courier_name'    => $data['courier_name'] ?? null,
                'courier_service' => $data['courier_service'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $item['product_id'],
                    'variant_id'     => $item['variant_id'] ?? null,
                    'qty'            => $item['qty'],
                    'price_at_order' => $item['price'],
                    'variant_name'   => $item['variant_name'] ?? null,
                ]);
            }

            if (isset($data['address'])) {
                Address::create([
                    'order_id'        => $order->id,
                    'city_id'         => $data['address']['city_id'] ?? null,
                    'subdistrict_id'  => $data['address']['subdistrict_id'] ?? null,
                    'city_name'       => $data['address']['city_name'] ?? null,
                    'subdistrict_name'=> $data['address']['subdistrict_name'] ?? null,
                    'full_address'    => $data['address']['full_address'],
                    'recipient_name'  => $data['address']['recipient_name'],
                    'recipient_phone' => $data['address']['recipient_phone'],
                    'postal_code'     => $data['address']['postal_code'] ?? null,
                    'notes'           => $data['address']['notes'] ?? null,
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

    /**
     * Get paginated orders for a business with optional filters.
     */
    public function getOrdersByBusiness(
        string $businessId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = Order::with(['items.product', 'customer', 'payment'])
            ->where('business_id', $businessId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('wa_number', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function updateOrderStatus(string $orderId, string $status, string $businessId): Order
    {
        $order = $this->getOrderById($orderId, $businessId);

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        $timestamps = [];
        if ($status === 'paid')      $timestamps['paid_at']     = now();
        if ($status === 'shipped')    $timestamps['shipped_at']   = now();
        if ($status === 'completed')  $timestamps['completed_at'] = now();

        $order->update(array_merge(['status' => $status], $timestamps));

        return $order->fresh(['items.product', 'customer', 'address', 'payment']);
    }

    /**
     * Update tracking number, set status to shipped, and notify customer via WhatsApp.
     */
    public function updateTrackingNumber(string $orderId, string $trackingNumber, string $businessId): Order
    {
        $order = $this->getOrderById($orderId, $businessId);

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        $order->update([
            'tracking_number' => $trackingNumber,
            'status'          => 'shipped',
            'shipped_at'      => now(),
        ]);

        // Notify customer about shipment
        $customer = $order->customer;
        if ($customer?->wa_number) {
            $courier  = $order->courier_name ?? 'Kurir';
            $service  = $order->courier_service ?? '';
            $message  = "📦 Pesanan #{$order->order_number} telah dikirim!\n\n"
                       . "Kurir: {$courier} {$service}\n"
                       . "No. Resi: *{$trackingNumber}*\n\n"
                       . "Pantau paket Anda dengan nomor resi di atas. Terima kasih telah berbelanja! 🙏";

            dispatch(new SendWhatsAppNotification($customer->wa_number, $message, $businessId));
        }

        return $order->fresh(['items.product', 'customer', 'address', 'payment']);
    }

    public function cancelOrder(string $orderId, string $businessId): bool
    {
        $order = $this->getOrderById($orderId, $businessId);

        if (!$order) {
            return false;
        }

        // Restore product stock
        foreach ($order->items as $item) {
            if ($item->product) {
                $item->product->increment('stock', $item->qty);
            }
        }

        return (bool) $order->update(['status' => 'cancelled']);
    }

    /**
     * Fixed: each count uses its own fresh query to avoid stale builder state.
     */
    public function getOrderStats(string $businessId): array
    {
        $base = fn () => Order::where('business_id', $businessId);

        return [
            'total'     => $base()->count(),
            'pending'   => $base()->where('status', 'pending')->count(),
            'paid'      => $base()->where('status', 'paid')->count(),
            'processing'=> $base()->where('status', 'processing')->count(),
            'shipped'   => $base()->where('status', 'shipped')->count(),
            'completed' => $base()->where('status', 'completed')->count(),
            'cancelled' => $base()->where('status', 'cancelled')->count(),
            'revenue'   => (float) $base()->where('status', 'completed')->sum('total_amount'),
        ];
    }

    private function getOrCreateCustomer(array $customerData, string $businessId): Customer
    {
        return Customer::firstOrCreate(
            ['wa_number' => $customerData['wa_number'], 'business_id' => $businessId],
            ['name' => $customerData['name'] ?? null, 'email' => $customerData['email'] ?? null]
        );
    }

    private function generateOrderNumber(string $businessId): string
    {
        $prefix = 'ORD-' . date('Ymd') . '-';

        // Use DB lock to prevent race conditions on concurrent orders
        $lastOrder = Order::where('business_id', $businessId)
            ->where('order_number', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderBy('order_number', 'desc')
            ->first();

        $seq = $lastOrder
            ? (int) substr($lastOrder->order_number, -4) + 1
            : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
