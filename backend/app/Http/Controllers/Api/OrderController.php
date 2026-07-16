<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Order\OrderService;
use App\Jobs\SendWhatsAppNotification;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $filters = [
            'status'    => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to'   => $request->query('date_to'),
            'search'    => $request->query('search'),
        ];

        $perPage = (int) $request->query('per_page', 20);
        $orders  = $this->orderService->getOrdersByBusiness($businessId, $filters, $perPage);

        return response()->json($orders);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $order = $this->orderService->getOrderById($id, $businessId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'order' => $order,
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:pending,paid,processing,shipped,completed,cancelled,refunded',
        ]);

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $order = $this->orderService->updateOrderStatus($id, $request->status, $businessId);
            
            return response()->json([
                'order' => $order,
                'message' => 'Order status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateTracking(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tracking_number' => 'required|string|max:255',
        ]);

        $businessId = $request->user()->business_id;

        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $order = $this->orderService->updateTrackingNumber($id, $request->tracking_number, $businessId);

            // Auto-advance status to 'shipped' when resi is added
            if ($order->status === 'processing' || $order->status === 'paid') {
                $order->update(['status' => 'shipped']);
                $order->refresh();
            }

            // Notify customer via WhatsApp
            $customer = $order->customer;
            if ($customer?->wa_number) {
                $courier  = $order->courier_name    ?? 'Kurir';
                $service  = $order->courier_service ?? '';
                $resi     = $request->tracking_number;
                $total    = 'Rp' . number_format($order->total_amount, 0, ',', '.');

                $msg = "📦 *Paket Anda Sedang Dikirim!*\n\n"
                     . "Order *#{$order->order_number}* (total: {$total})\n"
                     . "Kurir: *{$courier} {$service}*\n"
                     . "No. Resi: *{$resi}*\n\n"
                     . "Lacak paket Anda di website resmi {$courier}.\n"
                     . "Terima kasih sudah berbelanja! 🙏";

                dispatch(new SendWhatsAppNotification($customer->wa_number, $msg, $businessId));
            }

            return response()->json([
                'order'   => $order,
                'message' => 'Resi disimpan. Notifikasi dikirim ke pelanggan.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $result = $this->orderService->cancelOrder($id, $businessId);

        if (!$result) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $stats = $this->orderService->getOrderStats($businessId);

        return response()->json([
            'stats' => $stats,
        ]);
    }
}