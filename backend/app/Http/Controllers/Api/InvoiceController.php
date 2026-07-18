<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    /**
     * GET /api/orders/{id}/invoice
     * Download invoice PDF untuk satu order.
     */
    public function download(Request $request, string $id): Response|\Illuminate\Http\JsonResponse
    {
        $businessId = $request->user()->business_id;

        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $order = Order::with([
            'items.product',
            'items.variant',
            'customer',
            'address',
            'payment',
            'business',
        ])->where('id', $id)
          ->where('business_id', $businessId)
          ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $data = [
            'order'    => $order,
            'business' => $order->business,
            'customer' => $order->customer,
            'address'  => $order->address,
            'items'    => $order->items,
            'payment'  => $order->payment,
        ];

        $pdf = Pdf::loadView('pdf.invoice', $data)
                  ->setPaper('a4', 'portrait');

        $filename = "invoice-{$order->order_number}.pdf";

        return $pdf->download($filename);
    }
}
