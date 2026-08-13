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

class ZohoReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private ZohoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'reconcile-test.myshopify.com',
            'access_token' => 'shpat_test_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'org_reconcile_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function getFieldsMockResponse(): array
    {
        return [
            'code' => 0,
            'fields' => [
                [
                    'field_id' => '888001',
                    'api_name' => 'cf_shopify_variant_id',
                    'label' => 'Shopify Variant ID',
                ],
            ],
        ];
    }

    public function test_local_zoho_item_id_points_to_existing_zoho_item_keeps_mapping()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Shirt',
            'handle' => 'shirt',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/200',
            'title' => 'Large',
            'sku' => 'SHIRT-L',
            'price' => '30.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => '4081216000000069001',
            'zoho_sync_hash' => 'old_hash',
            'zoho_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/4081216000000069001*' => Http::response([
                'code' => 0,
                'message' => 'success',
                'item' => [
                    'item_id' => '4081216000000069001',
                    'name' => 'Shirt - Large',
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/settings/fields*' => Http::response($this->getFieldsMockResponse(), 200),
            'https://www.zohoapis.com/books/v3/items?*' => Http::response([
                'code' => 0,
                'items' => [
                    [
                        'item_id' => '4081216000000069001',
                        'custom_fields' => [
                            [
                                'customfield_id' => '888001',
                                'value' => 'gid://shopify/ProductVariant/200',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncItem($variant);

        $variant->refresh();
        $this->assertEquals('4081216000000069001', $variant->zoho_item_id);
    }

    public function test_local_zoho_item_id_points_to_deleted_item_clears_stale_mapping()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Shirt',
            'handle' => 'shirt',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/200',
            'title' => 'Large',
            'sku' => 'SHIRT-L',
            'price' => '30.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => '4081216000000069001',
            'zoho_sync_hash' => 'stale_hash_xyz',
            'zoho_synced_at' => now()->subDays(2),
        ]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/settings/fields')) {
                return Http::response($this->getFieldsMockResponse(), 200);
            }

            if ($request->method() === 'GET' && str_contains($path, '/items/4081216000000069001')) {
                return Http::response([
                    'code' => 1002,
                    'message' => 'Sorry! The item you are looking for is not available!',
                ], 404);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/items')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been created.',
                    'item' => ['item_id' => '9999999999999999999'],
                ], 201);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/items')) {
                return Http::response(['code' => 0, 'items' => []], 200);
            }

            return Http::response(['code' => 0], 200);
        });

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncItem($variant);

        $variant->refresh();

        // Stale mapping 4081216000000069001 was cleared and replaced with newly created Zoho item ID 9999999999999999999
        $this->assertNotEquals('4081216000000069001', $variant->zoho_item_id);
        $this->assertEquals('9999999999999999999', $variant->zoho_item_id);
        $this->assertNotNull($variant->zoho_sync_hash);
        $this->assertNotNull($variant->zoho_synced_at);
    }

    public function test_deleted_local_mapping_reuses_existing_zoho_item_if_shopify_variant_id_matches()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Hat',
            'handle' => 'hat',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/500',
            'title' => 'Red',
            'sku' => 'HAT-RED',
            'price' => '15.00',
            'inventory_quantity' => 20,
            'zoho_item_id' => 'stale_id_777',
            'zoho_sync_hash' => 'old_hash',
        ]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/settings/fields')) {
                return Http::response($this->getFieldsMockResponse(), 200);
            }

            if ($request->method() === 'GET' && str_contains($path, '/items/stale_id_777')) {
                return Http::response([
                    'code' => 1002,
                    'message' => 'Sorry! The item you are looking for is not available!',
                ], 404);
            }

            if ($request->method() === 'PUT' && str_contains($path, '/items/existing_zoho_item_888')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been updated.',
                    'item' => ['item_id' => 'existing_zoho_item_888'],
                ], 200);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/items')) {
                return Http::response([
                    'code' => 0,
                    'items' => [
                        [
                            'item_id' => 'existing_zoho_item_888',
                            'name' => 'Hat - Red',
                            'custom_fields' => [
                                [
                                    'customfield_id' => '888001',
                                    'value' => 'gid://shopify/ProductVariant/500',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['code' => 0], 200);
        });

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncItem($variant);

        $variant->refresh();

        // Reused existing Zoho item existing_zoho_item_888 without creating a duplicate!
        $this->assertEquals('existing_zoho_item_888', $variant->zoho_item_id);

        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/books/v3/items');
        });
    }

    public function test_deleted_local_mapping_and_no_matching_zoho_item_creates_exactly_one_new_item()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Shoes',
            'handle' => 'shoes',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/600',
            'title' => 'Size 10',
            'sku' => 'SHOES-10',
            'price' => '60.00',
            'inventory_quantity' => 3,
            'zoho_item_id' => null,
            'zoho_sync_hash' => null,
        ]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/settings/fields')) {
                return Http::response($this->getFieldsMockResponse(), 200);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/items')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been created.',
                    'item' => ['item_id' => 'new_zoho_item_333'],
                ], 201);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/items')) {
                return Http::response(['code' => 0, 'items' => []], 200);
            }

            return Http::response(['code' => 0], 200);
        });

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncItem($variant);

        $variant->refresh();
        $this->assertEquals('new_zoho_item_333', $variant->zoho_item_id);
        $this->assertTrue($result['created']);
    }

    public function test_duplicate_shopify_variant_id_never_creates_duplicate_zoho_item()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Socks',
            'handle' => 'socks',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/700',
            'title' => 'Black',
            'sku' => 'SOCKS-BLK',
            'price' => '5.00',
            'inventory_quantity' => 50,
            'zoho_item_id' => null,
        ]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/settings/fields')) {
                return Http::response($this->getFieldsMockResponse(), 200);
            }

            if ($request->method() === 'PUT' && str_contains($path, '/items/pre_existing_zoho_444')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been updated.',
                    'item' => ['item_id' => 'pre_existing_zoho_444'],
                ], 200);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/items')) {
                return Http::response([
                    'code' => 0,
                    'items' => [
                        [
                            'item_id' => 'pre_existing_zoho_444',
                            'name' => 'Socks - Black',
                            'custom_fields' => [
                                [
                                    'customfield_id' => '888001',
                                    'value' => 'gid://shopify/ProductVariant/700',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['code' => 0], 200);
        });

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->createItem($variant);

        $variant->refresh();
        $this->assertEquals('pre_existing_zoho_444', $variant->zoho_item_id);

        // POST /books/v3/items must NEVER be sent if matching item exists!
        Http::assertNotSent(function (Request $request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/books/v3/items');
        });
    }

    public function test_existing_valid_mapping_still_updates_normally()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/100',
            'title' => 'Jacket',
            'handle' => 'jacket',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/800',
            'title' => 'XL',
            'sku' => 'JKT-XL',
            'price' => '120.00',
            'inventory_quantity' => 2,
            'zoho_item_id' => 'valid_zoho_555',
            'zoho_sync_hash' => 'old_hash_outdated',
        ]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/settings/fields')) {
                return Http::response($this->getFieldsMockResponse(), 200);
            }

            if ($request->method() === 'GET' && str_contains($path, '/items/valid_zoho_555')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'success',
                    'item' => ['item_id' => 'valid_zoho_555'],
                ], 200);
            }

            if ($request->method() === 'PUT' && str_contains($path, '/items/valid_zoho_555')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'The item has been updated.',
                    'item' => ['item_id' => 'valid_zoho_555'],
                ], 200);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/items')) {
                return Http::response([
                    'code' => 0,
                    'items' => [
                        [
                            'item_id' => 'valid_zoho_555',
                            'custom_fields' => [
                                [
                                    'customfield_id' => '888001',
                                    'value' => 'gid://shopify/ProductVariant/800',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['code' => 0], 200);
        });

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncItem($variant);

        $variant->refresh();
        $this->assertEquals('valid_zoho_555', $variant->zoho_item_id);
        $this->assertTrue($result['updated']);
    }
}
