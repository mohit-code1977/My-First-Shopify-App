<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\ZohoConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrdersRefreshPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;
    protected ZohoConnection $zohoConnection;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'perf-refresh-shop.myshopify.com',
            'access_token' => 'shpat_perf_test_123',
        ]);

        $this->zohoConnection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => '123456789',
            'access_token' => 'z_access_perf_123',
            'refresh_token' => 'z_refresh_perf_123',
            'expires_at' => now()->addHour(),
            'api_url' => 'https://www.zohoapis.com',
            'accounts_url' => 'https://accounts.zoho.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/99001',
            'first_name' => 'Perf',
            'last_name' => 'Tester',
            'email' => 'perf@example.com',
            'zoho_contact_id' => 'zoho_cust_perf_99001',
        ]);

        // Create 5 local orders with invoices and payments
        for ($i = 1; $i <= 5; $i++) {
            $order = Order::create([
                'shop_id' => $this->shop->id,
                'customer_id' => $this->customer->id,
                'shopify_order_id' => "gid://shopify/Order/9900{$i}",
                'order_number' => "#990{$i}",
                'currency' => 'USD',
                'subtotal' => 100.00 * $i,
                'discount_total' => 0.00,
                'shipping_total' => 10.00,
                'tax_total' => 0.00,
                'total_price' => (100.00 * $i) + 10.00,
                'financial_status' => 'paid',
                'zoho_sales_order_id' => "zoho_so_9900{$i}",
                'zoho_sales_order_number' => "SO-9900{$i}",
            ]);

            $invoice = Invoice::create([
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'zoho_invoice_id' => "zoho_inv_9900{$i}",
                'zoho_invoice_number' => "INV-9900{$i}",
                'total' => $order->total_price,
                'balance' => 0.00,
                'currency' => 'USD',
                'status' => 'paid',
            ]);

            Payment::create([
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'shopify_order_id' => $order->shopify_order_id,
                'shopify_transaction_id' => "gid://shopify/OrderTransaction/9900{$i}",
                'payment_reference' => "TXN-9900{$i}",
                'zoho_payment_id' => "zoho_pay_9900{$i}",
                'zoho_invoice_id' => $invoice->zoho_invoice_id,
                'amount' => $order->total_price,
                'currency' => 'USD',
                'status' => 'paid',
                'sync_status' => 'synced',
            ]);
        }
    }

    public function test_orders_data_refresh_returns_local_orders_without_external_api_calls(): void
    {
        Http::fake();

        $request = Request::create('/api/zoho/orders', 'GET');
        $request->attributes->set('shop', $this->shop);

        DB::enableQueryLog();
        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $response = $controller->ordersData($request);
        $queries = DB::getQueryLog();

        $this->assertEquals(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertCount(5, $data['orders']);
        $this->assertTrue($data['zohoConnected']);

        // Verify relationships are eager loaded
        $firstOrder = $data['orders'][0];
        $this->assertNotNull($firstOrder['customer']);
        $this->assertNotNull($firstOrder['invoice']);
        $this->assertNotEmpty($firstOrder['payments']);

        // Verify low DB query count (<= 10 queries, no N+1 query loops)
        $this->assertLessThanOrEqual(10, count($queries));

        // Verify NO external HTTP calls were made to Shopify or Zoho during standard refresh
        Http::assertNothingSent();
    }

    public function test_orders_data_preserves_invoice_and_payment_statuses(): void
    {
        $request = Request::create('/api/zoho/orders', 'GET');
        $request->attributes->set('shop', $this->shop);

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $response = $controller->ordersData($request);
        $data = $response->getData(true);

        foreach ($data['orders'] as $order) {
            $this->assertEquals('paid', $order['financial_status']);
            $this->assertNotNull($order['invoice']);
            $this->assertEquals('paid', $order['invoice']['status']);
            $this->assertNotEmpty($order['payments']);
            $this->assertEquals('synced', $order['payments'][0]['sync_status']);
        }
    }

    public function test_batch_ingestion_updates_orders_efficiently(): void
    {
        $rawOrders = [
            [
                'id' => 'gid://shopify/Order/99001',
                'order_number' => '#9901',
                'financial_status' => 'paid',
                'fulfillment_status' => 'fulfilled',
                'subtotal_price' => 100.00,
                'total_discounts' => 0.00,
                'shipping_lines' => [],
                'total_tax' => 0.00,
                'total_price' => 110.00,
                'currency' => 'USD',
                'customer' => [
                    'id' => 'gid://shopify/Customer/99001',
                    'first_name' => 'Perf',
                    'last_name' => 'Tester',
                    'email' => 'perf@example.com',
                ],
            ],
        ];

        DB::enableQueryLog();
        $controller = app(\App\Http\Controllers\ZohoSyncController::class);

        // Reflection to call protected ingestShopifyOrders
        $reflector = new \ReflectionClass($controller);
        $method = $reflector->getMethod('ingestShopifyOrders');
        $method->setAccessible(true);
        $method->invoke($controller, $this->shop, $rawOrders);

        $queries = DB::getQueryLog();

        // Verify order fulfillment status was updated
        $updatedOrder = Order::where('shopify_order_id', 'gid://shopify/Order/99001')->first();
        $this->assertEquals('fulfilled', $updatedOrder->fulfillment_status);

        // Verify query count is minimal for batch update
        $this->assertLessThanOrEqual(10, count($queries));
    }

    public function test_ingestion_normalizes_raw_numeric_id_matching_existing_gid_record_without_duplicate(): void
    {
        $rawOrders = [
            [
                'id' => '99001', // Raw numeric ID matching stored GID 'gid://shopify/Order/99001'
                'order_number' => '#9901',
                'financial_status' => 'paid',
                'fulfillment_status' => 'fulfilled',
                'subtotal_price' => 100.00,
                'total_discounts' => 0.00,
                'shipping_lines' => [],
                'total_tax' => 0.00,
                'total_price' => 110.00,
                'currency' => 'USD',
            ],
        ];

        $initialOrderCount = Order::where('shop_id', $this->shop->id)->count();

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $reflector = new \ReflectionClass($controller);
        $method = $reflector->getMethod('ingestShopifyOrders');
        $method->setAccessible(true);
        $method->invoke($controller, $this->shop, $rawOrders);

        // Count should remain unchanged (no duplicate created)
        $this->assertEquals($initialOrderCount, Order::where('shop_id', $this->shop->id)->count());

        $existingOrder = Order::where('shopify_order_id', 'gid://shopify/Order/99001')->first();
        $this->assertNotNull($existingOrder);
        $this->assertEquals('zoho_so_99001', $existingOrder->zoho_sales_order_id);
    }

    public function test_ingestion_normalizes_gid_id_matching_existing_raw_record_without_duplicate(): void
    {
        // Create an order stored with plain numeric ID
        $rawOrderRecord = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => '88001',
            'order_number' => '#8801',
            'currency' => 'USD',
            'subtotal' => 200.00,
            'total_price' => 200.00,
            'financial_status' => 'paid',
            'zoho_sales_order_id' => 'zoho_so_88001',
        ]);

        $rawOrders = [
            [
                'id' => 'gid://shopify/Order/88001', // GID input matching raw '88001'
                'order_number' => '#8801',
                'financial_status' => 'paid',
                'fulfillment_status' => 'fulfilled',
                'subtotal_price' => 200.00,
                'total_discounts' => 0.00,
                'shipping_lines' => [],
                'total_tax' => 0.00,
                'total_price' => 200.00,
                'currency' => 'USD',
            ],
        ];

        $initialOrderCount = Order::where('shop_id', $this->shop->id)->count();

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $reflector = new \ReflectionClass($controller);
        $method = $reflector->getMethod('ingestShopifyOrders');
        $method->setAccessible(true);
        $method->invoke($controller, $this->shop, $rawOrders);

        // Count should remain unchanged (no duplicate created)
        $this->assertEquals($initialOrderCount, Order::where('shop_id', $this->shop->id)->count());

        $updatedOrder = Order::find($rawOrderRecord->id);
        $this->assertEquals('fulfilled', $updatedOrder->fulfillment_status);
        $this->assertEquals('zoho_so_88001', $updatedOrder->zoho_sales_order_id);
    }

    public function test_batch_ingestion_preserves_existing_zoho_sync_data(): void
    {
        $existingOrder = Order::where('shopify_order_id', 'gid://shopify/Order/99001')->first();
        $initialZohoSoId = $existingOrder->zoho_sales_order_id;
        $initialInvoice = $existingOrder->invoice;
        $initialPayment = $existingOrder->payments->first();

        $rawOrders = [
            [
                'id' => '99001',
                'order_number' => '#9901',
                'financial_status' => 'paid',
                'fulfillment_status' => 'fulfilled',
                'subtotal_price' => 100.00,
                'total_discounts' => 0.00,
                'shipping_lines' => [],
                'total_tax' => 0.00,
                'total_price' => 110.00,
                'currency' => 'USD',
            ],
        ];

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $reflector = new \ReflectionClass($controller);
        $method = $reflector->getMethod('ingestShopifyOrders');
        $method->setAccessible(true);
        $method->invoke($controller, $this->shop, $rawOrders);

        $reloadedOrder = Order::with(['invoice', 'payments'])->find($existingOrder->id);
        $this->assertEquals($initialZohoSoId, $reloadedOrder->zoho_sales_order_id);
        $this->assertNotNull($reloadedOrder->invoice);
        $this->assertEquals($initialInvoice->id, $reloadedOrder->invoice->id);
        $this->assertNotEmpty($reloadedOrder->payments);
        $this->assertEquals($initialPayment->id, $reloadedOrder->payments->first()->id);
    }
}
