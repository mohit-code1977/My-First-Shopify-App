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
use App\Models\ZohoConnection;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ZohoSyncController extends Controller { 
    public function __construct(
        private ShopifyService $shopifyService
    ) {}

    private function resolveShopContext(Request $request): array
    {
        $shopDomain = $request->query('shop') ?? $request->header('X-Shop-Domain');
        $host = $request->query('host');
        $shop = null;

        if ($shopDomain) {
            $shop = Shop::where('shop_domain', $shopDomain)->first();
        }

        if (!$shop) {
            $shop = Shop::whereHas('products')->first()
                ?? Shop::whereNotNull('access_token')->latest()->first()
                ?? Shop::first();
            if ($shop) {
                $shopDomain = $shop->shop_domain;
            }
        }

        $zohoConnected = $shop ? ($shop->zohoConnection !== null) : false;

        return [
            'shop' => [
                'id' => $shop?->id,
                'shop_domain' => $shopDomain ?: ($shop?->shop_domain ?? 'Unknown store'),
            ],
            'zohoConnected' => $zohoConnected,
            'host' => $host,
        ];
    }

    private function resolveShopModel(Request $request): ?Shop
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            $shopDomain = $request->query('shop') ?? $request->header('X-Shop-Domain');
            if ($shopDomain) {
                $shop = Shop::where('shop_domain', $shopDomain)->first();
            }
        }

        if (!$shop) {
            $shop = Shop::whereHas('products')->first()
                ?? Shop::whereNotNull('access_token')->latest()->first()
                ?? Shop::first();
        }

        if ($shop) {
            $this->shopifyService->ensureWebhooksRegistered($shop);
        }

        return $shop;
    }

    private function getVariantsForShop(Shop $shop): array
    {
        $shopifyVariants = [];
        try {
            $shopifyVariants = $this->fetchShopifyCatalog($shop);
        } catch (\Throwable $e) {
            Log::error('Shopify catalog fetch failed.', [
                'shop_id' => $shop->id,
                'message' => $e->getMessage(),
            ]);
        }

        if (!empty($shopifyVariants)) {
            $variantIds = collect($shopifyVariants)
                ->pluck('shopify_variant_id')
                ->filter()
                ->values();

            $localMappings = ProductVariant::query()
                ->whereIn('shopify_variant_id', $variantIds)
                ->whereHas('product', function ($query) use ($shop) {
                    $query->where('shop_id', $shop->id);
                })
                ->get([
                    'id',
                    'product_id',
                    'shopify_variant_id',
                    'zoho_item_id',
                    'zoho_sync_hash',
                    'zoho_synced_at',
                ])
                ->keyBy('shopify_variant_id');

            return collect($shopifyVariants)
                ->map(function (array $variant) use ($localMappings) {
                    $mapping = $localMappings->get($variant['shopify_variant_id']);

                    return array_merge($variant, [
                        'id' => $variant['shopify_variant_id'],
                        'zoho_item_id' => $mapping?->zoho_item_id,
                        'zoho_sync_hash' => $mapping?->zoho_sync_hash,
                        'zoho_synced_at' => $mapping?->zoho_synced_at,
                    ]);
                })
                ->values()
                ->toArray();
        }

        $dbVariants = ProductVariant::with(['product'])
            ->whereHas('product', function ($query) use ($shop) {
                $query->where('shop_id', $shop->id);
            })
            ->get();

        return $dbVariants->map(function ($v) {
            return [
                'id' => $v->shopify_variant_id,
                'shopify_variant_id' => $v->shopify_variant_id,
                'shopify_inventory_item_id' => $v->shopify_inventory_item_id,
                'title' => $v->title ?: 'Default Title',
                'sku' => $v->sku,
                'price' => (string) $v->price,
                'inventory_quantity' => $v->inventory_quantity ?? 0,
                'zoho_item_id' => $v->zoho_item_id,
                'zoho_sync_hash' => $v->zoho_sync_hash,
                'zoho_synced_at' => $v->zoho_synced_at,
                'product' => [
                    'id' => $v->product?->shopify_product_id,
                    'title' => $v->product?->title ?: 'Untitled Product',
                    'handle' => $v->product?->handle,
                    'image_url' => $v->product?->image_url,
                ],
            ];
        })->values()->toArray();
    }

    public function index(Request $request)
    {
        return redirect()->route('zoho.products', $request->query());
    }

    public function products(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shopModel = $this->resolveShopModel($request);
        $variants = $shopModel ? $this->getVariantsForShop($shopModel) : [];

        return Inertia::render('Zoho/Products', array_merge($context, [
            'variants' => $variants,
            'failedCount' => 0,
        ]));
    }

    public function sync(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shopModel = $this->resolveShopModel($request);
        $inventoryCapability = 'unavailable';

        if ($shopModel && $shopModel->zohoConnection) {
            try {
                $zohoService = new ZohoService($shopModel);
                $inventoryCapability = $zohoService->detectInventoryCapability();
            } catch (\Throwable $e) {
                $inventoryCapability = 'unavailable';
            }
        }

        return Inertia::render('Zoho/Sync', array_merge($context, [
            'variants' => [],
            'failedCount' => 0,
            'inventoryCapability' => $inventoryCapability,
        ]));
    }

    public function orders(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shopModel = $this->resolveShopModel($request);
        $orders = [];

        if ($shopModel) {
            $orders = Order::with(['customer', 'invoice', 'payments', 'refunds'])
                ->where('shop_id', $shopModel->id)
                ->latest()
                ->take(50)
                ->get();

            if ($orders->isEmpty()) {
                try {
                    $shopifyService = app(\App\Services\ShopifyService::class);
                    $rawOrders = $shopifyService->fetchOrders($shopModel);

                    foreach ($rawOrders as $raw) {
                        $customerId = null;
                        if (!empty($raw['customer'])) {
                            $rawCust = $raw['customer'];
                            $custObj = Customer::updateOrCreate(
                                [
                                    'shop_id' => $shopModel->id,
                                    'shopify_customer_id' => (string) $rawCust['id'],
                                ],
                                [
                                    'first_name' => $rawCust['first_name'] ?? '',
                                    'last_name' => $rawCust['last_name'] ?? '',
                                    'email' => $rawCust['email'] ?? '',
                                    'phone' => $rawCust['phone'] ?? null,
                                    'billing_address' => $rawCust['default_address'] ?? [],
                                    'shipping_address' => $rawCust['default_address'] ?? [],
                                ]
                            );
                            $customerId = $custObj->id;
                        }

                        Order::updateOrCreate(
                            [
                                'shop_id' => $shopModel->id,
                                'shopify_order_id' => (string) $raw['id'],
                            ],
                            [
                                'customer_id' => $customerId,
                                'order_number' => (string) ($raw['order_number'] ?? $raw['name'] ?? $raw['id']),
                                'financial_status' => $raw['financial_status'] ?? 'pending',
                                'fulfillment_status' => $raw['fulfillment_status'] ?? 'unfulfilled',
                                'subtotal' => $raw['subtotal_price'] ?? 0.00,
                                'discount_total' => $raw['total_discounts'] ?? 0.00,
                                'shipping_total' => !empty($raw['shipping_lines']) ? array_sum(array_column($raw['shipping_lines'], 'price')) : 0.00,
                                'tax_total' => $raw['total_tax'] ?? 0.00,
                                'total_price' => $raw['total_price'] ?? 0.00,
                                'currency' => $raw['currency'] ?? 'USD',
                                'line_items' => $raw['line_items'] ?? [],
                                'notes' => $raw['note'] ?? null,
                                'order_date' => !empty($raw['created_at']) ? \Carbon\Carbon::parse($raw['created_at']) : now(),
                            ]
                        );
                    }

                    $orders = Order::with(['customer', 'invoice', 'payments'])
                        ->where('shop_id', $shopModel->id)
                        ->latest()
                        ->take(50)
                        ->get();
                } catch (\Exception $e) {
                    Log::warning('Could not fetch existing Shopify orders for view', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return Inertia::render('Zoho/Orders', array_merge($context, [
            'orders' => $orders,
        ]));
    }

    public function refunds(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shopModel = $this->resolveShopModel($request);
        $refunds = [];

        if ($shopModel) {
            $refunds = \App\Models\Refund::with(['order.customer'])
                ->where('shop_id', $shopModel->id)
                ->latest()
                ->take(50)
                ->get();
        }

        return Inertia::render('Zoho/Refunds', array_merge($context, [
            'refunds' => $refunds,
        ]));
    }

    public function customers(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shopModel = $this->resolveShopModel($request);
        $customers = [];

        if ($shopModel) {
            $customers = Customer::where('shop_id', $shopModel->id)
                ->latest()
                ->take(50)
                ->get();

            if ($customers->isEmpty()) {
                try {
                    $shopifyService = app(\App\Services\ShopifyService::class);
                    $rawCustomers = $shopifyService->fetchCustomers($shopModel);

                    foreach ($rawCustomers as $raw) {
                        $defaultAddr = $raw['default_address'] ?? [];
                        Customer::updateOrCreate(
                            [
                                'shop_id' => $shopModel->id,
                                'shopify_customer_id' => (string) $raw['id'],
                            ],
                            [
                                'first_name' => $raw['first_name'] ?? '',
                                'last_name' => $raw['last_name'] ?? '',
                                'email' => $raw['email'] ?? '',
                                'phone' => $raw['phone'] ?? $defaultAddr['phone'] ?? null,
                                'billing_address' => $defaultAddr,
                                'shipping_address' => $defaultAddr,
                            ]
                        );
                    }

                    $customers = Customer::where('shop_id', $shopModel->id)
                        ->latest()
                        ->take(50)
                        ->get();
                } catch (\Exception $e) {
                    Log::warning('Could not fetch existing Shopify customers for view', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return Inertia::render('Zoho/Customers', array_merge($context, [
            'customers' => $customers,
        ]));
    }

    public function customersData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $customers = Customer::where('shop_id', $shop->id)
            ->latest()
            ->take(50)
            ->get();

        if ($customers->isEmpty() || $request->boolean('refresh')) {
            try {
                $shopifyService = app(\App\Services\ShopifyService::class);
                $rawCustomers = $shopifyService->fetchCustomers($shop);

                foreach ($rawCustomers as $raw) {
                    $defaultAddr = $raw['default_address'] ?? [];
                    Customer::updateOrCreate(
                        [
                            'shop_id' => $shop->id,
                            'shopify_customer_id' => (string) $raw['id'],
                        ],
                        [
                            'first_name' => $raw['first_name'] ?? '',
                            'last_name' => $raw['last_name'] ?? '',
                            'email' => $raw['email'] ?? '',
                            'phone' => $raw['phone'] ?? $defaultAddr['phone'] ?? null,
                            'billing_address' => $defaultAddr,
                            'shipping_address' => $defaultAddr,
                        ]
                    );
                }

                $customers = Customer::where('shop_id', $shop->id)
                    ->latest()
                    ->take(50)
                    ->get();
            } catch (\Exception $e) {
                Log::warning('Could not fetch existing Shopify customers for list view', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $zohoConnection = $shop->zohoConnection;

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'customers' => $customers,
            'zohoConnected' => $zohoConnection !== null,
        ]);
    }

    public function ordersData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $orders = Order::with(['customer', 'invoice', 'payments', 'refunds'])
            ->where('shop_id', $shop->id)
            ->latest()
            ->take(50)
            ->get();

        if ($orders->isEmpty() || $request->boolean('refresh')) {
            try {
                $shopifyService = app(\App\Services\ShopifyService::class);
                $rawOrders = $shopifyService->fetchOrders($shop);

                foreach ($rawOrders as $raw) {
                    $customerId = null;
                    if (!empty($raw['customer'])) {
                        $rawCust = $raw['customer'];
                        $custObj = Customer::updateOrCreate(
                            [
                                'shop_id' => $shop->id,
                                'shopify_customer_id' => (string) $rawCust['id'],
                            ],
                            [
                                'first_name' => $rawCust['first_name'] ?? '',
                                'last_name' => $rawCust['last_name'] ?? '',
                                'email' => $rawCust['email'] ?? '',
                                'phone' => $rawCust['phone'] ?? null,
                                'billing_address' => $rawCust['default_address'] ?? [],
                                'shipping_address' => $rawCust['default_address'] ?? [],
                            ]
                        );
                        $customerId = $custObj->id;
                    }

                    Order::updateOrCreate(
                        [
                            'shop_id' => $shop->id,
                            'shopify_order_id' => (string) $raw['id'],
                        ],
                        [
                            'customer_id' => $customerId,
                            'order_number' => (string) ($raw['order_number'] ?? $raw['name'] ?? $raw['id']),
                            'financial_status' => $raw['financial_status'] ?? 'pending',
                            'fulfillment_status' => $raw['fulfillment_status'] ?? 'unfulfilled',
                            'subtotal' => $raw['subtotal_price'] ?? 0.00,
                            'discount_total' => $raw['total_discounts'] ?? 0.00,
                            'shipping_total' => !empty($raw['shipping_lines']) ? array_sum(array_column($raw['shipping_lines'], 'price')) : 0.00,
                            'tax_total' => $raw['total_tax'] ?? 0.00,
                            'total_price' => $raw['total_price'] ?? 0.00,
                            'currency' => $raw['currency'] ?? 'USD',
                            'line_items' => $raw['line_items'] ?? [],
                            'notes' => $raw['note'] ?? null,
                            'order_date' => !empty($raw['created_at']) ? \Carbon\Carbon::parse($raw['created_at']) : now(),
                        ]
                    );
                }

                $orders = Order::with(['customer', 'invoice', 'payments'])
                    ->where('shop_id', $shop->id)
                    ->latest()
                    ->take(50)
                    ->get();
            } catch (\Exception $e) {
                Log::warning('Could not fetch existing Shopify orders for list view', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $zohoConnection = $shop->zohoConnection;

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'orders' => $orders,
            'zohoConnected' => $zohoConnection !== null,
        ]);
    }

    public function refundsData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $refunds = \App\Models\Refund::with(['order.customer'])
            ->where('shop_id', $shop->id)
            ->latest()
            ->take(50)
            ->get();

        $zohoConnection = $shop->zohoConnection;

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'refunds' => $refunds,
            'zohoConnected' => $zohoConnection !== null,
        ]);
    }

    public function refundDetail(Request $request, $id): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $refund = \App\Models\Refund::with(['order.customer', 'syncHistories'])
            ->where('shop_id', $shop->id)
            ->where('id', $id)
            ->first();

        if (!$refund) {
            return response()->json([
                'success' => false,
                'message' => 'Refund record not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'refund' => $refund,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $shop = $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $variants = $this->getVariantsForShop($shop);
        $zohoConnection = $shop->zohoConnection;

        $orders = Order::with(['customer', 'invoice'])
            ->where('shop_id', $shop->id)
            ->latest()
            ->take(50)
            ->get();

        $inventoryCapability = 'unavailable';
        if ($zohoConnection !== null) {
            try {
                $zohoService = new ZohoService($shop);
                $inventoryCapability = $zohoService->detectInventoryCapability();
            } catch (\Throwable $e) {
                $inventoryCapability = 'unavailable';
            }
        }

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'variants' => $variants,
            'orders' => $orders,
            'zohoConnected' => $zohoConnection !== null,
            'inventoryCapability' => $inventoryCapability,
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
                            inventoryItem {
                                id
                            }

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

                        'shopify_inventory_item_id' =>
                        $variant['inventoryItem']['id'] ?? null,

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

    public function syncZohoInventory(Request $request): JsonResponse
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
            'variant_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'string'],
        ]);

        $zohoService = new ZohoService($shop);

        if (!empty($validated['variant_id'])) {
            $variant = ProductVariant::where('id', $validated['variant_id'])
                ->whereHas('product', function ($query) use ($shop) {
                    $query->where('shop_id', $shop->id);
                })
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product variant not found.',
                ], 404);
            }

            try {
                $result = $zohoService->syncZohoInventoryToShopify($variant, $validated['location_id'] ?? null);

                return response()->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'Zoho inventory synchronized to Shopify successfully.',
                    'data' => $result,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        try {
            $result = $zohoService->syncAllZohoInventoryToShopify($shop);

            return response()->json([
                'success' => true,
                'message' => "Bulk Zoho inventory sync complete: {$result['synced']} synced, {$result['failed']} failed, {$result['skipped']} skipped.",
                'synced' => $result['synced'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'total' => $result['total'],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncCustomer(Request $request): JsonResponse
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
            'customer_id' => ['required', 'integer'],
        ]);

        $customer = Customer::where('id', $validated['customer_id'])
            ->where('shop_id', $shop->id)
            ->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        try {
            $zohoService = new ZohoService($shop);
            $result = $zohoService->syncCustomer($customer);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Customer synchronized to Zoho successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncOrder(Request $request): JsonResponse
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
            'order_id' => ['required', 'integer'],
        ]);

        $order = Order::where('id', $validated['order_id'])
            ->where('shop_id', $shop->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $zohoService = new ZohoService($shop);
            $result = $zohoService->syncOrder($order);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Sales Order synchronized to Zoho successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncInvoice(Request $request): JsonResponse
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
            'order_id' => ['required', 'integer'],
        ]);

        $order = Order::where('id', $validated['order_id'])
            ->where('shop_id', $shop->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $zohoService = new ZohoService($shop);
            $result = $zohoService->syncInvoice($order);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Invoice synchronized to Zoho successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncPayment(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

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
            'payment_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
        ]);

        $payment = null;

        if (!empty($validated['payment_id'])) {
            $payment = Payment::where('id', $validated['payment_id'])
                ->where('shop_id', $shop->id)
                ->first();
        } elseif (!empty($validated['order_id'])) {
            $order = Order::where('id', $validated['order_id'])
                ->where('shop_id', $shop->id)
                ->first();

            if ($order) {
                $payment = Payment::where('order_id', $order->id)
                    ->where('shop_id', $shop->id)
                    ->latest()
                    ->first();

                if (!$payment && $order->invoice) {
                    $payment = Payment::create([
                        'shop_id' => $shop->id,
                        'order_id' => $order->id,
                        'invoice_id' => $order->invoice->id,
                        'shopify_order_id' => $order->shopify_order_id,
                        'shopify_transaction_id' => "gid://shopify/OrderTransaction/manual_{$order->id}_" . time(),
                        'payment_reference' => "TXN-MANUAL-{$order->id}",
                        'amount' => $order->total_price,
                        'currency' => $order->currency ?? 'USD',
                        'payment_date' => now(),
                        'payment_method' => 'shopify_payments',
                        'status' => Payment::STATUS_PAID,
                        'sync_status' => Payment::SYNC_STATUS_PENDING,
                    ]);
                }
            }
        }

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment record not found for sync.',
            ], 404);
        }

        try {
            $payment->unsetRelations();
            $payment->refresh();

            $zohoService = new ZohoService($shop);
            $result = $zohoService->syncPayment($payment);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Payment synchronized to Zoho successfully.',
                'data' => $result,
                'payment' => $payment->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'payment' => $payment ? $payment->fresh() : null,
            ], 500);
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
        inventoryItem {
            id
        }

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

        if (!empty($shopifyVariant['inventoryItem']['id'])) {
            $variant->shopify_inventory_item_id = $shopifyVariant['inventoryItem']['id'];
        }

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
        $context = $this->resolveShopContext($request);

        return Inertia::render('Zoho/History', array_merge($context, [
            'histories' => [
                'data' => [],
                'total' => 0,
                'current_page' => 1,
                'last_page' => 1,
                'links' => [],
            ],
            'pendingProducts' => 0,
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'status' => (string) $request->query('status', 'all'),
            ],
        ]));
    }

    public function historyData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

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
            'order.customer',
            'invoice',
            'payment',
        ])
            ->where('shop_id', $shop->id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%")
                        ->orWhere('zoho_item_id', 'like', "%{$search}%")
                        ->orWhere('zoho_invoice_id', 'like', "%{$search}%")
                        ->orWhere('zoho_payment_id', 'like', "%{$search}%")
                        ->orWhereHas('productVariant', function ($variantQuery) use ($search) {
                            $variantQuery->where('title', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        })
                        ->orWhereHas('productVariant.product', function ($productQuery) use ($search) {
                            $productQuery->where('title', 'like', "%{$search}%");
                        })
                        ->orWhereHas('order', function ($orderQuery) use ($search) {
                            $orderQuery->where('order_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('payment', function ($paymentQuery) use ($search) {
                            $paymentQuery->where('payment_reference', 'like', "%{$search}%")
                                ->orWhere('shopify_transaction_id', 'like', "%{$search}%")
                                ->orWhere('zoho_payment_id', 'like', "%{$search}%");
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
        $context = $this->resolveShopContext($request);

        return Inertia::render('Zoho/Settings', array_merge($context, [
            'zohoConnection' => null,
        ]));
    }

    public function settingsData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $zohoConnection = $shop->zohoConnection;
        $zohoAccounts = [];
        $zohoTaxes = [];
        if ($zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $zohoAccounts = $zohoService->fetchAccounts();
                $zohoTaxes = $zohoService->getTaxes();
            } catch (\Throwable $e) {
                Log::warning('Could not fetch Zoho metadata: ' . $e->getMessage());
            }
        }

        $defaultGateways = [
            'shopify_payments' => ['shopify_gateway' => 'shopify_payments', 'gateway_label' => 'Shopify Payments', 'payment_mode' => 'creditcard', 'account_id' => ''],
            'stripe' => ['shopify_gateway' => 'stripe', 'gateway_label' => 'Stripe', 'payment_mode' => 'creditcard', 'account_id' => ''],
            'paypal' => ['shopify_gateway' => 'paypal', 'gateway_label' => 'PayPal', 'payment_mode' => 'paypal', 'account_id' => ''],
            'cash_on_delivery' => ['shopify_gateway' => 'cash_on_delivery', 'gateway_label' => 'Cash on Delivery', 'payment_mode' => 'cash', 'account_id' => ''],
            'bank_transfer' => ['shopify_gateway' => 'bank_transfer', 'gateway_label' => 'Bank Transfer', 'payment_mode' => 'banktransfer', 'account_id' => ''],
            'manual' => ['shopify_gateway' => 'manual', 'gateway_label' => 'Manual / Other', 'payment_mode' => 'others', 'account_id' => ''],
        ];

        $savedSettings = $shop->payment_gateway_settings ?? [];
        $paymentGatewaySettings = [];
        foreach ($defaultGateways as $key => $default) {
            $configured = $savedSettings[$key] ?? [];
            $paymentGatewaySettings[] = [
                'shopify_gateway' => $key,
                'gateway_label' => $default['gateway_label'],
                'payment_mode' => $configured['payment_mode'] ?? $default['payment_mode'],
                'account_id' => $configured['account_id'] ?? $default['account_id'],
            ];
        }

        $defaultTaxSettings = [
            'tax_mode' => 'exclusive',
            'default_tax_id' => '',
            'shipping_tax_mode' => 'use_order_tax',
            'discount_tax_mode' => 'before_tax',
            'tax_mappings' => [
                ['shopify_tax_name' => 'GST', 'shopify_rate' => 5, 'zoho_tax_id' => ''],
                ['shopify_tax_name' => 'GST', 'shopify_rate' => 18, 'zoho_tax_id' => ''],
                ['shopify_tax_name' => 'VAT', 'shopify_rate' => 20, 'zoho_tax_id' => ''],
            ],
        ];

        $taxSettings = array_merge($defaultTaxSettings, $shop->tax_settings ?? []);

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'zohoConnection' => $zohoConnection,
            'paymentGatewaySettings' => $paymentGatewaySettings,
            'zohoAccounts' => $zohoAccounts,
            'taxSettings' => $taxSettings,
            'zohoTaxes' => $zohoTaxes,
            'host' => $request->query('host'),
        ]);
    }

    public function savePaymentSettings(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $validated = $request->validate([
            'gateways' => ['required', 'array'],
            'gateways.*.shopify_gateway' => ['required', 'string'],
            'gateways.*.payment_mode' => ['required', 'string'],
            'gateways.*.account_id' => ['nullable', 'string'],
        ]);

        $settingsMap = $shop->payment_gateway_settings ?? [];
        foreach ($validated['gateways'] as $item) {
            $key = strtolower(trim($item['shopify_gateway']));
            $settingsMap[$key] = [
                'payment_mode' => strtolower(trim($item['payment_mode'])),
                'account_id' => !empty($item['account_id']) ? trim($item['account_id']) : null,
            ];
        }

        $shop->payment_gateway_settings = $settingsMap;
        $shop->save();

        return response()->json([
            'success' => true,
            'message' => 'Payment gateway settings saved successfully.',
            'payment_gateway_settings' => $settingsMap,
        ]);
    }

    public function saveTaxSettings(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $validated = $request->validate([
            'tax_mode' => ['required', 'string', 'in:inclusive,exclusive'],
            'default_tax_id' => ['nullable', 'string'],
            'shipping_tax_mode' => ['required', 'string', 'in:use_order_tax,separate_shipping_tax,no_tax'],
            'discount_tax_mode' => ['required', 'string', 'in:before_tax,after_tax'],
            'tax_mappings' => ['nullable', 'array', 'max:50'],
            'tax_mappings.*.shopify_tax_name' => ['nullable', 'string', 'max:100'],
            'tax_mappings.*.shopify_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_mappings.*.zoho_tax_id' => ['nullable', 'string'],
        ]);

        // Fetch Zoho taxes to validate rate parity
        $zohoTaxes = [];
        try {
            $zohoService = new ZohoService($shop);
            $zohoTaxes = $zohoService->getTaxes();
        } catch (\Throwable $e) {
            Log::warning('saveTaxSettings: Could not fetch Zoho taxes for validation: ' . $e->getMessage());
        }

        $zohoTaxMap = [];
        foreach ($zohoTaxes as $zt) {
            if (!empty($zt['tax_id'])) {
                $zohoTaxMap[(string) $zt['tax_id']] = $zt;
            }
        }

        if (!empty($validated['tax_mappings'])) {
            if (count($validated['tax_mappings']) > 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum limit of 50 tax mappings allowed per shop.',
                ], 422);
            }

            $seen = [];
            foreach ($validated['tax_mappings'] as $mapping) {
                $name = strtolower(trim($mapping['shopify_tax_name'] ?? ''));
                $rate = isset($mapping['shopify_rate']) && $mapping['shopify_rate'] !== '' ? round((float) $mapping['shopify_rate'], 2) : null;
                $zohoTaxId = !empty($mapping['zoho_tax_id']) ? (string) $mapping['zoho_tax_id'] : null;

                if ($name !== '' && $rate !== null) {
                    $key = "{$name}:{$rate}";
                    if (isset($seen[$key])) {
                        $displayName = $mapping['shopify_tax_name'] ?? 'Tax';
                        return response()->json([
                            'success' => false,
                            'message' => "Duplicate tax mapping detected for '{$displayName}' at rate {$rate}%.",
                        ], 422);
                    }
                    $seen[$key] = true;
                }

                if ($zohoTaxId && $rate !== null && isset($zohoTaxMap[$zohoTaxId])) {
                    $actualZohoRate = (float) ($zohoTaxMap[$zohoTaxId]['tax_percentage'] ?? 0.0);
                    if (abs($rate - $actualZohoRate) > 0.01) {
                        $zohoTaxName = $zohoTaxMap[$zohoTaxId]['tax_name'] ?? 'Zoho Tax';
                        return response()->json([
                            'success' => false,
                            'message' => "Tax mapping rate mismatch: Mapped Zoho tax '{$zohoTaxName}' has an actual API rate of {$actualZohoRate}%, which does not match the Shopify tax rate of {$rate}%.",
                        ], 422);
                    }
                }
            }
        }

        $shop->tax_settings = $validated;
        $shop->save();

        return response()->json([
            'success' => true,
            'message' => 'Tax configuration saved successfully.',
            'tax_settings' => $shop->tax_settings,
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

        $connection = ZohoConnection::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Zoho is not connected.',
            ], 404);
        }

        $connection->update([
            'is_active' => false,
            'disconnected_at' => now(),
            'access_token' => null,
            'refresh_token' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Zoho disconnected successfully.',
        ]);
    }

    public function syncRefund(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

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
            'refund_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
        ]);

        try {
            $refund = null;
            if (!empty($validated['refund_id'])) {
                $refund = \App\Models\Refund::where('shop_id', $shop->id)->find($validated['refund_id']);
            } elseif (!empty($validated['order_id'])) {
                $refund = \App\Models\Refund::where('shop_id', $shop->id)
                    ->where('order_id', $validated['order_id'])
                    ->latest()
                    ->first();
            }

            if (!$refund) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund record not found.',
                ], 404);
            }

            $zohoService = new ZohoService($shop);
            $result = $zohoService->syncRefund($refund);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refund sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkSyncOrders(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'No Shopify shop installed.'], 404);
        }

        if (!$shop->zohoConnection) {
            return response()->json(['success' => false, 'message' => 'Zoho is not connected.'], 409);
        }

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer'],
            'sync_type' => ['nullable', 'string', 'in:order,invoice,payment'],
        ]);

        $syncType = $validated['sync_type'] ?? 'order';
        $orderIds = $validated['order_ids'];

        $orders = Order::where('shop_id', $shop->id)
            ->whereIn('id', $orderIds)
            ->with(['invoice'])
            ->get();

        $zohoService = app()->bound(ZohoService::class) ? app(ZohoService::class) : new ZohoService($shop);
        $results = [];
        $syncedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($orderIds as $orderId) {
            $order = $orders->firstWhere('id', $orderId);

            if (!$order) {
                $results[] = [
                    'id' => $orderId,
                    'status' => 'failed',
                    'message' => 'Order not found for current shop.',
                ];
                $failedCount++;
                continue;
            }

            try {
                if ($syncType === 'invoice') {
                    $res = $zohoService->syncInvoice($order);
                } elseif ($syncType === 'payment') {
                    $payment = Payment::where('order_id', $order->id)
                        ->where('shop_id', $shop->id)
                        ->latest()
                        ->first();

                    if (!$payment && $order->invoice) {
                        $payment = Payment::create([
                            'shop_id' => $shop->id,
                            'order_id' => $order->id,
                            'invoice_id' => $order->invoice->id,
                            'shopify_order_id' => $order->shopify_order_id,
                            'shopify_transaction_id' => "gid://shopify/OrderTransaction/manual_{$order->id}_" . time(),
                            'payment_reference' => "TXN-MANUAL-{$order->id}",
                            'amount' => $order->total_price,
                            'currency' => $order->currency ?? 'USD',
                            'payment_date' => now(),
                            'payment_method' => 'shopify_payments',
                            'status' => Payment::STATUS_PAID,
                            'sync_status' => Payment::SYNC_STATUS_PENDING,
                        ]);
                    }

                    if ($payment) {
                        $payment->unsetRelations();
                        $payment->refresh();
                        $res = $zohoService->syncPayment($payment);
                    } else {
                        $res = ['success' => false, 'message' => 'Invoice or payment record missing for order.'];
                    }
                } else {
                    $res = $zohoService->syncOrder($order);
                }

                $isSuccess = !empty($res['success']);
                $isSkipped = isset($res['skipped']) && $res['skipped'] === true;

                if ($isSkipped) {
                    $skippedCount++;
                    $results[] = [
                        'id' => $orderId,
                        'order_number' => $order->order_number,
                        'status' => 'skipped',
                        'message' => $res['message'] ?? 'Skipped.',
                    ];
                } elseif ($isSuccess) {
                    $syncedCount++;
                    $results[] = [
                        'id' => $orderId,
                        'order_number' => $order->order_number,
                        'status' => 'success',
                        'message' => $res['message'] ?? 'Synced successfully.',
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'id' => $orderId,
                        'order_number' => $order->order_number,
                        'status' => 'failed',
                        'message' => $res['message'] ?? 'Sync failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'id' => $orderId,
                    'order_number' => $order->order_number,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($orderIds),
                'synced' => $syncedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
            ],
        ]);
    }

    public function bulkSyncCustomers(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'No Shopify shop installed.'], 404);
        }

        if (!$shop->zohoConnection) {
            return response()->json(['success' => false, 'message' => 'Zoho is not connected.'], 409);
        }

        $validated = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => ['required', 'integer'],
        ]);

        $customerIds = $validated['customer_ids'];
        $customers = Customer::where('shop_id', $shop->id)
            ->whereIn('id', $customerIds)
            ->get();

        $zohoService = app()->bound(ZohoService::class) ? app(ZohoService::class) : new ZohoService($shop);
        $results = [];
        $syncedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($customerIds as $customerId) {
            $customer = $customers->firstWhere('id', $customerId);

            if (!$customer) {
                $results[] = [
                    'id' => $customerId,
                    'status' => 'failed',
                    'message' => 'Customer record not found for current shop.',
                ];
                $failedCount++;
                continue;
            }

            try {
                $res = $zohoService->syncCustomer($customer);
                $isSuccess = !empty($res['success']);
                $isSkipped = isset($res['skipped']) && $res['skipped'] === true;

                if ($isSkipped) {
                    $skippedCount++;
                    $results[] = [
                        'id' => $customerId,
                        'name' => trim("{$customer->first_name} {$customer->last_name}") ?: $customer->email,
                        'status' => 'skipped',
                        'message' => $res['message'] ?? 'Skipped.',
                    ];
                } elseif ($isSuccess) {
                    $syncedCount++;
                    $results[] = [
                        'id' => $customerId,
                        'name' => trim("{$customer->first_name} {$customer->last_name}") ?: $customer->email,
                        'status' => 'success',
                        'message' => $res['message'] ?? 'Synced successfully.',
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'id' => $customerId,
                        'name' => trim("{$customer->first_name} {$customer->last_name}") ?: $customer->email,
                        'status' => 'failed',
                        'message' => $res['message'] ?? 'Sync failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'id' => $customerId,
                    'name' => trim("{$customer->first_name} {$customer->last_name}") ?: $customer->email,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($customerIds),
                'synced' => $syncedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
            ],
        ]);
    }

    public function bulkSyncRefunds(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'No Shopify shop installed.'], 404);
        }

        if (!$shop->zohoConnection) {
            return response()->json(['success' => false, 'message' => 'Zoho is not connected.'], 409);
        }

        $validated = $request->validate([
            'refund_ids' => ['required', 'array', 'min:1'],
            'refund_ids.*' => ['required', 'integer'],
        ]);

        $refundIds = $validated['refund_ids'];
        $refunds = \App\Models\Refund::where('shop_id', $shop->id)
            ->whereIn('id', $refundIds)
            ->get();

        $zohoService = app()->bound(ZohoService::class) ? app(ZohoService::class) : new ZohoService($shop);
        $results = [];
        $syncedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($refundIds as $refundId) {
            $refund = $refunds->firstWhere('id', $refundId);

            if (!$refund) {
                $results[] = [
                    'id' => $refundId,
                    'status' => 'failed',
                    'message' => 'Refund record not found for current shop.',
                ];
                $failedCount++;
                continue;
            }

            try {
                $res = $zohoService->syncRefund($refund);
                $isSuccess = !empty($res['success']);
                $isSkipped = isset($res['skipped']) && $res['skipped'] === true;

                if ($isSkipped) {
                    $skippedCount++;
                    $results[] = [
                        'id' => $refundId,
                        'shopify_refund_id' => $refund->shopify_refund_id,
                        'status' => 'skipped',
                        'message' => $res['message'] ?? 'Skipped.',
                    ];
                } elseif ($isSuccess) {
                    $syncedCount++;
                    $results[] = [
                        'id' => $refundId,
                        'shopify_refund_id' => $refund->shopify_refund_id,
                        'status' => 'success',
                        'message' => $res['message'] ?? 'Synced successfully.',
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'id' => $refundId,
                        'shopify_refund_id' => $refund->shopify_refund_id,
                        'status' => 'failed',
                        'message' => $res['message'] ?? 'Sync failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'id' => $refundId,
                    'shopify_refund_id' => $refund->shopify_refund_id,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($refundIds),
                'synced' => $syncedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
            ],
        ]);
    }
}
