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

class ShopifyProductDeleteWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop1;
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
    }

    private function createHmac(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, $this->apiSecret, true));
    }

    private function createZohoConnection(): ZohoConnection
    {
        return ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_123',
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * 1. Product with single synced variant → Zoho item deleted, local mapping cleared, product deleted.
     */
    public function test_single_synced_variant_deleted()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1001',
            'title' => 'Single Variant Product',
            'handle' => 'single-variant-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'title' => 'Default Title',
            'sku' => 'SINGLE-SKU',
            'price' => '19.99',
            'inventory_quantity' => 10,
            'zoho_item_id' => 'zoho_item_101',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_101*' => Http::response([
                'code' => 0,
                'message' => 'The item has been deleted.',
            ], 200),
        ]);

        $payload = json_encode([
            'id' => 1001,
            'admin_graphql_api_id' => 'gid://shopify/Product/1001',
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook_delete_single_001',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.zoho_deleted'));
        $this->assertEquals(1, $response->json('summary.cleaned_variants'));

        // Assert local product and variant were removed
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);

        // Assert SyncHistory recorded
        $this->assertDatabaseHas('sync_histories', [
            'shop_id' => $this->shop1->id,
            'action' => 'delete',
            'status' => 'success',
            'zoho_item_id' => 'zoho_item_101',
        ]);

        // Assert HTTP DELETE call was sent to Zoho
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://www.zohoapis.com/books/v3/items/zoho_item_101?organization_id=org_123' &&
                $request->method() === 'DELETE';
        });
    }

    /**
     * 2. Product with multiple synced variants → all Zoho items handled.
     */
    public function test_multiple_synced_variants_all_deleted()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1002',
            'title' => 'Multi Variant Product',
            'handle' => 'multi-variant-product',
        ]);

        $variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5002',
            'title' => 'Variant Small',
            'sku' => 'MULTI-S',
            'price' => '10.00',
            'zoho_item_id' => 'zoho_item_201',
        ]);

        $variant2 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5003',
            'title' => 'Variant Medium',
            'sku' => 'MULTI-M',
            'price' => '15.00',
            'zoho_item_id' => 'zoho_item_202',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_201*' => Http::response(['code' => 0, 'message' => 'Deleted'], 200),
            'https://www.zohoapis.com/books/v3/items/zoho_item_202*' => Http::response(['code' => 0, 'message' => 'Deleted'], 200),
        ]);

        $payload = json_encode(['id' => 1002]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook_delete_multi_002',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $response->json('summary.zoho_deleted'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant1->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant2->id]);
    }

    /**
     * 3. Variant without zoho_item_id → safely skipped, row cleaned up.
     */
    public function test_variant_without_zoho_item_id_skipped_safely()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1003',
            'title' => 'Unmapped Product',
            'handle' => 'unmapped-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5004',
            'title' => 'Default',
            'zoho_item_id' => null,
        ]);

        Http::fake();

        $payload = json_encode(['id' => 1003]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.skipped_unmapped'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
        Http::assertNothingSent();
    }

    /**
     * 4. Zoho item already missing → treated as successful cleanup.
     */
    public function test_zoho_item_already_missing_treated_as_successful()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1004',
            'title' => 'Stale Zoho Item Product',
            'handle' => 'stale-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5005',
            'title' => 'Default',
            'zoho_item_id' => 'zoho_stale_404',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_stale_404*' => Http::response([
                'code' => 1002,
                'message' => 'The item does not exist.',
            ], 200),
        ]);

        $payload = json_encode(['id' => 1004]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.zoho_already_missing'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
    }

    /**
     * 5. Duplicate webhook → idempotent.
     */
    public function test_duplicate_webhook_is_idempotent()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1005',
            'title' => 'Idempotent Test Product',
            'handle' => 'idempotent-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5006',
            'title' => 'Default',
            'zoho_item_id' => 'zoho_item_505',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_505*' => Http::response(['code' => 0, 'message' => 'Deleted'], 200),
        ]);

        $payload = json_encode(['id' => 1005]);
        $hmac = $this->createHmac($payload);
        $webhookId = 'delivery_dup_1005';

        // First delivery
        $res1 = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $res1->getStatusCode());
        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', $webhookId)->count());

        // Second delivery (Duplicate)
        $res2 = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $res2->getStatusCode());
        $this->assertEquals('Webhook already processed.', $res2->json('message'));
    }

    /**
     * 6. One Zoho deletion fails → partial failure is reported correctly, failed variant preserved for retry.
     */
    public function test_partial_failure_preserves_failed_state_for_retry()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1006',
            'title' => 'Partial Failure Product',
            'handle' => 'partial-failure-product',
        ]);

        $variantSuccess = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5007',
            'title' => 'Success Variant',
            'zoho_item_id' => 'zoho_item_succ',
        ]);

        $variantFail = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5008',
            'title' => 'Failing Variant',
            'zoho_item_id' => 'zoho_item_fail',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_succ*' => Http::response(['code' => 0, 'message' => 'Deleted'], 200),
            'https://www.zohoapis.com/books/v3/items/zoho_item_fail*' => Http::sequence()
                ->push(['code' => 500, 'message' => 'Zoho Internal Service Error'], 500)
                ->push(['code' => 0, 'message' => 'Deleted'], 200),
        ]);

        $payload = json_encode(['id' => 1006]);
        $hmac = $this->createHmac($payload);
        $webhookId = 'delivery_partial_1006';

        // Attempt 1: Returns HTTP 500 due to partial failure
        $res1 = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(500, $res1->getStatusCode());
        $this->assertEquals(1, $res1->json('summary.zoho_deleted'));
        $this->assertEquals(1, $res1->json('summary.zoho_failures'));

        // Successful variant removed
        $this->assertDatabaseMissing('product_variants', ['id' => $variantSuccess->id]);

        // Failed variant & product retained for retry
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $variantFail->id, 'zoho_item_id' => 'zoho_item_fail']);

        // Delivery is recorded with status 'failed' so system tracks partial failure lifecycle
        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', $webhookId)->where('status', 'failed')->count());

        // Attempt 2 (Retry): Zoho API fixed, succeeds
        $res2 = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $res2->getStatusCode());

        // Both product and failing variant now removed
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variantFail->id]);
        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', $webhookId)->where('status', 'completed')->count());
    }

    /**
     * 6b. Webhook currently processing → duplicate request returns 429 to avoid concurrent execution.
     */
    public function test_concurrent_webhook_processing_rejected()
    {
        $this->createZohoConnection();

        $webhookId = 'delivery_concurrent_999';

        ShopifyProcessedWebhook::create([
            'webhook_id' => $webhookId,
            'topic' => 'products/delete',
            'shop_domain' => 'store-one.myshopify.com',
            'status' => 'processing',
        ]);

        $payload = json_encode(['id' => 99999]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals('Webhook is currently processing.', $response->json('error'));
    }

    /**
     * 6c. Crash Recovery: Stale 'processing' record (>5 mins old) recovers safely on retry.
     */
    public function test_stale_processing_webhook_recovers_on_retry()

    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1088',
            'title' => 'Crashed Product',
            'handle' => 'crashed-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5088',
            'title' => 'Default',
            'zoho_item_id' => 'zoho_item_crash_88',
        ]);

        $webhookId = 'delivery_crash_recovery_88';

        // Insert stale processing record updated 10 minutes ago (simulating process crash)
        $staleWebhook = ShopifyProcessedWebhook::create([
            'webhook_id' => $webhookId,
            'topic' => 'products/delete',
            'shop_domain' => 'store-one.myshopify.com',
            'status' => 'processing',
        ]);
        $staleWebhook->updated_at = now()->subMinutes(10);
        $staleWebhook->save();

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_crash_88*' => Http::response(['code' => 0, 'message' => 'Deleted'], 200),
        ]);

        $payload = json_encode(['id' => 1088]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', $webhookId)->where('status', 'completed')->count());
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }



    /**
     * 7. Referenced Zoho item → safe inactive/archive fallback.
     */
    public function test_referenced_zoho_item_falls_back_to_inactive()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1007',
            'title' => 'Referenced Product',
            'handle' => 'referenced-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5009',
            'title' => 'Default',
            'zoho_item_id' => 'zoho_item_referenced',
        ]);

        Http::fake([
            // DELETE attempt returns code 1005 (Associated with transactions)
            'https://www.zohoapis.com/books/v3/items/zoho_item_referenced?*' => Http::response([
                'code' => 1005,
                'message' => 'This item cannot be deleted as it is associated with transactions.',
            ], 200),
            // Fallback POST /inactive call returns success code 0
            'https://www.zohoapis.com/books/v3/items/zoho_item_referenced/inactive?*' => Http::response([
                'code' => 0,
                'message' => 'The item has been marked as inactive.',
            ], 200),
        ]);

        $payload = json_encode(['id' => 1007]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.zoho_inactivated'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);

        // Assert fallback POST inactive was sent
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/books/v3/items/zoho_item_referenced/inactive') &&
                $request->method() === 'POST';
        });
    }

    /**
     * 8. Invalid/missing product mapping → handled safely without exception.
     */
    public function test_product_does_not_exist_locally_handled_safely()
    {
        $this->createZohoConnection();

        $payload = json_encode([
            'id' => 9999999,
            'admin_graphql_api_id' => 'gid://shopify/Product/9999999',
        ]);

        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook_delete_nonexistent_008',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Product not found locally or already deleted.', $response->json('message'));
        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', 'webhook_delete_nonexistent_008')->count());
    }

    /**
     * 9. Missing delete permission (code 57) → fails webhook gracefully and preserves local state.
     */
    public function test_missing_delete_permission_returns_failure()
    {
        $this->createZohoConnection();

        $product = Product::create([
            'shop_id' => $this->shop1->id,
            'shopify_product_id' => 'gid://shopify/Product/1057',
            'title' => 'Code 57 Product',
            'handle' => 'code-57-product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5057',
            'title' => 'Default',
            'zoho_item_id' => 'zoho_item_57',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items/zoho_item_57*' => Http::response([
                'code' => 57,
                'message' => 'You are not authorized to perform this operation',
            ], 200),
        ]);

        $payload = json_encode(['id' => 1057]);
        $hmac = $this->createHmac($payload);

        $response = $this->call('POST', '/webhooks/products/delete', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'store-one.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook_delete_code57_009',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals(1, $response->json('summary.zoho_failures'));

        // Local product and variant MUST remain saved until permission issue is resolved and retry succeeds
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
    }
}
