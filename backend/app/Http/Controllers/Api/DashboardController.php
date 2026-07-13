<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Order\OrderService;
use App\Domain\Catalog\ProductService;
use App\Domain\Tenant\BusinessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    private OrderService $orderService;
    private ProductService $productService;
    private BusinessService $businessService;

    public function __construct(
        OrderService $orderService,
        ProductService $productService,
        BusinessService $businessService
    ) {
        $this->orderService = $orderService;
        $this->productService = $productService;
        $this->businessService = $businessService;
    }

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $orderStats = $this->orderService->getOrderStats($businessId);
        $businessStats = $this->businessService->getBusinessStats($businessId);

        // Calculate today's stats
        $todayOrders = \App\Models\Order::where('business_id', $businessId)
            ->whereDate('created_at', today())
            ->get();

        $todayRevenue = $todayOrders->where('status', 'completed')->sum('total_amount');
        $todayOrderCount = $todayOrders->count();

        // Calculate weekly stats
        $weekAgo = now()->subWeek();
        $weeklyOrders = \App\Models\Order::where('business_id', $businessId)
            ->where('created_at', '>=', $weekAgo)
            ->get();

        $weeklyRevenue = $weeklyOrders->where('status', 'completed')->sum('total_amount');

        // Get recent orders
        $recentOrders = $this->orderService->getOrdersByBusiness($businessId, ['limit' => 5]);

        // Get low stock products
        $lowStockProducts = \App\Models\Product::where('business_id', $businessId)
            ->where('stock', '<=', 5)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'stats' => [
                'today' => [
                    'revenue' => $todayRevenue,
                    'orders' => $todayOrderCount,
                ],
                'week' => [
                    'revenue' => $weeklyRevenue,
                    'orders' => $weeklyOrders->count(),
                ],
                'overall' => [
                    'total_revenue' => $orderStats['revenue'],
                    'total_orders' => $orderStats['total'],
                    'active_products' => $businessStats['products_count'],
                    'total_customers' => $businessStats['customers_count'],
                ],
                'orders_by_status' => [
                    'pending' => $orderStats['pending'],
                    'paid' => $orderStats['paid'],
                    'shipped' => $orderStats['shipped'],
                    'completed' => $orderStats['completed'],
                    'cancelled' => $orderStats['cancelled'],
                ],
            ],
            'recent_orders' => $recentOrders,
            'low_stock_products' => $lowStockProducts,
            'subscription' => $businessStats['subscription'],
        ]);
    }

    public function salesChart(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'required|in:7d,30d,90d',
        ]);

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $period = $request->period;
        $days = match($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        $startDate = now()->subDays($days);
        
        $orders = \App\Models\Order::where('business_id', $businessId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as revenue, COUNT(*) as orders')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill in missing dates
        $chartData = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $orderData = $orders->firstWhere('date', $date);
            
            $chartData[] = [
                'date' => $date,
                'revenue' => $orderData->revenue ?? 0,
                'orders' => $orderData->orders ?? 0,
            ];
        }

        return response()->json([
            'chart_data' => $chartData,
        ]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $topProducts = \App\Models\OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.business_id', $businessId)
            ->where('orders.status', 'completed')
            ->selectRaw('products.name, SUM(order_items.qty) as total_sold, SUM(order_items.qty * order_items.price_at_order) as revenue')
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_sold', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'top_products' => $topProducts,
        ]);
    }

    public function topCities(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $topCities = \App\Models\Address::join('orders', 'addresses.order_id', '=', 'orders.id')
            ->where('orders.business_id', $businessId)
            ->where('orders.status', 'completed')
            ->selectRaw('city_name, COUNT(*) as order_count')
            ->groupBy('city_name')
            ->orderBy('order_count', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'top_cities' => $topCities,
        ]);
    }
}