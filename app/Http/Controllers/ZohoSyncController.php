<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Services\ZohoService;
use Illuminate\Http\JsonResponse;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Http;

class ZohoSyncController extends Controller
{
    public function __construct(
        private ShopifyService $shopifyService
    ) {}

    public function index()
    {
        $shop = Shop::first();

        if (!$shop) {
            abort(404, 'No Shopify shop installed.');
        }

        $variants = ProductVariant::with('product')
            ->orderBy('id')
            ->get();

        return Inertia::render('Zoho/Sync', [
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
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

            /*
        |--------------------------------------------------------------------------
        | 1. Get fresh Shopify data
        |--------------------------------------------------------------------------
        */

            $accessToken = $this->shopifyService->getValidAccessToken($shop);

            $query = <<<'GRAPHQL'
query GetVariant($id: ID!) {
    productVariant(id: $id) {
        id
        title
        sku
        price
        inventoryQuantity
    }
}
GRAPHQL;

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->post(
                "https://{$shop->shop_domain}/admin/api/2026-07/graphql.json",
                [
                    'query' => $query,
                    'variables' => [
                        'id' => $variant->shopify_variant_id,
                    ],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception(
                    'Shopify API request failed: ' . $response->body()
                );
            }

            $responseData = $response->json();

            if (!empty($responseData['errors'])) {
                throw new \Exception(
                    'Shopify GraphQL request failed: ' .
                        json_encode($responseData['errors'])
                );
            }

            $shopifyVariant =
                $responseData['data']['productVariant'] ?? null;

            if (!$shopifyVariant) {
                throw new \Exception(
                    'Shopify variant not found.'
                );
            }


            /*
        |--------------------------------------------------------------------------
        | 2. Update local variant with latest Shopify data
        |--------------------------------------------------------------------------
        */

            $variant->update([
                'title' => $shopifyVariant['title'],
                'sku' => $shopifyVariant['sku'],
                'price' => $shopifyVariant['price'],
                'inventory_quantity' =>
                $shopifyVariant['inventoryQuantity'],
            ]);

            // Refresh model so ZohoService receives latest DB values
            $variant->refresh();


            /*
        |--------------------------------------------------------------------------
        | 3. Sync latest Shopify data to Zoho
        |--------------------------------------------------------------------------
        */

            $zohoService = new ZohoService($shop);

            $result = $zohoService->syncItem($variant);


            return response()->json([
                'success' => true,
                'message' => $result['message']
                    ?? 'Synchronization completed.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {

            $status = str_contains(
                $e->getMessage(),
                'Zoho is not connected'
            )
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

        return Inertia::render('Zoho/History', [
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
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

        return Inertia::render('Zoho/Settings', [
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
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
