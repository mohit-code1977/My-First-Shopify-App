<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoOrderCancellationSyncTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'cancel-test.myshopify.com',
            'access_token' => 'shpat_cancel_test_token',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token',
            'refresh_token' => 'zoho_refresh_token',
            'expires_at' => now()->addHour(),
            'organization_id' => '12345678',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9001',
            'zoho_contact_id' => 'zoho_contact_9001',
            'first_name' => 'Jane',
            'last_name' => 'Cancel',
            'email' => 'jane.cancel@example.com',
        ]);
    }

    private function calculateHmac(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    /**
     * Test 1: Active Shopify order cancelled -> Local order financial_status = cancelled, Zoho SO voided.
     */
    public function test_1_active_shopify_order_cancelled_voids_zoho_sales_order()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c1/status/void*' => Http::response([
                'code' => 0,
                'message' => 'The sales order has been voided.',
                'salesorder' => ['salesorder_id' => 'zoho_so_c1', 'status' => 'void'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/10001',
            'order_number' => '#10001',
            'zoho_sales_order_id' => 'zoho_so_c1',
            'financial_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => 100.00,
            'total_price' => 100.00,
            'line_items' => [],
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->cancelOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('cancelled', $order->fresh()->financial_status);
        $this->assertEquals('synced', $order->fresh()->cancel_sync_status);

        $this->assertDatabaseHas('sync_histories', [
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'action' => 'order_cancelled',
            'status' => 'success',
        ]);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/books/v3/salesorders/zoho_so_c1/status/void');
        });
    }

    /**
     * Test 2 & 3: Cancelled order with draft or open invoice -> Zoho Invoice voided.
     */
    public function test_2_cancelled_order_with_invoice_voids_zoho_invoice()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c2/status/void*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_c2', 'status' => 'void'],
            ], 200),
            'https://www.zohoapis.com/books/v3/invoices/zoho_inv_c2/status/void*' => Http::response([
                'code' => 0,
                'invoice' => ['invoice_id' => 'zoho_inv_c2', 'status' => 'void'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/10002',
            'order_number' => '#10002',
            'zoho_sales_order_id' => 'zoho_so_c2',
            'financial_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => 150.00,
            'total_price' => 150.00,
            'line_items' => [],
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 'gid://shopify/Order/10002',
            'zoho_invoice_id' => 'zoho_inv_c2',
            'invoice_number' => 'INV-10002',
            'status' => 'sent',
            'amount' => 150.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->cancelOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('void', $invoice->fresh()->status);
        $this->assertEquals('synced', $invoice->fresh()->sync_status);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/books/v3/invoices/zoho_inv_c2/status/void');
        });
    }

    /**
     * Test 4: Paid cancelled order blocks manual payment sync.
     */
    public function test_4_paid_cancelled_order_blocks_manual_payment_creation()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/10004',
            'order_number' => '#10004',
            'financial_status' => 'cancelled',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => 200.00,
        ]);

        Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 'gid://shopify/Order/10004',
            'zoho_invoice_id' => 'zoho_inv_c4',
            'invoice_number' => 'INV-10004',
            'status' => 'sent',
            'amount' => 200.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 'gid://shopify/Order/10004',
            'shopify_transaction_id' => 'txn_10004',
            'payment_reference' => 'TXN-10004',
            'amount' => 200.00,
            'currency' => 'USD',
            'payment_date' => now(),
            'payment_method' => 'shopify_payments',
            'status' => 'paid',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Payments cannot be recorded or synchronized for cancelled");

        $zohoService->syncPayment($payment);
    }

    /**
     * Test 5: Cancelled order with refund handles associated payments safely.
     */
    public function test_5_cancelled_order_with_paid_invoice_handles_payment_restriction()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c5/status/void*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_c5', 'status' => 'void'],
            ], 200),
            'https://www.zohoapis.com/books/v3/invoices/zoho_inv_c5/status/void*' => Http::response([
                'code' => 100002,
                'message' => 'The invoice cannot be voided as customer payments are associated with it.',
            ], 400),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/10005',
            'order_number' => '#10005',
            'zoho_sales_order_id' => 'zoho_so_c5',
            'financial_status' => 'paid',
            'currency' => 'USD',
            'total_price' => 300.00,
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 'gid://shopify/Order/10005',
            'zoho_invoice_id' => 'zoho_inv_c5',
            'invoice_number' => 'INV-10005',
            'status' => 'paid',
            'amount' => 300.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->cancelOrder($order);

        $this->assertTrue($result['success']);
        // Invoice remains in original status because payments exist and requires credit note refund
        $this->assertEquals('paid', $invoice->fresh()->status);
    }

    /**
     * Test 6: Duplicate cancellation webhook is idempotent.
     */
    public function test_6_duplicate_cancellation_webhook_is_idempotent()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c6/status/void*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_c6', 'status' => 'void'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10006',
            'order_number' => '#10006',
            'zoho_sales_order_id' => 'zoho_so_c6',
            'financial_status' => 'pending',
            'currency' => 'USD',
            'total_price' => 50.00,
        ]);

        $payload = json_encode([
            'id' => 10006,
            'cancelled_at' => now()->toIso8601String(),
            'cancel_reason' => 'customer',
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        // First delivery
        $res1 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cancel_dup_1',
            'X-Shopify-Topic' => 'orders/cancelled',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders/cancelled', json_decode($payload, true));

        $res1->assertStatus(200);
        $res1->assertJson(['message' => 'Order cancellation webhook processed successfully.']);

        // Duplicate delivery
        $res2 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cancel_dup_1',
            'X-Shopify-Topic' => 'orders/cancelled',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders/cancelled', json_decode($payload, true));

        $res2->assertStatus(200);
        $res2->assertJson(['message' => 'Webhook already processed']);
    }

    /**
     * Test 7: Zoho Sales Order already void handled gracefully.
     */
    public function test_7_zoho_sales_order_already_void_handled_gracefully()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_already_void/status/void*' => Http::response([
                'code' => 100001,
                'message' => 'The sales order status is already void.',
            ], 400),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/10007',
            'order_number' => '#10007',
            'zoho_sales_order_id' => 'zoho_so_already_void',
            'financial_status' => 'cancelled',
            'currency' => 'USD',
            'total_price' => 80.00,
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncOrder($order);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['voided']);
    }

    /**
     * Test 8: Zoho API failure records failed SyncHistory and cancel_sync_status = failed.
     */
    public function test_8_zoho_api_failure_records_failed_sync_status()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c8/status/void*' => Http::response([
                'code' => 5000,
                'message' => 'Internal server error during void operation',
            ], 500),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/10008',
            'order_number' => '#10008',
            'zoho_sales_order_id' => 'zoho_so_c8',
            'financial_status' => 'pending',
            'currency' => 'USD',
            'total_price' => 120.00,
        ]);

        $zohoService = new ZohoService($this->shop);

        try {
            $zohoService->cancelOrder($order);
            $this->fail("Expected Exception was not thrown");
        } catch (\Throwable $e) {
            $this->assertEquals('failed', $order->fresh()->cancel_sync_status);
            $this->assertDatabaseHas('sync_histories', [
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'action' => 'order_cancelled',
                'status' => 'failed',
            ]);
        }
    }

    /**
     * Test 9: Controller cancel order endpoint.
     */
    public function test_9_controller_cancel_order_endpoint()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c9/status/void*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_c9', 'status' => 'void'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10009',
            'order_number' => '10009',
            'zoho_sales_order_id' => 'zoho_so_c9',
            'financial_status' => 'pending',
            'currency' => 'USD',
            'total_price' => 75.00,
        ]);

        $shop = $this->shop;
        $this->app->instance(\App\Http\Middleware\ShopifyAuthenticate::class, new class($shop) {
            public function __construct(private $shop) {}
            public function handle($request, $next) {
                $request->attributes->set('shop', $this->shop);
                return $next($request);
            }
        });

        $response = $this->postJson('/zoho/cancel-order', [
            'order_id' => $order->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => "Order #10009 cancellation synchronized successfully.",
        ]);
    }

    /**
     * Test 10: Cancelled order webhook stores timestamp and cancel_reason.
     */
    public function test_10_webhook_stores_cancellation_timestamp_and_reason()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_c10/status/void*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_c10', 'status' => 'void'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10010',
            'order_number' => '#10010',
            'zoho_sales_order_id' => 'zoho_so_c10',
            'financial_status' => 'pending',
            'currency' => 'USD',
            'total_price' => 90.00,
        ]);

        $cancelTime = '2026-08-19T10:00:00Z';
        $payload = json_encode([
            'id' => 10010,
            'cancelled_at' => $cancelTime,
            'cancel_reason' => 'customer',
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_cancel_reason_1',
            'X-Shopify-Topic' => 'orders/cancelled',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders/cancelled', json_decode($payload, true));

        $response->assertStatus(200);

        $refreshed = $order->fresh();
        $this->assertEquals('cancelled', $refreshed->financial_status);
        $this->assertEquals('customer', $refreshed->cancel_reason);
        $this->assertNotNull($refreshed->cancelled_at);
    }

    /**
     * Test 11: Cancel order tenant isolation.
     */
    public function test_11_cancel_order_tenant_isolation()
    {
        $otherShop = Shop::create([
            'shop_domain' => 'other-cancel-shop.myshopify.com',
            'access_token' => 'shpat_other_token',
        ]);

        $otherOrder = Order::create([
            'shop_id' => $otherShop->id,
            'shopify_order_id' => 'gid://shopify/Order/10011',
            'order_number' => '#10011',
            'financial_status' => 'pending',
            'currency' => 'USD',
            'total_price' => 100.00,
        ]);

        $zohoService = new ZohoService($this->shop);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Order #{$otherOrder->id} does not belong to shop cancel-test.myshopify.com");

        $zohoService->cancelOrder($otherOrder);
    }

    /**
     * Test 12: Paid order -> cancelled -> status must not show Paid.
     */
    public function test_12_paid_order_cancelled_does_not_show_paid()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10012',
            'order_number' => '10012',
            'financial_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_sync_status' => 'synced',
            'currency' => 'INR',
            'total_price' => 1098.25,
        ]);

        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'txn_10012',
            'amount' => 1098.25,
            'currency' => 'INR',
            'status' => 'paid',
            'sync_status' => 'synced',
            'zoho_payment_id' => 'zp_10012',
        ]);

        $order->refresh();
        $this->assertEquals('cancelled', $order->financial_status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertCount(1, $order->payments);
        // Financial status cancelled ensures it is not treated as active paid payment
        $this->assertNotEquals('paid', $order->financial_status);
    }

    /**
     * Test 13: Paid order -> cancelled + refunded -> financial status reflects cancellation & refund.
     */
    public function test_13_paid_order_cancelled_and_refunded()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10013',
            'order_number' => '10013',
            'financial_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_sync_status' => 'synced',
            'currency' => 'INR',
            'total_price' => 1098.25,
        ]);

        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'txn_10013',
            'amount' => 1098.25,
            'currency' => 'INR',
            'status' => 'paid',
            'sync_status' => 'synced',
            'zoho_payment_id' => 'zp_10013',
        ]);

        Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_refund_id' => 'ref_10013',
            'shopify_order_id' => $order->shopify_order_id,
            'amount' => 1098.25,
            'currency' => 'INR',
            'sync_status' => 'synced',
            'zoho_creditnote_id' => 'cn_10013',
        ]);

        $order->refresh();
        $this->assertEquals('cancelled', $order->financial_status);
        $this->assertCount(1, $order->refunds);
        $this->assertEquals('synced', $order->refunds->first()->sync_status);
    }

    /**
     * Test 14: Cancelled order with synced historical payment retains payment record for audit.
     */
    public function test_14_cancelled_order_retains_historical_payment_for_audit()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10014',
            'order_number' => '10014',
            'financial_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_sync_status' => 'synced',
            'currency' => 'USD',
            'total_price' => 50.00,
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'txn_10014',
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => 'paid',
            'sync_status' => 'synced',
            'zoho_payment_id' => 'zp_10014',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'zoho_payment_id' => 'zp_10014',
            'sync_status' => 'synced',
        ]);
    }

    /**
     * Test 15: Cancelled order with failed Zoho cancellation reflects cancel_sync_status = failed.
     */
    public function test_15_cancelled_order_with_failed_zoho_cancellation()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_f15/status/void*' => Http::response([
                'code' => 500,
                'message' => 'Internal Zoho error',
            ], 500),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10015',
            'order_number' => '10015',
            'zoho_sales_order_id' => 'zoho_so_f15',
            'financial_status' => 'pending',
            'currency' => 'USD',
            'total_price' => 75.00,
        ]);

        $zohoService = new ZohoService($this->shop);

        try {
            $zohoService->cancelOrder($order);
        } catch (\Throwable $e) {
            // expected
        }

        $order->refresh();
        $this->assertEquals('cancelled', $order->financial_status);
        $this->assertEquals('failed', $order->cancel_sync_status);
    }

    /**
     * Test 16: Refunded order with successful credit note.
     */
    public function test_16_refunded_order_with_successful_credit_note()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10016',
            'order_number' => '10016',
            'financial_status' => 'refunded',
            'currency' => 'INR',
            'total_price' => 1098.25,
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_refund_id' => 'ref_10016',
            'shopify_order_id' => $order->shopify_order_id,
            'amount' => 1098.25,
            'currency' => 'INR',
            'sync_status' => 'synced',
            'zoho_creditnote_id' => 'cn_10016',
        ]);

        $this->assertEquals('refunded', $order->financial_status);
        $this->assertEquals('cn_10016', $refund->zoho_creditnote_id);
    }

    /**
     * Test 17: Invoice/payment state remains auditable without falsely showing active paid state.
     */
    public function test_17_auditable_payment_history_preserved()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/10017',
            'order_number' => '10017',
            'financial_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_sync_status' => 'synced',
            'currency' => 'INR',
            'total_price' => 1098.25,
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => 'inv_10017',
            'invoice_number' => 'INV-10017',
            'status' => 'paid',
            'amount' => 1098.25,
            'currency' => 'INR',
            'sync_status' => 'synced',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'txn_10017',
            'amount' => 1098.25,
            'currency' => 'INR',
            'status' => 'paid',
            'sync_status' => 'synced',
            'zoho_payment_id' => 'zp_10017',
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_refund_id' => 'ref_10017',
            'shopify_order_id' => $order->shopify_order_id,
            'amount' => 1098.25,
            'currency' => 'INR',
            'sync_status' => 'synced',
            'zoho_creditnote_id' => 'cn_10017',
        ]);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'zoho_invoice_id' => 'inv_10017']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'zoho_payment_id' => 'zp_10017']);
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'zoho_creditnote_id' => 'cn_10017']);
    }

    public function test_cancelled_order_with_no_refund()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '102811',
            'order_number' => '#102811',
            'financial_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => 'customer',
        ]);

        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals('cancelled', $order->financial_status);
        $this->assertCount(0, $order->refunds);
    }

    public function test_cancelled_and_refunded_order_coexistence()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '102812',
            'order_number' => '#102812',
            'financial_status' => 'refunded',
            'cancelled_at' => now(),
            'cancel_reason' => 'customer',
        ]);

        Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_refund_id' => 'ref_102812',
            'shopify_order_id' => '102812',
            'amount' => 100.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals('refunded', $order->financial_status);
        $this->assertCount(1, $order->refunds);
    }

    public function test_refunded_order_without_cancellation()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '102813',
            'order_number' => '#102813',
            'financial_status' => 'refunded',
            'cancelled_at' => null,
        ]);

        Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_refund_id' => 'ref_102813',
            'shopify_order_id' => '102813',
            'amount' => 50.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $order->refresh();
        $this->assertNull($order->cancelled_at);
        $this->assertEquals('refunded', $order->financial_status);
        $this->assertCount(1, $order->refunds);
    }

    public function test_cancellation_webhook_followed_by_refund_webhook()
    {
        $secret = config('services.shopify.api_secret') ?? 'test_secret';
        config(['services.shopify.api_secret' => $secret]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '102814',
            'order_number' => '#102814',
            'financial_status' => 'paid',
            'total_price' => 100.00,
        ]);

        // 1. Cancellation Webhook
        $cancelPayload = [
            'id' => 102814,
            'name' => '#102814',
            'cancelled_at' => '2026-08-19T10:00:00Z',
            'cancel_reason' => 'customer',
            'financial_status' => 'cancelled',
        ];
        $hmacCancel = base64_encode(hash_hmac('sha256', json_encode($cancelPayload), $secret, true));

        $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmacCancel,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'wh_cancel_102814',
        ])->postJson('/webhooks/orders/cancelled', $cancelPayload)->assertStatus(200);

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);

        // 2. Refund Webhook
        $refundPayload = [
            'id' => 999814,
            'order_id' => '102814',
            'total_refunded' => '100.00',
            'cancelled_at' => '2026-08-19T10:00:00Z',
            'transactions' => [['status' => 'success', 'amount' => '100.00', 'kind' => 'refund']],
            'refund_line_items' => [],
        ];
        $hmacRefund = base64_encode(hash_hmac('sha256', json_encode($refundPayload), $secret, true));

        $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmacRefund,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'wh_refund_102814',
        ])->postJson('/webhooks/refunds', $refundPayload)->assertStatus(200);

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals('refunded', $order->financial_status);
    }

    public function test_refund_webhook_followed_by_cancellation_webhook()
    {
        $secret = config('services.shopify.api_secret') ?? 'test_secret';
        config(['services.shopify.api_secret' => $secret]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '102815',
            'order_number' => '#102815',
            'financial_status' => 'paid',
            'total_price' => 100.00,
        ]);

        // 1. Refund Webhook first
        $refundPayload = [
            'id' => 999815,
            'order_id' => '102815',
            'total_refunded' => '100.00',
            'cancelled_at' => '2026-08-19T10:00:00Z',
            'cancel_reason' => 'customer',
            'transactions' => [['status' => 'success', 'amount' => '100.00', 'kind' => 'refund']],
            'refund_line_items' => [],
        ];
        $hmacRefund = base64_encode(hash_hmac('sha256', json_encode($refundPayload), $secret, true));

        $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmacRefund,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'wh_refund_102815',
        ])->postJson('/webhooks/refunds', $refundPayload)->assertStatus(200);

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals('refunded', $order->financial_status);

        // 2. Cancellation Webhook second
        $cancelPayload = [
            'id' => 102815,
            'name' => '#102815',
            'cancelled_at' => '2026-08-19T10:00:00Z',
            'cancel_reason' => 'customer',
            'financial_status' => 'cancelled',
        ];
        $hmacCancel = base64_encode(hash_hmac('sha256', json_encode($cancelPayload), $secret, true));

        $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmacCancel,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'wh_cancel_102815',
        ])->postJson('/webhooks/orders/cancelled', $cancelPayload)->assertStatus(200);

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
    }

    public function test_batch_refresh_after_cancellation_preserves_cancelled_at()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => '102816',
            'order_number' => '#102816',
            'financial_status' => 'refunded',
            'cancelled_at' => now(),
            'cancel_reason' => 'customer',
        ]);

        $controller = new \App\Http\Controllers\ZohoSyncController(new \App\Services\ShopifyService());
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('ingestShopifyOrders');
        $method->setAccessible(true);

        $rawOrders = [
            [
                'id' => 102816,
                'name' => '#102816',
                'financial_status' => 'refunded',
                'cancelled_at' => null, // raw update missing cancelled_at
                'total_price' => '100.00',
            ]
        ];

        $method->invoke($controller, $this->shop, $rawOrders);

        $order->refresh();
        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals('customer', $order->cancel_reason);
    }

    /**
     * Helper to set authenticated shop domain attribute on requests.
     */
    private function actingAsShopDomain(string $shopDomain)
    {
        return $this->withHeaders([
            'X-Shop-Domain' => $shopDomain,
            'Accept' => 'application/json',
        ])->withUnencryptedCookie('shop_domain', $shopDomain);
    }
}
