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
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ZohoSyncController extends Controller
{
    public function __construct(
        private ShopifyService $shopifyService
    ) {}

    public function index(Request $request)
    {
        $shopDomain = $request->query('shop');
        $host = $request->query('host');

        return Inertia::render(
            'Zoho/Sync',
            [
                'shop' => [
                    'shop_domain' => $shopDomain,
                ],
                'variants' => [],
                'failedCount' => 0,
                'zohoConnected' => false,
                'host' => $host,
            ]
        );
    }

    public function data(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        try {
            $shopifyVariants =
                $this->fetchShopifyCatalog($shop);
        } catch (\Throwable $e) {
            Log::error(
                'Shopify catalog fetch failed.',
                [
                    'shop_id' => $shop->id,
                    'message' => $e->getMessage(),
                ]
            );

            $shopifyVariants = [];
        }

        $variantIds = collect($shopifyVariants)
            ->pluck('shopify_variant_id')
            ->filter()
            ->values();

        $localMappings = ProductVariant::query()
            ->whereIn(
                'shopify_variant_id',
                $variantIds
            )
            ->whereHas(
                'product',
                function ($query) use ($shop) {
                    $query->where(
                        'shop_id',
                        $shop->id
                    );
                }
            )
            ->get([
                'id',
                'product_id',
                'shopify_variant_id',
                'zoho_item_id',
                'zoho_sync_hash',
                'zoho_synced_at',
            ])
            ->keyBy('shopify_variant_id');

        $variants = collect($shopifyVariants)
            ->map(function (array $variant) use (
                $localMappings
            ) {
                $mapping =
                    $localMappings->get(
                        $variant['shopify_variant_id']
                    );

                return array_merge(
                    $variant,
                    [
                        'id' =>
                        $variant['shopify_variant_id'],

                        'zoho_item_id' =>
                        $mapping?->zoho_item_id,

                        'zoho_sync_hash' =>
                        $mapping?->zoho_sync_hash,

                        'zoho_synced_at' =>
                        $mapping?->zoho_synced_at,
                    ]
                );
            })
            ->values();

        $zohoConnection =
            $shop->zohoConnection;

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'variants' => $variants,
            'zohoConnected' => $zohoConnection !== null,
        ]);
    }


    private function fetchShopifyCatalog(
        Shop $shop
    ): array {
        $accessToken =
            $this->shopifyService
            ->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query GetProducts($cursor: String) {
    products(
        first: 250,
        after: $cursor
    ) {
        pageInfo {
            hasNextPage
            endCursor
        }

        edges {
            node {
                id
                title
                handle

                featuredImage {
                    url
                    altText
                }

                variants(first: 250) {
                    edges {
                        node {
                            id
                            title
                            sku
                            price
                            inventoryQuantity

                            image {
                                url
                                altText
                            }
                        }
                    }
                }
            }
        }
    }
}
GRAPHQL;

        $cursor = null;
        $result = [];

        do {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->post(
                "https://{$shop->shop_domain}/admin/api/2026-07/graphql.json",
                [
                    'query' => $query,
                    'variables' => [
                        'cursor' => $cursor,
                    ],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception(
                    'Shopify API request failed: ' .
                        $response->body()
                );
            }

            $responseData = $response->json();

            if (!empty($responseData['errors'] ?? [])) {
                throw new \Exception(
                    'Shopify GraphQL request failed: ' .
                        json_encode($responseData['errors'])
                );
            }

            $products =
                $responseData['data']['products']
                ?? null;

            if (!$products) {
                throw new \Exception(
                    'Shopify products data was not returned.'
                );
            }

            foreach ($products['edges'] as $productEdge) {
                $product = $productEdge['node'];

                foreach (
                    $product['variants']['edges']
                    as $variantEdge
                ) {
                    $variant =
                        $variantEdge['node'];

                    $result[] = [
                        'id' => $variant['id'],

                        'shopify_variant_id' =>
                        $variant['id'],

                        'title' =>
                        $variant['title']
                            ?: 'Default Title',

                        'sku' =>
                        $variant['sku'],

                        'price' =>
                        $variant['price'],

                        'inventory_quantity' =>
                        $variant['inventoryQuantity']
                            ?? 0,

                        'image_url' =>
                        $variant['image']['url']
                            ??
                            $product['featuredImage']['url']
                            ??
                            null,

                        'product' => [
                            'shopify_product_id' =>
                            $product['id'],

                            'title' =>
                            $product['title'],

                            'handle' =>
                            $product['handle'],

                            'image_url' =>
                            $product['featuredImage']['url']
                                ?? null,
                        ],
                    ];
                }
            }

            $pageInfo = $products['pageInfo'] ?? [];

            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);

            $cursor = $pageInfo['endCursor'] ?? null;
        } while ($hasNextPage && $cursor);

        return $result;
    }


    public function syncVariant(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        if (!$shop->zohoConnection) {
            return response()->json([
                'success' => false,
                'message' => 'Zoho is not connected.',
            ], 409);
        }

        $validated = $request->validate([
            'shopify_variant_id' => [
                'required',
                'string',
            ],
        ]);

        try {
            /*
        |--------------------------------------------------------------------------
        | 1. Fetch fresh Shopify variant
        |--------------------------------------------------------------------------
        */

            $shopifyVariant = $this->fetchShopifyVariant(
                $shop,
                $validated['shopify_variant_id']
            );

            /*
        |--------------------------------------------------------------------------
        | 2. Create/update local integration mapping
        |--------------------------------------------------------------------------
        */

            $variant = $this->upsertLocalVariant(
                $shop,
                $shopifyVariant
            );

            /*
        |--------------------------------------------------------------------------
        | 3. Sync fresh Shopify data to Zoho
        |--------------------------------------------------------------------------
        */

            $zohoService = new ZohoService($shop);

            $result = $zohoService->syncItem($variant);

            return response()->json([
                'success' => true,
                'message' =>
                $result['message']
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



    private function fetchShopifyVariant(
        Shop $shop,
        string $shopifyVariantId
    ): array {
        $accessToken =
            $this->shopifyService
            ->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query GetVariant($id: ID!) {
    productVariant(id: $id) {
        id
        title
        sku
        price
        inventoryQuantity

        image {
            url
            altText
        }

        product {
            id
            title
            handle

            featuredImage {
                url
                altText
            }
        }
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
                    'id' => $shopifyVariantId,
                ],
            ]
        );

        if (!$response->successful()) {
            throw new \Exception(
                'Shopify API request failed: ' .
                    $response->body()
            );
        }

        $responseData = $response->json();

        if (!empty($responseData['errors'] ?? [])) {
            throw new \Exception(
                'Shopify GraphQL request failed: ' .
                    json_encode($responseData['errors'])
            );
        }

        $variant =
            $responseData['data']['productVariant']
            ?? null;

        if (!$variant) {
            throw new \Exception(
                'Shopify variant not found.'
            );
        }

        return $variant;
    }


    private function upsertLocalVariant(
        Shop $shop,
        array $shopifyVariant
    ): ProductVariant {
        $shopifyProduct =
            $shopifyVariant['product']
            ?? null;

        if (!$shopifyProduct) {
            throw new \Exception(
                'Shopify product was not returned for this variant.'
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Product mapping
    |--------------------------------------------------------------------------
    */

        $product = Product::updateOrCreate(
            [
                'shop_id' =>
                $shop->id,

                'shopify_product_id' =>
                $shopifyProduct['id'],
            ],
            [
                'title' =>
                $shopifyProduct['title']
                    ?? '',

                'handle' =>
                $shopifyProduct['handle']
                    ?? null,
            ]
        );

        /*
    |--------------------------------------------------------------------------
    | Variant mapping
    |--------------------------------------------------------------------------
    */

        $variant = ProductVariant::firstOrNew(
            [
                'product_id' =>
                $product->id,

                'shopify_variant_id' =>
                $shopifyVariant['id'],
            ]
        );

        /*
    |--------------------------------------------------------------------------
    | Update Shopify-owned fields only
    |--------------------------------------------------------------------------
    */

        $variant->title =
            $shopifyVariant['title']
            ?? 'Default Title';

        $variant->sku =
            $shopifyVariant['sku']
            ?? null;

        $variant->price =
            $shopifyVariant['price']
            ?? 0;

        $variant->inventory_quantity =
            $shopifyVariant['inventoryQuantity']
            ?? 0;

        $variant->save();

        $variant->refresh();

        return $variant;
    }


    public function syncAll(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        if (!$shop->zohoConnection) {
            return response()->json([
                'success' => false,
                'message' => 'Zoho is not connected.',
            ], 409);
        }

        try {
            /*
        |--------------------------------------------------------------------------
        | Fetch CURRENT Shopify catalog
        |--------------------------------------------------------------------------
        */

            $shopifyVariants =
                $this->fetchShopifyCatalog($shop);

            $zohoService =
                new ZohoService($shop);

            $successCount = 0;
            $failedCount = 0;
            $failures = [];

            /*
        |--------------------------------------------------------------------------
        | Sync every current Shopify variant
        |--------------------------------------------------------------------------
        */

            foreach ($shopifyVariants as $shopifyVariant) {
                try {
                    /*
                |--------------------------------------------------------------------------
                | fetchShopifyCatalog() returns normalized data,
                | so fetch the exact Shopify variant again before
                | creating/updating its local mapping.
                |--------------------------------------------------------------------------
                */

                    $freshShopifyVariant =
                        $this->fetchShopifyVariant(
                            $shop,
                            $shopifyVariant['shopify_variant_id']
                        );

                    /*
                |--------------------------------------------------------------------------
                | Create/update local mapping
                |--------------------------------------------------------------------------
                */

                    $localVariant =
                        $this->upsertLocalVariant(
                            $shop,
                            $freshShopifyVariant
                        );

                    /*
                |--------------------------------------------------------------------------
                | Sync to Zoho
                |--------------------------------------------------------------------------
                */

                    $zohoService->syncItem(
                        $localVariant
                    );

                    $successCount++;
                } catch (\Throwable $e) {
                    $failedCount++;

                    $failures[] = [
                        'shopify_variant_id' =>
                        $shopifyVariant['shopify_variant_id'],

                        'product' =>
                        $shopifyVariant['product']['title']
                            ?? 'Unknown Product',

                        'message' =>
                        $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'success' => true,

                'message' =>
                'Synchronization completed.',

                'data' => [
                    'total' =>
                    count($shopifyVariants),

                    'success' =>
                    $successCount,

                    'failed' =>
                    $failedCount,

                    'failures' =>
                    $failures,
                ],
            ]);
        } catch (\Throwable $e) {

            Log::error(
                'Sync all failed.',
                [
                    'shop_id' => $shop->id,
                    'message' => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function history(Request $request)
    {
        $shopDomain = $request->query('shop');

        return Inertia::render('Zoho/History', [
            'shop' => [
                'shop_domain' => $shopDomain,
            ],
            'histories' => [
                'data' => [],
                'total' => 0,
                'current_page' => 1,
                'last_page' => 1,
                'links' => [],
            ],
            'pendingProducts' => 0,
            'zohoConnected' => false,
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'status' => (string) $request->query('status', 'all'),
            ],
        ]);
    }

    public function historyData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', 'all');

        $histories = SyncHistory::with([
            'productVariant.product',
        ])
            ->where('shop_id', $shop->id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%")
                        ->orWhere('zoho_item_id', 'like', "%{$search}%")
                        ->orWhereHas('productVariant', function ($variantQuery) use ($search) {
                            $variantQuery->where('title', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        })
                        ->orWhereHas('productVariant.product', function ($productQuery) use ($search) {
                            $productQuery->where('title', 'like', "%{$search}%");
                        });
                });
            })
            ->when($status !== 'all', fn($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $pendingProducts = 0;

        try {
            $shopifyVariants = $this->fetchShopifyCatalog($shop);

            $variantIds = collect($shopifyVariants)
                ->pluck('shopify_variant_id')
                ->filter()
                ->values();

            $syncedVariantIds = ProductVariant::query()
                ->whereIn('shopify_variant_id', $variantIds)
                ->whereHas('product', function ($query) use ($shop) {
                    $query->where('shop_id', $shop->id);
                })
                ->whereNotNull('zoho_item_id')
                ->pluck('shopify_variant_id');

            $pendingProducts = $variantIds->diff($syncedVariantIds)->count();
        } catch (\Throwable $e) {
            Log::error('Failed to calculate pending Shopify products.', [
                'shop_id' => $shop->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'histories' => $histories,
            'pendingProducts' => $pendingProducts,
            'zohoConnected' => $shop->zohoConnection !== null,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }




    public function settings(Request $request)
    {
        $shopDomain = $request->query('shop');
        $host = $request->query('host');

        return Inertia::render('Zoho/Settings', [
            'shop' => [
                'shop_domain' => $shopDomain,
            ],
            'zohoConnection' => null,
            'host' => $host,
        ]);
    }

    public function settingsData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $zohoConnection = $shop->zohoConnection;

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'zohoConnection' => $zohoConnection,
            'host' => $request->query('host'),
        ]);
    }

    public function disconnect(Request $request)
    {
        $shop = $request->attributes->get('shop');

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
