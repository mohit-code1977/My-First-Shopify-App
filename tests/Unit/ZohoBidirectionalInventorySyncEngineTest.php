<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ShopifyService;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoBidirectionalInventorySyncEngineTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private ZohoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'bidirectional-sync-test.myshopify.com',
            'access_token' => 'shpat_test_token',
        ]);

        $this->connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'org_bidirectional_100',
            'access_token' => 'zoho_access_token',
            'refresh_token' => 'zoho_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
            'inventory_capability' => ZohoService::CAPABILITY_ZOHO_INVENTORY,
        ]);
    }

    public function test_shopify_to_zoho_sync_updates_zoho_and_saves_last_sync_metadata(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/items/item_bi_1*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'item_bi_1',
                    'track_inventory' => true,
                    'actual_available_stock' => 10,
                ],
            ], 200),
            'https://www.zohoapis.com/inventory/v1/inventoryadjustments*' => Http::response([
                'code' => 0,
                'message' => 'Adjustment successful',
            ], 200),
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Bi-Sync Widget',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/5001',
            'title' => 'Default',
            'inventory_quantity' => 15,
            'zoho_item_id' => 'item_bi_1',
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncInventory($variant, 15, 'shopify');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['adjusted']);
        $this->assertEquals(5, $result['delta']);

        $variant->refresh();
        $this->assertEquals(15, $variant->inventory_quantity);
        $this->assertEquals(15, $variant->last_synced_quantity);
        $this->assertEquals('shopify', $variant->last_sync_source);
        $this->assertEquals(1, $variant->inventory_sync_version);
    }

    public function test_loop_prevention_ignores_self_generated_shopify_echo(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Echo Test Widget',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1002',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/5002',
            'title' => 'Default',
            'inventory_quantity' => 20,
            'last_synced_quantity' => 20,
            'last_sync_source' => 'zoho', // Originated from Zoho sync!
            'zoho_item_id' => 'item_bi_2',
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncInventory($variant, 20, 'shopify');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['adjusted']);
        $this->assertStringContainsString('Self-generated inventory change ignored', $result['message']);
    }

    public function test_loop_prevention_ignores_self_generated_zoho_echo(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/items/item_bi_3*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'item_bi_3',
                    'track_inventory' => true,
                    'actual_available_stock' => 25,
                ],
            ], 200),
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/102',
            'title' => 'Zoho Echo Widget',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1003',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/5003',
            'title' => 'Default',
            'inventory_quantity' => 25,
            'last_synced_quantity' => 25,
            'last_sync_source' => 'shopify', // Originated from Shopify sync!
            'zoho_item_id' => 'item_bi_3',
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncZohoInventoryToShopify($variant, null, 'zoho');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('Self-generated inventory change ignored', $result['message']);
    }

    public function test_storewide_available_quantity_aggregation(): void
    {
        Http::fake([
            'https://bidirectional-sync-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/5004',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'location' => ['id' => 'gid://shopify/Location/1', 'isActive' => true],
                                    'quantities' => [['name' => 'available', 'quantity' => 12]],
                                ],
                                [
                                    'location' => ['id' => 'gid://shopify/Location/2', 'isActive' => true],
                                    'quantities' => [['name' => 'available', 'quantity' => 8]],
                                ],
                                [
                                    'location' => ['id' => 'gid://shopify/Location/3', 'isActive' => false], // Inactive!
                                    'quantities' => [['name' => 'available', 'quantity' => 100]],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $shopifyService = new ShopifyService();
        $totalAvailable = $shopifyService->fetchStorewideAvailableQuantity($this->shop, 'gid://shopify/InventoryItem/5004');

        $this->assertEquals(20, $totalAvailable); // 12 + 8 = 20 (inactive location 100 ignored)
    }

    public function test_dynamic_inventory_asset_account_resolution(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    [
                        'account_id' => '4081216000000000999',
                        'account_name' => 'Inventory Asset Test',
                        'is_active' => true,
                    ],
                ],
            ], 200),
        ]);

        $service = new ZohoService($this->shop);
        $accountId = $service->getInventoryAssetAccountId();

        $this->assertEquals('4081216000000000999', $accountId);
    }

    public function test_legacy_non_tracked_item_reconciliation(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    [
                        'account_id' => '4081216000000000626',
                        'account_name' => 'Inventory Asset',
                        'is_active' => true,
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    [
                        'field_id' => 'cf_shopify_variant_id',
                        'api_name' => 'cf_shopify_variant_id',
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/items/item_legacy_1*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'item_legacy_1',
                    'name' => 'Dekorly Artificial Potted Plants',
                    'track_inventory' => false, // Legacy non-tracked!
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/items*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'item' => [
                            'item_id' => 'item_tracked_new_2',
                            'name' => 'Dekorly Artificial Potted Plants - Default',
                            'track_inventory' => true,
                            'stock_on_hand' => 20,
                        ],
                    ], 201);
                }
                return Http::response([
                    'code' => 0,
                    'items' => [],
                ], 200);
            },
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/50',
            'title' => 'Dekorly Artificial Potted Plants',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/50',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/50',
            'title' => 'Default',
            'price' => 11.00,
            'inventory_quantity' => 20,
            'zoho_item_id' => 'item_legacy_1', // legacy non-tracked
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->reconcileLegacyNonTrackedItem($variant);

        $this->assertTrue($result['created']);
        $this->assertEquals('item_tracked_new_2', $result['zoho_item_id']);

        $variant->refresh();
        $this->assertEquals('item_tracked_new_2', $variant->zoho_item_id);
    }
}
