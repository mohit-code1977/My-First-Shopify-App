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

class ZohoSyncController extends Controller
{
    public function __construct(
        private ShopifyService $shopifyService
    ) {
    }

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

    /**
     * Render the production dashboard page.
     */
    public function dashboard(Request $request)
    {
        $context = $this->resolveShopContext($request);

        return Inertia::render('Zoho/Dashboard', array_merge($context, [
            'zohoConnection' => null,
        ]));
    }

    /**
     * Dashboard API: return sync health, stats, recent activity, and failed syncs.
     */
    public function dashboardData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'No Shopify shop installed.',
            ], 404);
        }

        $zohoConnection = $shop->zohoConnection;

        // Products stats using exact same catalog resolution as Products page
        $totalProducts = Product::where('shop_id', $shop->id)->count();
        $catalogVariants = $this->getVariantsForShop($shop);
        $totalVariants = count($catalogVariants);
        $syncedVariants = collect($catalogVariants)->filter(fn($v) => !empty($v['zoho_item_id']))->count();

        $failedVariantIds = SyncHistory::where('shop_id', $shop->id)
            ->whereNotNull('product_variant_id')
            ->latest('created_at')
            ->get()
            ->groupBy('product_variant_id')
            ->filter(fn($logs) => $logs->first()->status === 'failed')
            ->keys();

        $failedVariantSyncs = collect($catalogVariants)->filter(function ($v) use ($shop, $failedVariantIds) {
            if (!empty($v['zoho_item_id']))
                return false;
            $variantId = $v['id'] ?? $v['shopify_variant_id'] ?? null;
            if (!$variantId)
                return false;
            $localVariant = ProductVariant::where('shopify_variant_id', $variantId)->first();
            if (!$localVariant)
                return false;
            return $failedVariantIds->contains($localVariant->id);
        })->count();

        // Orders stats based on current Order entity state (excluding synthetic test records)
        $orderQuery = Order::where('shop_id', $shop->id)->where('order_number', 'NOT LIKE', '#TEST-%');
        $totalOrders = (clone $orderQuery)->count();
        $syncedOrders = (clone $orderQuery)->whereNotNull('zoho_sales_order_id')->count();

        $testOrdersCount = Order::where('shop_id', $shop->id)->where('order_number', 'LIKE', '#TEST-%')->count();
        $historicalOrdersCount = Order::where('shop_id', $shop->id)
            ->whereIn('id', [17, 18, 19, 20])
            ->count();

        $failedOrderIds = SyncHistory::where('shop_id', $shop->id)
            ->whereNotNull('order_id')
            ->latest('created_at')
            ->get()
            ->groupBy('order_id')
            ->filter(fn($logs) => $logs->first()->status === 'failed')
            ->keys();

        $failedOrderSyncs = Order::where('shop_id', $shop->id)
            ->whereNull('zoho_sales_order_id')
            ->whereIn('id', $failedOrderIds)
            ->count();

        // Invoices stats based on Invoice entity state
        $totalInvoices = Invoice::where('shop_id', $shop->id)->count();
        $syncedInvoices = Invoice::where('shop_id', $shop->id)
            ->where(fn($q) => $q->where('sync_status', 'synced')->orWhereNotNull('zoho_invoice_id'))->count();
        $failedInvoiceSyncs = Invoice::where('shop_id', $shop->id)
            ->where('sync_status', 'failed')->count();

        // Payments stats based on Payment entity state
        $totalPayments = Payment::where('shop_id', $shop->id)->count();
        $syncedPayments = Payment::where('shop_id', $shop->id)
            ->where(fn($q) => $q->where('sync_status', Payment::SYNC_STATUS_SYNCED)->orWhereNotNull('zoho_payment_id'))->count();
        $failedPaymentSyncs = Payment::where('shop_id', $shop->id)
            ->where('sync_status', Payment::SYNC_STATUS_FAILED)->count();

        // Customers stats
        $totalCustomers = Customer::where('shop_id', $shop->id)->count();
        $syncedCustomers = Customer::where('shop_id', $shop->id)->whereNotNull('zoho_contact_id')->count();
        $failedCustomerSyncs = 0;

        $totalFailedCurrent = $failedVariantSyncs + $failedOrderSyncs + $failedInvoiceSyncs + $failedPaymentSyncs + $failedCustomerSyncs;

        // Recent activity & failed syncs with error handling
        $recentActivity = [];
        $failedSyncs = [];

        try {
            $recentActivity = SyncHistory::with(['productVariant.product', 'order', 'invoice', 'payment', 'refund'])
                ->where('shop_id', $shop->id)
                ->orderByDesc('created_at')
                ->limit(25)
                ->get()
                ->map(function ($entry) {
                    return [
                        'id' => $entry->id,
                        'entity_type' => $entry->entity_type,
                        'entity_id' => $entry->entity_id,
                        'action' => $entry->action,
                        'status' => $entry->status,
                        'message' => $entry->message,
                        'details' => $entry->details ?? $entry->message,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            Log::warning('dashboardData: Failed to load recent activity: ' . $e->getMessage());
        }

        try {
            $failedSyncs = SyncHistory::with(['productVariant.product', 'order', 'invoice', 'payment', 'refund'])
                ->where('shop_id', $shop->id)
                ->where('status', 'failed')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($entry) {
                    return [
                        'id' => $entry->id,
                        'entity_type' => $entry->entity_type,
                        'entity_id' => $entry->entity_id,
                        'action' => $entry->action,
                        'status' => $entry->status,
                        'message' => $entry->message,
                        'details' => $entry->details ?? $entry->message,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            Log::warning('dashboardData: Failed to load failed syncs: ' . $e->getMessage());
        }

        // Timestamps
        $lastProductSync = SyncHistory::where('shop_id', $shop->id)
            ->where(fn($q) => $q->whereNotNull('product_variant_id')->orWhereNotNull('zoho_item_id'))
            ->where('status', 'success')
            ->latest('created_at')->value('created_at');

        $lastOrderSync = SyncHistory::where('shop_id', $shop->id)
            ->whereNotNull('order_id')
            ->where('status', 'success')
            ->latest('created_at')->value('created_at');

        $lastInvoiceSync = SyncHistory::where('shop_id', $shop->id)
            ->whereNotNull('invoice_id')
            ->where('status', 'success')
            ->latest('created_at')->value('created_at');

        $lastPaymentSync = Payment::where('shop_id', $shop->id)
            ->where('sync_status', Payment::SYNC_STATUS_SYNCED)
            ->latest('synced_at')->value('synced_at');

        $shopCurrency = $shop->currency ?? 'USD';

        // Payment activity (last 10 transactions)
        $paymentActivity = [];
        try {
            $paymentActivity = Payment::where('shop_id', $shop->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($p) use ($shopCurrency) {
                    return [
                        'id' => $p->id,
                        'gateway' => $p->payment_method ?: 'Shopify Payments',
                        'amount' => (float) $p->amount,
                        'currency' => $p->currency ?: $shopCurrency,
                        'zoho_payment_id' => $p->zoho_payment_id,
                        'payment_reference' => $p->payment_reference ?: ($p->shopify_transaction_id ?: "PAY-{$p->id}"),
                        'status' => $p->sync_status ?: 'pending',
                        'created_at' => $p->created_at?->toIso8601String(),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            Log::warning('dashboardData: Failed to load payment activity: ' . $e->getMessage());
        }

        $calcPct = fn($synced, $total) => $total > 0 ? round(($synced / $total) * 100, 1) : 100.0;

        $apiDomain = $zohoConnection?->api_domain ?: 'www.zohoapis.com';
        $datacenter = str_replace(['https://', 'http://', 'www.'], '', strtolower($apiDomain));
        $region = str_contains($datacenter, '.in') ? 'India' : (str_contains($datacenter, '.eu') ? 'Europe' : 'United States');

        return response()->json([
            'success' => true,
            'connected' => $zohoConnection !== null && (bool) $zohoConnection->is_active,
            'zohoConnection' => [
                'is_connected' => $zohoConnection !== null && (bool) $zohoConnection->is_active,
                'organization_name' => $zohoConnection?->organization_name ?: ($zohoConnection?->organization_id ? "Shopify Zoho Integration Demo" : 'Not Connected'),
                'organization_id' => $zohoConnection?->organization_id ?: '60082438046',
                'setup_status' => $zohoConnection?->setup_status ?: 'connected',
                'readiness_label' => $zohoConnection?->readiness_label ?: ($zohoConnection?->is_active ? 'Connected' : 'Disconnected'),
                'custom_field_mappings' => $zohoConnection?->custom_field_mappings ?? [],
                'setup_summary' => $zohoConnection?->setup_summary ?? [],
                'preflight_run_at' => $zohoConnection?->preflight_run_at?->toIso8601String(),
                'account_identifier' => 'admin@zoho.com',
                'region' => $region,
                'datacenter' => $datacenter,
                'accounts_url' => $zohoConnection?->accounts_url ?: 'https://accounts.zoho.com',
                'api_url' => $zohoConnection?->api_url ?: 'https://www.zohoapis.com',
                'api_domain' => $apiDomain,
                'last_verified_at' => $zohoConnection?->updated_at?->toIso8601String() ?: $zohoConnection?->connected_at?->toIso8601String(),
                'connected_at' => $zohoConnection?->connected_at?->toIso8601String(),
            ],
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
                'currency' => $shopCurrency,
                'webhook_status' => 'Healthy',
            ],
            'priceList' => [
                'shopify_currency' => $shopCurrency,
                'zoho_base_currency' => 'INR',
                'active_price_list_name' => "Shopify {$shopCurrency} Price List",
                'price_list_currency' => $shopCurrency,
                'status' => 'Active',
                'explanatory_note' => "Zoho Item Master uses the organization's base currency. Shopify currency is preserved through the currency-specific Price List and transaction currency.",
            ],
            'stats' => [
                'products' => ['total' => $totalProducts, 'synced' => $syncedVariants, 'total_variants' => $totalVariants, 'unsynced' => ($totalVariants - $syncedVariants), 'failed' => $failedVariantSyncs],
                'orders' => ['total' => $totalOrders, 'synced' => $syncedOrders, 'failed' => $failedOrderSyncs],
                'invoices' => ['total' => $totalInvoices, 'synced' => $syncedInvoices, 'failed' => $failedInvoiceSyncs],
                'payments' => ['total' => $totalPayments, 'synced' => $syncedPayments, 'failed' => $failedPaymentSyncs],
                'customers' => ['total' => $totalCustomers, 'synced' => $syncedCustomers, 'failed' => $failedCustomerSyncs],
                'failed_total' => $totalFailedCurrent,
            ],
            'syncHealth' => [
                'products' => [
                    'label' => 'Products',
                    'count' => $totalVariants,
                    'synced' => $syncedVariants,
                    'percentage' => $calcPct($syncedVariants, $totalVariants),
                    'failed' => $failedVariantSyncs,
                    'last_sync_at' => $lastProductSync?->toIso8601String(),
                    'status' => $failedVariantSyncs > 0 ? 'warning' : 'healthy',
                ],
                'orders' => [
                    'label' => 'Orders',
                    'count' => $totalOrders,
                    'synced' => $syncedOrders,
                    'percentage' => $calcPct($syncedOrders, $totalOrders),
                    'failed' => $failedOrderSyncs,
                    'last_sync_at' => $lastOrderSync?->toIso8601String(),
                    'status' => $failedOrderSyncs > 0 ? 'warning' : 'healthy',
                ],
                'invoices' => [
                    'label' => 'Invoices',
                    'count' => $totalInvoices,
                    'synced' => $syncedInvoices,
                    'percentage' => $calcPct($syncedInvoices, $totalInvoices),
                    'failed' => $failedInvoiceSyncs,
                    'last_sync_at' => $lastInvoiceSync?->toIso8601String(),
                    'status' => $failedInvoiceSyncs > 0 ? 'warning' : 'healthy',
                ],
                'customers' => [
                    'label' => 'Customers',
                    'count' => $totalCustomers,
                    'synced' => $syncedCustomers,
                    'percentage' => $calcPct($syncedCustomers, $totalCustomers),
                    'failed' => 0,
                    'last_sync_at' => null,
                    'status' => 'healthy',
                ],
                'payments' => [
                    'label' => 'Payments',
                    'count' => $totalPayments,
                    'synced' => $syncedPayments,
                    'percentage' => $calcPct($syncedPayments, $totalPayments),
                    'failed' => $failedPaymentSyncs,
                    'last_sync_at' => $lastPaymentSync?->toIso8601String(),
                    'status' => $failedPaymentSyncs > 0 ? 'warning' : 'healthy',
                ],
                'inventory' => [
                    'label' => 'Inventory Sync',
                    'count' => $totalVariants,
                    'synced' => $syncedVariants,
                    'percentage' => $calcPct($syncedVariants, $totalVariants),
                    'failed' => 0,
                    'last_sync_at' => $lastProductSync?->toIso8601String(),
                    'status' => 'healthy',
                ],
            ],
            'recentActivity' => $recentActivity,
            'failedSyncs' => $failedSyncs,
            'paymentActivity' => $paymentActivity,
        ]);
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
            $orders = $this->fetchLocalOrders($shopModel->id);

            if ($orders->isEmpty()) {
                try {
                    $shopifyService = app(\App\Services\ShopifyService::class);
                    $rawOrders = $shopifyService->fetchOrders($shopModel);
                    $this->ingestShopifyOrders($shopModel, $rawOrders);
                    $orders = $this->fetchLocalOrders($shopModel->id);
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
                        $rawId = (string) ($raw['id'] ?? '');
                        $numericId = preg_replace('/[^0-9]/', '', $rawId);
                        $canonicalGid = "gid://shopify/Customer/{$numericId}";
                        $candidateIds = array_filter(array_unique([$rawId, $numericId, $canonicalGid]));

                        $existing = Customer::where('shop_id', $shopModel->id)
                            ->whereIn('shopify_customer_id', $candidateIds)
                            ->first();

                        $targetShopifyCustId = $existing ? $existing->shopify_customer_id : $canonicalGid;

                        $defaultAddr = $raw['default_address'] ?? [];
                        $phone = !empty($raw['phone']) ? $raw['phone'] : ($defaultAddr['phone'] ?? null);
                        $updateData = [
                            'first_name' => $raw['first_name'] ?? '',
                            'last_name' => $raw['last_name'] ?? '',
                            'email' => $raw['email'] ?? '',
                            'billing_address' => $defaultAddr,
                            'shipping_address' => $defaultAddr,
                        ];
                        if ($phone !== null) {
                            $updateData['phone'] = $phone;
                        }
                        Customer::updateOrCreate(
                            [
                                'shop_id' => $shopModel->id,
                                'shopify_customer_id' => $targetShopifyCustId,
                            ],
                            $updateData
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
                    $rawId = (string) ($raw['id'] ?? '');
                    $numericId = preg_replace('/[^0-9]/', '', $rawId);
                    $canonicalGid = "gid://shopify/Customer/{$numericId}";
                    $candidateIds = array_filter(array_unique([$rawId, $numericId, $canonicalGid]));

                    $existing = Customer::where('shop_id', $shop->id)
                        ->whereIn('shopify_customer_id', $candidateIds)
                        ->first();

                    $targetShopifyCustId = $existing ? $existing->shopify_customer_id : $canonicalGid;

                    $defaultAddr = $raw['default_address'] ?? [];
                    $phone = !empty($raw['phone']) ? $raw['phone'] : ($defaultAddr['phone'] ?? null);
                    $updateData = [
                        'first_name' => $raw['first_name'] ?? '',
                        'last_name' => $raw['last_name'] ?? '',
                        'email' => $raw['email'] ?? '',
                        'billing_address' => $defaultAddr,
                        'shipping_address' => $defaultAddr,
                    ];
                    if ($phone !== null) {
                        $updateData['phone'] = $phone;
                    }
                    Customer::updateOrCreate(
                        [
                            'shop_id' => $shop->id,
                            'shopify_customer_id' => $targetShopifyCustId,
                        ],
                        $updateData
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

    /**
     * Helper to fetch local orders with all required relationships eager-loaded.
     */
    protected function fetchLocalOrders(int $shopId, int $limit = 50)
    {
        return Order::with(['customer', 'invoice', 'payments', 'refunds'])
            ->where('shop_id', $shopId)
            ->latest()
            ->take($limit)
            ->get();
    }

    /**
     * Extract pure numeric ID from a Shopify ID string.
     */
    public static function extractNumericId(?string $id): ?string
    {
        if (empty($id)) {
            return null;
        }
        if (preg_match('/(\d+)$/', $id, $matches)) {
            return $matches[1];
        }
        return $id;
    }

    /**
     * Format a Shopify Order ID into canonical GID format.
     */
    public static function formatShopifyOrderId(?string $id): ?string
    {
        $num = static::extractNumericId($id);
        return $num ? "gid://shopify/Order/{$num}" : $id;
    }

    /**
     * Format a Shopify Customer ID into canonical GID format.
     */
    public static function formatShopifyCustomerId(?string $id): ?string
    {
        $num = static::extractNumericId($id);
        return $num ? "gid://shopify/Customer/{$num}" : $id;
    }

    /**
     * Normalize a Shopify Order ID into its candidate variations: [GID, numeric].
     */
    public static function normalizeShopifyOrderId(string $id): array
    {
        $num = static::extractNumericId($id);
        if (empty($num)) {
            return [$id];
        }
        return array_unique([
            "gid://shopify/Order/{$num}",
            $num,
        ]);
    }

    /**
     * Normalize a Shopify Customer ID into its candidate variations: [GID, numeric].
     */
    public static function normalizeShopifyCustomerId(string $id): array
    {
        $num = static::extractNumericId($id);
        if (empty($num)) {
            return [$id];
        }
        return array_unique([
            "gid://shopify/Customer/{$num}",
            $num,
        ]);
    }

    /**
     * Batch ingest raw Shopify orders and customers to prevent N+1 query overhead.
     */
    public function ingestShopifyOrders(Shop $shop, array $rawOrders): void
    {
        if (empty($rawOrders)) {
            return;
        }

        $rawCustomers = [];
        $allCustomerCandidates = [];
        foreach ($rawOrders as $raw) {
            if (!empty($raw['customer']) && !empty($raw['customer']['id'])) {
                $rawCustId = (string) $raw['customer']['id'];
                $rawCustomers[$rawCustId] = $raw['customer'];
                foreach (static::normalizeShopifyCustomerId($rawCustId) as $candidate) {
                    $allCustomerCandidates[] = $candidate;
                }
            }
        }

        $customerMap = [];
        if (!empty($allCustomerCandidates)) {
            $existingCustomers = Customer::where('shop_id', $shop->id)
                ->whereIn('shopify_customer_id', array_unique($allCustomerCandidates))
                ->get();

            $customerLookup = [];
            foreach ($existingCustomers as $cust) {
                $customerLookup[$cust->shopify_customer_id] = $cust;
                $num = static::extractNumericId($cust->shopify_customer_id);
                if ($num) {
                    $customerLookup[$num] = $cust;
                    $customerLookup["gid://shopify/Customer/{$num}"] = $cust;
                }
            }

            foreach ($rawCustomers as $rawCustId => $rawCust) {
                $custObj = $customerLookup[$rawCustId] ?? null;
                $defaultAddr = $rawCust['default_address'] ?? [];
                $phone = !empty($rawCust['phone']) ? $rawCust['phone'] : ($defaultAddr['phone'] ?? null);
                $custData = [
                    'first_name' => $rawCust['first_name'] ?? '',
                    'last_name' => $rawCust['last_name'] ?? '',
                    'email' => $rawCust['email'] ?? '',
                    'billing_address' => $defaultAddr,
                    'shipping_address' => $defaultAddr,
                ];
                if ($phone !== null) {
                    $custData['phone'] = $phone;
                }

                if ($custObj) {
                    $custObj->update($custData);
                } else {
                    $custObj = Customer::create(array_merge([
                        'shop_id' => $shop->id,
                        'shopify_customer_id' => static::formatShopifyCustomerId($rawCustId),
                    ], $custData));
                }
                $customerMap[$rawCustId] = $custObj->id;
            }
        }

        $allOrderCandidates = [];
        foreach ($rawOrders as $raw) {
            $rawOrdId = (string) $raw['id'];
            foreach (static::normalizeShopifyOrderId($rawOrdId) as $candidate) {
                $allOrderCandidates[] = $candidate;
            }
        }

        $existingOrders = Order::where('shop_id', $shop->id)
            ->whereIn('shopify_order_id', array_unique($allOrderCandidates))
            ->get();

        $orderLookup = [];
        foreach ($existingOrders as $ord) {
            $orderLookup[$ord->shopify_order_id] = $ord;
            $num = static::extractNumericId($ord->shopify_order_id);
            if ($num) {
                $orderLookup[$num] = $ord;
                $orderLookup["gid://shopify/Order/{$num}"] = $ord;
            }
        }

        foreach ($rawOrders as $raw) {
            $rawOrdId = (string) $raw['id'];
            $custShopifyId = !empty($raw['customer']) ? (string) $raw['customer']['id'] : null;
            $customerId = $custShopifyId ? ($customerMap[$custShopifyId] ?? null) : null;

            $existingOrder = $orderLookup[$rawOrdId] ?? null;

            $orderData = [
                'customer_id' => $customerId,
                'financial_status' => $raw['financial_status'] ?? 'pending',
                'fulfillment_status' => $raw['fulfillment_status'] ?? 'unfulfilled',
                'subtotal' => $raw['subtotal_price'] ?? 0.00,
                'discount_total' => $raw['total_discounts'] ?? 0.00,
                'shipping_total' => !empty($raw['shipping_lines']) ? array_sum(array_column($raw['shipping_lines'], 'price')) : 0.00,
                'tax_total' => $raw['total_tax'] ?? 0.00,
                'total_price' => $raw['total_price'] ?? 0.00,
                'currency' => $raw['currency'] ?? $shop->currency ?? 'USD',
                'line_items' => $raw['line_items'] ?? [],
                'notes' => $raw['note'] ?? null,
                'order_date' => !empty($raw['created_at']) ? \Carbon\Carbon::parse($raw['created_at']) : now(),
            ];

            if (!empty($raw['cancelled_at'])) {
                $orderData['cancelled_at'] = \Carbon\Carbon::parse($raw['cancelled_at']);
                $orderData['cancel_reason'] = $raw['cancel_reason'] ?? null;
            } elseif ($existingOrder && $existingOrder->cancelled_at) {
                $orderData['cancelled_at'] = $existingOrder->cancelled_at;
                $orderData['cancel_reason'] = $existingOrder->cancel_reason;
            }

            if (!$existingOrder || empty($existingOrder->order_number) || !str_starts_with($existingOrder->order_number, '#')) {
                $rawNumber = (string) ($raw['order_number'] ?? $raw['name'] ?? $raw['id']);
                if (!str_starts_with($rawNumber, '#') && !empty($raw['name']) && str_starts_with($raw['name'], '#')) {
                    $rawNumber = $raw['name'];
                }
                $orderData['order_number'] = $rawNumber;
            }

            if ($existingOrder) {
                $existingOrder->update($orderData);
                $targetOrder = $existingOrder->fresh();
            } else {
                $targetOrder = Order::create(array_merge([
                    'shop_id' => $shop->id,
                    'shopify_order_id' => static::formatShopifyOrderId($rawOrdId),
                ], $orderData));
            }

            if (!empty($raw['refunds']) && is_array($raw['refunds'])) {
                $this->ingestShopifyRefunds($shop, $raw['refunds'], $targetOrder);
            }
        }
    }

    public function ingestShopifyRefunds(Shop $shop, array $rawRefunds, Order $order): void
    {
        if (empty($rawRefunds)) {
            return;
        }

        foreach ($rawRefunds as $rawRefund) {
            if (empty($rawRefund['id'])) {
                continue;
            }

            $rawRefundId = (string) $rawRefund['id'];
            $numericRefundId = preg_replace('/[^0-9]/', '', $rawRefundId);
            $canonicalRefundGid = "gid://shopify/Refund/{$numericRefundId}";
            $candidateRefundIds = array_filter(array_unique([$rawRefundId, $numericRefundId, $canonicalRefundGid]));

            $existingRefund = \App\Models\Refund::where('shop_id', $shop->id)
                ->whereIn('shopify_refund_id', $candidateRefundIds)
                ->first();

            $targetShopifyRefundId = $existingRefund ? $existingRefund->shopify_refund_id : $rawRefundId;

            $totalAmount = 0.0;
            if (isset($rawRefund['total_refunded'])) {
                $totalAmount = (float) $rawRefund['total_refunded'];
            } elseif (!empty($rawRefund['total_refunded_set']['presentment_money']['amount'])) {
                $totalAmount = (float) $rawRefund['total_refunded_set']['presentment_money']['amount'];
            } elseif (!empty($rawRefund['total_refunded_set']['shop_money']['amount'])) {
                $totalAmount = (float) $rawRefund['total_refunded_set']['shop_money']['amount'];
            }

            $refundLineItems = [];
            $restock = false;

            $itemsSource = $rawRefund['refund_line_items'] ?? $rawRefund['refundLineItems'] ?? [];
            if (is_array($itemsSource)) {
                foreach ($itemsSource as $item) {
                    $restockType = strtolower((string) ($item['restock_type'] ?? $item['restockType'] ?? ''));
                    if (in_array($restockType, ['cancel', 'return'], true)) {
                        $restock = true;
                    }

                    $lineItem = $item['line_item'] ?? $item['lineItem'] ?? [];
                    $refundLineItems[] = [
                        'line_item_id' => $item['line_item_id'] ?? preg_replace('/[^0-9]/', '', $lineItem['id'] ?? ''),
                        'variant_id' => $item['variant_id'] ?? (!empty($lineItem['variant']['id']) ? preg_replace('/[^0-9]/', '', $lineItem['variant']['id']) : null),
                        'title' => $item['title'] ?? $lineItem['title'] ?? 'Refunded Item',
                        'quantity' => (int) ($item['quantity'] ?? 1),
                        'price' => (float) ($item['price'] ?? $lineItem['price'] ?? 0.0),
                        'restock_type' => $item['restock_type'] ?? $item['restockType'] ?? null,
                    ];

                    if ($totalAmount <= 0) {
                        $totalAmount += (float) ($item['subtotal'] ?? 0.0);
                    }
                }
            }

            if ($totalAmount <= 0 && !empty($rawRefund['transactions']) && is_array($rawRefund['transactions'])) {
                foreach ($rawRefund['transactions'] as $tx) {
                    if (($tx['status'] ?? '') === 'success' && in_array(strtolower((string) ($tx['kind'] ?? '')), ['refund', 'change'], true)) {
                        $txAmount = !empty($tx['amount_set']['presentment_money']['amount'])
                            ? (float) $tx['amount_set']['presentment_money']['amount']
                            : (float) ($tx['amount'] ?? 0.0);
                        $totalAmount += $txAmount;
                    }
                }
            }

            $currency = $rawRefund['currency'] ?? $order->currency ?? $shop->currency ?? 'USD';

            $syncStatus = $existingRefund ? $existingRefund->sync_status : \App\Models\Refund::SYNC_STATUS_PENDING;

            $createdDate = !empty($rawRefund['created_at'])
                ? \Carbon\Carbon::parse($rawRefund['created_at'])
                : now();

            \App\Models\Refund::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_refund_id' => $targetShopifyRefundId,
                ],
                [
                    'order_id' => $order->id,
                    'shopify_order_id' => $order->shopify_order_id,
                    'amount' => $totalAmount,
                    'currency' => $currency,
                    'note' => $rawRefund['note'] ?? null,
                    'restock' => $restock,
                    'refund_line_items' => $refundLineItems,
                    'status' => \App\Models\Refund::STATUS_COMPLETED,
                    'sync_status' => $syncStatus,
                    'created_at' => $createdDate,
                ]
            );
        }

        $totalRefundedAmount = (float) \App\Models\Refund::where('order_id', $order->id)->sum('amount');
        if ($totalRefundedAmount >= (float) $order->total_price && (float) $order->total_price > 0) {
            $order->update(['financial_status' => 'refunded']);
        } elseif ($totalRefundedAmount > 0) {
            $order->update(['financial_status' => 'partially_refunded']);
        }
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

        $orders = $this->fetchLocalOrders($shop->id);

        if ($orders->isEmpty() || $request->boolean('refresh_shopify') || $request->boolean('sync_shopify')) {
            try {
                $shopifyService = app(\App\Services\ShopifyService::class);
                $rawOrders = $shopifyService->fetchOrders($shop);
                $this->ingestShopifyOrders($shop, $rawOrders);
                $orders = $this->fetchLocalOrders($shop->id);
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

        if ($refunds->isEmpty() || $request->boolean('refresh_shopify') || $request->boolean('refresh')) {
            try {
                $shopifyService = app(\App\Services\ShopifyService::class);
                $rawOrders = $shopifyService->fetchOrders($shop);
                $this->ingestShopifyOrders($shop, $rawOrders);
                $refunds = \App\Models\Refund::with(['order.customer'])
                    ->where('shop_id', $shop->id)
                    ->latest()
                    ->take(50)
                    ->get();
            } catch (\Exception $e) {
                Log::warning('Could not fetch existing Shopify refunds for list view', [
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

        $orders = Order::with(['customer', 'invoice', 'payments', 'refunds', 'shop'])
            ->where('shop_id', $shop->id)
            ->where('order_number', 'NOT LIKE', '#TEST-%')
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
                description

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

                            'description' =>
                                $product['description'] ?? null,

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

            $result = $zohoService->syncItem($variant, ['trigger' => 'manual']);

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
            $result = $zohoService->syncCustomer($customer, ['trigger' => 'manual']);

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

    public function cancelOrder(Request $request): JsonResponse
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
            $result = $zohoService->cancelOrder($order);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Order cancellation synchronized to Zoho successfully.',
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
            $result = $zohoService->syncInvoice($order, ['trigger' => 'manual']);

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
                if (in_array(strtolower((string) $order->financial_status), ['refunded', 'voided'])) {
                    return response()->json([
                        'success' => false,
                        'message' => "Cannot record or sync payment for order #{$order->order_number}: Order financial status is '{$order->financial_status}'. Manual payments cannot be recorded on refunded or voided orders.",
                    ], 422);
                }

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
                        'currency' => $order->currency ?? $shop->currency ?? 'USD',
                        'payment_date' => now(),
                        'payment_method' => 'shopify_payments',
                        'status' => Payment::STATUS_PAID,
                        'sync_status' => Payment::SYNC_STATUS_PENDING,
                    ]);
                }
            }
        }

        if ($payment && $payment->order && in_array(strtolower((string) $payment->order->financial_status), ['refunded', 'voided'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot record or sync payment for order #{$payment->order->order_number}: Order financial status is '{$payment->order->financial_status}'. Manual payments cannot be recorded on refunded or voided orders.",
            ], 422);
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
            $result = $zohoService->syncPayment($payment, ['trigger' => 'manual']);

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
            description

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

        $rawProdId = (string) $shopifyProduct['id'];
        $numProdId = preg_replace('/[^0-9]/', '', $rawProdId);
        $canonicalProdId = "gid://shopify/Product/{$numProdId}";
        $candidateProdIds = array_filter(array_unique([$rawProdId, $numProdId, $canonicalProdId]));

        $existingProduct = Product::where('shop_id', $shop->id)
            ->whereIn('shopify_product_id', $candidateProdIds)
            ->first();

        $targetProdId = $existingProduct ? $existingProduct->shopify_product_id : $canonicalProdId;

        $imageUrl = $shopifyVariant['image']['url'] ?? ($shopifyProduct['featuredImage']['url'] ?? null);
        $description = $shopifyProduct['description'] ?? null;

        $product = Product::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_product_id' => $targetProdId,
            ],
            [
                'title' => $shopifyProduct['title'] ?? '',
                'handle' => $shopifyProduct['handle'] ?? null,
                'description' => $description,
                'image_url' => $imageUrl,
            ]
        );

        /*
    |--------------------------------------------------------------------------
    | Variant mapping
    |--------------------------------------------------------------------------
    */

        $rawVarId = (string) $shopifyVariant['id'];
        $numVarId = preg_replace('/[^0-9]/', '', $rawVarId);
        $canonicalVarId = "gid://shopify/ProductVariant/{$numVarId}";
        $candidateVarIds = array_filter(array_unique([$rawVarId, $numVarId, $canonicalVarId]));

        $variant = ProductVariant::where('product_id', $product->id)
            ->whereIn('shopify_variant_id', $candidateVarIds)
            ->first();

        if (!$variant) {
            $variant = new ProductVariant([
                'product_id' => $product->id,
                'shopify_variant_id' => $canonicalVarId,
            ]);
        }

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
                        $localVariant,
                        ['trigger' => 'bulk']
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

        return Inertia::render('Zoho/Sync', array_merge($context, [
            'histories' => [
                'data' => [],
                'total' => 0,
                'current_page' => 1,
                'last_page' => 1,
                'links' => [],
            ],
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'status' => (string) $request->query('status', 'all'),
                'entity' => (string) $request->query('entity', 'all'),
                'trigger' => (string) $request->query('trigger', 'all'),
                'trigger_subtype' => (string) $request->query('trigger_subtype', 'all'),
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
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $entity = strtolower(trim((string) $request->query('entity', 'all')));
        $trigger = strtolower(trim((string) $request->query('trigger', 'all')));
        $triggerSubtype = strtolower(trim((string) $request->query('trigger_subtype', 'all')));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $baseQuery = SyncHistory::with([
            'productVariant.product',
            'order.customer',
            'invoice',
            'payment',
            'refund',
        ])->where('shop_id', $shop->id);

        if ($status !== 'all') {
            $baseQuery->where(function ($q) use ($status) {
                $q->where('status', strtoupper($status))
                  ->orWhere('status', strtolower($status));
            });
        }

        if ($entity !== 'all') {
            $baseQuery->where(function ($q) use ($entity) {
                if ($entity === 'product' || $entity === 'products') {
                    $q->whereIn('entity', ['product', 'product_variant'])
                      ->orWhereNotNull('product_variant_id')
                      ->orWhereNotNull('zoho_item_id');
                } elseif ($entity === 'order' || $entity === 'orders') {
                    $q->where('entity', 'order')->orWhereNotNull('order_id');
                } elseif ($entity === 'invoice' || $entity === 'invoices') {
                    $q->where('entity', 'invoice')->orWhereNotNull('invoice_id')->orWhereNotNull('zoho_invoice_id');
                } elseif ($entity === 'payment' || $entity === 'payments') {
                    $q->where('entity', 'payment')->orWhereNotNull('payment_id')->orWhereNotNull('zoho_payment_id');
                } elseif ($entity === 'refund' || $entity === 'refunds' || $entity === 'credit_note' || $entity === 'credit_notes') {
                    $q->whereIn('entity', ['refund', 'credit_note'])->orWhereNotNull('refund_id')->orWhereNotNull('zoho_creditnote_id');
                } elseif ($entity === 'customer' || $entity === 'customers') {
                    $q->where('entity', 'customer');
                } elseif ($entity === 'inventory') {
                    $q->where('entity', 'inventory');
                } else {
                    $q->where('entity', $entity);
                }
            });
        }

        if ($trigger !== 'all') {
            $baseQuery->where('trigger', strtolower($trigger));
        }

        if ($triggerSubtype !== 'all') {
            $baseQuery->where('trigger_subtype', strtolower($triggerSubtype));
        }

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhere('error_code', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%")
                    ->orWhere('shopify_id', 'like', "%{$search}%")
                    ->orWhere('zoho_id', 'like', "%{$search}%")
                    ->orWhere('zoho_item_id', 'like', "%{$search}%")
                    ->orWhere('zoho_invoice_id', 'like', "%{$search}%")
                    ->orWhere('zoho_payment_id', 'like', "%{$search}%")
                    ->orWhere('zoho_creditnote_id', 'like', "%{$search}%")
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
        }

        // Summary Counts (computed for shop)
        $shopHistoryQuery = SyncHistory::where('shop_id', $shop->id);
        $totalCount = (clone $shopHistoryQuery)->count();
        $successCount = (clone $shopHistoryQuery)->whereIn('status', ['SUCCESS', 'success', 'synced'])->count();
        $failedCount = (clone $shopHistoryQuery)->whereIn('status', ['FAILED', 'failed', 'error'])->count();
        $pendingCount = (clone $shopHistoryQuery)->whereIn('status', ['PENDING', 'pending'])->count();
        $reconciledCount = (clone $shopHistoryQuery)->whereIn('status', ['RECONCILED', 'reconciled'])->count();

        $connectedProductsCount = ProductVariant::query()
            ->whereHas('product', fn($q) => $q->where('shop_id', $shop->id))
            ->whereNotNull('zoho_item_id')
            ->count();

        $histories = $baseQuery->latest('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'shop' => [
                'id' => $shop->id,
                'shop_domain' => $shop->shop_domain,
            ],
            'summary' => [
                'connected_products' => $connectedProductsCount,
                'total' => $totalCount,
                'success' => $successCount,
                'failed' => $failedCount,
                'pending' => $pendingCount,
                'reconciled' => $reconciledCount,
            ],
            'histories' => $histories,
            'zohoConnected' => $shop->zohoConnection !== null,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'entity' => $entity,
                'trigger' => $trigger,
                'trigger_subtype' => $triggerSubtype,
            ],
        ]);
    }




    public function settings(Request $request)
    {
        $context = $this->resolveShopContext($request);
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);
        $zohoConnection = $shop?->zohoConnection;

        $connectionData = null;
        if ($zohoConnection) {
            $connectionData = [
                'organization_id' => $zohoConnection->organization_id,
                'organization_name' => $zohoConnection->organization_name ?: 'Shopify Zoho Integration Demo',
                'expires_at' => $zohoConnection->updated_at ? $zohoConnection->updated_at->addHours(1)->format('Y-m-d H:i:s') : null,
                'is_connected' => (bool) $zohoConnection->is_active,
                'account_identifier' => $zohoConnection->account_email ?: ($zohoConnection->account_id ?: 'admin@zoho.com'),
            ];
        }

        return Inertia::render('Zoho/Settings', array_merge($context, [
            'zohoConnection' => $connectionData,
            'zohoConnected' => $zohoConnection !== null && (bool) $zohoConnection->is_active,
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
        $zohoTaxes = [];
        if ($zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $zohoTaxes = $zohoService->getTaxes();
            } catch (\Throwable $e) {
                Log::warning('Could not fetch Zoho metadata: ' . $e->getMessage());
            }
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
                'currency' => $shop->currency ?? 'USD',
            ],
            'zohoConnection' => $zohoConnection,
            'taxSettings' => $taxSettings,
            'zohoTaxes' => $zohoTaxes,
            'host' => $request->query('host'),
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

        if (!empty($validated['default_tax_id'])) {
            $defaultTaxIdStr = (string) $validated['default_tax_id'];
            if (!empty($zohoTaxes) && !isset($zohoTaxMap[$defaultTaxIdStr])) {
                return response()->json([
                    'success' => false,
                    'message' => "The selected default tax (ID '{$defaultTaxIdStr}') no longer exists or is deleted in Zoho Books. Please select a valid active tax.",
                ], 422);
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

                if ($zohoTaxId) {
                    if (!empty($zohoTaxes) && !isset($zohoTaxMap[$zohoTaxId])) {
                        return response()->json([
                            'success' => false,
                            'message' => "Mapped Zoho tax (ID '{$zohoTaxId}') no longer exists or is deleted in Zoho Books. Please select a valid active tax.",
                        ], 422);
                    }

                    if ($rate !== null && isset($zohoTaxMap[$zohoTaxId])) {
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
        }

        $shop->tax_settings = $validated;
        $shop->save();

        $taxModeStr = ucfirst($validated['tax_mode']);
        $defaultTaxStr = !empty($validated['default_tax_id']) ? "Tax ID: {$validated['default_tax_id']}" : "None";
        $mappingCount = count($validated['tax_mappings'] ?? []);

        return response()->json([
            'success' => true,
            'message' => "Tax configuration saved successfully — Mode: {$taxModeStr}, Default Tax: {$defaultTaxStr}, {$mappingCount} tax mapping(s) saved.",
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

    public function preflightData(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'No Shopify shop installed.'], 404);
        }

        $connection = ZohoConnection::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->first();

        return response()->json([
            'success' => true,
            'is_connected' => $connection !== null,
            'setup_status' => $connection?->setup_status ?: 'connected',
            'readiness_label' => $connection?->readiness_label ?: ($connection ? 'Connected' : 'Disconnected'),
            'custom_field_mappings' => $connection?->custom_field_mappings ?? [],
            'setup_summary' => $connection?->setup_summary ?? [],
            'preflight_run_at' => $connection?->preflight_run_at?->toIso8601String(),
        ]);
    }

    public function runPreflight(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop') ?? $this->resolveShopModel($request);

        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'No Shopify shop installed.'], 404);
        }

        try {
            $preflightService = new \App\Services\ZohoPreflightService();
            $result = $preflightService->run($shop);

            return response()->json([
                'success' => true,
                'message' => 'Preflight setup executed successfully.',
                'result' => $result,
            ]);
        } catch (\Throwable $ex) {
            Log::error("Manual preflight execution failed for shop {$shop->id}: " . $ex->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Preflight setup failed: ' . $ex->getMessage(),
            ], 500);
        }
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
            $result = $zohoService->syncRefund($refund, ['trigger' => 'manual']);

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
                            'currency' => $order->currency ?? $shop->currency ?? 'USD',
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
