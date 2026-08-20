<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PendingInventoryWebhook;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Models\SyncHistory;
use App\Services\ShopifyService;
use App\Services\ZohoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    /**
     * Handle Shopify products/update webhook.
     */
    public function productsUpdate(Request $request)
    {
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

        if (!$product) {
            $product = Product::create([
                'shop_id' => $shop->id,
                'shopify_product_id' => $productGid ?? ($numericProductId ? "gid://shopify/Product/{$numericProductId}" : 'gid://shopify/Product/' . time()),
                'title' => $payload['title'] ?? 'Untitled Product',
                'handle' => $payload['handle'] ?? null,
                'image_url' => $imageUrl,
            ]);
        } else {
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

                $existingVariant = ProductVariant::whereIn('shopify_variant_id', $variantIdCandidates)->first();

                if ($existingVariant && $existingVariant->product->shop_id !== $shop->id) {
                    continue;
                }

                $isNewVariant = false;

                if (!$existingVariant) {
                    $rawInvId = !empty($vData['inventory_item_id']) ? (string) $vData['inventory_item_id'] : null;
                    $formattedInvId = $rawInvId ? (str_starts_with($rawInvId, 'gid://') ? $rawInvId : "gid://shopify/InventoryItem/{$rawInvId}") : null;

                    $variant = ProductVariant::create([
                        'product_id' => $product->id,
                        'shopify_variant_id' => $variantGid ?? ($numericVariantId ? "gid://shopify/ProductVariant/{$numericVariantId}" : 'gid://shopify/ProductVariant/' . time()),
                        'shopify_inventory_item_id' => $formattedInvId,
                        'title' => $vData['title'] ?? 'Default',
                        'sku' => $vData['sku'] ?? null,
                        'price' => $vData['price'] ?? 0.00,
                        'inventory_quantity' => $vData['inventory_quantity'] ?? 0,
                    ]);
                    $isNewVariant = true;
                } else {
                    $variant = $existingVariant;
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
                }

                $summary['processed']++;

                if ($isNewVariant) {
                    $shopifyService = app(ShopifyService::class);
                    if ($variant->shopify_inventory_item_id) {
                        $liveQty = $shopifyService->fetchStorewideAvailableQuantity($shop, $variant->shopify_inventory_item_id);
                        if ($liveQty !== null) {
                            $variant->inventory_quantity = $liveQty;
                            $variant->save();
                        }
                    }

                    if (!$variant->zoho_item_id && $zohoService) {
                        try {
                            $zohoService->createItem($variant, $variant->inventory_quantity);
                        } catch (\Throwable $e) {
                            Log::error("Zoho createItem failed for new variant ID {$variant->id}: " . $e->getMessage());
                        }
                    }
                }

                // Zoho metadata update if zoho_item_id exists
                if ($variant->zoho_item_id) {
                    if ($zohoService && !$isNewVariant) {
                        $metaSuccess = false;

                        try {
                            $zohoService->updateItem($variant);
                            $metaSuccess = true;
                            $summary['metadata_synced']++;
                        } catch (\Throwable $e) {
                            Log::error("Zoho item update failed for variant ID {$variant->id}: " . $e->getMessage());
                            $summary['metadata_failures']++;
                        }

                        if ($metaSuccess) {
                            $summary['synced_to_zoho']++;
                        } else {
                            $summary['zoho_failures']++;
                        }
                    } else if ($isNewVariant) {
                        $summary['synced_to_zoho']++;
                    }
                } else {
                    $summary['skipped_unmapped']++;
                }

                // Process any deferred pending inventory webhooks for this variant
                $this->processPendingInventoryWebhooks($shop, $variant, $zohoService);
            }
        }

        return response()->json([
            'message' => 'Product update webhook processed successfully.',
            'summary' => $summary,
        ], 200);
    }

    /**
     * Handle Shopify products/delete webhook.
     */
    public function productsDelete(Request $request)
    {
        Log::info('Shopify products/delete webhook received', [
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
        | 3. Check & Record Persistent Webhook Idempotency Status (Atomic Lock)
        |--------------------------------------------------------------------------
        */

        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $processedWebhook = null;

        if ($webhookId) {
            $processedWebhook = ShopifyProcessedWebhook::where('webhook_id', $webhookId)->first();

            if ($processedWebhook) {
                $status = $processedWebhook->status ?? 'completed';

                if ($status === 'completed') {
                    return response()->json([
                        'message' => 'Webhook already processed.',
                        'webhook_id' => $webhookId,
                    ], 200);
                }

                $isStale = ($status === 'processing') &&
                    $processedWebhook->updated_at &&
                    $processedWebhook->updated_at->lt(now()->subMinutes(5));

                if ($status === 'processing' && !$isStale) {
                    return response()->json([
                        'error' => 'Webhook is currently processing.',
                        'webhook_id' => $webhookId,
                    ], 429);
                }

                // Atomic state claim: only 1 thread can update status from non-processing (or stale processing) to processing
                $affected = ShopifyProcessedWebhook::where('id', $processedWebhook->id)
                    ->where(function ($q) {
                        $q->where('status', '!=', 'processing')
                            ->orWhere('updated_at', '<', now()->subMinutes(5));
                    })
                    ->update([
                        'status' => 'processing',
                        'updated_at' => now(),
                    ]);

                if ($affected === 0) {
                    return response()->json([
                        'error' => 'Webhook is currently processing.',
                        'webhook_id' => $webhookId,
                    ], 429);
                }

                $processedWebhook->refresh();
            } else {
                try {
                    $processedWebhook = ShopifyProcessedWebhook::create([
                        'webhook_id' => $webhookId,
                        'topic' => $request->header('X-Shopify-Topic', 'products/delete'),
                        'shop_domain' => $shopDomain,
                        'status' => 'processing',
                    ]);
                } catch (\Throwable $e) {
                    // Unique constraint violation: another concurrent thread created the row first
                    $processedWebhook = ShopifyProcessedWebhook::where('webhook_id', $webhookId)->first();
                    if ($processedWebhook) {
                        $st = $processedWebhook->status ?? 'completed';
                        if ($st === 'completed') {
                            return response()->json([
                                'message' => 'Webhook already processed.',
                                'webhook_id' => $webhookId,
                            ], 200);
                        }
                        if ($st === 'processing') {
                            return response()->json([
                                'error' => 'Webhook is currently processing.',
                                'webhook_id' => $webhookId,
                            ], 429);
                        }
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 4. Validate JSON Payload
        |--------------------------------------------------------------------------
        */

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || (empty($payload['id']) && empty($payload['admin_graphql_api_id']))) {
            if ($processedWebhook) {
                $processedWebhook->update(['status' => 'failed']);
            }
            return response()->json(['error' => 'Invalid product delete payload.'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Resolve Local Product & Variants
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
            ->with('variants')
            ->first();

        if (!$product) {
            Log::info("productsDelete: Product not found locally for candidates: " . json_encode($productIdCandidates) . " on shop {$shopDomain}. Marking webhook processed.");
            if ($processedWebhook) {
                $processedWebhook->update(['status' => 'completed']);
            }

            return response()->json([
                'message' => 'Product not found locally or already deleted.',
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Initialize Zoho Service & Process Variants
        |--------------------------------------------------------------------------
        */

        $zohoService = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
            } catch (\Throwable $e) {
                Log::warning('ZohoService initialization skipped for product delete on shop ' . $shopDomain . ': ' . $e->getMessage());
            }
        }

        $variants = $product->variants;
        $summary = [
            'total_variants' => count($variants),
            'zoho_deleted' => 0,
            'zoho_inactivated' => 0,
            'zoho_already_missing' => 0,
            'skipped_unmapped' => 0,
            'zoho_failures' => 0,
            'cleaned_variants' => 0,
        ];

        $hasFailure = false;

        foreach ($variants as $variant) {
            $zohoItemId = $variant->zoho_item_id;

            if (!$zohoItemId) {
                $summary['skipped_unmapped']++;
                $variant->delete();
                $summary['cleaned_variants']++;
                continue;
            }

            if (!$zohoService) {
                Log::warning("productsDelete: Cannot delete Zoho item {$zohoItemId} for variant ID {$variant->id} because Zoho service is unavailable.");
                $summary['zoho_failures']++;
                $hasFailure = true;
                continue;
            }

            $result = $zohoService->deleteItem($zohoItemId);

            try {
                SyncHistory::create([
                    'shop_id' => $shop->id,
                    'product_variant_id' => $variant->id,
                    'action' => 'delete',
                    'status' => $result['success'] ? 'success' : 'failed',
                    'zoho_item_id' => $zohoItemId,
                    'message' => "Product delete webhook: Zoho item {$zohoItemId} " . ($result['status'] ?? 'processed') . (!empty($result['error']) ? " ({$result['error']})" : ""),
                    'synced_at' => now(),
                ]);
            } catch (\Throwable $shEx) {
                Log::warning("productsDelete: Failed to create SyncHistory for variant ID {$variant->id}: " . $shEx->getMessage());
            }

            if ($result['success']) {
                $status = $result['status'] ?? 'deleted';
                if ($status === 'deleted') {
                    $summary['zoho_deleted']++;
                } elseif ($status === 'inactivated') {
                    $summary['zoho_inactivated']++;
                } elseif ($status === 'already_missing') {
                    $summary['zoho_already_missing']++;
                }

                $variant->update([
                    'zoho_item_id' => null,
                    'zoho_sync_hash' => null,
                    'zoho_synced_at' => null,
                ]);
                $variant->delete();
                $summary['cleaned_variants']++;
            } else {
                $summary['zoho_failures']++;
                $hasFailure = true;
                Log::error("productsDelete: Zoho item deletion failed for variant ID {$variant->id} (Zoho Item ID: {$zohoItemId}): " . ($result['error'] ?? 'Unknown error'));
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Final Cleanup & Response
        |--------------------------------------------------------------------------
        */

        $remainingVariantsCount = $product->variants()->count();

        if (!$hasFailure && $remainingVariantsCount === 0) {
            $product->delete();

            if ($processedWebhook) {
                $processedWebhook->update(['status' => 'completed']);
            }

            return response()->json([
                'message' => 'Product delete webhook processed successfully.',
                'summary' => $summary,
            ], 200);
        } else {
            Log::warning("productsDelete: Partial failure during product deletion for product ID {$product->id} on shop {$shopDomain}.", $summary);

            if ($processedWebhook) {
                $processedWebhook->update(['status' => 'failed']);
            }

            return response()->json([
                'error' => 'Product deletion partially failed. Some Zoho items could not be processed.',
                'summary' => $summary,
            ], 500);
        }

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
            Log::info("inventoryLevelsUpdate: Variant not found for inventory item ID {$rawInventoryItemId} on shop {$shopDomain}. Deferring event to pending_inventory_webhooks.");

            $formattedInvId = str_starts_with($rawInventoryItemId, 'gid://')
                ? $rawInventoryItemId
                : "gid://shopify/InventoryItem/{$numericInventoryItemId}";

            PendingInventoryWebhook::create([
                'shop_id' => $shop->id,
                'shopify_inventory_item_id' => $formattedInvId,
                'webhook_id' => $webhookId,
                'available_quantity' => (int) ($payload['available'] ?? 0),
                'status' => 'pending',
                'payload' => $payload,
            ]);

            $summary['deferred_pending'] = 1;

            return response()->json([
                'message' => 'Inventory update deferred as pending for unmapped variant.',
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

        $phone = $this->extractCustomerPhone($payload);
        $updateData = [
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'email' => $payload['email'] ?? null,
            'billing_address' => $billingAddr,
            'shipping_address' => $shippingAddr,
        ];
        if (!empty($phone)) {
            $updateData['phone'] = $phone;
        }

        $customer = Customer::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_customer_id' => $shopifyCustomerId,
            ],
            $updateData
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

            $phone = $this->extractCustomerPhone($custData);
            if (empty($phone)) {
                $phone = $this->extractCustomerPhone($payload);
            }
            $updateData = [
                'first_name' => $custData['first_name'] ?? null,
                'last_name' => $custData['last_name'] ?? null,
                'email' => $custData['email'] ?? ($payload['email'] ?? null),
                'billing_address' => $billingAddr,
                'shipping_address' => $shippingAddr,
            ];
            if (!empty($phone)) {
                $updateData['phone'] = $phone;
            }

            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_customer_id' => $shopifyCustId,
                ],
                $updateData
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

        // Resolve single source of truth order currency (presentment/market currency preferred)
        $defaultCurrency = !empty($payload['presentment_currency'])
            ? strtoupper(trim((string) $payload['presentment_currency']))
            : (!empty($payload['total_price_set']['presentment_money']['currency_code'])
                ? strtoupper(trim((string) $payload['total_price_set']['presentment_money']['currency_code']))
                : (!empty($payload['currency']) ? strtoupper(trim((string) $payload['currency'])) : ($shop->currency ?? 'USD')));

        $resolvedTotal = \App\Services\ShopifyService::extractRestMoney($payload['total_price_set'] ?? null, $defaultCurrency, $payload['total_price'] ?? 0.00);
        $orderCurrency = $resolvedTotal['currency'];
        $totalPrice = $resolvedTotal['amount'];

        $subtotalData = \App\Services\ShopifyService::extractRestMoney($payload['subtotal_price_set'] ?? null, $orderCurrency, $payload['subtotal_price'] ?? 0.00);
        $subtotal = $subtotalData['amount'];

        $discountData = \App\Services\ShopifyService::extractRestMoney($payload['total_discounts_set'] ?? null, $orderCurrency, $payload['total_discounts'] ?? 0.00);
        $discountTotal = $discountData['amount'];

        $shippingData = \App\Services\ShopifyService::extractRestMoney($payload['total_shipping_price_set'] ?? null, $orderCurrency, $payload['total_shipping_price'] ?? 0.00);
        $shippingTotal = $shippingData['amount'];

        $taxData = \App\Services\ShopifyService::extractRestMoney($payload['total_tax_set'] ?? null, $orderCurrency, $payload['total_tax'] ?? 0.00);
        $taxTotal = $taxData['amount'];

        // If subtotal is still 0 while total price > 0, fallback calculate subtotal
        if ($subtotal <= 0 && $totalPrice > 0) {
            $subtotal = max(0.00, $totalPrice - $taxTotal - $shippingTotal + $discountTotal);
        }

        // Parse line items using order currency representation
        $lineItems = [];
        if (!empty($payload['line_items']) && is_array($payload['line_items'])) {
            foreach ($payload['line_items'] as $item) {
                $liTaxLines = [];
                if (!empty($item['tax_lines']) && is_array($item['tax_lines'])) {
                    foreach ($item['tax_lines'] as $tl) {
                        $tlMoney = \App\Services\ShopifyService::extractRestMoney($tl['price_set'] ?? null, $orderCurrency, $tl['price'] ?? 0.00);
                        $liTaxLines[] = [
                            'title' => $tl['title'] ?? '',
                            'price' => $tlMoney['amount'],
                            'rate' => (float) ($tl['rate'] ?? 0.0),
                        ];
                    }
                }

                $liPriceData = \App\Services\ShopifyService::extractRestMoney($item['price_set'] ?? null, $orderCurrency, $item['price'] ?? 0.00);
                $liDiscountData = \App\Services\ShopifyService::extractRestMoney($item['total_discount_set'] ?? null, $orderCurrency, $item['total_discount'] ?? 0.00);

                $lineItems[] = [
                    'line_item_id' => $item['id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'variant_id' => $item['variant_id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'title' => $item['title'] ?? ($item['name'] ?? null),
                    'name' => $item['name'] ?? ($item['title'] ?? null),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => $liPriceData['amount'],
                    'total_discount' => $liDiscountData['amount'],
                    'tax_lines' => $liTaxLines,
                ];
            }
        }

        $orderTaxLines = [];
        if (!empty($payload['tax_lines']) && is_array($payload['tax_lines'])) {
            foreach ($payload['tax_lines'] as $tl) {
                $tlMoney = \App\Services\ShopifyService::extractRestMoney($tl['price_set'] ?? null, $orderCurrency, $tl['price'] ?? 0.00);
                $orderTaxLines[] = [
                    'title' => $tl['title'] ?? '',
                    'price' => $tlMoney['amount'],
                    'rate' => (float) ($tl['rate'] ?? 0.0),
                ];
            }
        }

        $taxesIncluded = (bool) ($payload['taxes_included'] ?? false);
        $couponCode = !empty($payload['discount_codes'][0]['code']) ? $payload['discount_codes'][0]['code'] : null;

        $shippingLines = [];
        $shippingMethod = null;
        if (!empty($payload['shipping_lines']) && is_array($payload['shipping_lines'])) {
            foreach ($payload['shipping_lines'] as $sl) {
                $slTitle = $sl['title'] ?? '';
                $slPriceData = \App\Services\ShopifyService::extractRestMoney($sl['price_set'] ?? null, $orderCurrency, $sl['price'] ?? 0.00);
                $shippingLines[] = [
                    'title' => $slTitle,
                    'price' => $slPriceData['amount'],
                    'code' => $sl['code'] ?? null,
                ];
                if (empty($shippingMethod) && !empty($slTitle)) {
                    $shippingMethod = $slTitle;
                }
            }
        }

        $shippingAddr = $payload['shipping_address'] ?? null;

        $fulfillments = [];
        $trackingNumber = null;
        $trackingCompany = null;
        $trackingUrl = null;

        if (!empty($payload['fulfillments']) && is_array($payload['fulfillments'])) {
            foreach ($payload['fulfillments'] as $ful) {
                $fNumber = $ful['tracking_number'] ?? (!empty($ful['tracking_numbers'][0]) ? $ful['tracking_numbers'][0] : null);
                $fCompany = $ful['tracking_company'] ?? (!empty($ful['tracking_companies'][0]) ? $ful['tracking_companies'][0] : null);
                $fUrl = $ful['tracking_url'] ?? (!empty($ful['tracking_urls'][0]) ? $ful['tracking_urls'][0] : null);

                if (!empty($fNumber) && empty($trackingNumber)) {
                    $trackingNumber = $fNumber;
                }
                if (!empty($fCompany) && empty($trackingCompany)) {
                    $trackingCompany = $fCompany;
                }
                if (!empty($fUrl) && empty($trackingUrl)) {
                    $trackingUrl = $fUrl;
                }

                $fulfillments[] = [
                    'id' => $ful['id'] ?? null,
                    'status' => $ful['status'] ?? null,
                    'tracking_number' => $fNumber,
                    'tracking_company' => $fCompany,
                    'tracking_url' => $fUrl,
                ];
            }
        }

        $financialStatus = $payload['financial_status'] ?? null;
        if (!empty($payload['cancelled_at']) && !in_array(strtolower((string) $financialStatus), ['voided', 'cancelled', 'refunded'], true)) {
            $financialStatus = 'cancelled';
        }

        $existingOrder = Order::where('shop_id', $shop->id)
            ->where(function ($q) use ($shopifyOrderId) {
                $numericId = preg_replace('/[^0-9]/', '', (string) $shopifyOrderId);
                $gid = str_starts_with((string) $shopifyOrderId, 'gid://')
                    ? (string) $shopifyOrderId
                    : "gid://shopify/Order/{$shopifyOrderId}";
                $q->where('shopify_order_id', $numericId)
                    ->orWhere('shopify_order_id', $gid);
            })->first();

        $updateData = [
            'customer_id' => $customerId,
            'order_number' => $orderNumber,
            'order_date' => !empty($payload['created_at']) ? date('Y-m-d H:i:s', strtotime($payload['created_at'])) : now(),
            'currency' => $orderCurrency,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'shipping_total' => $shippingTotal,
            'shipping_method' => $shippingMethod,
            'shipping_address' => $shippingAddr,
            'shipping_lines' => $shippingLines,
            'tracking_number' => $trackingNumber,
            'tracking_company' => $trackingCompany,
            'tracking_url' => $trackingUrl,
            'fulfillments' => $fulfillments,
            'tax_total' => $taxTotal,
            'total_price' => $totalPrice,
            'taxes_included' => $taxesIncluded,
            'tax_lines' => $orderTaxLines,
            'financial_status' => $financialStatus,
            'fulfillment_status' => $payload['fulfillment_status'] ?? null,
            'line_items' => $lineItems,
            'notes' => $payload['note'] ?? null,
            'coupon_code' => $couponCode,
        ];

        if (!empty($payload['cancelled_at'])) {
            $updateData['cancelled_at'] = date('Y-m-d H:i:s', strtotime($payload['cancelled_at']));
            $updateData['cancel_reason'] = $payload['cancel_reason'] ?? null;
        } elseif ($existingOrder && $existingOrder->cancelled_at) {
            $updateData['cancelled_at'] = $existingOrder->cancelled_at;
            $updateData['cancel_reason'] = $existingOrder->cancel_reason;
        }

        $order = Order::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_order_id' => $existingOrder ? $existingOrder->shopify_order_id : $shopifyOrderId,
            ],
            $updateData
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
     * Handle Shopify orders/cancelled webhook.
     */
    public function ordersCancelled(Request $request): JsonResponse
    {
        Log::info('Shopify orders/cancelled webhook received', [
            'shop' => $request->header('X-Shopify-Shop-Domain'),
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'topic' => $request->header('X-Shopify-Topic'),
        ]);

        // 1. Verify HMAC Signature
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256') ?? $request->header('X-Shopify-Hmac-SHA256');
        if (!empty($hmacHeader)) {
            $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET') ?? config('shopify-app.api_secret');
            if ($secret) {
                $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
                if (!hash_equals($calculatedHmac, $hmacHeader)) {
                    Log::warning('ordersCancelled: Invalid HMAC signature.');
                    return response()->json(['error' => 'Invalid HMAC signature'], 401);
                }
            }
        }

        // 2. Identify Shop
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = Shop::where('shop_domain', $shopDomain)->first();
        if (!$shop) {
            Log::warning("ordersCancelled: Shop not found for domain '{$shopDomain}'.");
            return response()->json(['error' => 'Shop not found'], 404);
        }

        // 3. Idempotency Check
        $webhookId = $request->header('X-Shopify-Webhook-Id');
        if (!empty($webhookId)) {
            $alreadyProcessed = ShopifyProcessedWebhook::where('webhook_id', $webhookId)
                ->where('shop_domain', $shopDomain)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("ordersCancelled: Webhook ID {$webhookId} has already been processed. Skipping.");
                return response()->json([
                    'message' => 'Webhook already processed',
                    'webhook_id' => $webhookId,
                ], 200);
            }

            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'shop_domain' => $shopDomain,
                'topic' => $request->header('X-Shopify-Topic') ?? 'orders/cancelled',
            ]);
        }

        $payload = json_decode($request->getContent(), true);
        if (!$payload || empty($payload['id'])) {
            Log::warning('ordersCancelled: Invalid or missing order payload.');
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $rawOrderId = (string) $payload['id'];
        $numericOrderId = preg_replace('/^gid:\/\/shopify\/Order\//', '', $rawOrderId);
        $gidOrderId = str_starts_with($rawOrderId, 'gid://') ? $rawOrderId : "gid://shopify/Order/{$rawOrderId}";

        $order = Order::where('shop_id', $shop->id)
            ->where(function ($q) use ($numericOrderId, $gidOrderId, $rawOrderId) {
                $q->where('shopify_order_id', $numericOrderId)
                    ->orWhere('shopify_order_id', $gidOrderId)
                    ->orWhere('shopify_order_id', $rawOrderId);
            })
            ->first();

        if (!$order) {
            Log::info("ordersCancelled: Order {$numericOrderId} not found locally. Creating order before cancellation.");
            $order = $this->createLocalOrderFromShopifyData($shop, $payload);
        }

        $order->financial_status = 'cancelled';
        $order->cancelled_at = !empty($payload['cancelled_at']) ? date('Y-m-d H:i:s', strtotime($payload['cancelled_at'])) : now();
        $order->cancel_reason = $payload['cancel_reason'] ?? null;
        $order->save();

        $synced = false;
        $syncError = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $result = $zohoService->cancelOrder($order);
                $synced = true;
            } catch (\Throwable $e) {
                Log::error("ordersCancelled: Zoho cancellation sync failed for order ID {$order->id}: " . $e->getMessage());
                $syncError = $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Order cancellation webhook processed successfully.',
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'financial_status' => $order->financial_status,
            'zoho_synced' => $synced,
            'error' => $syncError,
        ], 200);
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
        $rawAmount = (float) ($payload['amount'] ?? 0.00);

        $presCurrency = !empty($payload['presentment_money']['currency_code'])
            ? strtoupper(trim((string) $payload['presentment_money']['currency_code']))
            : null;
        $presAmount = isset($payload['presentment_money']['amount'])
            ? (float) $payload['presentment_money']['amount']
            : null;

        $shopCurrency = !empty($payload['shop_money']['currency_code'])
            ? strtoupper(trim((string) $payload['shop_money']['currency_code']))
            : null;
        $shopAmount = isset($payload['shop_money']['amount'])
            ? (float) $payload['shop_money']['amount']
            : null;

        $topCurrency = !empty($payload['currency'])
            ? strtoupper(trim((string) $payload['currency']))
            : null;
        $rawAmount = (float) ($payload['amount'] ?? 0.00);

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

        $orderCurrency = $order ? strtoupper(trim((string) $order->currency)) : null;

        if ($orderCurrency) {
            $currency = $orderCurrency;
            if ($presCurrency && strcasecmp($presCurrency, $orderCurrency) === 0 && $presAmount !== null) {
                $amount = $presAmount;
            } elseif ($shopCurrency && strcasecmp($shopCurrency, $orderCurrency) === 0 && $shopAmount !== null) {
                $amount = $shopAmount;
            } elseif ($presAmount !== null) {
                $amount = $presAmount;
            } elseif ($shopAmount !== null) {
                $amount = $shopAmount;
            } else {
                $amount = $rawAmount;
            }
        } elseif ($presCurrency) {
            $currency = $presCurrency;
            $amount = $presAmount ?? $rawAmount;
        } elseif ($shopCurrency) {
            $currency = $shopCurrency;
            $amount = $shopAmount ?? $rawAmount;
        } else {
            $currency = $topCurrency ?? $shop->currency ?? 'USD';
            $amount = $rawAmount;
        }

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
                'currency' => $currency ?: ($order->currency ?? $shop->currency ?? 'USD'),
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

    /**
     * Webhook Endpoint: refunds/create
     */
    public function refundsCreate(Request $request): JsonResponse
    {
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256') ?? $request->header('X-Shopify-Hmac-SHA256');

        if (!empty($hmacHeader)) {
            $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET') ?? config('shopify-app.api_secret');
            if ($secret) {
                $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
                if (!hash_equals($calculatedHmac, $hmacHeader)) {
                    Log::warning('refundsCreate: Invalid HMAC signature.');
                    return response()->json(['error' => 'Invalid HMAC signature'], 401);
                }
            }
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = Shop::where('shop_domain', $shopDomain)->first();

        if (!$shop) {
            Log::warning("refundsCreate: Shop not found for domain '{$shopDomain}'.");
            return response()->json(['error' => 'Shop not found'], 404);
        }

        $webhookId = $request->header('X-Shopify-Webhook-Id');
        if ($webhookId) {
            $alreadyProcessed = ShopifyProcessedWebhook::where('webhook_id', $webhookId)->exists();
            if ($alreadyProcessed) {
                Log::info("refundsCreate: Duplicate webhook received ({$webhookId}), skipping.");
                return response()->json(['message' => 'Webhook already processed'], 200);
            }
        }

        $data = $request->getContent();
        $payload = json_decode($data, true);
        if (!$payload || empty($payload['id']) || empty($payload['order_id'])) {
            Log::warning('refundsCreate: Missing required refund payload data.');
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if ($webhookId) {
            ShopifyProcessedWebhook::create([
                'webhook_id' => $webhookId,
                'topic' => 'refunds/create',
            ]);
        }

        $shopifyRefundId = (string) $payload['id'];
        $rawOrderId = (string) $payload['order_id'];
        $numericOrderId = preg_replace('/^gid:\/\/shopify\/Order\//', '', $rawOrderId);
        $shopifyOrderId = $numericOrderId;
        $gidOrderId = str_starts_with($rawOrderId, 'gid://') ? $rawOrderId : "gid://shopify/Order/{$rawOrderId}";

        $order = Order::where('shop_id', $shop->id)
            ->where(function ($q) use ($numericOrderId, $gidOrderId, $rawOrderId) {
                $q->where('shopify_order_id', $numericOrderId)
                    ->orWhere('shopify_order_id', $gidOrderId)
                    ->orWhere('shopify_order_id', $rawOrderId);
            })
            ->first();

        if (!$order) {
            Log::info("refundsCreate: Local Order not found for shopify_order_id {$rawOrderId}. Attempting fallback order resolution.");

            $orderData = null;
            try {
                $shopifyService = app(\App\Services\ShopifyService::class);
                $orderData = $shopifyService->fetchOrderById($shop, $numericOrderId);
            } catch (\Throwable $e) {
                Log::warning("refundsCreate: Unable to fetch order {$numericOrderId} from Shopify API: " . $e->getMessage());
            }

            if ($orderData) {
                $order = $this->createLocalOrderFromShopifyData($shop, $orderData);
            } elseif (!empty($payload['order']) && is_array($payload['order'])) {
                $order = $this->createLocalOrderFromShopifyData($shop, $payload['order']);
            } else {
                $order = Order::create([
                    'shop_id' => $shop->id,
                    'shopify_order_id' => $numericOrderId,
                    'order_number' => "#ORD-{$numericOrderId}",
                    'financial_status' => 'partially_refunded',
                    'fulfillment_status' => 'unfulfilled',
                    'order_date' => now(),
                    'currency' => $payload['currency'] ?? $shop->currency ?? 'USD',
                    'subtotal' => 0.00,
                    'total_price' => 0.00,
                    'line_items' => [],
                ]);
            }
        }

        $totalAmount = 0.0;
        if (!empty($payload['total_refunded_set']['presentment_money']['amount'])) {
            $totalAmount = (float) $payload['total_refunded_set']['presentment_money']['amount'];
        } elseif (!empty($payload['total_refunded'])) {
            $totalAmount = (float) $payload['total_refunded'];
        } elseif (!empty($payload['total_refunded_set']['shop_money']['amount'])) {
            $totalAmount = (float) $payload['total_refunded_set']['shop_money']['amount'];
        }

        if ($totalAmount <= 0 && !empty($payload['transactions']) && is_array($payload['transactions'])) {
            foreach ($payload['transactions'] as $tx) {
                if (($tx['status'] ?? '') === 'success' && in_array(strtolower((string) ($tx['kind'] ?? '')), ['refund', 'change'], true)) {
                    $txAmount = !empty($tx['amount_set']['presentment_money']['amount'])
                        ? (float) $tx['amount_set']['presentment_money']['amount']
                        : (float) ($tx['amount'] ?? 0.0);
                    $totalAmount += $txAmount;
                }
            }
        }

        $refundLineItems = [];
        $restock = false;
        if (!empty($payload['refund_line_items']) && is_array($payload['refund_line_items'])) {
            foreach ($payload['refund_line_items'] as $item) {
                $restockType = strtolower((string) ($item['restock_type'] ?? ''));
                if (in_array($restockType, ['cancel', 'return'], true)) {
                    $restock = true;
                }

                $lineItem = $item['line_item'] ?? [];
                $refundLineItems[] = [
                    'line_item_id' => $item['line_item_id'] ?? null,
                    'variant_id' => $lineItem['variant_id'] ?? null,
                    'title' => $lineItem['title'] ?? 'Refunded Item',
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => (float) ($lineItem['price'] ?? 0.0),
                    'restock_type' => $item['restock_type'] ?? null,
                ];

                if ($totalAmount <= 0) {
                    $totalAmount += (float) ($item['subtotal'] ?? 0.0);
                }
            }
        }

        $currency = $order ? $order->currency : ($payload['currency'] ?? $shop->currency ?? 'USD');

        $refund = \App\Models\Refund::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_refund_id' => $shopifyRefundId,
            ],
            [
                'order_id' => $order->id,
                'shopify_order_id' => $shopifyOrderId,
                'amount' => $totalAmount,
                'currency' => $currency,
                'note' => $payload['note'] ?? null,
                'restock' => $restock,
                'refund_line_items' => $refundLineItems,
                'status' => \App\Models\Refund::STATUS_COMPLETED,
                'sync_status' => \App\Models\Refund::SYNC_STATUS_PENDING,
            ]
        );

        $order->financial_status = ($totalAmount >= (float) $order->total_price && (float) $order->total_price > 0)
            ? 'refunded'
            : 'partially_refunded';
        if (!empty($payload['cancelled_at']) && empty($order->cancelled_at)) {
            $order->cancelled_at = date('Y-m-d H:i:s', strtotime($payload['cancelled_at']));
            $order->cancel_reason = $payload['cancel_reason'] ?? null;
        }
        $order->save();

        $synced = false;
        $syncError = null;

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $result = $zohoService->syncRefund($refund);
                $synced = true;
            } catch (\Throwable $e) {
                Log::error("refundsCreate: Zoho refund sync failed for refund ID {$refund->id}: " . $e->getMessage());
                $syncError = $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Refund webhook processed successfully.',
            'refund_id' => $refund->id,
            'shopify_refund_id' => $refund->shopify_refund_id,
            'zoho_creditnote_id' => $refund->zoho_creditnote_id,
            'zoho_synced' => $synced,
            'error' => $syncError,
        ], 200);
    }

    /**
     * Extract phone number from a customer or order payload safely.
     */
    private function extractCustomerPhone(array $payload): ?string
    {
        if (!empty($payload['phone'])) {
            return trim($payload['phone']);
        }
        if (!empty($payload['default_address']['phone'])) {
            return trim($payload['default_address']['phone']);
        }
        if (!empty($payload['billing_address']['phone'])) {
            return trim($payload['billing_address']['phone']);
        }
        if (!empty($payload['shipping_address']['phone'])) {
            return trim($payload['shipping_address']['phone']);
        }
        if (!empty($payload['addresses']) && is_array($payload['addresses'])) {
            foreach ($payload['addresses'] as $addr) {
                if (!empty($addr['phone'])) {
                    return trim($addr['phone']);
                }
            }
        }
        if (!empty($payload['sms_marketing_consent']['phone_number'])) {
            return trim($payload['sms_marketing_consent']['phone_number']);
        }
        return null;
    }

    private function createLocalOrderFromShopifyData(Shop $shop, array $orderData): Order
    {
        $rawOrderId = (string) ($orderData['id'] ?? '');
        $numericOrderId = preg_replace('/^gid:\/\/shopify\/Order\//', '', $rawOrderId);

        $orderNumber = $orderData['name'] ?? (!empty($orderData['order_number']) ? "#{$orderData['order_number']}" : "#{$numericOrderId}");

        $customerId = null;
        if (!empty($orderData['customer']) && is_array($orderData['customer']) && !empty($orderData['customer']['id'])) {
            $custData = $orderData['customer'];
            $rawCustId = (string) $custData['id'];
            $shopifyCustId = str_starts_with($rawCustId, 'gid://') ? $rawCustId : "gid://shopify/Customer/{$rawCustId}";

            $defaultAddr = $custData['default_address'] ?? ($custData['addresses'][0] ?? null);
            $phone = $this->extractCustomerPhone($custData);
            if (empty($phone)) {
                $phone = $this->extractCustomerPhone($orderData);
            }
            $updateData = [
                'first_name' => $custData['first_name'] ?? null,
                'last_name' => $custData['last_name'] ?? null,
                'email' => $custData['email'] ?? ($orderData['email'] ?? null),
                'billing_address' => $orderData['billing_address'] ?? $defaultAddr,
                'shipping_address' => $orderData['shipping_address'] ?? $defaultAddr,
            ];
            if (!empty($phone)) {
                $updateData['phone'] = $phone;
            }

            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_customer_id' => $shopifyCustId,
                ],
                $updateData
            );
            $customerId = $customer->id;
        }

        $lineItems = [];
        if (!empty($orderData['line_items']) && is_array($orderData['line_items'])) {
            foreach ($orderData['line_items'] as $item) {
                $lineItems[] = [
                    'line_item_id' => $item['id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'variant_id' => $item['variant_id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'title' => $item['title'] ?? ($item['name'] ?? null),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0.00),
                ];
            }
        }

        $shippingLines = [];
        $shippingMethod = null;
        if (!empty($orderData['shipping_lines']) && is_array($orderData['shipping_lines'])) {
            foreach ($orderData['shipping_lines'] as $sl) {
                $slTitle = $sl['title'] ?? '';
                $slPrice = (float) ($sl['price'] ?? ($sl['price_set']['shop_money']['amount'] ?? 0.00));
                $shippingLines[] = [
                    'title' => $slTitle,
                    'price' => $slPrice,
                    'code' => $sl['code'] ?? null,
                ];
                if (empty($shippingMethod) && !empty($slTitle)) {
                    $shippingMethod = $slTitle;
                }
            }
        }

        $shippingAddr = $orderData['shipping_address'] ?? null;

        $fulfillments = [];
        $trackingNumber = null;
        $trackingCompany = null;
        $trackingUrl = null;

        if (!empty($orderData['fulfillments']) && is_array($orderData['fulfillments'])) {
            foreach ($orderData['fulfillments'] as $ful) {
                $fNumber = $ful['tracking_number'] ?? (!empty($ful['tracking_numbers'][0]) ? $ful['tracking_numbers'][0] : null);
                $fCompany = $ful['tracking_company'] ?? (!empty($ful['tracking_companies'][0]) ? $ful['tracking_companies'][0] : null);
                $fUrl = $ful['tracking_url'] ?? (!empty($ful['tracking_urls'][0]) ? $ful['tracking_urls'][0] : null);

                if (!empty($fNumber) && empty($trackingNumber)) {
                    $trackingNumber = $fNumber;
                }
                if (!empty($fCompany) && empty($trackingCompany)) {
                    $trackingCompany = $fCompany;
                }
                if (!empty($fUrl) && empty($trackingUrl)) {
                    $trackingUrl = $fUrl;
                }

                $fulfillments[] = [
                    'id' => $ful['id'] ?? null,
                    'status' => $ful['status'] ?? null,
                    'tracking_number' => $fNumber,
                    'tracking_company' => $fCompany,
                    'tracking_url' => $fUrl,
                ];
            }
        }

        $shippingTotal = (float) ($orderData['total_shipping_price_set']['shop_money']['amount'] ?? ($orderData['total_shipping_price'] ?? 0.00));
        if ($shippingTotal === 0.00 && !empty($shippingLines)) {
            $shippingTotal = (float) array_sum(array_column($shippingLines, 'price'));
        }

        return Order::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_order_id' => $numericOrderId,
            ],
            [
                'customer_id' => $customerId,
                'order_number' => $orderNumber,
                'financial_status' => $orderData['financial_status'] ?? 'partially_refunded',
                'fulfillment_status' => $orderData['fulfillment_status'] ?? 'unfulfilled',
                'order_date' => !empty($orderData['created_at']) ? new \DateTime($orderData['created_at']) : now(),
                'currency' => $orderData['currency'] ?? $shop->currency ?? 'USD',
                'subtotal' => (float) ($orderData['subtotal_price'] ?? 0.00),
                'shipping_total' => $shippingTotal,
                'shipping_method' => $shippingMethod,
                'shipping_address' => $shippingAddr,
                'shipping_lines' => $shippingLines,
                'tracking_number' => $trackingNumber,
                'tracking_company' => $trackingCompany,
                'tracking_url' => $trackingUrl,
                'fulfillments' => $fulfillments,
                'total_price' => (float) ($orderData['total_price'] ?? 0.00),
                'line_items' => $lineItems,
            ]
        );
    }

    /**
     * Process deferred pending inventory webhooks for a newly created/mapped variant.
     */
    private function processPendingInventoryWebhooks(Shop $shop, ProductVariant $variant, ?ZohoService $zohoService = null): void
    {
        if (empty($variant->shopify_inventory_item_id)) {
            return;
        }

        $rawId = preg_replace('/^gid:\/\/shopify\/InventoryItem\//', '', $variant->shopify_inventory_item_id);
        $candidateItemIds = array_values(array_filter(array_unique([
            $variant->shopify_inventory_item_id,
            "gid://shopify/InventoryItem/{$rawId}",
            $rawId,
        ])));

        $pendingEvents = PendingInventoryWebhook::where('shop_id', $shop->id)
            ->whereIn('shopify_inventory_item_id', $candidateItemIds)
            ->where('status', 'pending')
            ->get();

        if ($pendingEvents->isEmpty()) {
            return;
        }

        Log::info("Processing " . $pendingEvents->count() . " pending inventory webhooks for variant ID {$variant->id} (Inventory Item: {$variant->shopify_inventory_item_id})");

        $shopifyService = app(ShopifyService::class);
        $liveAggregateQty = $shopifyService->fetchStorewideAvailableQuantity($shop, $variant->shopify_inventory_item_id);

        if ($liveAggregateQty !== null) {
            $variant->inventory_quantity = $liveAggregateQty;
            $variant->save();

            if ($variant->zoho_item_id && $zohoService) {
                try {
                    $zohoService->syncInventory($variant, $liveAggregateQty, 'shopify');
                } catch (\Throwable $e) {
                    Log::error("Failed syncing inventory for variant ID {$variant->id} during pending webhook resolution: " . $e->getMessage());
                }
            }
        }

        foreach ($pendingEvents as $pending) {
            $pending->update(['status' => 'processed']);
        }
    }
}


