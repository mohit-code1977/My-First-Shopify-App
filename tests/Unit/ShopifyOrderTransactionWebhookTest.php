<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ShopifyProcessedWebhook;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOrderTransactionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;
    private Order $order;
    private Invoice $invoice;
    private string $apiSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiSecret = 'test_webhook_secret_key_123';
        config(['services.shopify.api_secret' => $this->apiSecret]);

        $this->shop = Shop::create([
            'shop_domain' => 'txn-webhook-store.myshopify.com',
            'access_token' => 'shpat_txn_webhook_token',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token_txn',
            'refresh_token' => 'zoho_refresh_token_txn',
            'expires_at' => now()->addHour(),
            'organization_id' => '88776655',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/7100',
            'zoho_contact_id' => 'zoho_contact_7100',
            'first_name' => 'Bob',
            'last_name' => 'Webhook',
            'email' => 'bob.webhook@example.com',
        ]);

        $this->order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7200',
            'order_number' => '#ORD-7200',
            'zoho_sales_order_id' => 'zoho_so_7200',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '200.00',
            'total_price' => '200.00',
        ]);

        $this->invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_7200',
            'invoice_number' => 'INV-07200',
            'status' => 'sent',
            'amount' => '200.00',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);
    }

    private function createHmac(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, $this->apiSecret, true));
    }

    private function sendWebhook(array $payload, array $headers = [])
    {
        $jsonPayload = json_encode($payload);
        $hmac = $this->createHmac($jsonPayload);

        $defaultHeaders = [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'HTTP_X_SHOPIFY_TOPIC' => 'order_transactions/create',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_' . uniqid(),
            'CONTENT_TYPE' => 'application/json',
        ];

        $finalHeaders = array_merge($defaultHeaders, $headers);

        return $this->call('POST', '/webhooks/order-transactions', [], [], [], $finalHeaders, $jsonPayload);
    }

    // 1. Valid HMAC accepted
    public function test_1_valid_hmac_accepted()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_1']], 201);
            },
        ]);

        $payload = [
            'id' => 8001,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8001',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);
        $this->assertEquals('Order transaction webhook processed successfully.', $response->json('message'));
    }

    // 2. Invalid HMAC rejected
    public function test_2_invalid_hmac_rejected()
    {
        $jsonPayload = json_encode(['id' => 8002]);
        $response = $this->call('POST', '/webhooks/order-transactions', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid_hmac_signature',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shop_domain,
            'CONTENT_TYPE' => 'application/json',
        ], $jsonPayload);

        $response->assertStatus(401);
        $this->assertEquals('Invalid HMAC signature.', $response->json('error'));
    }

    // 3. Correct shop resolved
    public function test_3_correct_shop_resolved()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_3']], 201);
            },
        ]);

        $payload = [
            'id' => 8003,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8003',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8003')->first();
        $this->assertNotNull($payment);
        $this->assertEquals($this->shop->id, $payment->shop_id);
    }

    // 4. Duplicate webhook delivery is ignored
    public function test_4_duplicate_webhook_is_ignored()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_4']], 201);
            },
        ]);

        $payload = [
            'id' => 8004,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8004',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $headers = ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook_dup_8004'];

        $res1 = $this->sendWebhook($payload, $headers);
        $res1->assertStatus(200);

        $res2 = $this->sendWebhook($payload, $headers);
        $res2->assertStatus(200);
        $this->assertEquals('Webhook already processed.', $res2->json('message'));
        $this->assertEquals(1, ShopifyProcessedWebhook::where('webhook_id', 'webhook_dup_8004')->count());
    }

    // 5. Duplicate transaction does not create second Payment
    public function test_5_duplicate_transaction_does_not_create_second_payment()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_5']], 201);
            },
        ]);

        $payload = [
            'id' => 8005,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8005',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        // Send twice with different webhook delivery IDs
        $res1 = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_del_1']);
        $res2 = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_del_2']);

        $res1->assertStatus(200);
        $res2->assertStatus(200);

        $this->assertEquals(1, Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8005')->count());
    }

    // 6. Repeated webhook does not create second Zoho payment
    public function test_6_repeated_webhook_does_not_create_second_zoho_payment()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_6']], 201);
            },
        ]);

        $payload = [
            'id' => 8006,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8006',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_del_6a']);
        $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_del_6b']);

        Http::assertSentCount(2); // 1 GET (find) + 1 POST (create)
    }

    // 7. Successful payment transaction creates Payment
    public function test_7_successful_payment_transaction_creates_payment()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_7']], 201);
            },
        ]);

        $payload = [
            'id' => 8007,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8007',
            'order_id' => 7200,
            'kind' => 'capture',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'shopify_payments',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8007')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
    }

    // 8. Failed transaction does not create Zoho Payment
    public function test_8_failed_transaction_does_not_create_zoho_payment()
    {
        Http::fake();

        $payload = [
            'id' => 8008,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8008',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'failure',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);
        $this->assertEquals('Transaction not eligible for payment synchronization.', $response->json('message'));
        $this->assertNull(Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8008')->first());
        Http::assertNothingSent();
    }

    // 9. Error transaction does not create Zoho Payment
    public function test_9_error_transaction_does_not_create_zoho_payment()
    {
        Http::fake();

        $payload = [
            'id' => 8009,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8009',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'error',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);
        $this->assertEquals('Transaction not eligible for payment synchronization.', $response->json('message'));
        $this->assertNull(Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8009')->first());
        Http::assertNothingSent();
    }

    // 10. Pending transaction does not create Zoho Payment
    public function test_10_pending_transaction_does_not_create_zoho_payment()
    {
        Http::fake();

        $payload = [
            'id' => 8010,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8010',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'pending',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);
        $this->assertEquals('Transaction not eligible for payment synchronization.', $response->json('message'));
        $this->assertNull(Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8010')->first());
        Http::assertNothingSent();
    }

    // 11. Authorization-only transaction is handled correctly (skipped)
    public function test_11_authorization_only_transaction_is_skipped()
    {
        Http::fake();

        $payload = [
            'id' => 8011,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8011',
            'order_id' => 7200,
            'kind' => 'authorization',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);
        $this->assertEquals('Transaction not eligible for payment synchronization.', $response->json('message'));
        $this->assertNull(Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8011')->first());
        Http::assertNothingSent();
    }

    // 12. Refund transaction is not processed by Module F (skipped)
    public function test_12_refund_transaction_is_skipped()
    {
        Http::fake();

        $payload = [
            'id' => 8012,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8012',
            'order_id' => 7200,
            'kind' => 'refund',
            'status' => 'success',
            'amount' => '50.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);
        $this->assertEquals('Transaction not eligible for payment synchronization.', $response->json('message'));
        $this->assertNull(Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8012')->first());
        Http::assertNothingSent();
    }

    // 13. Shopify transaction ID stored correctly
    public function test_13_shopify_transaction_id_stored_correctly()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_13']], 201);
            },
        ]);

        $payload = [
            'id' => 8013,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8013',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8013')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('gid://shopify/OrderTransaction/8013', $payment->shopify_transaction_id);
    }

    // 14. Shopify order ID mapped correctly
    public function test_14_shopify_order_id_mapped_correctly()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_14']], 201);
            },
        ]);

        $payload = [
            'id' => 8014,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8014',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8014')->first();
        $this->assertEquals($this->order->id, $payment->order_id);
        $this->assertEquals('gid://shopify/Order/7200', $payment->shopify_order_id);
    }

    // 15. Amount stored correctly
    public function test_15_amount_stored_correctly()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_15']], 201);
            },
        ]);

        $payload = [
            'id' => 8015,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8015',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8015')->first();
        $this->assertEquals(200.00, (float) $payment->amount);
    }

    // 16. Currency stored correctly
    public function test_16_currency_stored_correctly()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_16']], 201);
            },
        ]);

        $payload = [
            'id' => 8016,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8016',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8016')->first();
        $this->assertEquals('USD', $payment->currency);
    }

    // 17. Gateway stored correctly
    public function test_17_gateway_stored_correctly()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_17']], 201);
            },
        ]);

        $payload = [
            'id' => 8017,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8017',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'paypal',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8017')->first();
        $this->assertEquals('paypal', $payment->payment_method);
    }

    // 18. Payment date stored correctly
    public function test_18_payment_date_stored_correctly()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_18']], 201);
            },
        ]);

        $payload = [
            'id' => 8018,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8018',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
            'processed_at' => '2026-08-17T10:30:00Z',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8018')->first();
        $this->assertEquals('2026-08-17 10:30:00', $payment->payment_date->format('Y-m-d H:i:s'));
    }

    // 19. Deterministic payment_reference remains stable
    public function test_19_deterministic_payment_reference_remains_stable()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_19']], 201);
            },
        ]);

        $payload = [
            'id' => 8019,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8019',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8019')->first();
        $this->assertEquals('TXN-8019', $payment->payment_reference);
    }

    // 20. Partial payment works
    public function test_20_partial_payment_works()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_20']], 201);
            },
        ]);

        $payload = [
            'id' => 8020,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8020',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '75.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8020')->first();
        $this->assertEquals(75.00, (float) $payment->amount);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
    }

    // 21. Multiple payments on one order work
    public function test_21_multiple_payments_on_one_order_work()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_multi']], 201);
            },
        ]);

        $payload1 = [
            'id' => 8021,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8021',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '100.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $payload2 = [
            'id' => 8022,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8022',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '100.00',
            'currency' => 'USD',
            'gateway' => 'paypal',
        ];

        $this->sendWebhook($payload1, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_multi_1']);
        $this->sendWebhook($payload2, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_multi_2']);

        $payments = Payment::where('order_id', $this->order->id)->get();
        $this->assertCount(2, $payments);
    }

    // 22. Invoice mapping works
    public function test_22_invoice_mapping_works()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_22']], 201);
            },
        ]);

        $payload = [
            'id' => 8022,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8022',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8022')->first();
        $this->assertEquals($this->invoice->id, $payment->invoice_id);
        $this->assertEquals($this->invoice->zoho_invoice_id, $payment->zoho_invoice_id);
    }

    // 23. Existing Payment is reused
    public function test_23_existing_payment_is_reused()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_23']], 201);
            },
        ]);

        $existingPayment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/8023',
            'payment_reference' => 'TXN-8023',
            'amount' => 200.00,
            'currency' => 'USD',
            'payment_method' => 'stripe',
            'status' => Payment::STATUS_PAID,
            'sync_status' => Payment::SYNC_STATUS_PENDING,
        ]);

        $payload = [
            'id' => 8023,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8023',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);

        $this->assertEquals(1, Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8023')->count());
        $existingPayment->refresh();
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $existingPayment->sync_status);
    }

    // 24. ZohoService::syncPayment is called once per transaction
    public function test_24_zoho_service_sync_payment_called_once_per_transaction()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_24']], 201);
            },
        ]);

        $payload = [
            'id' => 8024,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8024',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $this->sendWebhook($payload);

        Http::assertSent(function (HttpClientRequest $request) {
            return $request->method() === 'POST' && $request->url() === 'https://www.zohoapis.com/books/v3/customerpayments?organization_id=88776655';
        });
    }

    // 25. Zoho failure leaves recoverable sync state
    public function test_25_zoho_failure_leaves_recoverable_sync_state()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 1000, 'message' => 'Zoho API Internal Error'], 500);
            },
        ]);

        $payload = [
            'id' => 8025,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/8025',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '200.00',
            'currency' => 'USD',
            'gateway' => 'stripe',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200); // Webhook accepted locally

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/8025')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(Payment::SYNC_STATUS_FAILED, $payment->sync_status);
        $this->assertNotNull($payment->error_message);

        // Verify SyncHistory recorded the failure
        $history = SyncHistory::where('payment_id', $payment->id)->first();
        $this->assertNotNull($history);
        $this->assertEquals('failed', $history->status);
    }

    // 26. Production regression: numeric order_id webhook matches GID local order
    public function test_26_numeric_order_id_webhook_matches_gid_local_order()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_7476440367272']], 201);
            },
        ]);

        $orderGid = 'gid://shopify/Order/7476440367272';

        $prodOrder = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => $orderGid,
            'order_number' => '#1003',
            'zoho_sales_order_id' => 'zoho_so_7476440367272',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '150.00',
            'total_price' => '150.00',
        ]);

        $prodInvoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $prodOrder->id,
            'shopify_order_id' => $orderGid,
            'zoho_invoice_id' => 'zoho_inv_7476440367272',
            'invoice_number' => 'INV-1003',
            'status' => 'sent',
            'amount' => '150.00',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $payload = [
            'id' => 987654321,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/987654321',
            'order_id' => 7476440367272, // Real production-style numeric order_id
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '150.00',
            'currency' => 'USD',
            'gateway' => 'shopify_payments',
        ];

        $response = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_7476_1']);
        $response->assertStatus(200);

        // 1. Confirm local order is found & Payment record is created
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/987654321')->first();
        $this->assertNotNull($payment);
        $this->assertEquals($prodOrder->id, $payment->order_id);
        $this->assertEquals($orderGid, $payment->shopify_order_id);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);

        // 2. Confirm ZohoService::syncPayment() was called
        Http::assertSent(function (HttpClientRequest $request) {
            return $request->method() === 'POST' && $request->url() === 'https://www.zohoapis.com/books/v3/customerpayments?organization_id=88776655';
        });

        // 3. Confirm duplicate transaction remains idempotent
        $responseDuplicate = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_prod_7476_2']);
        $responseDuplicate->assertStatus(200);

        $paymentCount = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/987654321')->count();
        $this->assertEquals(1, $paymentCount);
    }

    // 27. Race condition: Payment webhook arrives before local Order exists
    public function test_27_payment_webhook_arrives_before_local_order_exists_race_condition()
    {
        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/99001',
            'title' => 'Sample Widget',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/99002',
            'zoho_item_id' => 'zoho_item_99002',
            'title' => 'Default',
            'sku' => 'WIDGET-01',
            'price' => '100.00',
        ]);

        $orderId = '7476488831144';
        $orderGid = "gid://shopify/Order/{$orderId}";

        // Ensure order does not exist in local database before webhook arrives
        $this->assertNull(Order::where('shopify_order_id', $orderGid)->first());

        Http::fake([
            'https://txn-webhook-store.myshopify.com/admin/api/2026-07/graphql.json' => function (HttpClientRequest $request) use ($orderGid) {
                return Http::response([
                    'data' => [
                        'order' => [
                            'id' => $orderGid,
                            'name' => '#1004',
                            'createdAt' => '2026-08-17T12:00:00Z',
                            'currencyCode' => 'USD',
                            'subtotalPriceSet' => ['shopMoney' => ['amount' => '100.00']],
                            'totalDiscountsSet' => ['shopMoney' => ['amount' => '0.00']],
                            'totalTaxSet' => ['shopMoney' => ['amount' => '0.00']],
                            'totalPriceSet' => ['shopMoney' => ['amount' => '100.00']],
                            'displayFinancialStatus' => 'paid',
                            'displayFulfillmentStatus' => 'unfulfilled',
                            'note' => 'Race condition order',
                            'customer' => [
                                'id' => 'gid://shopify/Customer/7100',
                                'firstName' => 'Bob',
                                'lastName' => 'Race',
                                'email' => 'bob.race@example.com',
                                'defaultAddress' => [],
                            ],
                            'lineItems' => [
                                'nodes' => [
                                    [
                                        'id' => 'gid://shopify/LineItem/99001',
                                        'title' => 'Sample Widget',
                                        'quantity' => 1,
                                        'originalUnitPriceSet' => ['shopMoney' => ['amount' => '100.00']],
                                        'variant' => [
                                            'id' => 'gid://shopify/ProductVariant/99002',
                                            'sku' => 'WIDGET-01',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://www.zohoapis.com/books/v3/contacts*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'contacts' => []], 200);
                return Http::response(['code' => 0, 'contact' => ['contact_id' => 'zoho_c_race_7100']], 201);
            },
            'https://www.zohoapis.com/books/v3/salesorders*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'salesorders' => []], 200);
                return Http::response(['code' => 0, 'salesorder' => ['salesorder_id' => 'zoho_so_race_1004']], 201);
            },
            'https://www.zohoapis.com/books/v3/invoices*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'invoices' => []], 200);
                return Http::response(['code' => 0, 'invoice' => ['invoice_id' => 'zoho_inv_race_1004']], 201);
            },
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_race_1004']], 201);
            },
        ]);

        $payload = [
            'id' => 99887766,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/99887766',
            'order_id' => (int) $orderId,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '100.00',
            'currency' => 'USD',
            'gateway' => 'shopify_payments',
        ];

        // 1 & 2 & 3: Send payment webhook when local Order does not exist
        $response = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_race_1']);
        $response->assertStatus(200);

        // Verify Order was fetched & saved locally
        $fetchedOrder = Order::where('shopify_order_id', $orderGid)->first();
        $this->assertNotNull($fetchedOrder);

        // Verify Payment record was created locally
        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/99887766')->first();
        $this->assertNotNull($payment);
        $this->assertEquals($fetchedOrder->id, $payment->order_id);
        $this->assertEquals($orderGid, $payment->shopify_order_id);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);

        // Verify Zoho customer payment sync was called
        Http::assertSent(function (HttpClientRequest $request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/books/v3/customerpayments');
        });

        // 4: Duplicate payment webhook does not create a second Payment
        $responseDuplicate = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_race_2']);
        $responseDuplicate->assertStatus(200);
        $this->assertEquals(1, Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/99887766')->count());
    }

    // 28. Multi-currency shop_money normalization test
    public function test_28_transaction_webhook_uses_shop_money_when_presentment_currency_differs()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_multi_cur_28']], 201);
            },
        ]);

        $payload = [
            'id' => 9443431481512,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/9443431481512',
            'order_id' => 7200,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '1845.92',
            'currency' => 'INR',
            'shop_money' => [
                'amount' => '200.00',
                'currency_code' => 'USD',
            ],
            'presentment_money' => [
                'amount' => '1845.92',
                'currency_code' => 'INR',
            ],
            'gateway' => 'bogus',
        ];

        $response = $this->sendWebhook($payload);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/9443431481512')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(200.00, (float) $payment->amount);
        $this->assertEquals('USD', $payment->currency);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
    }

    // 29. Order #1017 regression: USD order with USD transaction creates USD payment even if shop_money is INR
    public function test_29_usd_order_and_usd_presentment_transaction_creates_usd_payment_even_if_shop_money_currency_is_inr()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_1017_usd']], 201);
            },
        ]);

        $usdOrder = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/1017',
            'order_number' => '#1017',
            'zoho_sales_order_id' => 'zoho_so_1017',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '19.29',
            'total_price' => '19.29',
        ]);

        $usdInvoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $usdOrder->id,
            'shopify_order_id' => $usdOrder->shopify_order_id,
            'zoho_invoice_id' => '4081216000000205025',
            'invoice_number' => 'INV-4081216000000205025',
            'status' => 'sent',
            'amount' => '19.29',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $payload = [
            'id' => 991017,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/991017',
            'order_id' => 1017,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '19.29',
            'currency' => 'USD',
            'shop_money' => [
                'amount' => '19.29',
                'currency_code' => 'INR', // Differing shop currency
            ],
            'presentment_money' => [
                'amount' => '19.29',
                'currency_code' => 'USD',
            ],
            'gateway' => 'shopify_payments',
        ];

        $response = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_1017_usd']);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/991017')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('USD', $payment->currency);
        $this->assertEquals(19.29, (float) $payment->amount);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
        $this->assertEquals($usdInvoice->id, $payment->invoice_id);
    }

    // 30. INR order with INR transaction creates INR payment & syncs cleanly
    public function test_30_inr_order_and_inr_transaction_creates_inr_payment_and_inr_invoice()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_1014_inr']], 201);
            },
        ]);

        $inrOrder = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/1014',
            'order_number' => '#1014',
            'zoho_sales_order_id' => 'zoho_so_1014',
            'order_date' => now(),
            'currency' => 'INR',
            'subtotal' => '1381.92',
            'total_price' => '1381.92',
        ]);

        $inrInvoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $inrOrder->id,
            'shopify_order_id' => $inrOrder->shopify_order_id,
            'zoho_invoice_id' => '4081216000000205099',
            'invoice_number' => 'INV-4081216000000205099',
            'status' => 'sent',
            'amount' => '1381.92',
            'currency' => 'INR',
            'sync_status' => 'synced',
        ]);

        $payload = [
            'id' => 991014,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/991014',
            'order_id' => 1014,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '1381.92',
            'currency' => 'INR',
            'shop_money' => [
                'amount' => '1381.92',
                'currency_code' => 'INR',
            ],
            'gateway' => 'shopify_payments',
        ];

        $response = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_1014_inr']);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/991014')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('INR', $payment->currency);
        $this->assertEquals(1381.92, (float) $payment->amount);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
        $this->assertEquals($inrInvoice->id, $payment->invoice_id);
    }

    // 31. USD order with INR presentment & USD shop_money ($19.30) settles USD invoice ($19.29)
    public function test_31_usd_order_and_inr_presentment_transaction_uses_usd_shop_money_and_settles_usd_invoice()
    {
        $postedPayload = null;
        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (HttpClientRequest $request) use (&$postedPayload) {
                if ($request->method() === 'GET') return Http::response(['code' => 0, 'customerpayments' => []], 200);
                $postedPayload = $request->data();
                return Http::response(['code' => 0, 'payment' => ['payment_id' => 'zoho_p_1017_shop_money']], 201);
            },
        ]);

        $usdOrder = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/1017_sm',
            'order_number' => '#1017_sm',
            'zoho_sales_order_id' => 'zoho_so_1017_sm',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '11.17',
            'shipping_total' => '8.12',
            'total_price' => '19.29',
        ]);

        $usdInvoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $usdOrder->id,
            'shopify_order_id' => $usdOrder->shopify_order_id,
            'zoho_invoice_id' => '4081216000000205025_sm',
            'invoice_number' => 'INV-4081216000000205025_SM',
            'status' => 'sent',
            'amount' => '19.29',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $payload = [
            'id' => 9446242812072,
            'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/9446242812072',
            'order_id' => '1017_sm',
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '1846.02',
            'currency' => 'INR',
            'shop_money' => [
                'amount' => '19.30',
                'currency_code' => 'USD',
            ],
            'presentment_money' => [
                'amount' => '1846.02',
                'currency_code' => 'INR',
            ],
            'gateway' => 'bogus',
        ];

        $response = $this->sendWebhook($payload, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh_1017_sm']);
        $response->assertStatus(200);

        $payment = Payment::where('shopify_transaction_id', 'gid://shopify/OrderTransaction/9446242812072')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('USD', $payment->currency);
        $this->assertEquals(19.30, (float) $payment->amount);
        $this->assertEquals(Payment::SYNC_STATUS_SYNCED, $payment->sync_status);
        $this->assertEquals($usdInvoice->id, $payment->invoice_id);

        $this->assertNotNull($postedPayload);
        $this->assertEquals(19.30, (float) $postedPayload['amount']);
        $this->assertEquals(19.29, (float) $postedPayload['invoices'][0]['amount_applied']);
    }
}
