<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Services\ZohoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller {
    /**
     * Handle Shopify products/update webhook.
     */
    public function productsUpdate(Request $request) {
        Log::info('Shopify products/update webhook received', [
    'shop' => $request->header('X-Shopify-Shop-Domain'),
    'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
    'topic' => $request->header('X-Shopify-Topic'),
    'payload' => $request->getContent(),
]);
        /*
        |--------------------------------------------------------------------------
        | 1. Verify HMAC Signature
        |--------------------------------------------------------------------------
        */

        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');

        if (empty($hmacHeader)) {
            return response()->json(['error' => 'Missing HMAC signature.'], 401);
        }

        $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET');

        if (empty($secret)) {
            return response()->json(['error' => 'Shopify API secret not configured.'], 500);
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (!hash_equals($calculatedHmac, $hmacHeader)) {
            return response()->json(['error' => 'Invalid HMAC signature.'], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Validate Shop Domain & Resolve Tenant
        |--------------------------------------------------------------------------
        */

        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (empty($shopDomain) || !is_string($shopDomain) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shopDomain)) {
            return response()->json(['error' => 'Invalid Shopify shop domain.'], 400);
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();

        if (!$shop) {
            return response()->json(['error' => 'Unknown shop domain.'], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Check Persistent Webhook Idempotency
        |--------------------------------------------------------------------------
        */

        $webhookId = $request->header('X-Shopify-Webhook-Id');

        if ($webhookId) {
            if (ShopifyProcessedWebhook::where('webhook_id', $webhookId)->exists()) {
                return response()->json([
                    'message' => 'Webhook already processed.',
                    'webhook_id' => $webhookId,
                ], 200);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Validate JSON Payload
        |--------------------------------------------------------------------------
        */

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || (empty($payload['id']) && empty($payload['admin_graphql_api_id']))) {
            return response()->json(['error' => 'Invalid product update payload.'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Record Webhook Delivery ID
        |--------------------------------------------------------------------------
        */

        if ($webhookId) {
            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'topic' => $request->header('X-Shopify-Topic', 'products/update'),
                'shop_domain' => $shopDomain,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Update Local Product (Prefer admin_graphql_api_id, convert numeric ID to GID)
        |--------------------------------------------------------------------------
        */

        $productGid = $payload['admin_graphql_api_id'] ?? null;
        $numericProductId = isset($payload['id']) ? (string) $payload['id'] : null;

        if (empty($productGid) && $numericProductId !== null) {
            $productGid = str_starts_with($numericProductId, 'gid://shopify/Product/')
                ? $numericProductId
                : "gid://shopify/Product/{$numericProductId}";
        }

        $productIdCandidates = array_values(array_filter(array_unique([
            $productGid,
            $numericProductId ? (str_starts_with($numericProductId, 'gid://') ? $numericProductId : "gid://shopify/Product/{$numericProductId}") : null,
            $numericProductId ? preg_replace('/^gid:\/\/shopify\/Product\//', '', $numericProductId) : null,
        ])));

        $product = Product::where('shop_id', $shop->id)
            ->whereIn('shopify_product_id', $productIdCandidates)
            ->first();

        $imageUrl = $payload['image']['src'] ?? ($payload['images'][0]['src'] ?? ($payload['image_url'] ?? null));

        if ($product) {
            $updateData = [
                'title' => $payload['title'] ?? $product->title,
                'handle' => $payload['handle'] ?? $product->handle,
            ];

            if ($imageUrl !== null) {
                $updateData['image_url'] = $imageUrl;
            }

            $product->update($updateData);
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Update Local Product Variants & Zoho Items
        |--------------------------------------------------------------------------
        */

        $zohoService = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
            } catch (\Throwable $e) {
                Log::warning('ZohoService initialization skipped for shop ' . $shopDomain . ': ' . $e->getMessage());
            }
        }

        $variantsData = $payload['variants'] ?? [];
        $summary = [
            'processed' => 0,
            'synced_to_zoho' => 0,
            'skipped_unmapped' => 0,
            'zoho_failures' => 0,
            'metadata_synced' => 0,
            'metadata_failures' => 0,
            'inventory_synced' => 0,
            'inventory_failures' => 0,
        ];

        if (is_array($variantsData)) {
            foreach ($variantsData as $vData) {
                if (empty($vData['id']) && empty($vData['admin_graphql_api_id'])) {
                    continue;
                }

                $variantGid = $vData['admin_graphql_api_id'] ?? null;
                $numericVariantId = isset($vData['id']) ? (string) $vData['id'] : null;

                if (empty($variantGid) && $numericVariantId !== null) {
                    $variantGid = str_starts_with($numericVariantId, 'gid://shopify/ProductVariant/')
                        ? $numericVariantId
                        : "gid://shopify/ProductVariant/{$numericVariantId}";
                }

                $variantIdCandidates = array_values(array_filter(array_unique([
                    $variantGid,
                    $numericVariantId ? (str_starts_with($numericVariantId, 'gid://') ? $numericVariantId : "gid://shopify/ProductVariant/{$numericVariantId}") : null,
                    $numericVariantId ? preg_replace('/^gid:\/\/shopify\/ProductVariant\//', '', $numericVariantId) : null,
                ])));

                $variant = ProductVariant::whereIn('shopify_variant_id', $variantIdCandidates)
                    ->whereHas('product', function ($query) use ($shop) {
                        $query->where('shop_id', $shop->id);
                    })
                    ->first();

                if (!$variant) {
                    continue;
                }

                $summary['processed']++;

                // Update local variant fields
                $variantUpdateData = [
                    'title' => $vData['title'] ?? $variant->title,
                    'sku' => array_key_exists('sku', $vData) ? $vData['sku'] : $variant->sku,
                    'price' => $vData['price'] ?? $variant->price,
                    'inventory_quantity' => $vData['inventory_quantity'] ?? $variant->inventory_quantity,
                ];

                if (!empty($vData['inventory_item_id'])) {
                    $rawInvId = (string) $vData['inventory_item_id'];
                    $variantUpdateData['shopify_inventory_item_id'] = str_starts_with($rawInvId, 'gid://')
                        ? $rawInvId
                        : "gid://shopify/InventoryItem/{$rawInvId}";
                }

                $variant->update($variantUpdateData);

                // Zoho sync if zoho_item_id exists
                if ($variant->zoho_item_id) {
                    if ($zohoService) {
                        $metaSuccess = false;

                        // 1. Update item metadata (name, rate, SKU)
                        try {
                            $zohoService->updateItem($variant);
                            $metaSuccess = true;
                            $summary['metadata_synced']++;
                        } catch (\Throwable $e) {
                            Log::error("Zoho item update failed for variant ID {$variant->id}: " . $e->getMessage());
                            $summary['metadata_failures']++;
                        }

                        // 2. Synchronize inventory quantity (backward compatibility)
                        if (array_key_exists('inventory_quantity', $vData)) {
                            try {
                                $zohoService->syncInventory($variant, (int) $vData['inventory_quantity']);
                                $summary['inventory_synced']++;
                            } catch (\Throwable $e) {
                                Log::error("Zoho inventory sync failed for variant ID {$variant->id}: " . $e->getMessage());
                                $summary['inventory_failures']++;
                            }
                        }

                        if ($metaSuccess) {
                            $summary['synced_to_zoho']++;
                        } else {
                            $summary['zoho_failures']++;
                        }
                    } else {
                        $summary['zoho_failures']++;
                    }
                } else {
                    $summary['skipped_unmapped']++;
                }
            }
        }

        return response()->json([
            'message' => 'Product update webhook processed successfully.',
            'summary' => $summary,
        ], 200);
    }

    /**
     * Handle Shopify inventory_levels/update webhook.
     */
    public function inventoryLevelsUpdate(Request $request)
    {
        Log::info('Shopify inventory_levels/update webhook received', [
            'shop' => $request->header('X-Shopify-Shop-Domain'),
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'topic' => $request->header('X-Shopify-Topic'),
            'payload' => $request->getContent(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. Verify HMAC Signature
        |--------------------------------------------------------------------------
        */

        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');

        if (empty($hmacHeader)) {
            return response()->json(['error' => 'Missing HMAC signature.'], 401);
        }

        $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET');

        if (empty($secret)) {
            return response()->json(['error' => 'Shopify API secret not configured.'], 500);
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (!hash_equals($calculatedHmac, $hmacHeader)) {
            return response()->json(['error' => 'Invalid HMAC signature.'], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Validate Shop Domain & Resolve Tenant
        |--------------------------------------------------------------------------
        */

        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (empty($shopDomain) || !is_string($shopDomain) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shopDomain)) {
            return response()->json(['error' => 'Invalid Shopify shop domain.'], 400);
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();

        if (!$shop) {
            return response()->json(['error' => 'Unknown shop domain.'], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Check Persistent Webhook Idempotency
        |--------------------------------------------------------------------------
        */

        $webhookId = $request->header('X-Shopify-Webhook-Id');

        if ($webhookId) {
            if (ShopifyProcessedWebhook::where('webhook_id', $webhookId)->exists()) {
                return response()->json([
                    'message' => 'Webhook already processed.',
                    'webhook_id' => $webhookId,
                ], 200);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Validate JSON Payload
        |--------------------------------------------------------------------------
        */

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !array_key_exists('available', $payload) || (empty($payload['inventory_item_id']) && empty($payload['id']))) {
            return response()->json(['error' => 'Invalid inventory level update payload.'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Record Webhook Delivery ID
        |--------------------------------------------------------------------------
        */

        if ($webhookId) {
            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'topic' => $request->header('X-Shopify-Topic', 'inventory_levels/update'),
                'shop_domain' => $shopDomain,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Resolve Local ProductVariant via shopify_inventory_item_id
        |--------------------------------------------------------------------------
        */

        $rawInventoryItemId = (string) ($payload['inventory_item_id'] ?? $payload['id']);
        $numericInventoryItemId = preg_replace('/^gid:\/\/shopify\/InventoryItem\//', '', $rawInventoryItemId);

        $candidateInventoryItemIds = array_values(array_filter(array_unique([
            $rawInventoryItemId,
            "gid://shopify/InventoryItem/{$numericInventoryItemId}",
            $numericInventoryItemId,
        ])));

        $variant = ProductVariant::whereIn('shopify_inventory_item_id', $candidateInventoryItemIds)
            ->whereHas('product', function ($query) use ($shop) {
                $query->where('shop_id', $shop->id);
            })
            ->first();

        $summary = [
            'processed' => 0,
            'inventory_synced' => 0,
            'inventory_failures' => 0,
            'skipped_unmapped' => 0,
        ];

        if (!$variant) {
            Log::info("inventoryLevelsUpdate: Variant not found for inventory item ID {$rawInventoryItemId} on shop {$shopDomain}.");
            $summary['skipped_unmapped']++;

            return response()->json([
                'message' => 'Variant not found or unmapped for this inventory item.',
                'summary' => $summary,
            ], 200);
        }

        $summary['processed']++;
        $availableQty = (int) $payload['available'];

        // Update local variant inventory quantity
        $variant->update([
            'inventory_quantity' => $availableQty,
        ]);

        if (!$variant->zoho_item_id) {
            Log::info("inventoryLevelsUpdate: Variant ID {$variant->id} does not have zoho_item_id. Skipping Zoho inventory sync.");
            $summary['skipped_unmapped']++;

            return response()->json([
                'message' => 'Variant is not mapped to Zoho item.',
                'summary' => $summary,
            ], 200);
        }

        if (!$shop->zohoConnection) {
            Log::warning("inventoryLevelsUpdate: Zoho is not connected for shop {$shopDomain}. Skipping sync.");
            $summary['inventory_failures']++;

            return response()->json([
                'message' => 'Zoho connection unavailable.',
                'summary' => $summary,
            ], 200);
        }

        try {
            $zohoService = new ZohoService($shop);
            $zohoService->syncInventory($variant, $availableQty);
            $summary['inventory_synced']++;
        } catch (\Throwable $e) {
            Log::error("inventoryLevelsUpdate: Zoho inventory sync failed for variant ID {$variant->id}: " . $e->getMessage());
            $summary['inventory_failures']++;

            return response()->json([
                'message' => 'Failed to synchronize inventory level with Zoho.',
                'error' => $e->getMessage(),
                'summary' => $summary,
            ], 200);
        }

        return response()->json([
            'message' => 'Inventory level update webhook processed successfully.',
            'summary' => $summary,
        ], 200);
    }

    /**
     * Handle Shopify customers/create and customers/update webhooks.
     */
    public function customersUpdate(Request $request)
    {
        Log::info('Shopify customers/update webhook received', [
            'shop' => $request->header('X-Shopify-Shop-Domain'),
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'topic' => $request->header('X-Shopify-Topic'),
            'payload' => $request->getContent(),
        ]);

        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');

        if (empty($hmacHeader)) {
            return response()->json(['error' => 'Missing HMAC signature.'], 401);
        }

        $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET');

        if (empty($secret)) {
            return response()->json(['error' => 'Shopify API secret not configured.'], 500);
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (!hash_equals($calculatedHmac, $hmacHeader)) {
            return response()->json(['error' => 'Invalid HMAC signature.'], 401);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (empty($shopDomain) || !is_string($shopDomain) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shopDomain)) {
            return response()->json(['error' => 'Invalid Shopify shop domain.'], 400);
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();

        if (!$shop) {
            return response()->json(['error' => 'Shop not found.'], 404);
        }

        $webhookId = $request->header('X-Shopify-Webhook-Id');

        if (!empty($webhookId)) {
            $alreadyProcessed = ShopifyProcessedWebhook::where('webhook_id', $webhookId)
                ->where('shop_domain', $shopDomain)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("customersUpdate: Webhook ID {$webhookId} has already been processed for shop {$shopDomain}. Skipping.");
                return response()->json(['message' => 'Webhook already processed.'], 200);
            }

            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'shop_domain' => $shopDomain,
                'topic' => $request->header('X-Shopify-Topic') ?? 'customers/update',
            ]);
        }

        $payload = json_decode($request->getContent(), true);

        if (empty($payload) || !is_array($payload) || empty($payload['id'])) {
            return response()->json(['error' => 'Invalid customer payload.'], 400);
        }

        $rawCustomerId = (string) $payload['id'];
        $shopifyCustomerId = str_starts_with($rawCustomerId, 'gid://')
            ? $rawCustomerId
            : "gid://shopify/Customer/{$rawCustomerId}";

        $defaultAddr = $payload['default_address'] ?? ($payload['addresses'][0] ?? null);
        $billingAddr = $payload['billing_address'] ?? $defaultAddr;
        $shippingAddr = $payload['shipping_address'] ?? $defaultAddr;

        $customer = Customer::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_customer_id' => $shopifyCustomerId,
            ],
            [
                'first_name' => $payload['first_name'] ?? null,
                'last_name' => $payload['last_name'] ?? null,
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? ($defaultAddr['phone'] ?? null),
                'billing_address' => $billingAddr,
                'shipping_address' => $shippingAddr,
            ]
        );

        $synced = false;
        $syncError = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $zohoService->syncCustomer($customer);
                $synced = true;
            } catch (\Throwable $e) {
                Log::error("customersUpdate: Zoho customer sync failed for customer ID {$customer->id}: " . $e->getMessage());
                $syncError = $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Customer update webhook processed successfully.',
            'customer_id' => $customer->id,
            'shopify_customer_id' => $customer->shopify_customer_id,
            'zoho_synced' => $synced,
            'error' => $syncError,
        ], 200);
    }

    /**
     * Handle Shopify orders/create and orders/updated webhooks.
     */
    public function ordersUpdate(Request $request)
    {
        Log::info('Shopify orders/update webhook received', [
            'shop' => $request->header('X-Shopify-Shop-Domain'),
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'topic' => $request->header('X-Shopify-Topic'),
            'payload' => $request->getContent(),
        ]);

        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');

        if (empty($hmacHeader)) {
            return response()->json(['error' => 'Missing HMAC signature.'], 401);
        }

        $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET');

        if (empty($secret)) {
            return response()->json(['error' => 'Shopify API secret not configured.'], 500);
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (!hash_equals($calculatedHmac, $hmacHeader)) {
            return response()->json(['error' => 'Invalid HMAC signature.'], 401);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (empty($shopDomain) || !is_string($shopDomain) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shopDomain)) {
            return response()->json(['error' => 'Invalid Shopify shop domain.'], 400);
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();

        if (!$shop) {
            return response()->json(['error' => 'Shop not found.'], 404);
        }

        $webhookId = $request->header('X-Shopify-Webhook-Id');

        if (!empty($webhookId)) {
            $alreadyProcessed = ShopifyProcessedWebhook::where('webhook_id', $webhookId)
                ->where('shop_domain', $shopDomain)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("ordersUpdate: Webhook ID {$webhookId} has already been processed for shop {$shopDomain}. Skipping.");
                return response()->json(['message' => 'Webhook already processed.'], 200);
            }

            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'shop_domain' => $shopDomain,
                'topic' => $request->header('X-Shopify-Topic') ?? 'orders/updated',
            ]);
        }

        $payload = json_decode($request->getContent(), true);

        if (empty($payload) || !is_array($payload) || empty($payload['id'])) {
            return response()->json(['error' => 'Invalid order payload.'], 400);
        }

        $rawOrderId = (string) $payload['id'];
        $shopifyOrderId = str_starts_with($rawOrderId, 'gid://')
            ? $rawOrderId
            : "gid://shopify/Order/{$rawOrderId}";

        $orderNumber = $payload['name'] ?? (!empty($payload['order_number']) ? "#{$payload['order_number']}" : null);

        // Resolve or create Customer record if present
        $customerId = null;
        if (!empty($payload['customer']) && is_array($payload['customer']) && !empty($payload['customer']['id'])) {
            $custData = $payload['customer'];
            $rawCustId = (string) $custData['id'];
            $shopifyCustId = str_starts_with($rawCustId, 'gid://')
                ? $rawCustId
                : "gid://shopify/Customer/{$rawCustId}";

            $defaultAddr = $custData['default_address'] ?? ($custData['addresses'][0] ?? null);
            $billingAddr = $payload['billing_address'] ?? $defaultAddr;
            $shippingAddr = $payload['shipping_address'] ?? $defaultAddr;

            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_customer_id' => $shopifyCustId,
                ],
                [
                    'first_name' => $custData['first_name'] ?? null,
                    'last_name' => $custData['last_name'] ?? null,
                    'email' => $custData['email'] ?? ($payload['email'] ?? null),
                    'phone' => $custData['phone'] ?? ($payload['phone'] ?? ($defaultAddr['phone'] ?? null)),
                    'billing_address' => $billingAddr,
                    'shipping_address' => $shippingAddr,
                ]
            );

            $customerId = $customer->id;

            // Sync customer to Zoho if needed
            if ($shop->zohoConnection) {
                try {
                    $zohoService = new ZohoService($shop);
                    $zohoService->syncCustomer($customer);
                } catch (\Throwable $e) {
                    Log::warning("ordersUpdate: Customer sync pre-step failed for order customer ID {$customer->id}: " . $e->getMessage());
                }
            }
        }

        // Parse line items
        $lineItems = [];
        if (!empty($payload['line_items']) && is_array($payload['line_items'])) {
            foreach ($payload['line_items'] as $item) {
                $lineItems[] = [
                    'line_item_id' => $item['id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'variant_id' => $item['variant_id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'title' => $item['title'] ?? ($item['name'] ?? null),
                    'name' => $item['name'] ?? ($item['title'] ?? null),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0.00),
                    'total_discount' => (float) ($item['total_discount'] ?? 0.00),
                ];
            }
        }

        $discountTotal = (float) ($payload['total_discounts'] ?? 0.00);
        $shippingTotal = (float) ($payload['total_shipping_price_set']['shop_money']['amount'] ?? ($payload['total_shipping_price'] ?? 0.00));
        $taxTotal = (float) ($payload['total_tax'] ?? 0.00);
        $totalPrice = (float) ($payload['total_price'] ?? 0.00);
        $subtotal = (float) ($payload['subtotal_price'] ?? ($totalPrice - $taxTotal - $shippingTotal + $discountTotal));

        $couponCode = !empty($payload['discount_codes'][0]['code']) ? $payload['discount_codes'][0]['code'] : null;

        $order = Order::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_order_id' => $shopifyOrderId,
            ],
            [
                'customer_id' => $customerId,
                'order_number' => $orderNumber,
                'order_date' => !empty($payload['created_at']) ? date('Y-m-d H:i:s', strtotime($payload['created_at'])) : now(),
                'currency' => $payload['currency'] ?? 'USD',
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shippingTotal,
                'tax_total' => $taxTotal,
                'total_price' => $totalPrice,
                'financial_status' => $payload['financial_status'] ?? null,
                'fulfillment_status' => $payload['fulfillment_status'] ?? null,
                'line_items' => $lineItems,
                'notes' => $payload['note'] ?? null,
                'coupon_code' => $couponCode,
            ]
        );

        $synced = false;
        $syncError = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $result = $zohoService->syncOrder($order);
                $synced = true;

                try {
                    $zohoService->syncInvoice($order);
                } catch (\Throwable $invEx) {
                    Log::warning("ordersUpdate: Zoho invoice auto-sync warning for order ID {$order->id}: " . $invEx->getMessage());
                }
            } catch (\Throwable $e) {
                Log::error("ordersUpdate: Zoho order sync failed for order ID {$order->id}: " . $e->getMessage());
                $syncError = $e->getMessage();
            }
        }

        $topic = $request->header('X-Shopify-Topic') ?? 'orders/updated';
        $isCreate = str_contains(strtolower($topic), 'create');
        $msgTopic = $isCreate ? 'create' : 'update';

        return response()->json([
            'message' => "Order {$msgTopic} webhook processed successfully.",
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_sales_order_id' => $order->zoho_sales_order_id,
            'zoho_synced' => $synced,
            'error' => $syncError,
        ], 200);
    }

    /**
     * Alias method for Shopify orders/create webhooks.
     */
    public function ordersCreate(Request $request)
    {
        return $this->ordersUpdate($request);
    }

    /**
     * Handle Shopify order_transactions/create webhook.
     */
    public function orderTransactionsCreate(Request $request)
    {
        Log::info('Shopify order_transactions/create webhook received', [
            'shop' => $request->header('X-Shopify-Shop-Domain'),
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'topic' => $request->header('X-Shopify-Topic'),
            'payload' => $request->getContent(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. Verify HMAC Signature
        |--------------------------------------------------------------------------
        */
        $hmacHeader = $request->header('X-Shopify-Hmac-SHA256');

        if (empty($hmacHeader)) {
            return response()->json(['error' => 'Missing HMAC signature.'], 401);
        }

        $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET');

        if (empty($secret)) {
            return response()->json(['error' => 'Shopify API secret not configured.'], 500);
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (!hash_equals($calculatedHmac, $hmacHeader)) {
            return response()->json(['error' => 'Invalid HMAC signature.'], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Validate Shop Domain & Resolve Tenant
        |--------------------------------------------------------------------------
        */
        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (empty($shopDomain) || !is_string($shopDomain) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shopDomain)) {
            return response()->json(['error' => 'Invalid Shopify shop domain.'], 400);
        }

        $shop = Shop::where('shop_domain', $shopDomain)->first();

        if (!$shop) {
            return response()->json(['error' => 'Shop not found.'], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Check Persistent Webhook Idempotency (Layer 1)
        |--------------------------------------------------------------------------
        */
        $webhookId = $request->header('X-Shopify-Webhook-Id');

        if (!empty($webhookId)) {
            $alreadyProcessed = ShopifyProcessedWebhook::where('webhook_id', $webhookId)
                ->where('shop_domain', $shopDomain)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("orderTransactionsCreate: Webhook ID {$webhookId} has already been processed for shop {$shopDomain}. Skipping.");
                return response()->json(['message' => 'Webhook already processed.'], 200);
            }

            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'shop_domain' => $shopDomain,
                'topic' => $request->header('X-Shopify-Topic') ?? 'order_transactions/create',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Validate JSON Payload
        |--------------------------------------------------------------------------
        */
        $payload = json_decode($request->getContent(), true);

        if (empty($payload) || !is_array($payload) || (empty($payload['id']) && empty($payload['admin_graphql_api_id']))) {
            return response()->json(['error' => 'Invalid order transaction payload.'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Extract & Normalize Transaction Data
        |--------------------------------------------------------------------------
        */
        $rawTxnId = (string) ($payload['admin_graphql_api_id'] ?? $payload['id']);
        $shopifyTxnId = str_starts_with($rawTxnId, 'gid://')
            ? $rawTxnId
            : "gid://shopify/OrderTransaction/{$rawTxnId}";

        $rawOrderId = !empty($payload['order_id'])
            ? (string) $payload['order_id']
            : (!empty($payload['order']['id']) ? (string) $payload['order']['id'] : null);

        $shopifyOrderId = null;
        $numericOrderId = null;

        if ($rawOrderId) {
            $numericOrderId = preg_replace('/^gid:\/\/shopify\/Order\//', '', $rawOrderId);
            $shopifyOrderId = str_starts_with($rawOrderId, 'gid://')
                ? $rawOrderId
                : "gid://shopify/Order/{$rawOrderId}";
        }

        $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $amount = (float) ($payload['amount'] ?? 0.00);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
        $gateway = $payload['gateway'] ?? 'shopify_payments';
        $processedAtStr = $payload['processed_at'] ?? ($payload['created_at'] ?? null);
        $paymentDate = !empty($processedAtStr) ? date('Y-m-d H:i:s', strtotime($processedAtStr)) : now();

        /*
        |--------------------------------------------------------------------------
        | 6. Transaction Qualification Rules
        |--------------------------------------------------------------------------
        */
        // Only SUCCESS status and 'sale' or 'capture' kinds qualify for payment recording
        $qualifyingKinds = ['sale', 'capture'];

        if ($status !== 'success' || !in_array($kind, $qualifyingKinds, true)) {
            Log::info("orderTransactionsCreate: Transaction ID {$shopifyTxnId} with status '{$status}' and kind '{$kind}' does not qualify for Zoho customer payment. Skipping.");
            return response()->json([
                'message' => 'Transaction not eligible for payment synchronization.',
                'shopify_transaction_id' => $shopifyTxnId,
                'status' => $status,
                'kind' => $kind,
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Locate Local Order
        |--------------------------------------------------------------------------
        */
        $orderCandidates = array_values(array_unique(array_filter([
            $shopifyOrderId,
            $rawOrderId,
            $numericOrderId,
            $numericOrderId ? "gid://shopify/Order/{$numericOrderId}" : null,
        ])));

        $order = null;
        if (!empty($orderCandidates)) {
            $order = Order::where('shop_id', $shop->id)
                ->whereIn('shopify_order_id', $orderCandidates)
                ->first();
        }

        if (!$order) {
            try {
                $shopifyService = new \App\Services\ShopifyService();
                $targetOrderId = $shopifyOrderId ?? $rawOrderId;
                if ($targetOrderId) {
                    $order = $shopifyService->fetchAndSyncOrder($shop, $targetOrderId);
                }
            } catch (\Throwable $e) {
                Log::warning("orderTransactionsCreate: Failed on-the-fly order fetch/sync for order {$rawOrderId}: " . $e->getMessage());
            }

            if (!$order && !empty($orderCandidates)) {
                $order = Order::where('shop_id', $shop->id)
                    ->whereIn('shopify_order_id', $orderCandidates)
                    ->first();
            }
        }

        if (!$order) {
            Log::warning("orderTransactionsCreate: Local order not found for transaction {$shopifyTxnId} (Order ID: {$rawOrderId}).");
            return response()->json([
                'message' => 'Order not found locally for transaction. Payment sync deferred.',
                'shopify_transaction_id' => $shopifyTxnId,
                'shopify_order_id' => $shopifyOrderId,
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Locate Local Invoice & Ensure Zoho Invoice Mapping
        |--------------------------------------------------------------------------
        */
        $invoice = $order->invoice;

        if (!$invoice && $shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $zohoService->syncInvoice($order);
                $order->refresh();
                $invoice = $order->invoice;
            } catch (\Throwable $e) {
                Log::warning("orderTransactionsCreate: Auto invoice sync attempt failed for order ID {$order->id}: " . $e->getMessage());
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Create or Update Local Payment (Layer 2 Idempotency)
        |--------------------------------------------------------------------------
        */
        $numericTxnId = preg_replace('/^gid:\/\/shopify\/OrderTransaction\//', '', $shopifyTxnId);
        $paymentReference = "TXN-{$numericTxnId}";

        $payment = Payment::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_transaction_id' => $shopifyTxnId,
            ],
            [
                'order_id' => $order->id,
                'invoice_id' => $invoice?->id,
                'shopify_order_id' => $order->shopify_order_id,
                'payment_reference' => $paymentReference,
                'amount' => $amount,
                'currency' => $currency ?: ($order->currency ?? 'USD'),
                'payment_date' => $paymentDate,
                'payment_method' => $gateway,
                'status' => Payment::STATUS_PAID,
                'sync_status' => Payment::SYNC_STATUS_PENDING,
                'gateway_data' => [
                    'id' => $payload['id'] ?? null,
                    'kind' => $kind,
                    'status' => $status,
                    'gateway' => $gateway,
                    'test' => $payload['test'] ?? false,
                ],
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | 10. Call Zoho Customer Payment Sync Service
        |--------------------------------------------------------------------------
        */
        $synced = false;
        $syncError = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $result = $zohoService->syncPayment($payment);
                $synced = true;
            } catch (\Throwable $e) {
                Log::error("orderTransactionsCreate: Zoho payment sync failed for payment ID {$payment->id}: " . $e->getMessage());
                $syncError = $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Order transaction webhook processed successfully.',
            'payment_id' => $payment->id,
            'shopify_transaction_id' => $payment->shopify_transaction_id,
            'zoho_payment_id' => $payment->zoho_payment_id,
            'zoho_synced' => $synced,
            'error' => $syncError,
        ], 200);
    }
}


