<?php

namespace Tests\Unit;

use App\Http\Controllers\ShopifyWebhookController;
use App\Models\PendingInventoryWebhook;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyInitialInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'test-shop.myshopify.com',
            'access_token' => 'shpat_test_token_123',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token_123',
            'refresh_token' => 'zoho_refresh_token_123',
            'organization_id' => '4081216000000000100',
            'api_url' => 'https://www.zohoapis.com',
            'api_domain' => 'www.zohoapis.com',
            'environment' => 'production',
            'expires_at' => now()->addHour(),
            'inventory_capability' => ZohoService::CAPABILITY_ZOHO_INVENTORY,
        ]);

        config(['services.shopify.api_secret' => 'test_secret_key']);
    }

    private function createHmacHeader(string $payload): string
    {
        return base64_encode(hash_hmac('sha256', $payload, 'test_secret_key', true));
    }

    public function test_new_tracked_product_with_shopify_stock_25_initializes_zoho_with_25(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/1001',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/InventoryLevel/1001_loc1',
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 25],
                                    ],
                                    'location' => [
                                        'id' => 'gid://shopify/Location/loc1',
                                        'name' => 'Primary Location',
                                        'isActive' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            '*zoho*/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    ['field_id' => '999', 'api_name' => 'cf_shopify_variant_id'],
                ],
            ]),
            '*zoho*/books/v3/chartofaccounts*' => Http::response([
                'code' => 0,
                'chartofaccounts' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'account_type' => 'inventory'],
                ],
            ]),
            '*zoho*/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'is_active' => true],
                ],
            ]),
            '*zoho*/books/v3/items*' => function (ClientRequest $request) {
                if ($request->method() === 'POST') {
                    $data = json_decode($request->body(), true) ?? [];
                    $this->assertEquals(25, $data['initial_stock'] ?? null);
                    $this->assertEquals(true, $data['track_inventory'] ?? null);
                    return Http::response([
                        'code' => 0,
                        'message' => 'Item created',
                        'item' => [
                            'item_id' => 'zoho_item_25',
                            'name' => $data['name'],
                            'stock_on_hand' => 25.0,
                            'track_inventory' => true,
                        ],
                    ], 200);
                }

                return Http::response(['code' => 0, 'items' => []], 200);
            },
            '*zoho*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $payload = json_encode([
            'id' => 9001,
            'admin_graphql_api_id' => 'gid://shopify/Product/9001',
            'title' => 'Tracked Product 25',
            'variants' => [
                [
                    'id' => 90011,
                    'admin_graphql_api_id' => 'gid://shopify/ProductVariant/90011',
                    'title' => 'Default Title',
                    'price' => '25.00',
                    'inventory_item_id' => 1001,
                    'inventory_quantity' => 0,
                ],
            ],
        ]);

        $request = Request::create('/webhooks/products', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_25',
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($payload),
        ], $payload);

        $controller = new ShopifyWebhookController();
        $response = $controller->productsUpdate($request);

        $this->assertEquals(200, $response->getStatusCode());

        $variant = ProductVariant::where('shopify_variant_id', 'gid://shopify/ProductVariant/90011')->first();
        $this->assertNotNull($variant);
        $this->assertEquals(25, $variant->inventory_quantity);
        $this->assertEquals('zoho_item_25', $variant->zoho_item_id);
        $this->assertEquals(25, $variant->last_synced_quantity);
        $this->assertEquals('shopify', $variant->last_sync_source);
    }

    public function test_new_tracked_product_with_shopify_stock_0_initializes_zoho_with_0(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/1002',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/InventoryLevel/1002_loc1',
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 0],
                                    ],
                                    'location' => [
                                        'id' => 'gid://shopify/Location/loc1',
                                        'name' => 'Primary Location',
                                        'isActive' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            '*zoho*/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    ['field_id' => '999', 'api_name' => 'cf_shopify_variant_id'],
                ],
            ]),
            '*zoho*/books/v3/chartofaccounts*' => Http::response([
                'code' => 0,
                'chartofaccounts' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'account_type' => 'inventory'],
                ],
            ]),
            '*zoho*/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'is_active' => true],
                ],
            ]),
            '*zoho*/books/v3/items*' => function (ClientRequest $request) {
                if ($request->method() === 'POST') {
                    $data = json_decode($request->body(), true) ?? [];
                    $this->assertEquals(0, $data['initial_stock'] ?? null);
                    return Http::response([
                        'code' => 0,
                        'item' => [
                            'item_id' => 'zoho_item_0',
                            'name' => $data['name'],
                            'stock_on_hand' => 0.0,
                            'track_inventory' => true,
                        ],
                    ], 200);
                }

                return Http::response(['code' => 0, 'items' => []], 200);
            },
            '*zoho*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $payload = json_encode([
            'id' => 9002,
            'admin_graphql_api_id' => 'gid://shopify/Product/9002',
            'title' => 'Zero Stock Product',
            'variants' => [
                [
                    'id' => 90022,
                    'admin_graphql_api_id' => 'gid://shopify/ProductVariant/90022',
                    'title' => 'Default Title',
                    'price' => '10.00',
                    'inventory_item_id' => 1002,
                    'inventory_quantity' => 0,
                ],
            ],
        ]);

        $request = Request::create('/webhooks/products', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_0',
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($payload),
        ], $payload);

        $controller = new ShopifyWebhookController();
        $response = $controller->productsUpdate($request);

        $this->assertEquals(200, $response->getStatusCode());

        $variant = ProductVariant::where('shopify_variant_id', 'gid://shopify/ProductVariant/90022')->first();
        $this->assertEquals(0, $variant->inventory_quantity);
        $this->assertEquals('zoho_item_0', $variant->zoho_item_id);
    }

    public function test_new_tracked_product_with_multiple_locations_uses_aggregate_available(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/1003',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/InventoryLevel/1003_loc1',
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 15],
                                    ],
                                    'location' => [
                                        'id' => 'gid://shopify/Location/loc1',
                                        'name' => 'Warehouse A',
                                        'isActive' => true,
                                    ],
                                ],
                                [
                                    'id' => 'gid://shopify/InventoryLevel/1003_loc2',
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 10],
                                    ],
                                    'location' => [
                                        'id' => 'gid://shopify/Location/loc2',
                                        'name' => 'Warehouse B',
                                        'isActive' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            '*zoho*/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    ['field_id' => '999', 'api_name' => 'cf_shopify_variant_id'],
                ],
            ]),
            '*zoho*/books/v3/chartofaccounts*' => Http::response([
                'code' => 0,
                'chartofaccounts' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'account_type' => 'inventory'],
                ],
            ]),
            '*zoho*/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'is_active' => true],
                ],
            ]),
            '*zoho*/books/v3/items*' => function (ClientRequest $request) {
                if ($request->method() === 'POST') {
                    $data = json_decode($request->body(), true) ?? [];
                    $this->assertEquals(25, $data['initial_stock'] ?? null);
                    return Http::response([
                        'code' => 0,
                        'item' => [
                            'item_id' => 'zoho_item_multi_loc',
                            'name' => $data['name'],
                            'stock_on_hand' => 25.0,
                            'track_inventory' => true,
                        ],
                    ], 200);
                }

                return Http::response(['code' => 0, 'items' => []], 200);
            },
            '*zoho*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $payload = json_encode([
            'id' => 9003,
            'admin_graphql_api_id' => 'gid://shopify/Product/9003',
            'title' => 'Multi Location Product',
            'variants' => [
                [
                    'id' => 90033,
                    'admin_graphql_api_id' => 'gid://shopify/ProductVariant/90033',
                    'title' => 'Default Title',
                    'price' => '50.00',
                    'inventory_item_id' => 1003,
                    'inventory_quantity' => 0,
                ],
            ],
        ]);

        $request = Request::create('/webhooks/products', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_multi',
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($payload),
        ], $payload);

        $controller = new ShopifyWebhookController();
        $response = $controller->productsUpdate($request);

        $this->assertEquals(200, $response->getStatusCode());

        $variant = ProductVariant::where('shopify_variant_id', 'gid://shopify/ProductVariant/90033')->first();
        $this->assertEquals(25, $variant->inventory_quantity);
    }

    public function test_inventory_webhook_arrives_before_variant_mapping_initializes_stock_once(): void
    {
        // 1. Inventory webhook arrives before variant creation
        $invPayload = json_encode([
            'inventory_item_id' => 1004,
            'available' => 25,
        ]);

        $invRequest = Request::create('/webhooks/inventory-levels', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_inv_early_1004',
            'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($invPayload),
        ], $invPayload);

        $controller = new ShopifyWebhookController();
        $invResponse = $controller->inventoryLevelsUpdate($invRequest);
        $this->assertEquals(200, $invResponse->getStatusCode());

        $this->assertDatabaseHas('pending_inventory_webhooks', [
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/1004',
            'status' => 'pending',
        ]);

        // 2. Product update arrives and creates variant
        $adjustmentCalled = false;

        Http::fake([
            'https://test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/1004',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/InventoryLevel/1004_loc1',
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 25],
                                    ],
                                    'location' => [
                                        'id' => 'gid://shopify/Location/loc1',
                                        'name' => 'Primary Location',
                                        'isActive' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            '*zoho*/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    ['field_id' => '999', 'api_name' => 'cf_shopify_variant_id'],
                ],
            ]),
            '*zoho*/books/v3/chartofaccounts*' => Http::response([
                'code' => 0,
                'chartofaccounts' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'account_type' => 'inventory'],
                ],
            ]),
            '*zoho*/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'is_active' => true],
                ],
            ]),
            '*zoho*/books/v3/items/zoho_item_1004*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_1004',
                    'name' => 'Tracked Product 1004 - Default Title',
                    'stock_on_hand' => 25.0,
                    'track_inventory' => true,
                ],
            ]),
            '*zoho*/books/v3/items*' => function (ClientRequest $request) {
                if ($request->method() === 'POST') {
                    $data = json_decode($request->body(), true) ?? [];
                    $this->assertEquals(25, $data['initial_stock'] ?? null);
                    return Http::response([
                        'code' => 0,
                        'item' => [
                            'item_id' => 'zoho_item_1004',
                            'name' => $data['name'],
                            'stock_on_hand' => 25.0,
                            'track_inventory' => true,
                        ],
                    ], 200);
                }

                return Http::response(['code' => 0, 'items' => []], 200);
            },
            '*zoho*/books/v3/inventoryadjustments*' => function (ClientRequest $request) use (&$adjustmentCalled) {
                $adjustmentCalled = true;
                return Http::response(['code' => 0, 'message' => 'Adjustment created'], 200);
            },
            '*zoho*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $prodPayload = json_encode([
            'id' => 9004,
            'admin_graphql_api_id' => 'gid://shopify/Product/9004',
            'title' => 'Tracked Product 1004',
            'variants' => [
                [
                    'id' => 90044,
                    'admin_graphql_api_id' => 'gid://shopify/ProductVariant/90044',
                    'title' => 'Default Title',
                    'price' => '15.00',
                    'inventory_item_id' => 1004,
                    'inventory_quantity' => 0,
                ],
            ],
        ]);

        $prodRequest = Request::create('/webhooks/products', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_1004',
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($prodPayload),
        ], $prodPayload);

        $prodResponse = $controller->productsUpdate($prodRequest);
        $this->assertEquals(200, $prodResponse->getStatusCode());

        // Verify pending webhook is now processed
        $this->assertDatabaseHas('pending_inventory_webhooks', [
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/1004',
            'status' => 'processed',
        ]);

        // Verify no extra adjustment call was made
        $this->assertFalse($adjustmentCalled, 'No inventory adjustment should be made if initial stock already matched live aggregate.');

        $variant = ProductVariant::where('shopify_variant_id', 'gid://shopify/ProductVariant/90044')->first();
        $this->assertEquals(25, $variant->inventory_quantity);
        $this->assertEquals('zoho_item_1004', $variant->zoho_item_id);
    }

    public function test_retry_product_webhook_does_not_duplicate_zoho_item_or_initial_stock(): void
    {
        $createdItemCount = 0;

        Http::fake([
            'https://test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/1005',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'id' => 'gid://shopify/InventoryLevel/1005_loc1',
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 25],
                                    ],
                                    'location' => [
                                        'id' => 'gid://shopify/Location/loc1',
                                        'name' => 'Primary Location',
                                        'isActive' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            '*zoho*/books/v3/settings/fields*' => Http::response([
                'code' => 0,
                'fields' => [
                    ['field_id' => '999', 'api_name' => 'cf_shopify_variant_id'],
                ],
            ]),
            '*zoho*/books/v3/chartofaccounts*' => Http::response([
                'code' => 0,
                'chartofaccounts' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'account_type' => 'inventory'],
                ],
            ]),
            '*zoho*/books/v3/items/editpage*' => Http::response([
                'code' => 0,
                'inventory_accounts_list' => [
                    ['account_id' => 'acc_inv_asset', 'account_name' => 'Inventory Asset', 'is_active' => true],
                ],
            ]),
            '*zoho*/books/v3/items/zoho_item_1005*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_1005',
                    'name' => 'Retry Product - Default Title',
                    'stock_on_hand' => 25.0,
                    'track_inventory' => true,
                ],
            ]),
            '*zoho*/books/v3/items*' => function (ClientRequest $request) use (&$createdItemCount) {
                if ($request->method() === 'POST') {
                    $createdItemCount++;
                    $data = json_decode($request->body(), true) ?? [];
                    return Http::response([
                        'code' => 0,
                        'item' => [
                            'item_id' => 'zoho_item_1005',
                            'name' => $data['name'],
                            'stock_on_hand' => 25.0,
                            'track_inventory' => true,
                        ],
                    ], 200);
                }

                if ($request->method() === 'PUT') {
                    return Http::response(['code' => 0, 'message' => 'Item updated'], 200);
                }

                return Http::response(['code' => 0, 'items' => []], 200);
            },
            '*zoho*' => Http::response(['code' => 0, 'message' => 'success'], 200),
        ]);

        $payload = json_encode([
            'id' => 9005,
            'admin_graphql_api_id' => 'gid://shopify/Product/9005',
            'title' => 'Retry Product',
            'variants' => [
                [
                    'id' => 90055,
                    'admin_graphql_api_id' => 'gid://shopify/ProductVariant/90055',
                    'title' => 'Default Title',
                    'price' => '15.00',
                    'inventory_item_id' => 1005,
                    'inventory_quantity' => 0,
                ],
            ],
        ]);

        $controller = new ShopifyWebhookController();

        // Delivery 1
        $request1 = Request::create('/webhooks/products', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_retry_1',
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($payload),
        ], $payload);
        $controller->productsUpdate($request1);

        $this::assertEquals(1, $createdItemCount);

        // Delivery 2 (Retry)
        $request2 = Request::create('/webhooks/products', 'POST', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_retry_2',
            'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->createHmacHeader($payload),
        ], $payload);
        $controller->productsUpdate($request2);

        $this::assertEquals(1, $createdItemCount, 'Retry product webhook should not invoke createItem again.');
    }
}
