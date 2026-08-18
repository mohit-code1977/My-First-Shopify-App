<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Models\SyncHistory;
use App\Services\ZohoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZohoWebhookController extends Controller
{
    /**
     * Handle incoming Zoho inventory / stock update webhook events.
     */
    public function inventoryUpdate(Request $request): JsonResponse
    {
        Log::info('Zoho inventory update webhook received', [
            'shop' => $request->query('shop') ?? $request->header('X-Shop-Domain'),
            'webhook_id' => $request->header('X-Zoho-Webhook-Id') ?? $request->header('X-Event-Id'),
            'content' => $request->getContent(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. Security / Token Validation
        |--------------------------------------------------------------------------
        */
        $configuredSecret = config('services.zoho.webhook_secret');

        if (!empty($configuredSecret)) {
            $token = $request->query('token')
                ?? $request->query('secret')
                ?? $request->header('X-Zoho-Webhook-Token')
                ?? (str_starts_with((string) $request->header('Authorization'), 'Bearer ')
                    ? substr((string) $request->header('Authorization'), 7)
                    : null);

            if (empty($token) || !hash_equals((string) $configuredSecret, (string) $token)) {
                return response()->json(['error' => 'Invalid or missing Zoho webhook security token.'], 401);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Parse Webhook Payload (JSON or JSONString form parameter)
        |--------------------------------------------------------------------------
        */
        $payload = [];
        if ($request->has('JSONString')) {
            $jsonRaw = $request->input('JSONString');
            $payload = is_string($jsonRaw) ? json_decode($jsonRaw, true) : (array) $jsonRaw;
        } else {
            $payload = $request->json()->all();
            if (empty($payload)) {
                $payload = $request->all();
            }
        }

        if (!is_array($payload)) {
            return response()->json(['error' => 'Invalid JSON payload in Zoho webhook.'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Check Webhook Idempotency
        |--------------------------------------------------------------------------
        */
        $webhookId = $request->header('X-Zoho-Webhook-Id') ?? $request->header('X-Event-Id');

        if (empty($webhookId)) {
            $adjustmentId = $payload['inventory_adjustment']['inventory_adjustment_id'] ?? null;
            $lastModifiedTime = $payload['inventory_adjustment']['last_modified_time']
                ?? $payload['last_modified_time']
                ?? null;

            if (!empty($adjustmentId)) {
                if (!empty($lastModifiedTime)) {
                    $webhookId = "zoho.inventory.adjustment.{$adjustmentId}.{$lastModifiedTime}";
                } else {
                    $webhookId = "zoho.inventory.adjustment.{$adjustmentId}." . md5(json_encode($payload));
                }
            } else {
                $rawEventId = $payload['event_id'] ?? $payload['webhook_id'] ?? $payload['id'] ?? null;
                if (!empty($rawEventId)) {
                    $webhookId = (string) $rawEventId;
                } else {
                    $webhookId = "zoho.inventory.payload." . md5(json_encode($payload));
                }
            }
        }

        if ($webhookId) {
            if (ShopifyProcessedWebhook::where('webhook_id', (string) $webhookId)->exists()) {
                return response()->json([
                    'message' => 'Webhook already processed.',
                    'webhook_id' => (string) $webhookId,
                ], 200);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Extract Line Items
        |--------------------------------------------------------------------------
        */
        $lineItems = [];

        if (!empty($payload['inventory_adjustment']['line_items']) && is_array($payload['inventory_adjustment']['line_items'])) {
            $lineItems = $payload['inventory_adjustment']['line_items'];
        } elseif (!empty($payload['line_items']) && is_array($payload['line_items'])) {
            $lineItems = $payload['line_items'];
        } else {
            $singleItemId = (string) (
                $payload['item']['item_id']
                ?? $payload['item_id']
                ?? $payload['entity_id']
                ?? $payload['item']['id']
                ?? ''
            );
            if (!empty($singleItemId)) {
                $lineItems = [['item_id' => $singleItemId]];
            }
        }

        if (empty($lineItems)) {
            return response()->json(['error' => 'Missing zoho_item_id or line_items in webhook payload.'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Process Line Items and Resolve Tenant
        |--------------------------------------------------------------------------
        */
        $shopDomain = $request->query('shop')
            ?? $request->header('X-Shop-Domain')
            ?? $request->header('X-Shopify-Shop-Domain')
            ?? ($payload['shop'] ?? null);

        $results = [];
        $skippedItems = [];
        $processedCount = 0;
        $resolvedShop = null;

        foreach ($lineItems as $lineItem) {
            $zohoItemId = (string) ($lineItem['item_id'] ?? $lineItem['id'] ?? '');
            if (empty($zohoItemId)) {
                continue;
            }

            Log::info("Zoho inventoryUpdate: Processing item zoho_item_id '{$zohoItemId}'");

            $variantQuery = ProductVariant::where('zoho_item_id', $zohoItemId);

            if (!empty($shopDomain)) {
                $variantQuery->whereHas('product.shop', function ($query) use ($shopDomain) {
                    $query->where('shop_domain', $shopDomain);
                });
            }

            $variant = $variantQuery->first();

            if (!$variant) {
                Log::info("Zoho inventoryUpdate: No mapped ProductVariant found for zoho_item_id '{$zohoItemId}'");

                $shop = !empty($shopDomain) ? Shop::where('shop_domain', $shopDomain)->first() : null;
                if ($shop) {
                    SyncHistory::create([
                        'shop_id' => $shop->id,
                        'action' => 'inventory_update',
                        'status' => 'skipped',
                        'zoho_item_id' => $zohoItemId,
                        'message' => "Zoho webhook skipped: No variant mapped to zoho_item_id {$zohoItemId}",
                        'synced_at' => now(),
                    ]);
                }

                $skippedItems[] = [
                    'zoho_item_id' => $zohoItemId,
                    'reason' => 'Variant not found or unmapped for this Zoho item.',
                ];
                continue;
            }

            $shop = $variant->product->shop;
            if (!$resolvedShop) {
                $resolvedShop = $shop;
            }

            try {
                $zohoService = new ZohoService($shop);
                $syncResult = $zohoService->syncZohoInventoryToShopify($variant);
                $processedCount++;

                $results[] = [
                    'zoho_item_id' => $zohoItemId,
                    'variant_id' => $variant->id,
                    'success' => !empty($syncResult['success']),
                    'data' => $syncResult,
                ];
            } catch (\Throwable $e) {
                Log::error("Zoho inventoryUpdate: Error syncing variant ID {$variant->id}: " . $e->getMessage());

                $results[] = [
                    'zoho_item_id' => $zohoItemId,
                    'variant_id' => $variant->id,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Record Webhook Delivery Idempotency Record
        |--------------------------------------------------------------------------
        */
        if ($webhookId) {
            ShopifyProcessedWebhook::create([
                'webhook_id' => (string) $webhookId,
                'topic' => 'zoho.inventory.update',
                'shop_domain' => $resolvedShop ? $resolvedShop->shop_domain : $shopDomain,
            ]);
        }

        $message = $processedCount > 0
            ? 'Zoho inventory webhook processed successfully.'
            : 'No mapped variants found for the Zoho inventory webhook items.';

        return response()->json([
            'message' => $message,
            'processed_count' => $processedCount,
            'results' => $results,
            'skipped_items' => $skippedItems,
        ], 200);
    }
}
