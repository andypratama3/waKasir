<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Tenant\BusinessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BusinessController extends Controller
{
    private BusinessService $businessService;

    public function __construct(BusinessService $businessService)
    {
        $this->businessService = $businessService;
    }

    public function show(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $business = $this->businessService->getBusinessById($businessId);

        return response()->json([
            'business' => $business,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'bot_settings' => 'sometimes|array',
            'operating_hours' => 'sometimes|array',
        ]);

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $business = $this->businessService->updateBusiness($businessId, $request->all());
            
            return response()->json([
                'business' => $business,
                'message' => 'Business updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateIntegration(Request $request, string $integration): JsonResponse
    {
        $validIntegrations = ['whatsapp', 'midtrans', 'rajaongkir'];
        
        if (!in_array($integration, $validIntegrations)) {
            return response()->json(['error' => 'Invalid integration'], 400);
        }

        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        try {
            $business = $this->businessService->updateIntegration($businessId, $integration, $request->all());
            
            return response()->json([
                'business' => $business,
                'message' => ucfirst($integration) . ' integration updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        $stats = $this->businessService->getBusinessStats($businessId);

        return response()->json([
            'stats' => $stats,
        ]);
    }
}