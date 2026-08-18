<?php

namespace Tests\Unit;

use App\Http\Middleware\ShopifyAuthenticate;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ShopifyService;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoBulkSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;
    protected Shop $otherShop;
    protected ZohoConnection $zohoConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ShopifyAuthenticate::class]);

        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('ensureWebhooksRegistered')->andReturn([]);
        });

        $this->shop = Shop::create([
            'shop_domain' => 'test-bulk-shop.myshopify.com',
            'access_token' => 'shpua_test_token',
        ]);

        $this->otherShop = Shop::create([
            'shop_domain' => 'other-tenant-shop.myshopify.com',
            'access_token' => 'shpua_other_token',
        ]);

        $this->zohoConnection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token',
            'refresh_token' => 'zoho_refresh_token',
            'organization_id' => 'zoho_org_123',
            'is_active' => true,
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    /** @test */
    public function test_bulk_sync_orders_single_and_multiple_selection_success(): void
    {
        $order1 = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1001',
            'order_number' => '1001',
            'total_price' => 100.00,
            'currency' => 'USD',
        ]);

        $order2 = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1002',
            'order_number' => '1002',
            'total_price' => 200.00,
            'currency' => 'USD',
        ]);

        $this->mock(ZohoService::class, function ($mock) use ($order1, $order2) {
            $mock->shouldReceive('syncOrder')
                ->with(\Mockery::on(fn($o) => (int)$o->id === (int)$order1->id))
                ->once()
                ->andReturn(['success' => true, 'message' => 'Sales order created', 'zoho_salesorder_id' => 'SO-1001']);

            $mock->shouldReceive('syncOrder')
                ->with(\Mockery::on(fn($o) => (int)$o->id === (int)$order2->id))
                ->once()
                ->andReturn(['success' => true, 'message' => 'Sales order created', 'zoho_salesorder_id' => 'SO-1002']);
        });

        $url = route('zoho.bulk-sync-orders') . '?shop=' . $this->shop->shop_domain;
        $response = $this->postJson($url, [
            'order_ids' => [$order1->id, $order2->id],
            'sync_type' => 'order',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'total' => 2,
                    'synced' => 2,
                    'failed' => 0,
                    'skipped' => 0,
                ],
            ])
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.status', 'success')
            ->assertJsonPath('results.1.status', 'success');
    }

    /** @test */
    public function test_bulk_sync_orders_tenant_isolation(): void
    {
        $ownOrder = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1003',
            'order_number' => '1003',
            'total_price' => 50.00,
        ]);

        $otherTenantOrder = Order::create([
            'shop_id' => $this->otherShop->id,
            'shopify_order_id' => '9999',
            'order_number' => '9999',
            'total_price' => 50.00,
        ]);

        $this->mock(ZohoService::class, function ($mock) use ($ownOrder) {
            $mock->shouldReceive('syncOrder')
                ->with(\Mockery::on(fn($o) => (int)$o->id === (int)$ownOrder->id))
                ->once()
                ->andReturn(['success' => true, 'message' => 'Synced']);
        });

        $url = route('zoho.bulk-sync-orders') . '?shop=' . $this->shop->shop_domain;
        $response = $this->postJson($url, [
            'order_ids' => [$ownOrder->id, $otherTenantOrder->id],
            'sync_type' => 'order',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'total' => 2,
                    'synced' => 1,
                    'failed' => 1,
                    'skipped' => 0,
                ],
            ]);

        $results = $response->json('results');
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals('failed', $results[1]['status']);
        $this->assertStringContainsString('not found for current shop', strtolower($results[1]['message']));
    }

    /** @test */
    public function test_bulk_sync_orders_partial_failure(): void
    {
        $order1 = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1004',
            'order_number' => '1004',
            'total_price' => 120.00,
        ]);

        $order2 = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1005',
            'order_number' => '1005',
            'total_price' => 140.00,
        ]);

        $this->mock(ZohoService::class, function ($mock) use ($order1, $order2) {
            $mock->shouldReceive('syncInvoice')
                ->with(\Mockery::on(fn($o) => (int)$o->id === (int)$order1->id))
                ->once()
                ->andReturn(['success' => true, 'message' => 'Invoice synced']);

            $mock->shouldReceive('syncInvoice')
                ->with(\Mockery::on(fn($o) => (int)$o->id === (int)$order2->id))
                ->once()
                ->andReturn(['success' => false, 'message' => 'Unmapped SKU for item T-Shirt']);
        });

        $url = route('zoho.bulk-sync-orders') . '?shop=' . $this->shop->shop_domain;
        $response = $this->postJson($url, [
            'order_ids' => [$order1->id, $order2->id],
            'sync_type' => 'invoice',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'total' => 2,
                    'synced' => 1,
                    'failed' => 1,
                    'skipped' => 0,
                ],
            ]);

        $results = $response->json('results');
        $this->assertEquals($order1->id, $results[0]['id']);
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals($order2->id, $results[1]['id']);
        $this->assertEquals('failed', $results[1]['status']);
        $this->assertStringContainsString('Unmapped SKU', $results[1]['message']);
    }

    /** @test */
    public function test_bulk_sync_orders_idempotency_and_skipped(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1006',
            'order_number' => '1006',
            'total_price' => 300.00,
        ]);

        $this->mock(ZohoService::class, function ($mock) use ($order) {
            $mock->shouldReceive('syncOrder')
                ->with(\Mockery::on(fn($o) => (int)$o->id === (int)$order->id))
                ->once()
                ->andReturn([
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Order #1006 already synced to Zoho Books.',
                ]);
        });

        $url = route('zoho.bulk-sync-orders') . '?shop=' . $this->shop->shop_domain;
        $response = $this->postJson($url, [
            'order_ids' => [$order->id],
            'sync_type' => 'order',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'total' => 1,
                    'synced' => 0,
                    'failed' => 0,
                    'skipped' => 1,
                ],
            ]);

        $this->assertEquals('skipped', $response->json('results.0.status'));
    }

    /** @test */
    public function test_bulk_sync_customers_success_and_tenant_isolation(): void
    {
        $customer1 = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => '5001',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@example.com',
        ]);

        $otherCustomer = Customer::create([
            'shop_id' => $this->otherShop->id,
            'shopify_customer_id' => '9991',
            'first_name' => 'Bob',
            'last_name' => 'Other',
            'email' => 'bob@other.com',
        ]);

        $this->mock(ZohoService::class, function ($mock) use ($customer1) {
            $mock->shouldReceive('syncCustomer')
                ->with(\Mockery::on(fn($c) => (int)$c->id === (int)$customer1->id))
                ->once()
                ->andReturn(['success' => true, 'message' => 'Customer synced', 'zoho_contact_id' => 'Z-5001']);
        });

        $url = route('zoho.bulk-sync-customers') . '?shop=' . $this->shop->shop_domain;
        $response = $this->postJson($url, [
            'customer_ids' => [$customer1->id, $otherCustomer->id],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'total' => 2,
                    'synced' => 1,
                    'failed' => 1,
                    'skipped' => 0,
                ],
            ]);

        $results = $response->json('results');
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals('failed', $results[1]['status']);
        $this->assertStringContainsString('not found for current shop', strtolower($results[1]['message']));
    }

    /** @test */
    public function test_bulk_sync_refunds_success_and_partial_failure(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '1007',
            'order_number' => '1007',
            'total_price' => 75.00,
        ]);

        $refund1 = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 1007,
            'shopify_refund_id' => '99001',
            'amount' => 50.00,
            'currency' => 'USD',
            'sync_status' => 'pending',
        ]);

        $refund2 = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 1007,
            'shopify_refund_id' => '99002',
            'amount' => 25.00,
            'currency' => 'USD',
            'sync_status' => 'failed',
            'error_message' => 'Previous failure',
        ]);

        $this->mock(ZohoService::class, function ($mock) use ($refund1, $refund2) {
            $mock->shouldReceive('syncRefund')
                ->with(\Mockery::on(fn($r) => (int)$r->id === (int)$refund1->id))
                ->once()
                ->andReturn(['success' => true, 'message' => 'Credit Note CN-99001 created']);

            $mock->shouldReceive('syncRefund')
                ->with(\Mockery::on(fn($r) => (int)$r->id === (int)$refund2->id))
                ->once()
                ->andReturn(['success' => false, 'message' => 'Zoho Credit Note Authorization error']);
        });

        $url = route('zoho.bulk-sync-refunds') . '?shop=' . $this->shop->shop_domain;
        $response = $this->postJson($url, [
            'refund_ids' => [$refund1->id, $refund2->id],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'summary' => [
                    'total' => 2,
                    'synced' => 1,
                    'failed' => 1,
                    'skipped' => 0,
                ],
            ]);

        $results = $response->json('results');
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals('failed', $results[1]['status']);
    }
}
