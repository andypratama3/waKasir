<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductsImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProductImportController extends Controller
{
    /**
     * POST /api/products/import
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['error' => 'No business associated with user'], 403);
        }

        // Check product limit
        $subscriptionService = app(\App\Domain\Tenant\SubscriptionService::class);
        if (!$subscriptionService->checkProductLimit($businessId)) {
            return response()->json([
                'error' => 'Batas jumlah produk untuk paket Anda sudah tercapai. Upgrade paket untuk menambah lebih banyak produk.',
            ], 422);
        }

        try {
            $import = new ProductsImport($businessId);
            Excel::import($import, $request->file('file'));

            return response()->json([
                'message'        => "Import berhasil! {$import->getImportedCount()} produk ditambahkan.",
                'imported_count' => $import->getImportedCount(),
                'skipped_count'  => $import->getSkippedCount(),
                'skipped_rows'   => $import->getSkippedRows(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('ProductImportController: import failed', [
                'error'       => $e->getMessage(),
                'business_id' => $businessId,
            ]);
            return response()->json(['error' => 'Gagal mengimpor: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/products/import/template
     */
    public function template(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return Excel::download(new ProductTemplateExport(), 'template-import-produk.xlsx');
    }
}
