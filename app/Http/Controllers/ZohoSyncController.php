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
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ZohoSyncController extends Controller
{
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

        return Inertia::render('Zoho/Sync', array_merge($context, [
            'variants' => [],
            'failedCount' => 0,
        ]));
    }

    public function orders(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shopModel = $this->resolveShopModel($request);
        $orders = [];

        if ($shopModel) {
            $orders = Order::with(['customer', 'invoice'])
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

                    $orders = Order::with(['customer', 'invoice'])
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

        $orders = Order::with(['customer', 'invoice'])
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

                $orders = Order::with(['customer', 'invoice'])
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

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'variants' => $variants,
            'orders' => $orders,
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
            'variant_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'string'],
        ]);

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
            $zohoService = new ZohoService($shop);
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
        $context = $this->resolveShopContext($request);

        return Inertia::render('Zoho/Settings', array_merge($context, [
            'zohoConnection' => null,
        ]));
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
}
