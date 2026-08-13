<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ShopifyService;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoImageSyncTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private ZohoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_secret' => 'test_shopify_api_secret_key_123']);

        $this->shop = Shop::create([
            'shop_domain' => 'image-test.myshopify.com',
            'access_token' => 'shpat_test_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'org_img_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function createHmac(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, 'test_shopify_api_secret_key_123', true));
    }

    public function test_image_url_stored_during_full_shopify_sync()
    {
        Http::fake([
            'https://image-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Product/101',
                                'title' => 'Shirt with Image',
                                'handle' => 'shirt-with-image',
                                'featuredImage' => [
                                    'url' => 'https://cdn.shopify.com/s/files/1/shirt.jpg',
                                ],
                                'variants' => [
                                    'nodes' => [
                                        [
                                            'id' => 'gid://shopify/ProductVariant/201',
                                            'title' => 'Medium',
                                            'sku' => 'SHIRT-M',
                                            'price' => '25.00',
                                            'inventoryQuantity' => 10,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response([
                'code' => 0,
                'message' => 'The item has been created.',
                'item' => ['item_id' => 'zoho_item_201'],
            ], 201),
        ]);

        $mockShopifyService = $this->createMock(ShopifyService::class);
        $mockShopifyService->method('getValidAccessToken')->willReturn('valid_token');

        $controller = new \App\Http\Controllers\ShopifyProductController($mockShopifyService);
        $request = new \Illuminate\Http\Request();
        $request->attributes->set('shop', $this->shop);

        $response = $controller->products($request);

        $this->assertEquals(200, $response->getStatusCode());

        $product = Product::where('shopify_product_id', 'gid://shopify/Product/101')->first();
        $this->assertNotNull($product);
        $this->assertEquals('https://cdn.shopify.com/s/files/1/shirt.jpg', $product->image_url);
    }

    public function test_image_url_updated_by_products_update_webhook()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Shirt',
            'handle' => 'shirt',
            'image_url' => 'https://cdn.shopify.com/s/files/1/old.jpg',
        ]);

        $payload = json_encode([
            'id' => 101,
            'title' => 'Shirt Updated',
            'handle' => 'shirt-updated',
            'image' => [
                'src' => 'https://cdn.shopify.com/s/files/1/new_featured_image.jpg',
            ],
            'variants' => [],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'image-test.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(200);

        $product->refresh();
        $this->assertEquals('https://cdn.shopify.com/s/files/1/new_featured_image.jpg', $product->image_url);
    }

    public function test_zoho_item_update_still_works_without_an_image()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Product No Image',
            'handle' => 'product-no-image',
            'image_url' => null,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/201',
            'title' => 'Default',
            'sku' => 'NO-IMG-SKU',
            'price' => '19.99',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'zoho_no_img_99',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_no_img_99*' => Http::response([
                'code' => 0,
                'message' => 'The item has been updated.',
                'item' => ['item_id' => 'zoho_no_img_99'],
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->updateItem($variant);

        $this->assertTrue($result['updated']);
        $this->assertEquals('zoho_no_img_99', $result['zoho_item_id']);

        // Verify only PUT was called for item update, NO image POST endpoint was called
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->method() === 'PUT' &&
                str_contains($request->url(), '/books/v3/items/zoho_no_img_99');
        });

        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/image');
        });
    }

    public function test_image_upload_called_when_image_exists()
    {
        $imageUrl = 'https://example.com/product_image.jpg';

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Product With Image',
            'handle' => 'product-with-image',
            'image_url' => $imageUrl,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/201',
            'title' => 'Default',
            'sku' => 'WITH-IMG-SKU',
            'price' => '29.99',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_img_100',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_img_100*' => Http::response([
                'code' => 0,
                'message' => 'The item has been updated.',
                'item' => ['item_id' => 'zoho_img_100'],
            ], 200),
            'https://example.com/product_image.jpg' => Http::response('fake_image_bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://www.zohoapis.com/books/v3/items/zoho_img_100/image*' => Http::response([
                'code' => 0,
                'message' => 'The item image has been attached.',
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->updateItem($variant);

        $this->assertTrue($result['updated']);

        // Verify the dedicated multipart image upload endpoint was called
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/books/v3/items/zoho_img_100/image');
        });
    }

    public function test_image_upload_skipped_when_no_image_exists()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'No Image Product',
            'handle' => 'no-image-product',
            'image_url' => null,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/201',
            'title' => 'Default',
            'sku' => 'NO-IMG-SKU',
            'price' => '15.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'zoho_skip_img_101',
        ]);

        $zohoService = new ZohoService($this->shop);
        $uploadResult = $zohoService->uploadItemImage($variant);

        $this->assertTrue($uploadResult['success']);
        $this->assertTrue($uploadResult['skipped']);
    }

    public function test_image_upload_failure_does_not_fail_normal_zoho_item_update()
    {
        $imageUrl = 'https://example.com/failing_image.jpg';

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Product Failing Image',
            'handle' => 'product-failing-image',
            'image_url' => $imageUrl,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/201',
            'title' => 'Default',
            'sku' => 'FAIL-IMG-SKU',
            'price' => '39.99',
            'inventory_quantity' => 8,
            'zoho_item_id' => 'zoho_fail_img_202',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_fail_img_202*' => Http::response([
                'code' => 0,
                'message' => 'The item has been updated.',
                'item' => ['item_id' => 'zoho_fail_img_202'],
            ], 200),
            'https://example.com/failing_image.jpg' => Http::response('Server Error downloading image', 500),
        ]);

        $zohoService = new ZohoService($this->shop);

        // Should NOT throw an exception despite image download failure
        $result = $zohoService->updateItem($variant);

        // Normal item update MUST succeed
        $this->assertTrue($result['updated']);
        $this->assertEquals('zoho_fail_img_202', $result['zoho_item_id']);
    }
}
