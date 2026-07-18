<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImportController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\TeamController;
use App\Jobs\ProcessMidtransWebhook;
use App\Jobs\ProcessIncomingWhatsAppMessage;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ── Public auth ──────────────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:10,1');

// ── Protected (Sanctum) ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ── Products ──────────────────────────────────────────────────────────
    // Specific routes BEFORE apiResource (to avoid shadowing)
    Route::get('/products/import/template', [ProductImportController::class, 'template']);
    Route::post('/products/import',         [ProductImportController::class, 'import']);
    Route::get('/products/search',          [ProductController::class,       'search']);
    Route::get('/products/category/{cat}',  [ProductController::class,       'byCategory']);
    Route::apiResource('products', ProductController::class);

    // ── Orders ────────────────────────────────────────────────────────────
    Route::get('/orders/stats',          [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class)->except(['store', 'create']);
    Route::post('/orders/{id}/status',   [OrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/tracking', [OrderController::class, 'updateTracking']);
    Route::post('/orders/{id}/cancel',   [OrderController::class, 'cancel']);
    Route::get('/orders/{id}/invoice',   [InvoiceController::class, 'download']);

    // ── Customers ────────────────────────────────────────────────────────
    Route::get('/customers',      [CustomerController::class, 'index']);
    Route::get('/customers/{id}', [CustomerController::class, 'show']);

    // ── Dashboard ─────────────────────────────────────────────────────────
    Route::get('/dashboard',              [DashboardController::class, 'index']);
    Route::get('/dashboard/sales-chart',  [DashboardController::class, 'salesChart']);
    Route::get('/dashboard/top-products', [DashboardController::class, 'topProducts']);
    Route::get('/dashboard/top-cities',   [DashboardController::class, 'topCities']);

    // ── Business ──────────────────────────────────────────────────────────
    Route::get('/business',                               [BusinessController::class, 'show']);
    Route::put('/business',                               [BusinessController::class, 'update']);
    Route::put('/business/integration/{integration}',     [BusinessController::class, 'updateIntegration']);
    Route::get('/business/stats',                         [BusinessController::class, 'stats']);

    // WhatsApp BSP — Embedded Signup
    Route::post('/business/whatsapp/connect',      [BusinessController::class, 'connectWhatsApp'])->middleware('throttle:5,10');
    Route::get('/business/whatsapp/status',        [BusinessController::class, 'waStatus']);
    Route::delete('/business/whatsapp/disconnect', [BusinessController::class, 'disconnectWhatsApp']);

    // ── Settings ──────────────────────────────────────────────────────────
    Route::get('/settings/bot',                   [SettingController::class, 'botSettings']);
    Route::put('/settings/bot',                   [SettingController::class, 'updateBotSettings']);
    Route::get('/settings/subscription',          [SettingController::class, 'subscription']);
    Route::post('/settings/subscription/upgrade', [SettingController::class, 'upgradeSubscription']);
    Route::get('/settings/categories',            [SettingController::class, 'categories']);
    Route::get('/settings/plans',                 [SettingController::class, 'plans']);

    // ── Team / Staff Management ───────────────────────────────────────────
    Route::get('/team',              [TeamController::class, 'index']);
    Route::post('/team/invite',      [TeamController::class, 'invite']);
    Route::put('/team/{id}/role',    [TeamController::class, 'updateRole']);
    Route::delete('/team/{id}',      [TeamController::class, 'remove']);

    // ── Reports export (Excel) ────────────────────────────────────────────
    Route::get('/reports/orders/export', function (Request $request) {
        $businessId = $request->user()->business_id;
        if (!$businessId) return response()->json(['error' => 'No business'], 403);

        $export = new \App\Exports\OrdersExport(
            $businessId,
            $request->query('status'),
            $request->query('date_from'),
            $request->query('date_to'),
        );

        $filename = 'laporan-pesanan-' . now()->format('Ymd-His') . '.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
    });
});

// ── Webhooks (no auth, rate-limited) ─────────────────────────────────────
Route::prefix('webhooks')->middleware('throttle:120,1')->group(function () {

    // Midtrans
    Route::post('/midtrans', function (Request $request) {
        $data = $request->all();
        if (empty($data['order_id']) || empty($data['transaction_status'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }
        dispatch(new ProcessMidtransWebhook($data));
        return response()->json(['status' => 'ok']);
    });

    // WhatsApp GET challenge verification
    Route::get('/whatsapp', function (Request $request) {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token', '')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }
        return response()->json(['status' => 'forbidden'], 403);
    });

    // WhatsApp POST incoming messages
    Route::post('/whatsapp', function (Request $request) {
        $body    = $request->all();
        $entry   = $body['entry'][0]    ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value   = $changes['value']    ?? null;

        if (!$value) return response()->json(['status' => 'ok']);

        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $messages      = $value['messages'] ?? [];

        if (empty($messages) || !$phoneNumberId) return response()->json(['status' => 'ok']);

        $business = \App\Models\Business::where('wa_phone_id', $phoneNumberId)->first();

        if (!$business) {
            \Illuminate\Support\Facades\Log::warning('WA webhook: unknown phone_number_id', [
                'phone_number_id' => $phoneNumberId,
            ]);
            return response()->json(['status' => 'ok']);
        }

        foreach ($messages as $msg) {
            $waNumber = $msg['from']         ?? null;
            $type     = $msg['type']         ?? 'text';
            $text     = $msg['text']['body'] ?? null;

            if ($type === 'interactive') {
                $ia   = $msg['interactive'] ?? [];
                $text = $ia['button_reply']['title'] ?? $ia['list_reply']['title'] ?? $text;
            }

            if (!$waNumber || !$text) continue;

            dispatch(new ProcessIncomingWhatsAppMessage(
                $waNumber, $text, $business->id,
                ['message_id' => $msg['id'] ?? null, 'type' => $type]
            ));
        }

        return response()->json(['status' => 'ok']);
    });
});
