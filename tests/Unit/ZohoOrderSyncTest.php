<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;
    private ProductVariant $variant1;
    private ProductVariant $variant2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'order-test.myshopify.com',
            'access_token' => 'shpat_test_token',
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
            'shopify_customer_id' => 'gid://shopify/Customer/2001',
            'zoho_contact_id' => 'zoho_contact_2001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.order@example.com',
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/3001',
            'title' => 'Test Order Product',
            'handle' => 'test-order-product',
        ]);

        $this->variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/4001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/5001',
            'sku' => 'SKU-ORD-01',
            'title' => 'Red / Large',
            'price' => '50.00',
            'inventory_quantity' => 100,
            'zoho_item_id' => 'zoho_item_4001',
        ]);

        $this->variant2 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/4002',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/5002',
            'sku' => 'SKU-ORD-02',
            'title' => 'Blue / Small',
            'price' => '30.00',
            'inventory_quantity' => 50,
            'zoho_item_id' => 'zoho_item_4002',
        ]);
    }

    private function calculateHmac(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    public function test_order_creation_in_zoho_books()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7001',
            'order_number' => '#1001',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '130.00',
            'discount_total' => '10.00',
            'shipping_total' => '15.00',
            'tax_total' => '0.00',
            'total_price' => '143.00',
            'notes' => 'Please leave at front door.',
            'coupon_code' => 'SAVE10',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/4001',
                    'sku' => 'SKU-ORD-01',
                    'name' => 'Red / Large',
                    'quantity' => 2,
                    'price' => 50.00,
                ],
                [
                    'variant_id' => 'gid://shopify/ProductVariant/4002',
                    'sku' => 'SKU-ORD-02',
                    'name' => 'Blue / Small',
                    'quantity' => 1,
                    'price' => 30.00,
                ],
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'salesorders' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_7001',
                            'salesorder_number' => 'SO-00001',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created']);
        $this->assertEquals('zoho_so_7001', $result['zoho_sales_order_id']);
        $this->assertEquals('zoho_so_7001', $order->fresh()->zoho_sales_order_id);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/books/v3/salesorders') &&
                $request->data()['customer_id'] === 'zoho_contact_2001' &&
                $request->data()['reference_number'] === '#1001' &&
                count($request->data()['line_items']) === 2 &&
                $request->data()['line_items'][0]['item_id'] === 'zoho_item_4001' &&
                $request->data()['shipping_charge'] === 15.0 &&
                $request->data()['discount'] === 10.0;
        });
    }

    public function test_order_update_modifies_existing_zoho_sales_order()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7002',
            'zoho_sales_order_id' => 'zoho_so_existing_99',
            'order_number' => '#1002',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '50.00',
            'total_price' => '50.00',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/4001',
                    'sku' => 'SKU-ORD-01',
                    'name' => 'Red / Large',
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'PUT') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order updated',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_existing_99',
                            'salesorder_number' => 'SO-00099',
                        ],
                    ], 200);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['updated']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' &&
                str_contains($request->url(), '/books/v3/salesorders/zoho_so_existing_99');
        });
    }

    public function test_order_sync_fails_on_unmapped_variant()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7003',
            'order_number' => '#1003',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/99999',
                    'sku' => 'UNMAPPED-SKU-X',
                    'name' => 'Unknown Item',
                    'quantity' => 1,
                    'price' => 10.00,
                ],
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Unmapped Shopify product variant/SKU 'UNMAPPED-SKU-X'");

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncOrder($order);
    }

    public function test_order_webhook_creates_and_syncs_order()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response([
                'code' => 0,
                'contacts' => [['contact_id' => 'zoho_contact_2001']],
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'salesorders' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_webhook_88',
                            'salesorder_number' => 'SO-00088',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $payload = json_encode([
            'id' => 8001,
            'name' => '#1008',
            'created_at' => now()->toIso8601String(),
            'currency' => 'USD',
            'subtotal_price' => '50.00',
            'total_discounts' => '0.00',
            'total_tax' => '0.00',
            'total_price' => '54.00',
            'customer' => [
                'id' => 2001,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.order@example.com',
            ],
            'line_items' => [
                [
                    'id' => 111,
                    'variant_id' => 4001,
                    'sku' => 'SKU-ORD-01',
                    'title' => 'Red / Large',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
            ],
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_1001',
            'X-Shopify-Topic' => 'orders/updated',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Order update webhook processed successfully.',
            'zoho_synced' => true,
            'zoho_sales_order_id' => 'zoho_so_webhook_88',
        ]);

        $this->assertDatabaseHas('orders', [
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/8001',
            'order_number' => '#1008',
            'zoho_sales_order_id' => 'zoho_so_webhook_88',
        ]);
    }

    public function test_order_webhook_idempotency()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'salesorder' => ['salesorder_id' => 'zoho_so_idem_100'],
                    ], 201);
                }
                return Http::response(['code' => 0, 'salesorders' => []], 200);
            },
        ]);

        $payload = json_encode([
            'id' => 8002,
            'name' => '#1009',
            'customer' => [
                'id' => 2001,
                'email' => 'john.order@example.com',
            ],
            'line_items' => [
                [
                    'variant_id' => 4001,
                    'sku' => 'SKU-ORD-01',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
            ],
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        // First delivery
        $response1 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_duplicate_id',
            'X-Shopify-Topic' => 'orders/updated',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response1->assertStatus(200);
        $response1->assertJson(['message' => 'Order update webhook processed successfully.']);

        // Second delivery (duplicate)
        $response2 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_duplicate_id',
            'X-Shopify-Topic' => 'orders/updated',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response2->assertStatus(200);
        $response2->assertJson(['message' => 'Webhook already processed.']);
    }

    public function test_new_order_create_webhook_creates_local_order_and_zoho_sales_order()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response([
                'code' => 0,
                'contacts' => [['contact_id' => 'zoho_contact_2001']],
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'salesorders' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_new_create_99',
                            'salesorder_number' => 'SO-00099',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $payload = json_encode([
            'id' => 8888,
            'name' => '#1088',
            'created_at' => now()->toIso8601String(),
            'currency' => 'USD',
            'subtotal_price' => '100.00',
            'total_discounts' => '0.00',
            'total_tax' => '0.00',
            'total_price' => '108.00',
            'customer' => [
                'id' => 2001,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.order@example.com',
            ],
            'line_items' => [
                [
                    'id' => 111,
                    'variant_id' => 4001,
                    'sku' => 'SKU-ORD-01',
                    'title' => 'Red / Large',
                    'quantity' => 2,
                    'price' => '50.00',
                ],
            ],
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_create_001',
            'X-Shopify-Topic' => 'orders/create',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Order create webhook processed successfully.',
            'zoho_synced' => true,
            'zoho_sales_order_id' => 'zoho_so_new_create_99',
        ]);

        $this->assertDatabaseHas('orders', [
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/8888',
            'order_number' => '#1088',
            'zoho_sales_order_id' => 'zoho_so_new_create_99',
        ]);
    }

    public function test_duplicate_order_create_webhook_does_not_create_duplicates()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'salesorder' => ['salesorder_id' => 'zoho_so_dup_create_01'],
                    ], 201);
                }
                return Http::response(['code' => 0, 'salesorders' => []], 200);
            },
        ]);

        $payload = json_encode([
            'id' => 8889,
            'name' => '#1089',
            'customer' => [
                'id' => 2001,
                'email' => 'john.order@example.com',
            ],
            'line_items' => [
                [
                    'variant_id' => 4001,
                    'sku' => 'SKU-ORD-01',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
            ],
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        // First delivery of orders/create
        $response1 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_create_duplicate_id',
            'X-Shopify-Topic' => 'orders/create',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response1->assertStatus(200);
        $response1->assertJson(['message' => 'Order create webhook processed successfully.']);

        // Duplicate delivery of orders/create
        $response2 = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_create_duplicate_id',
            'X-Shopify-Topic' => 'orders/create',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response2->assertStatus(200);
        $response2->assertJson(['message' => 'Webhook already processed.']);

        $this->assertEquals(1, Order::where('shopify_order_id', 'gid://shopify/Order/8889')->count());
    }

    public function test_order_tenant_isolation()
    {
        config(['services.shopify.api_secret' => 'test_secret']);

        $otherShop = Shop::create([
            'shop_domain' => 'other-order-shop.myshopify.com',
            'access_token' => 'shpat_other_token',
        ]);

        $payload = json_encode([
            'id' => 8003,
            'name' => '#1010',
            'line_items' => [],
        ]);

        $hmac = $this->calculateHmac($payload, 'test_secret');

        $response = $this->withHeaders([
            'X-Shopify-Hmac-SHA256' => $hmac,
            'X-Shopify-Shop-Domain' => $otherShop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_ord_tenant_01',
            'X-Shopify-Topic' => 'orders/updated',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/orders', json_decode($payload, true));

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'shop_id' => $otherShop->id,
            'shopify_order_id' => 'gid://shopify/Order/8003',
        ]);

        $this->assertDatabaseMissing('orders', [
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/8003',
        ]);
    }

    public function test_fetch_orders_success()
    {
        Http::fake([
            'https://order-test.myshopify.com/admin/api/2026-07/graphql.json*' => Http::response([
                'data' => [
                    'orders' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Order/8001',
                                'name' => '#1001',
                                'totalPriceSet' => ['shopMoney' => ['amount' => '100.00']],
                                'customer' => [
                                    'id' => 'gid://shopify/Customer/2001',
                                    'firstName' => 'Jane',
                                    'lastName' => 'Doe',
                                    'email' => 'jane@example.com',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $shopifyService = new \App\Services\ShopifyService();
        $orders = $shopifyService->fetchOrders($this->shop);
        $this->assertCount(1, $orders);
        $this->assertEquals('#1001', $orders[0]['name']);
        $this->assertNotNull($orders[0]['customer']);
        $this->assertNull($orders[0]['customer']['phone']);
    }

    public function test_fetch_orders_failure_throws_exception()
    {
        Http::fake([
            'https://order-test.myshopify.com/admin/api/2026-07/graphql.json*' => Http::response([
                'errors' => '[API] Access denied',
            ], 403),
        ]);

        $shopifyService = new \App\Services\ShopifyService();
        $this->expectException(\Exception::class);
        $shopifyService->fetchOrders($this->shop);
    }

    public function test_sync_cancelled_order_voids_zoho_sales_order_and_invoice()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_999/status/void*' => Http::response([
                'code' => 0,
                'message' => 'The sales order has been voided.',
                'salesorder' => ['salesorder_id' => 'zoho_so_999', 'status' => 'void'],
            ], 200),
            'https://www.zohoapis.com/books/v3/invoices/zoho_inv_999/status/void*' => Http::response([
                'code' => 0,
                'message' => 'The invoice has been voided.',
                'invoice' => ['invoice_id' => 'zoho_inv_999', 'status' => 'void'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => '99999',
            'order_number' => '#ORD-999',
            'zoho_sales_order_id' => 'zoho_so_999',
            'financial_status' => 'voided',
            'fulfillment_status' => 'unfulfilled',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => 100.00,
            'total_price' => 100.00,
            'line_items' => [],
        ]);

        Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => '99999',
            'zoho_invoice_id' => 'zoho_inv_999',
            'invoice_number' => 'INV-999',
            'status' => 'sent',
            'amount' => 100.00,
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);
        $orderRes = $zohoService->syncOrder($order);
        $invRes = $zohoService->syncInvoice($order);

        $this->assertTrue($orderRes['success']);
        $this->assertTrue($orderRes['voided']);
        $this->assertTrue($invRes['success']);
        $this->assertTrue($invRes['voided']);

        $invoice = Invoice::find($order->id);
        $this->assertEquals('void', Invoice::where('order_id', $order->id)->first()->status);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/books/v3/salesorders/zoho_so_999/status/void');
        });
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/books/v3/invoices/zoho_inv_999/status/void');
        });
    }

    public function test_sync_fulfilled_order_marks_zoho_sales_order_as_fulfilled()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_888*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_888', 'salesorder_number' => 'SO-888'],
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_888/status/fulfilled*' => Http::response([
                'code' => 0,
                'message' => 'Sales order status marked as fulfilled.',
                'salesorder' => ['salesorder_id' => 'zoho_so_888', 'status' => 'fulfilled'],
            ], 200),
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => '88888',
            'order_number' => '#ORD-888',
            'zoho_sales_order_id' => 'zoho_so_888',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => 150.00,
            'total_price' => 150.00,
            'line_items' => [
                ['variant_id' => $this->variant1->shopify_variant_id, 'quantity' => 1, 'price' => '150.00'],
            ],
        ]);

        $zohoService = new ZohoService($this->shop);
        $res = $zohoService->syncOrder($order);

        $this->assertTrue($res['success']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/books/v3/salesorders/zoho_so_888/status/fulfilled');
        });
    }

    public function test_mark_sales_order_as_fulfilled_sends_valid_json_object_body()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_json_check/status/fulfilled*' => Http::response([
                'code' => 0,
                'message' => 'Status marked as fulfilled',
                'salesorder' => ['salesorder_id' => 'zoho_so_json_check', 'status' => 'fulfilled'],
            ], 200),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->markSalesOrderAsFulfilled('zoho_so_json_check');

        $this->assertEquals('fulfilled', $result['status'] ?? null);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/books/v3/salesorders/zoho_so_json_check/status/fulfilled') &&
                $request->body() === '{}';
        });
    }

    public function test_mark_sales_order_as_fulfilled_handles_already_fulfilled_idempotently()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_already_fulfilled/status/fulfilled*' => Http::response([
                'code' => 100001,
                'message' => 'The sales order status is already fulfilled.',
            ], 400),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->markSalesOrderAsFulfilled('zoho_so_already_fulfilled');

        $this->assertEquals('zoho_so_already_fulfilled', $result['salesorder_id']);
        $this->assertEquals('fulfilled', $result['status']);
    }

    public function test_successful_sales_order_sync_persists_zoho_sales_order_id_and_number()
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8811',
            'order_number' => '#8811',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '50.00',
            'total_price' => '50.00',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/4001',
                    'sku' => 'SKU-ORD-01',
                    'name' => 'Red / Large',
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'salesorders' => []], 200);
                }
                return Http::response([
                    'code' => 0,
                    'message' => 'Sales Order created',
                    'salesorder' => [
                        'salesorder_id' => '4081216000000881199',
                        'salesorder_number' => 'SO-00088',
                    ],
                ], 201);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('4081216000000881199', $result['zoho_sales_order_id']);
        $this->assertEquals('SO-00088', $result['zoho_sales_order_number']);

        $refreshed = $order->fresh();
        $this->assertEquals('4081216000000881199', $refreshed->zoho_sales_order_id);
        $this->assertEquals('SO-00088', $refreshed->zoho_sales_order_number);
    }

    public function test_orders_listing_api_returns_zoho_sales_order_id_and_invoice_id()
    {
        $this->withoutMiddleware([\App\Http\Middleware\ShopifyAuthenticate::class]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8822',
            'order_number' => '#8822',
            'zoho_sales_order_id' => '4081216000000882299',
            'zoho_sales_order_number' => 'SO-00089',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '100.00',
            'total_price' => '100.00',
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => '4081216000000882200',
            'invoice_number' => 'INV-00089',
            'status' => 'sent',
            'amount' => '100.00',
            'currency' => 'USD',
            'sync_status' => 'synced',
        ]);

        $response = $this->call('GET', '/api/zoho/orders?shop=' . $this->shop->shop_domain, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $orders = $response->json('orders');
        $targetOrder = collect($orders)->firstWhere('id', $order->id);

        $this->assertNotNull($targetOrder);
        $this->assertEquals('4081216000000882299', $targetOrder['zoho_sales_order_id']);
        $this->assertEquals('SO-00089', $targetOrder['zoho_sales_order_number']);
        $this->assertNotNull($targetOrder['invoice']);
        $this->assertEquals('4081216000000882200', $targetOrder['invoice']['zoho_invoice_id']);
        $this->assertEquals('INV-00089', $targetOrder['invoice']['invoice_number']);
    }
}

