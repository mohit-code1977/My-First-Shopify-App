<?php

namespace Tests\Unit;

use App\Http\Controllers\ShopifyWebhookController;
use App\Models\PendingInventoryWebhook;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyInventoryWebhookRaceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private string $secret = 'test_secret_key_123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_secret' => $this->secret]);

        $this->shop = Shop::create([
            'shop_domain' => 'webhook-race-test.myshopify.com',
            'access_token' => 'shpat_race_token',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'org_race_100',
            'access_token' => 'race_zoho_token',
            'refresh_token' => 'race_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
            'inventory_capability' => ZohoService::CAPABILITY_ZOHO_INVENTORY,
        ]);
    }

    private function generateHmac(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, $this->secret, true));
    }

    public function test_inventory_webhook_arrives_before_product_mapping_defers_to_pending(): void
    {
        $payload = json_encode([
            'inventory_item_id' => 49286747455656,
            'location_id' => 85347401896,
            'available' => 25,
        ]);

        $request = Request::create(
            '/webhooks/inventory-levels',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_inv_race_001',
                'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->generateHmac($payload),
            ],
            $payload
        );

        $controller = new ShopifyWebhookController();
        $response = $controller->inventoryLevelsUpdate($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('deferred as pending', $response->getData()->message);

        $pending = PendingInventoryWebhook::where('shop_id', $this->shop->id)
            ->where('shopify_inventory_item_id', 'gid://shopify/InventoryItem/49286747455656')
            ->first();

        $this->assertNotNull($pending);
        $this->assertEquals('pending', $pending->status);
        $this->assertEquals(25, $pending->available_quantity);
    }

    public function test_product_update_creates_variant_and_resolves_deferred_pending_inventory_webhook(): void
    {
        // 1. Create deferred pending inventory webhook
        PendingInventoryWebhook::create([
            'shop_id' => $this->shop->id,
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/49286747455656',
            'webhook_id' => 'wh_inv_race_002',
            'available_quantity' => 25,
            'status' => 'pending',
        ]);

        Http::fake([
            // Shopify Storewide Aggregate Inventory Query
            'https://webhook-race-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/49286747455656',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'location' => ['id' => 'gid://shopify/Location/1', 'isActive' => true],
                                    'quantities' => [['name' => 'available', 'quantity' => 25]],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            // Zoho API setup
            'https://www.zohoapis.com/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [['field_id' => 'cf_shopify_variant_id', 'api_name' => 'cf_shopify_variant_id']],
            ], 200),
            'https://www.zohoapis.com/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [['account_id' => '4081216000000000626', 'account_name' => 'Inventory Asset', 'is_active' => true]],
            ], 200),
            'https://www.zohoapis.com/books/v3/items/item_torch_1*' => Http::response([
                'code' => 0,
                'item' => ['item_id' => 'item_torch_1', 'track_inventory' => true, 'actual_available_stock' => 0],
            ], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response([
                'code' => 0,
                'item' => ['item_id' => 'item_torch_1', 'track_inventory' => true, 'stock_on_hand' => 25],
            ], 201),
            'https://www.zohoapis.com/inventory/v1/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'Adjustment success',
            ], 200),
        ]);

        // 2. products/update payload for Duracell LED Torch Light
        $payload = json_encode([
            'id' => 9577316122792,
            'title' => 'Duracell LED Torch Light',
            'handle' => 'duracell-led-torch-light',
            'variants' => [
                [
                    'id' => 55889453645992,
                    'title' => 'Default Title',
                    'price' => '15.00',
                    'sku' => 'DURA-TORCH-01',
                    'inventory_item_id' => 49286747455656,
                    'inventory_quantity' => 0,
                ],
            ],
        ]);

        $request = Request::create(
            '/webhooks/products',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_race_002',
                'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->generateHmac($payload),
            ],
            $payload
        );

        $controller = new ShopifyWebhookController();
        $response = $controller->productsUpdate($request);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify variant was created
        $variant = ProductVariant::where('shopify_inventory_item_id', 'gid://shopify/InventoryItem/49286747455656')->first();
        $this->assertNotNull($variant);
        $this->assertEquals(25, $variant->inventory_quantity);
        $this->assertEquals('item_torch_1', $variant->zoho_item_id);

        // Verify pending webhook was resolved to processed status
        $pending = PendingInventoryWebhook::where('webhook_id', 'wh_inv_race_002')->first();
        $this->assertEquals('processed', $pending->status);
    }

    public function test_duplicate_inventory_webhook_is_idempotent(): void
    {
        ShopifyProcessedWebhook::create([
            'webhook_id' => 'wh_inv_dup_001',
            'topic' => 'inventory_levels/update',
            'shop_domain' => $this->shop->shop_domain,
        ]);

        $payload = json_encode([
            'inventory_item_id' => 49286747455656,
            'available' => 25,
        ]);

        $request = Request::create(
            '/webhooks/inventory-levels',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_inv_dup_001', // Duplicate!
                'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->generateHmac($payload),
            ],
            $payload
        );

        $controller = new ShopifyWebhookController();
        $response = $controller->inventoryLevelsUpdate($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Webhook already processed', $response->getData()->message);
    }

    public function test_tenant_isolation_prevents_cross_shop_pending_webhook_bleed(): void
    {
        $otherShop = Shop::create([
            'shop_domain' => 'other-shop.myshopify.com',
            'access_token' => 'shpat_other_token',
        ]);

        PendingInventoryWebhook::create([
            'shop_id' => $otherShop->id,
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/49286747455656',
            'webhook_id' => 'wh_inv_other_001',
            'available_quantity' => 10,
            'status' => 'pending',
        ]);

        // Creating product on $this->shop should NOT process $otherShop's pending webhook
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/999',
            'title' => 'Shop 1 Product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'title' => 'Default Title',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/999',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/49286747455656',
            'inventory_quantity' => 0,
        ]);

        $otherPending = PendingInventoryWebhook::where('webhook_id', 'wh_inv_other_001')->first();
        $this->assertEquals('pending', $otherPending->status);
    }
}
