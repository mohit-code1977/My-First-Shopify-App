<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([\App\Http\Middleware\ShopifyAuthenticate::class]);

        $this->shop = Shop::create([
            'shop_domain' => 'test-shop-dashboard.myshopify.com',
            'access_token' => 'shpat_test123456789',
            'currency' => 'USD',
        ]);
    }

    protected function actingAsShop()
    {
        return $this->withHeaders([
            'X-Shop-Domain' => $this->shop->shop_domain,
            'Accept' => 'application/json',
        ]);
    }

    public function test_dashboard_data_endpoint_returns_200(): void
    {
        $response = $this->actingAsShop()
            ->getJson('/api/zoho/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'connected' => false,
            ])
            ->assertJsonStructure([
                'success',
                'connected',
                'zohoConnection' => ['is_connected', 'organization_name', 'organization_id', 'account_identifier', 'region', 'datacenter'],
                'shop' => ['id', 'shop_domain', 'currency'],
                'priceList' => ['shopify_currency', 'zoho_base_currency', 'active_price_list_name', 'price_list_currency', 'status'],
                'stats' => ['products', 'orders', 'invoices', 'payments', 'customers', 'failed_total'],
                'syncHealth' => ['products', 'orders', 'invoices', 'customers', 'payments', 'inventory'],
                'recentActivity',
                'failedSyncs',
                'paymentActivity',
            ]);
    }

    public function test_empty_sync_history_returns_empty_arrays(): void
    {
        $response = $this->actingAsShop()
            ->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('recentActivity'));
        $this->assertEmpty($response->json('recentActivity'));
        $this->assertIsArray($response->json('failedSyncs'));
        $this->assertEmpty($response->json('failedSyncs'));
    }

    public function test_historical_failed_sync_with_current_success_results_in_zero_failed(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/101',
            'title' => 'Test Product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1011',
            'title' => 'Variant 101',
            'sku' => 'SKU-101',
            'price' => 19.99,
            'zoho_item_id' => 'zoho_item_101', // CURRENTLY SYNCED
        ]);

        // Create 3 historical failure log entries
        for ($i = 0; $i < 3; $i++) {
            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'create',
                'status' => 'failed',
                'message' => 'Past historical failure attempt',
            ]);
        }

        $response = $this->actingAsShop()->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('stats.products.synced'));
        $this->assertEquals(0, $response->json('stats.products.failed'));
    }

    public function test_currently_failed_product_counts_failed_one(): void
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/102',
            'title' => 'Failed Product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1022',
            'title' => 'Variant 102',
            'sku' => 'SKU-102',
            'price' => 29.99,
            'zoho_item_id' => null, // CURRENTLY UNSYNCED
        ]);

        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'product_variant_id' => $variant->id,
            'action' => 'create',
            'status' => 'failed',
            'message' => 'Latest sync attempt failed',
        ]);

        $response = $this->actingAsShop()->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('stats.products.total_variants'));
        $this->assertEquals(0, $response->json('stats.products.synced'));
        $this->assertEquals(1, $response->json('stats.products.failed'));
    }

    public function test_multiple_historical_failures_for_single_entity_counts_entity_once(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/201',
            'order_number' => '#1001',
            'zoho_sales_order_id' => null, // UNSYNCED
        ]);

        // Create 5 historical failure entries for single order
        for ($i = 0; $i < 5; $i++) {
            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'action' => 'sync_order',
                'status' => 'failed',
                'message' => 'Rate limit error attempt ' . $i,
            ]);
        }

        $response = $this->actingAsShop()->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('stats.orders.failed'));
    }

    public function test_orders_dashboard_failed_count_matches_current_order_dataset(): void
    {
        Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/202',
            'order_number' => '#1002',
            'zoho_sales_order_id' => 'zoho_so_202', // SYNCED
        ]);

        $failedOrder = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/203',
            'order_number' => '#1003',
            'zoho_sales_order_id' => null, // UNSYNCED FAILED
        ]);

        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'order_id' => $failedOrder->id,
            'action' => 'sync_order',
            'status' => 'failed',
            'message' => 'Missing customer email',
        ]);

        $response = $this->actingAsShop()->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('stats.orders.total'));
        $this->assertEquals(1, $response->json('stats.orders.synced'));
        $this->assertEquals(1, $response->json('stats.orders.failed'));
    }

    public function test_payments_current_failed_count(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/301',
            'order_number' => '#1004',
        ]);

        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'TXN-1',
            'amount' => 100.00,
            'currency' => 'USD',
            'sync_status' => Payment::SYNC_STATUS_SYNCED,
            'zoho_payment_id' => 'PAY-Z1',
        ]);

        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'TXN-2',
            'amount' => 50.00,
            'currency' => 'USD',
            'sync_status' => Payment::SYNC_STATUS_FAILED,
            'error_message' => 'Zoho account mapped invalid',
        ]);

        $response = $this->actingAsShop()->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('stats.payments.total'));
        $this->assertEquals(1, $response->json('stats.payments.synced'));
        $this->assertEquals(1, $response->json('stats.payments.failed'));
    }

    public function test_zoho_account_metadata_visible_and_no_secrets_exposed(): void
    {
        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'organization_id' => '60082438046',
            'organization_name' => 'Shopify Zoho Integration Demo',
            'access_token' => 'secret_access_token_123',
            'refresh_token' => 'secret_refresh_token_456',
            'accounts_url' => 'https://accounts.zoho.in',
            'api_url' => 'https://www.zohoapis.in',
            'api_domain' => 'www.zohoapis.in',
            'data_center' => 'in',
            'connected_at' => now(),
        ]);

        $response = $this->actingAsShop()->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertTrue($json['connected']);
        $this->assertEquals('Shopify Zoho Integration Demo', $json['zohoConnection']['organization_name']);
        $this->assertEquals('60082438046', $json['zohoConnection']['organization_id']);
        $this->assertEquals('India', $json['zohoConnection']['region']);
        $this->assertEquals('zohoapis.in', $json['zohoConnection']['datacenter']);

        $content = $response->getContent();
        $this->assertStringNotContainsString('secret_access_token', $content);
        $this->assertStringNotContainsString('secret_refresh_token', $content);
    }

    public function test_no_raw_sql_error_exposed(): void
    {
        $response = $this->actingAsShop()
            ->getJson('/api/zoho/dashboard');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('Unknown column', $content);
    }
}
