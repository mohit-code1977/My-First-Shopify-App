<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Services\ZohoService;
use Illuminate\Http\JsonResponse;

class ZohoSyncController extends Controller
{
    public function index()
    {
        $shop = Shop::first();

        if (!$shop) {
            abort(404, 'No Shopify shop installed.');
        }

        $variants = ProductVariant::with('product')
            ->orderBy('id')
            ->get();

        return view('zoho.sync', [
            'shop' => $shop,
            'variants' => $variants,
        ]);
    }

    public function syncVariant(ProductVariant $variant): JsonResponse
    {
        $shop = Shop::first();

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        try {
            $zohoService = new ZohoService($shop);

            $result = $zohoService->syncItem($variant);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Synchronization completed.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'Zoho is not connected')
                ? 409
                : 500;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    public function syncAll(): JsonResponse
    {
        $shop = Shop::first();

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        try {
            $zohoService = new ZohoService($shop);

            $result = $zohoService->syncAllVariants();

            return response()->json([
                'success' => true,
                'message' => 'Synchronization completed.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function history()
    {
        $shop = Shop::first();

        if (!$shop) {
            abort(404, 'No Shopify shop installed.');
        }

        $histories = SyncHistory::with([
            'productVariant.product',
        ])
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->paginate(20);

        return view('zoho.history', [
            'shop' => $shop,
            'histories' => $histories,
        ]);
    }




    public function settings()
    {
        $shop = Shop::first();

        if (!$shop) {
            abort(404, 'No Shopify shop installed.');
        }

        $zohoConnection = $shop->zohoConnection;

        return view('zoho.settings', [
            'shop' => $shop,
            'zohoConnection' => $zohoConnection,
        ]);
    }

    public function disconnect()
    {
        $shop = Shop::first();

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $connection = $shop->zohoConnection;

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Zoho is not connected.',
            ], 404);
        }

        $connection->delete();

        return response()->json([
            'success' => true,
            'message' => 'Zoho disconnected successfully.',
        ]);
    }
}
