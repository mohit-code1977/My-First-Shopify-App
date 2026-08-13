<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Models\ZohoConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop1;
    private Shop $shop2;
    private string $apiSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiSecret = 'test_shopify_api_secret_key_123';
        config(['services.shopify.api_secret' => $this->apiSecret]);

        $this->shop1 = Shop::create([
            'shop_domain' => 'store-one.myshopify.com',
            'access_token' => 'shpat_token_one',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->shop2 = Shop::create([
            'shop_domain' => 'store-two.myshopify.com',
            'access_token' => 'shpat_token_two',
            'access_token_expires_at' => now()->addDays(30),
        ]);
    }

    private function createHmac(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, $this->apiSecret, true));
    }

    public function test_valid_hmac_accepted()
    {
        $payload = json_encode([
            'id' => 1001,
            'title' => 'Sample Product',
            'variants' => [],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_invalid_hmac_rejected()
    {
        $payload = json_encode(['id' => 1001, 'title' => 'Sample Product']);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid_base64_hmac_signature',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('Invalid HMAC signature.', $response->json('error'));
    }

    public function test_missing_hmac_rejected()
    {
        $payload = json_encode(['id' => 1001]);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('Missing HMAC signature.', $response->json('error'));
    }

    public function test_invalid_shop_domain_rejected()
    {
        $payload = json_encode(['id' => 1001]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'invalid-domain-format',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Invalid Shopify shop domain.', $response->json('error'));
    }

    public function test_unknown_shop_rejected()
    {
        $payload = json_encode(['id' => 1001]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'unknown-store.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Unknown shop domain.', $response->json('error'));
    }

    public function test_numeric_webhook_product_id_matches_stored_graphql_gid()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Original Title',
            'handle' => 'original-handle',
        ]);

        $payload = json_encode([
            'id' => 1001, // Numeric ID from REST webhook payload
            'title' => 'Updated via Numeric Webhook ID',
            'handle' => 'updated-via-numeric-webhook-id',
            'variants' => [],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());

        $product->refresh();
        $this->assertEquals('Updated via Numeric Webhook ID', $product->title);
        $this->assertEquals('updated-via-numeric-webhook-id', $product->handle);
    }

    public function test_numeric_webhook_variant_id_matches_stored_graphql_gid()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Product Title',
            'handle' => 'product-title',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'title' => 'Default Title',
            'sku' => 'OLD-SKU',
            'price' => '10.00',
            'inventory_quantity' => 5,
        ]);

        $payload = json_encode([
            'id' => 1001,
            'variants' => [
                [
                    'id' => 5001, // Numeric variant ID from REST webhook payload
                    'title' => 'Updated Variant Title',
                    'sku' => 'NEW-SKU-999',
                    'price' => '49.99',
                    'inventory_quantity' => 75,
                ],
            ],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());

        $variant->refresh();
        $this->assertEquals('Updated Variant Title', $variant->title);
        $this->assertEquals('NEW-SKU-999', $variant->sku);
        $this->assertEquals('49.99', $variant->price);
        $this->assertEquals(75, $variant->inventory_quantity);
    }

    public function test_admin_graphql_api_id_preferred_when_present()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/7777',
            'title' => 'Title Before Update',
            'handle' => 'handle-before-update',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/8888',
            'title' => 'Variant Title Before Update',
            'sku' => 'SKU-BEFORE',
            'price' => '100.00',
            'inventory_quantity' => 10,
        ]);

        $payload = json_encode([
            'id' => 7777,
            'admin_graphql_api_id' => 'gid://shopify/Product/7777',
            'title' => 'Title After Update',
            'handle' => 'handle-after-update',
            'variants' => [
                [
                    'id' => 8888,
                    'admin_graphql_api_id' => 'gid://shopify/ProductVariant/8888',
                    'title' => 'Variant Title After Update',
                    'sku' => 'SKU-AFTER',
                    'price' => '150.00',
                    'inventory_quantity' => 20,
                ],
            ],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());

        $product->refresh();
        $this->assertEquals('Title After Update', $product->title);

        $variant->refresh();
        $this->assertEquals('SKU-AFTER', $variant->sku);
    }

    public function test_variant_belonging_to_another_shop_is_not_updated()
    {
        $productShop2 = Product::create([
            'shop_id' => $this->shop2->id,
            'shopify_product_id' => 'gid://shopify/Product/2002',
            'title' => 'Shop 2 Product',
            'handle' => 'shop-2-product',
        ]);

        $variantShop2 = ProductVariant::create([
            'product_id' => $productShop2->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/9009',
            'title' => 'Shop 2 Variant',
            'sku' => 'SHOP2-SKU',
            'price' => '50.00',
            'inventory_quantity' => 5,
        ]);

        // Malicious or mismatched payload from shop1 trying to update shop2's variant
        $payload = json_encode([
            'id' => 2002,
            'variants' => [
                [
                    'id' => 9009,
                    'price' => '0.01',
                    'sku' => 'HACKED-SKU',
                ],
            ],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify shop2's variant was NOT updated
        $variantShop2->refresh();
        $this->assertEquals('50.00', $variantShop2->price);
        $this->assertEquals('SHOP2-SKU', $variantShop2->sku);
    }

    public function test_mapped_variant_calls_zoho_service_update_item()
    {
        ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Mapped Product',
            'handle' => 'mapped-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'title' => 'Default',
            'sku' => 'MAPPED-SKU',
            'price' => '30.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_9999',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_9999*' => Http::response([
                'code' => 0,
                'message' => 'The item has been updated.',
                'item' => [
                    'item_id' => 'zoho_item_9999',
                ],
            ], 200),
        ]);

        $payload = json_encode([
            'id' => 1001,
            'title' => 'Mapped Product Updated',
            'handle' => 'mapped-product',
            'variants' => [
                [
                    'id' => 5001,
                    'title' => 'Default',
                    'sku' => 'MAPPED-SKU',
                    'price' => '35.00',
                    'inventory_quantity' => 10,
                ],
            ],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.synced_to_zoho'));

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://www.zohoapis.com/books/v3/items/zoho_item_9999?organization_id=org_123' &&
                $request->method() === 'PUT';
        });
    }

    public function test_unmapped_variant_is_skipped()
    {
        ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Unmapped Product',
            'handle' => 'unmapped-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'title' => 'Default',
            'sku' => 'UNMAPPED-SKU',
            'price' => '20.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => null, // Unmapped
        ]);

        Http::fake();

        $payload = json_encode([
            'id' => 1001,
            'title' => 'Unmapped Product',
            'handle' => 'unmapped-product',
            'variants' => [
                [
                    'id' => 5001,
                    'title' => 'Default',
                    'sku' => 'UNMAPPED-SKU',
                    'price' => '25.00',
                    'inventory_quantity' => 10,
                ],
            ],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.skipped_unmapped'));

        Http::assertNothingSent();
    }

    public function test_duplicate_webhook_id_is_ignored()
    {
        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Title Version 1',
            'handle' => 'title-version-1',
        ]);

        $payload = json_encode([
            'id' => 1001,
            'title' => 'Title Version 2',
            'handle' => 'title-version-2',
            'variants' => [],
        ]);

        $hmac = $this->createHmac($payload);
        $webhookId = 'webhook_delivery_unique_123';

        // Delivery 1
        $response1 = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response1->getStatusCode());
        $product->refresh();
        $this->assertEquals('Title Version 2', $product->title);

        // Update local database product directly to simulate a later state
        $product->update(['title' => 'Title Version 3']);

        // Delivery 2 (Duplicate webhook delivery)
        $response2 = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('Webhook already processed.', $response2->json('message'));

        $product->refresh();
        $this->assertEquals('Title Version 3', $product->title);

        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', $webhookId)->count());
    }

    public function test_zoho_update_failure_does_not_abort_the_entire_webhook()
    {
        ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Product Title',
            'handle' => 'product-title',
        ]);

        $variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'title' => 'Failing Variant',
            'sku' => 'SKU-FAIL',
            'price' => '10.00',
            'inventory_quantity' => 5,
            'zoho_item_id' => 'zoho_fail_item',
        ]);

        $variant2 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5002',
            'title' => 'Succeeding Variant',
            'sku' => 'SKU-SUCCESS',
            'price' => '20.00',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_success_item',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_fail_item*' => Http::response([
                'code' => 1000,
                'message' => 'Internal Zoho Server Error',
            ], 500),
            'https://www.zohoapis.com/books/v3/items/zoho_success_item*' => Http::response([
                'code' => 0,
                'message' => 'Item updated.',
                'item' => ['item_id' => 'zoho_success_item'],
            ], 200),
        ]);

        $payload = json_encode([
            'id' => 1001,
            'title' => 'Product Title',
            'handle' => 'product-title',
            'variants' => [
                [
                    'id' => 5001,
                    'title' => 'Failing Variant',
                    'sku' => 'SKU-FAIL',
                    'price' => '12.00',
                    'inventory_quantity' => 5,
                ],
                [
                    'id' => 5002,
                    'title' => 'Succeeding Variant',
                    'sku' => 'SKU-SUCCESS',
                    'price' => '22.00',
                    'inventory_quantity' => 10,
                ],
            ],
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.zoho_failures'));
        $this->assertEquals(1, $response->json('summary.synced_to_zoho'));

        $variant1->refresh();
        $this->assertEquals('12.00', $variant1->price);

        $variant2->refresh();
        $this->assertEquals('22.00', $variant2->price);
    }
}
