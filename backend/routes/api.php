<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\CustomerController;
use App\Jobs\ProcessMidtransWebhook;
use App\Jobs\ProcessIncomingWhatsAppMessage;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ── Public auth routes ──────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:10,1');

// ── Protected routes ────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Products — specific routes BEFORE apiResource to avoid route shadowing
    Route::get('/products/search',              [ProductController::class, 'search']);
    Route::get('/products/category/{category}', [ProductController::class, 'byCategory']);
    Route::apiResource('products', ProductController::class);

    // Orders — specific routes BEFORE apiResource
    Route::get('/orders/stats',          [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class)->except(['store', 'create']);
    Route::post('/orders/{id}/status',   [OrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/tracking', [OrderController::class, 'updateTracking']);
    Route::post('/orders/{id}/cancel',   [OrderController::class, 'cancel']);

    // Customers (dedicated endpoint)
    Route::get('/customers',      [CustomerController::class, 'index']);
    Route::get('/customers/{id}', [CustomerController::class, 'show']);

    // Dashboard
    Route::get('/dashboard',              [DashboardController::class, 'index']);
    Route::get('/dashboard/sales-chart',  [DashboardController::class, 'salesChart']);
    Route::get('/dashboard/top-products', [DashboardController::class, 'topProducts']);
    Route::get('/dashboard/top-cities',   [DashboardController::class, 'topCities']);

    // Business
    Route::get('/business',                               [BusinessController::class, 'show']);
    Route::put('/business',                               [BusinessController::class, 'update']);
    Route::put('/business/integration/{integration}',     [BusinessController::class, 'updateIntegration']);
    Route::get('/business/stats',                         [BusinessController::class, 'stats']);

    // WhatsApp BSP — Embedded Signup & status
    Route::post('/business/whatsapp/connect',     [BusinessController::class, 'connectWhatsApp']);
    Route::get('/business/whatsapp/status',       [BusinessController::class, 'waStatus']);
    Route::delete('/business/whatsapp/disconnect',[BusinessController::class, 'disconnectWhatsApp']);

    // Settings
    Route::get('/settings/bot',                     [SettingController::class, 'botSettings']);
    Route::put('/settings/bot',                     [SettingController::class, 'updateBotSettings']);
    Route::get('/settings/subscription',            [SettingController::class, 'subscription']);
    Route::post('/settings/subscription/upgrade',   [SettingController::class, 'upgradeSubscription']);
    Route::get('/settings/categories',              [SettingController::class, 'categories']);
    Route::get('/settings/plans',                   [SettingController::class, 'plans']);
});

// ── Webhook routes (no auth, rate-limited) ───────────────────────────────
Route::prefix('webhooks')->middleware('throttle:120,1')->group(function () {

    // ── Midtrans payment notification ────────────────────────────────────
    Route::post('/midtrans', function (Request $request) {
        $data = $request->all();

        // Basic structural validation before queuing
        if (empty($data['order_id']) || empty($data['transaction_status'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        // Dispatch async — respond immediately so Midtrans doesn't retry
        dispatch(new ProcessMidtransWebhook($data));

        return response()->json(['status' => 'ok']);
    });

    // ── WhatsApp Cloud API — GET challenge verification ───────────────────
    Route::get('/whatsapp', function (Request $request) {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token', '');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['status' => 'forbidden'], 403);
    });

    // ── WhatsApp Cloud API — POST incoming messages ───────────────────────
    Route::post('/whatsapp', function (Request $request) {
        $body = $request->all();

        // WhatsApp Cloud API wraps messages inside entry[].changes[]
        $entry   = $body['entry'][0]    ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value   = $changes['value']    ?? null;

        if (!$value) {
            // Could be a status update (delivery receipt) — just ack
            return response()->json(['status' => 'ok']);
        }

        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $messages      = $value['messages'] ?? [];

        if (empty($messages) || !$phoneNumberId) {
            return response()->json(['status' => 'ok']);
        }

        // Resolve business from phone number ID
        $business = \App\Models\Business::where('wa_phone_id', $phoneNumberId)->first();

        if (!$business) {
            \Illuminate\Support\Facades\Log::warning('WhatsApp webhook: unknown phone_number_id', [
                'phone_number_id' => $phoneNumberId,
            ]);
            return response()->json(['status' => 'ok']); // still 200 to prevent Meta retries
        }

        foreach ($messages as $msg) {
            $waNumber = $msg['from']             ?? null;
            $type     = $msg['type']             ?? 'text';
            $text     = $msg['text']['body']     ?? null;

            // Handle interactive responses (button/list replies)
            if ($type === 'interactive') {
                $interactive = $msg['interactive'] ?? [];
                $text = $interactive['button_reply']['title']
                     ?? $interactive['list_reply']['title']
                     ?? $text;
            }

            if (!$waNumber || !$text) {
                continue;
            }

            dispatch(new ProcessIncomingWhatsAppMessage(
                $waNumber,
                $text,
                $business->id,
                ['message_id' => $msg['id'] ?? null, 'type' => $type]
            ));
        }

        return response()->json(['status' => 'ok']);
    });
});
