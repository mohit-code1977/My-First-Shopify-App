<?php

namespace App\Http\Controllers;

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

        if ($product) {
            $product->update([
                'title' => $payload['title'] ?? $product->title,
                'handle' => $payload['handle'] ?? $product->handle,
            ]);
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
                $variant->update([
                    'title' => $vData['title'] ?? $variant->title,
                    'sku' => array_key_exists('sku', $vData) ? $vData['sku'] : $variant->sku,
                    'price' => $vData['price'] ?? $variant->price,
                    'inventory_quantity' => $vData['inventory_quantity'] ?? $variant->inventory_quantity,
                ]);

                // Zoho sync if zoho_item_id exists
                if ($variant->zoho_item_id) {
                    if ($zohoService) {
                        try {
                            $zohoService->updateItem($variant);
                            $summary['synced_to_zoho']++;
                        } catch (\Throwable $e) {
                            Log::error("Zoho update failed for variant ID {$variant->id}: " . $e->getMessage());
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
}
