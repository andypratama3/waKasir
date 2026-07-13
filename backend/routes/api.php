<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\SettingController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Product routes
    Route::apiResource('products', ProductController::class);
    Route::get('/products/search', [ProductController::class, 'search']);
    Route::get('/products/category/{category}', [ProductController::class, 'byCategory']);

    // Order routes
    Route::apiResource('orders', OrderController::class)->except(['store', 'create']);
    Route::post('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/tracking', [OrderController::class, 'updateTracking']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::get('/orders/stats', [OrderController::class, 'stats']);

    // Dashboard routes
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/sales-chart', [DashboardController::class, 'salesChart']);
    Route::get('/dashboard/top-products', [DashboardController::class, 'topProducts']);
    Route::get('/dashboard/top-cities', [DashboardController::class, 'topCities']);

    // Business routes
    Route::get('/business', [BusinessController::class, 'show']);
    Route::put('/business', [BusinessController::class, 'update']);
    Route::put('/business/integration/{integration}', [BusinessController::class, 'updateIntegration']);
    Route::get('/business/stats', [BusinessController::class, 'stats']);

    // Settings routes
    Route::get('/settings/bot', [SettingController::class, 'botSettings']);
    Route::put('/settings/bot', [SettingController::class, 'updateBotSettings']);
    Route::get('/settings/subscription', [SettingController::class, 'subscription']);
    Route::post('/settings/subscription/upgrade', [SettingController::class, 'upgradeSubscription']);
    Route::get('/settings/categories', [SettingController::class, 'categories']);
    Route::get('/settings/plans', [SettingController::class, 'plans']);
});

// Webhook routes (no auth required)
Route::post('/webhooks/midtrans', function (Request $request) {
    $paymentService = app(\App\Domain\Payment\PaymentService::class);
    $result = $paymentService->handleWebhook($request->all());
    
    if ($result) {
        return response()->json(['status' => 'success']);
    }
    
    return response()->json(['status' => 'error'], 400);
});

Route::post('/webhooks/whatsapp', function (Request $request) {
    // WhatsApp webhook handler
    $messageHandler = app(\App\Domain\Bot\MessageHandler::class);
    
    try {
        $waNumber = $request->input('from') ?? $request->input('phone');
        $message = $request->input('message') ?? $request->input('text');
        $businessId = $request->input('business_id'); // In production, this would come from phone number lookup
        
        if ($waNumber && $message && $businessId) {
            $response = $messageHandler->handle($message, $waNumber, $businessId);
            
            // Here you would send the response back via WhatsApp API
            // For now, just return the response
            return response()->json($response);
        }
        
        return response()->json(['status' => 'error', 'message' => 'Missing required fields'], 400);
    } catch (\Exception $e) {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});