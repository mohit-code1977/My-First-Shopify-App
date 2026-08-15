<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoInventorySyncTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;
    private ZohoConnection $connection;

    protected function setUp(): void {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'inventory-test.myshopify.com',
            'access_token' => 'shpat_inv_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'org_inv_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_inventory_sync_skipped_when_zoho_item_id_is_missing(){
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/1',
            'title' => 'Sample Item',
            'handle' => 'sample-item',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/10',
            'title' => 'Default',
            'price' => '10.00',
            'inventory_quantity' => 15,
            'zoho_item_id' => null,
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInventory($variant);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
    }

    public function test_inventory_sync_no_stock_change() {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/2',
            'title' => 'Item No Change',
            'handle' => 'item-no-change',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/20',
            'title' => 'Default',
            'price' => '20.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_200',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_200*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_200',
                    'actual_available_stock' => 10,
                ],
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInventory($variant);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['adjusted']);
        $this->assertEquals(0, $result['delta']);

        // Verify that NO inventory adjustment call was sent
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/inventoryadjustments');
        });
    }

    public function test_inventory_sync_stock_increase() {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/3',
            'title' => 'Item Increase',
            'handle' => 'item-increase',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/30',
            'title' => 'Default',
            'price' => '30.00',
            'inventory_quantity' => 25,
            'zoho_item_id' => 'zoho_item_300',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_300*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_300',
                    'actual_available_stock' => 15,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'The inventory adjustment has been created.',
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInventory($variant);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['adjusted']);
        $this->assertEquals(10, $result['delta']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/inventoryadjustments') &&
                $request->data()['line_items'][0]['item_id'] === 'zoho_item_300' &&
                $request->data()['line_items'][0]['quantity_adjusted'] === 10;
        });
    }

    public function test_inventory_sync_stock_decrease() {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/4',
            'title' => 'Item Decrease',
            'handle' => 'item-decrease',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/40',
            'title' => 'Default',
            'price' => '40.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'zoho_item_400',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_400*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_400',
                    'actual_available_stock' => 12,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'The inventory adjustment has been created.',
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInventory($variant);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['adjusted']);
        $this->assertEquals(-7, $result['delta']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/inventoryadjustments') &&
                $request->data()['line_items'][0]['item_id'] === 'zoho_item_400' &&
                $request->data()['line_items'][0]['quantity_adjusted'] === -7;
        });
    }

    public function test_inventory_sync_handles_zoho_api_failure() {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/5',
            'title' => 'Item Fail',
            'handle' => 'item-fail',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/50',
            'title' => 'Default',
            'price' => '50.00',
            'inventory_quantity' => 20,
            'zoho_item_id' => 'zoho_item_500',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_500*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_500',
                    'actual_available_stock' => 10,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 1000,
                'message' => 'Internal Server Error in Zoho',
            ], 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Zoho API request failed');

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncInventory($variant);
    }

    private function calculateHmac(string $payload, string $secret): string {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    public function test_inventory_webhook_stock_increase() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Webhook Product',
            'handle' => 'webhook-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90001',
            'title' => 'Default',
            'price' => '25.00',
            'inventory_quantity' => 15,
            'zoho_item_id' => 'zoho_item_1001',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_1001*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_1001',
                    'actual_available_stock' => 15,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'The inventory adjustment has been created.',
            ], 200),
        ]);

        $payload = json_encode([
            'inventory_item_id' => '90001',
            'location_id' => 888,
            'available' => 25,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_inv_001',
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Inventory level update webhook processed successfully.',
            'summary' => [
                'processed' => 1,
                'inventory_synced' => 1,
                'inventory_failures' => 0,
                'skipped_unmapped' => 0,
            ],
        ]);

        $variant->refresh();
        $this->assertEquals(25, $variant->inventory_quantity);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/inventoryadjustments') &&
                $request->data()['line_items'][0]['item_id'] === 'zoho_item_1001' &&
                $request->data()['line_items'][0]['quantity_adjusted'] === 10;
        });
    }

    public function test_inventory_webhook_stock_decrease() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/200',
            'title' => 'Webhook Product 2',
            'handle' => 'webhook-product-2',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90002',
            'title' => 'Default',
            'price' => '30.00',
            'inventory_quantity' => 12,
            'zoho_item_id' => 'zoho_item_2001',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_2001*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_2001',
                    'actual_available_stock' => 12,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'The inventory adjustment has been created.',
            ], 200),
        ]);

        $payload = json_encode([
            'inventory_item_id' => '90002',
            'location_id' => 888,
            'available' => 5,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_inv_002',
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Inventory level update webhook processed successfully.',
            'summary' => [
                'processed' => 1,
                'inventory_synced' => 1,
                'inventory_failures' => 0,
                'skipped_unmapped' => 0,
            ],
        ]);

        $variant->refresh();
        $this->assertEquals(5, $variant->inventory_quantity);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/inventoryadjustments') &&
                $request->data()['line_items'][0]['item_id'] === 'zoho_item_2001' &&
                $request->data()['line_items'][0]['quantity_adjusted'] === -7;
        });
    }

    public function test_inventory_webhook_no_stock_change() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/300',
            'title' => 'Webhook Product 3',
            'handle' => 'webhook-product-3',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/3001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90003',
            'title' => 'Default',
            'price' => '40.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_3001',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_3001*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_3001',
                    'actual_available_stock' => 10,
                ],
            ], 200),
        ]);

        $payload = json_encode([
            'inventory_item_id' => '90003',
            'location_id' => 888,
            'available' => 10,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_inv_003',
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Inventory level update webhook processed successfully.',
            'summary' => [
                'processed' => 1,
                'inventory_synced' => 1,
                'inventory_failures' => 0,
                'skipped_unmapped' => 0,
            ],
        ]);

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/inventoryadjustments');
        });
    }

    public function test_inventory_webhook_unmapped_variant() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $payload = json_encode([
            'inventory_item_id' => '9999999',
            'location_id' => 888,
            'available' => 50,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_inv_004',
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Variant not found or unmapped for this inventory item.',
            'summary' => [
                'processed' => 0,
                'skipped_unmapped' => 1,
            ],
        ]);
    }

    public function test_inventory_webhook_handles_zoho_failure() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/500',
            'title' => 'Webhook Product 5',
            'handle' => 'webhook-product-5',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90005',
            'title' => 'Default',
            'price' => '50.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_5001',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_5001*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_5001',
                    'actual_available_stock' => 10,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 1000,
                'message' => 'Internal Server Error in Zoho',
            ], 500),
        ]);

        $payload = json_encode([
            'inventory_item_id' => '90005',
            'location_id' => 888,
            'available' => 30,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_inv_005',
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Failed to synchronize inventory level with Zoho.',
            'summary' => [
                'processed' => 1,
                'inventory_synced' => 0,
                'inventory_failures' => 1,
            ],
        ]);
    }

    public function test_inventory_webhook_idempotency() {
        config(['services.shopify.api_secret' => 'test_secret']);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/600',
            'title' => 'Webhook Product 6',
            'handle' => 'webhook-product-6',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/6001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90006',
            'title' => 'Default',
            'price' => '60.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_6001',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_6001*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_6001',
                    'actual_available_stock' => 10,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'The inventory adjustment has been created.',
            ], 200),
        ]);

        $payload = json_encode([
            'inventory_item_id' => '90006',
            'location_id' => 888,
            'available' => 20,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');
        $webhookId = 'webhook_inv_006_duplicate';

        // Delivery 1
        $response1 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => $webhookId,
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response1->assertStatus(200);
        $response1->assertJsonPath('summary.inventory_synced', 1);

        // Delivery 2 (duplicate)
        $response2 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => $webhookId,
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Webhook already processed.',
            'webhook_id' => $webhookId,
        ]);

        Http::assertSentCount(2); // 1 GET item + 1 POST inventoryadjustments
    }

    public function test_inventory_webhook_resolves_different_inventory_item_id_and_variant_id()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/700',
            'title' => 'Different ID Product',
            'handle' => 'different-id-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/12345',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/67890',
            'title' => 'Variant With Separate Inventory ID',
            'price' => '99.99',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_700',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_700*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_700',
                    'actual_available_stock' => 10,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'The inventory adjustment has been created.',
            ], 200),
        ]);

        // Webhook payload sends inventory_item_id = 67890 (which does NOT match variant ID 12345)
        $payload = json_encode([
            'inventory_item_id' => '67890',
            'location_id' => 888,
            'available' => 45,
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_inv_diff_007',
            'X-Shopify-Topic' => 'inventory_levels/update',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/inventory-levels', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Inventory level update webhook processed successfully.',
            'summary' => [
                'processed' => 1,
                'inventory_synced' => 1,
                'inventory_failures' => 0,
                'skipped_unmapped' => 0,
            ],
        ]);

        $variant->refresh();
        $this->assertEquals(45, $variant->inventory_quantity);
        $this->assertEquals('gid://shopify/ProductVariant/12345', $variant->shopify_variant_id);
        $this->assertEquals('gid://shopify/InventoryItem/67890', $variant->shopify_inventory_item_id);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/inventoryadjustments') &&
                $request->data()['line_items'][0]['item_id'] === 'zoho_item_700' &&
                $request->data()['line_items'][0]['quantity_adjusted'] === 35;
        });
    }

    public function test_sync_zoho_inventory_to_shopify_success()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/800',
            'title' => 'Zoho to Shopify Sync Product',
            'handle' => 'zoho-to-shopify-sync-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/8001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90008',
            'title' => 'Default',
            'price' => '100.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_800',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_800*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_800',
                    'actual_available_stock' => 50,
                ],
            ], 200),
            "https://{$this->shop->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
                'data' => [
                    'locations' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Location/888',
                                'name' => 'Primary Location',
                                'isPrimary' => true,
                                'isActive' => true,
                            ],
                        ],
                    ],
                    'inventorySetQuantities' => [
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_123'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncZohoInventoryToShopify($variant);

        $this->assertTrue($result['success']);
        $this->assertEquals(50, $result['zoho_quantity']);
        $this->assertEquals(50, $result['shopify_quantity']);
        $this->assertEquals(50, $variant->fresh()->inventory_quantity);
    }

    public function test_sync_zoho_inventory_to_shopify_prevents_overselling_by_clamping_negative_stock()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/801',
            'title' => 'Negative Stock Product',
            'handle' => 'negative-stock-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/8011',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90009',
            'title' => 'Default',
            'price' => '50.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'zoho_item_801',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_801*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_801',
                    'actual_available_stock' => -10, // Negative stock in Zoho
                ],
            ], 200),
            "https://{$this->shop->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
                'data' => [
                    'locations' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Location/888',
                                'name' => 'Primary Location',
                                'isPrimary' => true,
                                'isActive' => true,
                            ],
                        ],
                    ],
                    'inventorySetQuantities' => [
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_456'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncZohoInventoryToShopify($variant);

        $this->assertTrue($result['success']);
        $this->assertEquals(-10, $result['zoho_quantity']);
        $this->assertEquals(0, $result['shopify_quantity']); // Clamped to 0 to prevent overselling
        $this->assertEquals(0, $variant->fresh()->inventory_quantity);
    }

    public function test_sync_zoho_inventory_to_shopify_skipped_when_unmapped()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/802',
            'title' => 'Unmapped Product',
            'handle' => 'unmapped-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/8021',
            'title' => 'Default',
            'price' => '10.00',
            'inventory_quantity' => 5,
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncZohoInventoryToShopify($variant);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
    }
}

