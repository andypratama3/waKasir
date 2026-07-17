<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Paginated customer list with aggregated stats per customer.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $search  = $request->query('search');
        $perPage = (int) $request->query('per_page', 20);

        $query = Customer::where('business_id', $businessId)
            ->withCount('orders')
            ->withSum('orders', 'total_amount')
            ->with(['orders' => fn ($q) => $q->latest()->limit(1)->select('id', 'customer_id', 'order_number', 'status', 'total_amount', 'created_at')])
            ->when($search, fn ($q) =>
                $q->where('wa_number', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
            )
            ->orderByDesc('orders_sum_total_amount');

        $customers = $query->paginate($perPage);

        // Shape the response so the frontend gets clean fields
        $customers->through(function ($customer) {
            $customer->total_orders = $customer->orders_count;
            $customer->total_spent  = (float) ($customer->orders_sum_total_amount ?? 0);
            $customer->last_order   = $customer->orders->first();
            unset($customer->orders, $customer->orders_count, $customer->orders_sum_total_amount);
            return $customer;
        });

        return response()->json($customers);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $businessId = $request->user()->business_id;

        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $customer = Customer::where('id', $id)
            ->where('business_id', $businessId)
            ->with(['orders' => fn ($q) =>
                $q->with(['items.product', 'address', 'payment'])
                  ->latest()
                  ->limit(50)
            ])
            ->first();

        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $totalSpent  = $customer->orders->whereIn('status', ['paid', 'completed'])->sum('total_amount');
        $totalOrders = $customer->orders->count();

        return response()->json([
            'customer'     => [
                'id'           => $customer->id,
                'wa_number'    => $customer->wa_number,
                'name'         => $customer->name,
                'email'        => $customer->email,
                'created_at'   => $customer->created_at,
                'total_orders' => $totalOrders,
                'total_spent'  => (float) $totalSpent,
                'orders'       => $customer->orders->map(fn ($o) => [
                    'id'           => $o->id,
                    'order_number' => $o->order_number,
                    'total_amount' => (float) $o->total_amount,
                    'status'       => $o->status,
                    'courier_name' => $o->courier_name,
                    'tracking_number' => $o->tracking_number,
                    'created_at'   => $o->created_at,
                    'items'        => $o->items->map(fn ($i) => [
                        'product_name' => $i->product?->name ?? $i->variant_name ?? 'Produk',
                        'qty'          => $i->qty,
                        'price'        => (float) $i->price_at_order,
                    ]),
                ]),
            ],
        ]);
    }
}
