<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop1;
    protected Shop $shop2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zoho.webhook_secret' => null]);

        $this->shop1 = Shop::create([
            'shop_domain' => 'tenant-one.myshopify.com',
            'access_token' => 'shpat_tenant_one_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_tenant_1',
            'access_token' => 'token_tenant_1',
            'refresh_token' => 'refresh_tenant_1',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);

        $this->shop2 = Shop::create([
            'shop_domain' => 'tenant-two.myshopify.com',
            'access_token' => 'shpat_tenant_two_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop2->id,
            'organization_id' => 'org_tenant_2',
            'access_token' => 'token_tenant_2',
            'refresh_token' => 'refresh_tenant_2',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_valid_zoho_inventory_webhook_real_nested_payload()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Zoho Webhook Item',
            'handle' => 'zoho-webhook-item',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1011',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90101',
            'title' => 'Default',
            'price' => '25.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => '4081216000000161063',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/4081216000000161063*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => '4081216000000161063',
                    'actual_available_stock' => 50,
                ],
            ], 200),
            "https://{$this->shop1->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
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
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/90101',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'location' => ['id' => 'gid://shopify/Location/888'],
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 10],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'inventorySetQuantities' => [
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_webhook_101'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000179027',
                'last_modified_time' => '2026-08-17T19:16:00+05:30',
                'adjustment_type' => 'quantity',
                'line_items' => [
                    [
                        'item_id' => '4081216000000161063',
                        'quantity_adjusted' => 3,
                        'name' => 'T-Shirt - Default Title',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Zoho inventory webhook processed successfully.',
            'processed_count' => 1,
        ]);

        $this->assertEquals(50, $variant->fresh()->inventory_quantity);
        $this->assertDatabaseHas('sync_histories', [
            'shop_id' => $this->shop1->id,
            'product_variant_id' => $variant->id,
            'action' => 'inventory_update',
            'status' => 'success',
            'zoho_item_id' => '4081216000000161063',
        ]);
        $this->assertDatabaseHas('shopify_processed_webhooks', [
            'webhook_id' => 'zoho.inventory.adjustment.4081216000000179027.2026-08-17T19:16:00+05:30',
        ]);
    }

    public function test_same_adjustment_id_same_last_modified_time_is_duplicate()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/105',
            'title' => 'Adjustment ID Test',
            'handle' => 'adjustment-id-test',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1051',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90105',
            'title' => 'Default',
            'price' => '15.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => '4081216000000161099',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/4081216000000161099*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => '4081216000000161099',
                    'actual_available_stock' => 12,
                ],
            ], 200),
            "https://{$this->shop1->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
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
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_webhook_105'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $payload = [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000179999',
                'last_modified_time' => '2026-08-17T19:16:00+05:30',
                'adjustment_type' => 'quantity',
                'line_items' => [
                    [
                        'item_id' => '4081216000000161099',
                        'quantity_adjusted' => 2,
                    ],
                ],
            ],
        ];

        $expectedKey = 'zoho.inventory.adjustment.4081216000000179999.2026-08-17T19:16:00+05:30';

        $response1 = $this->postJson('/webhooks/zoho/inventory', $payload);
        $response1->assertStatus(200);
        $this->assertDatabaseHas('shopify_processed_webhooks', [
            'webhook_id' => $expectedKey,
        ]);

        // Same payload with same last_modified_time should be skipped as duplicate
        $response2 = $this->postJson('/webhooks/zoho/inventory', $payload);
        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Webhook already processed.',
            'webhook_id' => $expectedKey,
        ]);
    }

    public function test_same_adjustment_id_different_last_modified_time_is_processed_again()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1055',
            'title' => 'Revision Test',
            'handle' => 'revision-test',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/10551',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/901055',
            'title' => 'Default',
            'price' => '15.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => '4081216000000161088',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/4081216000000161088*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => '4081216000000161088',
                    'actual_available_stock' => 20,
                ],
            ], 200),
            "https://{$this->shop1->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
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
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_webhook_1055'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $payload1 = [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000179027',
                'last_modified_time' => '2026-08-17T19:16:00+05:30',
                'line_items' => [
                    ['item_id' => '4081216000000161088', 'quantity_adjusted' => 3],
                ],
            ],
        ];

        $response1 = $this->postJson('/webhooks/zoho/inventory', $payload1);
        $response1->assertStatus(200);
        $response1->assertJson(['processed_count' => 1]);

        // Edit at 19:24 with new last_modified_time
        $payload2 = [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000179027',
                'last_modified_time' => '2026-08-17T19:24:00+05:30',
                'line_items' => [
                    ['item_id' => '4081216000000161088', 'quantity_adjusted' => 5],
                ],
            ],
        ];

        $response2 = $this->postJson('/webhooks/zoho/inventory', $payload2);
        $response2->assertStatus(200);
        $response2->assertJson(['processed_count' => 1]);
        $this->assertDatabaseHas('shopify_processed_webhooks', [
            'webhook_id' => 'zoho.inventory.adjustment.4081216000000179027.2026-08-17T19:24:00+05:30',
        ]);
    }

    public function test_header_webhook_id_takes_priority_over_payload()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1057',
            'title' => 'Header Priority Test',
            'handle' => 'header-priority-test',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/10571',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/901057',
            'title' => 'Default',
            'price' => '15.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => '4081216000000161077',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/4081216000000161077*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => '4081216000000161077',
                    'actual_available_stock' => 30,
                ],
            ], 200),
            "https://{$this->shop1->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
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
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_webhook_1057'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'X-Zoho-Webhook-Id' => 'header_evt_id_9999',
        ])->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000179027',
                'last_modified_time' => '2026-08-17T19:16:00+05:30',
                'line_items' => [
                    ['item_id' => '4081216000000161077'],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shopify_processed_webhooks', [
            'webhook_id' => 'header_evt_id_9999',
        ]);
    }

    public function test_missing_last_modified_time_uses_payload_hash()
    {
        $payload = [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000175555',
                'line_items' => [
                    ['item_id' => 'unmapped_hash_item_111'],
                ],
            ],
        ];

        $expectedHash = md5(json_encode($payload));
        $expectedWebhookId = "zoho.inventory.adjustment.4081216000000175555.{$expectedHash}";

        $response1 = $this->postJson('/webhooks/zoho/inventory', $payload);
        $response1->assertStatus(200);
        $this->assertDatabaseHas('shopify_processed_webhooks', [
            'webhook_id' => $expectedWebhookId,
        ]);

        // Identical payload missing last_modified_time is duplicate
        $response2 = $this->postJson('/webhooks/zoho/inventory', $payload);
        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Webhook already processed.',
            'webhook_id' => $expectedWebhookId,
        ]);
    }

    public function test_multiple_line_items_in_single_webhook()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/106',
            'title' => 'Multi Item Test',
            'handle' => 'multi-item-test',
        ]);

        $variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1061',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/901061',
            'title' => 'Variant 1',
            'price' => '10.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'item_multi_1',
        ]);

        $variant2 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1062',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/901062',
            'title' => 'Variant 2',
            'price' => '20.00',
            'inventory_quantity' => 8,
            'zoho_item_id' => 'item_multi_2',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/item_multi_1*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'item_multi_1',
                    'actual_available_stock' => 15,
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/items/item_multi_2*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'item_multi_2',
                    'actual_available_stock' => 25,
                ],
            ], 200),
            "https://{$this->shop1->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
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
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/90101',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'location' => ['id' => 'gid://shopify/Location/888'],
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 5],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'inventorySetQuantities' => [
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_multi'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000178888',
                'adjustment_type' => 'quantity',
                'line_items' => [
                    ['item_id' => 'item_multi_1', 'quantity_adjusted' => 10],
                    ['item_id' => 'item_multi_2', 'quantity_adjusted' => 17],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Zoho inventory webhook processed successfully.',
            'processed_count' => 2,
        ]);

        $this->assertEquals(15, $variant1->fresh()->inventory_quantity);
        $this->assertEquals(25, $variant2->fresh()->inventory_quantity);
    }

    public function test_unmapped_item_recorded_in_skipped_items()
    {
        $response = $this->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => '4081216000000177777',
                'line_items' => [
                    ['item_id' => 'unmapped_zoho_item_9999', 'quantity_adjusted' => 5],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'processed_count' => 0,
            'skipped_items' => [
                [
                    'zoho_item_id' => 'unmapped_zoho_item_9999',
                    'reason' => 'Variant not found or unmapped for this Zoho item.',
                ],
            ],
        ]);
    }

    public function test_duplicate_zoho_inventory_webhook_event_is_ignored()
    {
        ShopifyProcessedWebhook::create([
            'webhook_id' => 'zoho_evt_duplicate_001',
            'topic' => 'zoho.inventory.update',
            'shop_domain' => $this->shop1->shop_domain,
        ]);

        $response = $this->withHeaders([
            'X-Zoho-Webhook-Id' => 'zoho_evt_duplicate_001',
        ])->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'line_items' => [
                    ['item_id' => 'zoho_item_10102'],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Webhook already processed.',
            'webhook_id' => 'zoho_evt_duplicate_001',
        ]);
    }

    public function test_tenant_isolation_in_zoho_inventory_webhook()
    {
        // Shop 1 Product
        $product1 = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/201',
            'title' => 'Shop 1 Item',
            'handle' => 'shop-1-item',
        ]);

        $variant1 = ProductVariant::create([
            'product_id' => $product1->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2011',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90201',
            'title' => 'Shop 1 Variant',
            'price' => '50.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_shared',
        ]);

        // Shop 2 Product with same zoho_item_id
        $product2 = Product::create([
            'shop_id' => $this->shop2->id,
            'shopify_product_id' => 'gid://shopify/Product/202',
            'title' => 'Shop 2 Item',
            'handle' => 'shop-2-item',
        ]);

        $variant2 = ProductVariant::create([
            'product_id' => $product2->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/2021',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/90202',
            'title' => 'Shop 2 Variant',
            'price' => '50.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'zoho_item_shared',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_shared*' => Http::response([
                'code' => 0,
                'item' => [
                    'item_id' => 'zoho_item_shared',
                    'actual_available_stock' => 88,
                ],
            ], 200),
            "https://{$this->shop2->shop_domain}/admin/api/2026-07/graphql.json" => Http::response([
                'data' => [
                    'locations' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Location/999',
                                'name' => 'Shop 2 Primary Location',
                                'isPrimary' => true,
                                'isActive' => true,
                            ],
                        ],
                    ],
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/90202',
                        'inventoryLevels' => [
                            'nodes' => [
                                [
                                    'location' => ['id' => 'gid://shopify/Location/999'],
                                    'quantities' => [
                                        ['name' => 'available', 'quantity' => 5],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'inventorySetQuantities' => [
                        'inventoryAdjustmentGroup' => ['id' => 'adj_group_shop_2'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders([
            'X-Shop-Domain' => $this->shop2->shop_domain,
        ])->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => 'zoho_evt_tenant_001',
                'line_items' => [
                    ['item_id' => 'zoho_item_shared'],
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertEquals(88, $variant2->fresh()->inventory_quantity);
        $this->assertEquals(10, $variant1->fresh()->inventory_quantity);
    }

    public function test_zoho_webhook_security_token_validation()
    {
        config(['services.zoho.webhook_secret' => 'valid_secret_token_123']);

        $response1 = $this->withHeaders([
            'X-Zoho-Webhook-Token' => 'wrong_token',
        ])->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => ['line_items' => [['item_id' => '123']]],
        ]);
        $response1->assertStatus(401);

        $response2 = $this->withHeaders([
            'X-Zoho-Webhook-Token' => 'valid_secret_token_123',
        ])->postJson('/webhooks/zoho/inventory', [
            'inventory_adjustment' => [
                'inventory_adjustment_id' => 'sec_test_001',
                'line_items' => [['item_id' => 'unmapped_999']],
            ],
        ]);
        $response2->assertStatus(200);
    }
}
