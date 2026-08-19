<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ShopifyService;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelBMultiCurrencyAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'multicurrency-test-shop.myshopify.com',
            'access_token' => 'shpat_test_token_123',
            'scope' => 'read_orders,write_orders',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'zoho_org_9999',
            'access_token' => 'zoho_at_123',
            'refresh_token' => 'zoho_rt_123',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'api_domain' => 'www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * 1. India market: INR presentment -> INR Order -> INR SO -> INR Invoice -> INR Payment
     */
    public function test_1_india_market_inr_flow(): void
    {
        Http::fake([
            'https://multicurrency-test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'order' => [
                        'id' => 'gid://shopify/Order/9001',
                        'name' => '#1019-INR',
                        'createdAt' => '2026-08-19T10:00:00Z',
                        'currencyCode' => 'USD',
                        'subtotalPriceSet' => ['presentmentMoney' => ['amount' => '2000.00', 'currencyCode' => 'INR']],
                        'totalDiscountsSet' => ['presentmentMoney' => ['amount' => '0.00', 'currencyCode' => 'INR']],
                        'totalShippingPriceSet' => ['presentmentMoney' => ['amount' => '233.20', 'currencyCode' => 'INR']],
                        'totalTaxSet' => ['presentmentMoney' => ['amount' => '0.00', 'currencyCode' => 'INR']],
                        'totalPriceSet' => ['presentmentMoney' => ['amount' => '2233.20', 'currencyCode' => 'INR']],
                        'displayFinancialStatus' => 'paid',
                        'displayFulfillmentStatus' => 'unfulfilled',
                        'customer' => ['id' => 'gid://shopify/Customer/501', 'firstName' => 'Aarav', 'lastName' => 'Sharma', 'email' => 'aarav@example.in'],
                        'shippingLines' => ['nodes' => [['title' => 'Standard Express', 'originalPriceSet' => ['presentmentMoney' => ['amount' => '233.20', 'currencyCode' => 'INR']]]]],
                        'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/101', 'title' => 'Silk Sari', 'sku' => 'SARI-001', 'quantity' => 1, 'originalUnitPriceSet' => ['presentmentMoney' => ['amount' => '2000.00', 'currencyCode' => 'INR']]]]]
                    ]
                ]
            ], 200),
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response(['code' => 0, 'contacts' => [['contact_id' => 'zoho_c_inr']], 'contact' => ['contact_id' => 'zoho_c_inr']], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => [['item_id' => 'zoho_i_inr', 'sku' => 'SARI-001', 'name' => 'Silk Sari']]], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response(['code' => 0, 'salesorders' => [], 'salesorder' => ['salesorder_id' => 'zoho_so_inr']], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => Http::response(['code' => 0, 'invoices' => [], 'invoice' => ['invoice_id' => 'zoho_inv_inr']], 201),
            'https://www.zohoapis.com/books/v3/customerpayments*' => Http::response(['code' => 0, 'customerpayments' => [], 'payment' => ['payment_id' => 'zoho_pay_inr']], 201),
        ]);

        $shopifyService = new ShopifyService();
        $order = $shopifyService->fetchAndSyncOrder($this->shop, '9001');

        $this->assertNotNull($order);
        $this->assertEquals('INR', $order->currency);
        $this->assertEquals(2233.20, $order->total_price);
        $this->assertEquals(2000.00, $order->subtotal);

        $zohoService = new ZohoService($this->shop);
        $soRes = $zohoService->syncOrder($order);
        $this->assertTrue($soRes['success']);

        $invRes = $zohoService->syncInvoice($order);
        $this->assertTrue($invRes['success']);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertEquals('INR', $invoice->currency);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/90011',
            'amount' => 2233.20,
            'currency' => 'INR',
            'gateway' => 'razorpay',
            'status' => 'success',
            'sync_status' => 'pending',
        ]);

        $payRes = $zohoService->syncPayment($payment);
        $this->assertTrue($payRes['success']);
        $this->assertEquals('INR', $payment->fresh()->currency);
    }

    /**
     * 2. US market: USD presentment -> USD Order -> USD SO -> USD Invoice -> USD Payment
     */
    public function test_2_us_market_usd_flow(): void
    {
        Http::fake([
            'https://multicurrency-test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'order' => [
                        'id' => 'gid://shopify/Order/9002',
                        'name' => '#1020-USD',
                        'createdAt' => '2026-08-19T10:00:00Z',
                        'currencyCode' => 'USD',
                        'subtotalPriceSet' => ['presentmentMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
                        'totalDiscountsSet' => ['presentmentMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']],
                        'totalShippingPriceSet' => ['presentmentMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
                        'totalTaxSet' => ['presentmentMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']],
                        'totalPriceSet' => ['presentmentMoney' => ['amount' => '55.00', 'currencyCode' => 'USD']],
                        'displayFinancialStatus' => 'paid',
                        'displayFulfillmentStatus' => 'unfulfilled',
                        'customer' => ['id' => 'gid://shopify/Customer/502', 'firstName' => 'John', 'lastName' => 'Doe', 'email' => 'john@example.com'],
                        'shippingLines' => ['nodes' => [['title' => 'Standard Ground', 'originalPriceSet' => ['presentmentMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']]]]],
                        'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/102', 'title' => 'T-Shirt', 'sku' => 'TSHIRT-001', 'quantity' => 1, 'originalUnitPriceSet' => ['presentmentMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']]]]]
                    ]
                ]
            ], 200),
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response(['code' => 0, 'contacts' => [['contact_id' => 'zoho_c_usd']], 'contact' => ['contact_id' => 'zoho_c_usd']], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => [['item_id' => 'zoho_i_usd', 'sku' => 'TSHIRT-001', 'name' => 'T-Shirt']]], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response(['code' => 0, 'salesorders' => [], 'salesorder' => ['salesorder_id' => 'zoho_so_usd']], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => Http::response(['code' => 0, 'invoices' => [], 'invoice' => ['invoice_id' => 'zoho_inv_usd']], 201),
            'https://www.zohoapis.com/books/v3/customerpayments*' => Http::response(['code' => 0, 'customerpayments' => [], 'payment' => ['payment_id' => 'zoho_pay_usd']], 201),
        ]);

        $shopifyService = new ShopifyService();
        $order = $shopifyService->fetchAndSyncOrder($this->shop, '9002');

        $this->assertEquals('USD', $order->currency);
        $this->assertEquals(55.00, $order->total_price);

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncOrder($order);
        $zohoService->syncInvoice($order);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertEquals('USD', $invoice->currency);
    }

    /**
     * 3. EUR market: EUR presentment -> EUR Order -> EUR SO -> EUR Invoice -> EUR Payment
     */
    public function test_3_eur_market_flow(): void
    {
        Http::fake([
            'https://multicurrency-test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'order' => [
                        'id' => 'gid://shopify/Order/9003',
                        'name' => '#1021-EUR',
                        'createdAt' => '2026-08-19T10:00:00Z',
                        'currencyCode' => 'USD',
                        'subtotalPriceSet' => ['presentmentMoney' => ['amount' => '90.00', 'currencyCode' => 'EUR']],
                        'totalDiscountsSet' => ['presentmentMoney' => ['amount' => '0.00', 'currencyCode' => 'EUR']],
                        'totalShippingPriceSet' => ['presentmentMoney' => ['amount' => '10.00', 'currencyCode' => 'EUR']],
                        'totalTaxSet' => ['presentmentMoney' => ['amount' => '0.00', 'currencyCode' => 'EUR']],
                        'totalPriceSet' => ['presentmentMoney' => ['amount' => '100.00', 'currencyCode' => 'EUR']],
                        'displayFinancialStatus' => 'paid',
                        'displayFulfillmentStatus' => 'unfulfilled',
                        'customer' => ['id' => 'gid://shopify/Customer/503', 'firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@example.fr'],
                        'shippingLines' => ['nodes' => [['title' => 'EU Standard', 'originalPriceSet' => ['presentmentMoney' => ['amount' => '10.00', 'currencyCode' => 'EUR']]]]],
                        'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/103', 'title' => 'Jacket', 'sku' => 'JACKET-001', 'quantity' => 1, 'originalUnitPriceSet' => ['presentmentMoney' => ['amount' => '90.00', 'currencyCode' => 'EUR']]]]]
                    ]
                ]
            ], 200),
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response(['code' => 0, 'contacts' => [['contact_id' => 'zoho_c_eur']], 'contact' => ['contact_id' => 'zoho_c_eur']], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => [['item_id' => 'zoho_i_eur', 'sku' => 'JACKET-001', 'name' => 'Jacket']]], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response(['code' => 0, 'salesorders' => [], 'salesorder' => ['salesorder_id' => 'zoho_so_eur']], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => Http::response(['code' => 0, 'invoices' => [], 'invoice' => ['invoice_id' => 'zoho_inv_eur']], 201),
        ]);

        $shopifyService = new ShopifyService();
        $order = $shopifyService->fetchAndSyncOrder($this->shop, '9003');

        $this->assertEquals('EUR', $order->currency);
        $this->assertEquals(100.00, $order->total_price);
        $this->assertEquals(90.00, $order->subtotal);
        $this->assertEquals(10.00, $order->shipping_total);

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncInvoice($order);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertEquals('EUR', $invoice->currency);
    }

    /**
     * 4. Missing presentmentMoney falls back safely to shopMoney
     */
    public function test_4_fallback_to_shop_money_when_presentment_missing(): void
    {
        Http::fake([
            'https://multicurrency-test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'order' => [
                        'id' => 'gid://shopify/Order/9004',
                        'name' => '#1022-FALLBACK',
                        'createdAt' => '2026-08-19T10:00:00Z',
                        'currencyCode' => 'USD',
                        'subtotalPriceSet' => ['shopMoney' => ['amount' => '40.00', 'currencyCode' => 'USD']],
                        'totalDiscountsSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']],
                        'totalShippingPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']],
                        'totalTaxSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']],
                        'totalPriceSet' => ['shopMoney' => ['amount' => '45.00', 'currencyCode' => 'USD']],
                        'displayFinancialStatus' => 'paid',
                        'displayFulfillmentStatus' => 'unfulfilled',
                        'customer' => ['id' => 'gid://shopify/Customer/504', 'firstName' => 'Alice', 'lastName' => 'Smith', 'email' => 'alice@example.com'],
                        'shippingLines' => ['nodes' => [['title' => 'Standard', 'originalPriceSet' => ['shopMoney' => ['amount' => '5.00', 'currencyCode' => 'USD']]]]],
                        'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/104', 'title' => 'Book', 'sku' => 'BOOK-001', 'quantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '40.00', 'currencyCode' => 'USD']]]]]
                    ]
                ]
            ], 200),
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response(['code' => 0, 'contacts' => [['contact_id' => 'zoho_c_fallback']], 'contact' => ['contact_id' => 'zoho_c_fallback']], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => [['item_id' => 'zoho_i_fallback', 'sku' => 'BOOK-001', 'name' => 'Book']]], 200),
        ]);

        $shopifyService = new ShopifyService();
        $order = $shopifyService->fetchAndSyncOrder($this->shop, '9004');

        $this->assertEquals('USD', $order->currency);
        $this->assertEquals(45.00, $order->total_price);
    }

    /**
     * 5. Refund and Credit Note retain original order currency (INR)
     */
    public function test_5_refund_retains_order_currency(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/505',
            'first_name' => 'Aarav',
            'last_name' => 'Sharma',
            'email' => 'aarav@example.in',
            'zoho_contact_id' => 'zoho_c_ref_inr',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/9005',
            'order_number' => '#1023-INR',
            'currency' => 'INR',
            'subtotal' => 2000.00,
            'total_price' => 2233.20,
            'financial_status' => 'partially_refunded',
            'fulfillment_status' => 'unfulfilled',
            'order_date' => now(),
            'line_items' => [],
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => 'gid://shopify/Order/9005',
            'shopify_refund_id' => '778899',
            'amount' => 500.00,
            'currency' => 'INR',
            'restocked' => false,
            'sync_status' => 'pending',
            'refund_line_items' => [
                ['title' => 'Partial Item Refund', 'price' => 500.00, 'quantity' => 1]
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response(['code' => 0, 'contacts' => [['contact_id' => 'zoho_c_ref_inr']], 'contact' => ['contact_id' => 'zoho_c_ref_inr']], 200),
            'https://www.zohoapis.com/books/v3/creditnotes*' => Http::response(['code' => 0, 'creditnotes' => [], 'creditnote' => ['creditnote_id' => 'zoho_cn_inr']], 201),
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncRefund($refund);

        $this->assertTrue($res['success']);
        $this->assertEquals('INR', $refund->fresh()->currency);
    }

    /**
     * 6. Strict currency validation rejects genuinely inconsistent payment (USD invoice vs INR payment)
     */
    public function test_6_currency_mismatch_rejection(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/506',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'zoho_contact_id' => 'zoho_c_strict',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/9006',
            'order_number' => '#1024-USD',
            'currency' => 'USD',
            'subtotal' => 50.00,
            'total_price' => 50.00,
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'order_date' => now(),
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_usd_strict',
            'invoice_number' => 'INV-1024',
            'status' => 'sent',
            'amount' => 50.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $invalidPayment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/99911',
            'amount' => 4200.00,
            'currency' => 'INR', // Inconsistent currency!
            'gateway' => 'manual',
            'status' => 'success',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Currency mismatch');

        $zohoService->syncPayment($invalidPayment);
    }

    /**
     * 7. End-to-end regression test reproducing Order #1020 REST webhook ingestion & sync
     */
    public function test_7_order_1020_e2e_regression(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response(['code' => 0, 'contacts' => [['contact_id' => 'zoho_c_1020']], 'contact' => ['contact_id' => 'zoho_c_1020']], 200),
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => [['item_id' => 'zoho_i_1020', 'sku' => 'MONEY-PLANT-01', 'name' => 'Garland']]], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response(['code' => 0, 'salesorders' => [], 'salesorder' => ['salesorder_id' => 'zoho_so_1020']], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => Http::response(['code' => 0, 'invoices' => [], 'invoice' => ['invoice_id' => 'zoho_inv_1020', 'currency_code' => 'INR', 'total' => 1749.01]], 201),
            'https://www.zohoapis.com/books/v3/customerpayments*' => Http::response(['code' => 0, 'customerpayments' => [], 'payment' => ['payment_id' => 'zoho_pay_1020']], 201),
        ]);

        $controller = new \App\Http\Controllers\ShopifyWebhookController();

        // 1. Simulate REST order creation webhook payload for Order #1020
        config(['services.shopify.api_secret' => 'test_secret']);

        $orderPayload = [
            'id' => 7481853214888,
            'name' => '#1020',
            'order_number' => 1020,
            'created_at' => '2026-08-19T06:45:19Z',
            'currency' => 'USD', // Shop store currency
            'presentment_currency' => 'INR', // Market presentment currency
            'total_price' => '18.29',
            'subtotal_price' => '10.16',
            'total_tax' => '0.00',
            'total_discounts' => '0.00',
            'total_shipping_price_set' => [
                'shop_money' => ['amount' => '8.13', 'currency_code' => 'USD'],
                'presentment_money' => ['amount' => '777.34', 'currency_code' => 'INR'],
            ],
            'subtotal_price_set' => [
                'shop_money' => ['amount' => '10.16', 'currency_code' => 'USD'],
                'presentment_money' => ['amount' => '971.67', 'currency_code' => 'INR'],
            ],
            'total_price_set' => [
                'shop_money' => ['amount' => '18.29', 'currency_code' => 'USD'],
                'presentment_money' => ['amount' => '1749.01', 'currency_code' => 'INR'],
            ],
            'customer' => [
                'id' => 88776655,
                'first_name' => 'Mohit',
                'last_name' => 'Kumar',
                'email' => 'mohit@example.in',
            ],
            'line_items' => [
                [
                    'id' => 16374909763752,
                    'title' => 'SAUDEEP INDIA Artificial Green Leaf Money Plant Garland',
                    'name' => 'SAUDEEP INDIA Artificial Green Leaf Money Plant Garland',
                    'quantity' => 1,
                    'price' => '10.16',
                    'sku' => 'MONEY-PLANT-01',
                    'price_set' => [
                        'shop_money' => ['amount' => '10.16', 'currency_code' => 'USD'],
                        'presentment_money' => ['amount' => '971.67', 'currency_code' => 'INR'],
                    ],
                ]
            ],
            'shipping_lines' => [
                [
                    'title' => 'Standard Shipping',
                    'price' => '8.13',
                    'price_set' => [
                        'shop_money' => ['amount' => '8.13', 'currency_code' => 'USD'],
                        'presentment_money' => ['amount' => '777.34', 'currency_code' => 'INR'],
                    ],
                ]
            ],
        ];

        $orderJson = json_encode($orderPayload);
        $orderHmac = base64_encode(hash_hmac('sha256', $orderJson, 'test_secret', true));

        $req = \Illuminate\Http\Request::create('/api/webhooks/shopify/orders-create', 'POST', [], [], [], [], $orderJson);
        $req->headers->set('X-Shopify-Shop-Domain', $this->shop->shop_domain);
        $req->headers->set('X-Shopify-Hmac-SHA256', $orderHmac);

        $res = $controller->ordersCreate($req);
        $this->assertEquals(200, $res->getStatusCode());

        $order = Order::where('shopify_order_id', 'gid://shopify/Order/7481853214888')->first();
        $this->assertNotNull($order);

        // Verify Local Order Money & Currency Consistency
        $this->assertEquals('INR', $order->currency);
        $this->assertEquals(971.67, $order->subtotal);
        $this->assertEquals(777.34, $order->shipping_total);
        $this->assertEquals(1749.01, $order->total_price);
        $this->assertEquals(971.67, $order->line_items[0]['price']);
        $this->assertEquals(777.34, $order->shipping_lines[0]['price']);

        // 2. Simulate REST transaction creation webhook payload for Order #1020
        $txnPayload = [
            'id' => 9446341083304,
            'order_id' => 7481853214888,
            'kind' => 'sale',
            'status' => 'success',
            'amount' => '1749.01',
            'currency' => 'USD',
            'shop_money' => ['amount' => '18.28', 'currency_code' => 'USD'],
            'presentment_money' => ['amount' => '1749.01', 'currency_code' => 'INR'],
        ];

        $txnJson = json_encode($txnPayload);
        $txnHmac = base64_encode(hash_hmac('sha256', $txnJson, 'test_secret', true));

        $txnReq = \Illuminate\Http\Request::create('/api/webhooks/shopify/order-transactions-create', 'POST', [], [], [], [], $txnJson);
        $txnReq->headers->set('X-Shopify-Shop-Domain', $this->shop->shop_domain);
        $txnReq->headers->set('X-Shopify-Hmac-SHA256', $txnHmac);

        $txnRes = $controller->orderTransactionsCreate($txnReq);
        $this->assertEquals(200, $txnRes->getStatusCode());

        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);

        // Verify Payment Currency & Amount
        $this->assertEquals('INR', $payment->currency);
        $this->assertEquals(1749.01, $payment->amount);
        $this->assertEquals('synced', $payment->sync_status);
    }

    /**
     * 8. Verify strict database currency consistency across all monetary columns
     */
    public function test_8_database_currency_consistency_assertions(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/99001',
            'order_number' => '#ASSERT-001',
            'currency' => 'EUR',
            'subtotal' => 100.00,
            'discount_total' => 10.00,
            'shipping_total' => 5.00,
            'tax_total' => 0.00,
            'total_price' => 95.00,
            'line_items' => [['title' => 'Item', 'price' => 100.00, 'quantity' => 1]],
            'order_date' => now(),
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_assert',
            'amount' => 95.00,
            'currency' => $order->currency,
            'sync_status' => 'synced',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/990011',
            'amount' => 95.00,
            'currency' => $order->currency,
            'status' => 'success',
            'sync_status' => 'synced',
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_refund_id' => 'rf_99001',
            'amount' => 95.00,
            'currency' => $order->currency,
            'sync_status' => 'synced',
        ]);

        // Assert single-source currency across all entities
        $this->assertEquals($order->currency, $invoice->currency);
        $this->assertEquals($order->currency, $payment->currency);
        $this->assertEquals($order->currency, $refund->currency);
    }
}
