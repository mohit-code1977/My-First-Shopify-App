<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Shop;
use App\Models\SyncHistory;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoRefundSyncTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;
    private Order $order;
    private ProductVariant $variant;

    protected function setUp(): void {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'refund-test.myshopify.com',
            'access_token' => 'shpat_test_token',
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => 'org123',
            'api_url' => 'https://www.zohoapis.com',
            'access_token' => 'zoho_access',
            'refresh_token' => 'zoho_refresh',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'cust_111',
            'zoho_contact_id' => 'zoho_contact_111',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@example.com',
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'prod_101',
            'title' => 'Sample T-Shirt',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'var_202',
            'shopify_inventory_item_id' => 'inv_item_303',
            'zoho_item_id' => 'zoho_item_404',
            'sku' => 'TSHIRT-RED-M',
            'title' => 'Red / Medium',
            'price' => 25.00,
            'inventory_quantity' => 10,
        ]);

        $this->order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'order_555',
            'order_number' => '#ORD-555',
            'zoho_sales_order_id' => 'zoho_so_555',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => 50.00,
            'total_price' => 50.00,
            'line_items' => [
                ['variant_id' => 'var_202', 'quantity' => 2, 'price' => 25.00, 'title' => 'Sample T-Shirt'],
            ],
        ]);
    }

    public function test_sync_refund_creates_zoho_credit_note() {
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => Http::response([
                'code' => 0,
                'message' => 'Credit note created.',
                'creditnote' => [
                    'creditnote_id' => 'zoho_cn_777',
                    'creditnote_number' => 'CN-777',
                ],
            ], 200),
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_999',
            'shopify_order_id' => 'order_555',
            'amount' => 50.00,
            'currency' => 'USD',
            'note' => 'Customer returned items',
            'restock' => true,
            'refund_line_items' => [
                [
                    'variant_id' => 'var_202',
                    'title' => 'Sample T-Shirt',
                    'quantity' => 2,
                    'price' => 25.00,
                    'restock_type' => 'return',
                ],
            ],
            'status' => Refund::STATUS_COMPLETED,
            'sync_status' => Refund::SYNC_STATUS_PENDING,
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created']);
        $this->assertEquals('zoho_cn_777', $result['zoho_creditnote_id']);

        $refund->refresh();
        $this->assertEquals('zoho_cn_777', $refund->zoho_creditnote_id);
        $this->assertEquals(Refund::SYNC_STATUS_SYNCED, $refund->sync_status);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/books/v3/creditnotes') && $request->method() === 'POST';
        });
    }

    public function test_refund_webhook_creates_local_refund_and_triggers_sync() {
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => Http::response([
                'code' => 0,
                'creditnote' => [
                    'creditnote_id' => 'zoho_cn_888',
                    'creditnote_number' => 'CN-888',
                ],
            ], 200),
        ]);

        $payload = [
            'id' => 999111,
            'order_id' => 'order_555',
            'total_refunded' => '50.00',
            'note' => 'Damaged on arrival',
            'transactions' => [
                [
                    'status' => 'success',
                    'amount' => '50.00',
                    'kind' => 'refund',
                ],
            ],
            'refund_line_items' => [
                [
                    'line_item_id' => 12345,
                    'quantity' => 2,
                    'subtotal' => 50.00,
                    'restock_type' => 'cancel',
                    'line_item' => [
                        'variant_id' => 'var_202',
                        'title' => 'Sample T-Shirt',
                        'price' => '25.00',
                    ],
                ],
            ],
        ];

        $secret = config('services.shopify.api_secret') ?? env('SHOPIFY_API_SECRET') ?? 'test_secret';
        config(['services.shopify.api_secret' => $secret]);
        $rawPayload = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));

        $response = $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_refund_123',
        ])->postJson('/webhooks/refunds', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('refunds', [
            'shop_id' => $this->shop->id,
            'shopify_refund_id' => '999111',
            'zoho_creditnote_id' => 'zoho_cn_888',
            'sync_status' => 'synced',
        ]);

        $this->assertEquals('refunded', Order::find($this->order->id)->financial_status);
    }

    public function test_register_refund_create_webhook_subscription() {
        Http::fake([
            'https://' . $this->shop->shop_domain . '/admin/api/2026-07/graphql.json' => function (\Illuminate\Http\Client\Request $request) {
                $body = $request->body();
                if (str_contains($body, 'webhookSubscriptions')) {
                    return Http::response([
                        'data' => [
                            'webhookSubscriptions' => [
                                'nodes' => []
                            ]
                        ]
                    ], 200);
                }
                return Http::response([
                    'data' => [
                        'webhookSubscriptionCreate' => [
                            'webhookSubscription' => [
                                'id' => 'gid://shopify/WebhookSubscription/555',
                                'topic' => 'REFUNDS_CREATE',
                                'uri' => 'https://app.example.com/webhooks/refunds'
                            ],
                            'userErrors' => []
                        ]
                    ]
                ], 200);
            }
        ]);

        $shopifyService = new \App\Services\ShopifyService();
        $res = $shopifyService->registerRefundCreateWebhook($this->shop);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['created']);
    }

    public function test_register_all_webhooks_includes_refund_create() {
        $hasRefundsCreate = false;

        Http::fake([
            'https://' . $this->shop->shop_domain . '/admin/api/2026-07/graphql.json' => function (\Illuminate\Http\Client\Request $request) use (&$hasRefundsCreate) {
                $body = $request->body();
                if (str_contains($body, 'REFUNDS_CREATE')) {
                    $hasRefundsCreate = true;
                }
                return Http::response([
                    'data' => [
                        'webhookSubscriptions' => ['nodes' => []],
                        'webhookSubscriptionCreate' => [
                            'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/100', 'topic' => 'REFUNDS_CREATE', 'uri' => 'https://app.example.com/webhooks/refunds'],
                            'userErrors' => []
                        ]
                    ]
                ], 200);
            }
        ]);

        $shopifyService = new \App\Services\ShopifyService();
        $res = $shopifyService->registerAllWebhooks($this->shop);

        $this->assertTrue($res['success']);
        $this->assertArrayHasKey('refund', $res);
        $this->assertTrue($res['refund']['success']);
        $this->assertTrue($hasRefundsCreate);
    }

    public function test_duplicate_refund_webhook_delivery_is_idempotent() {
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => Http::response([
                'code' => 0,
                'creditnote' => [
                    'creditnote_id' => 'zoho_cn_dup',
                    'creditnote_number' => 'CN-DUP',
                ],
            ], 200),
        ]);

        $payload = [
            'id' => 888222,
            'order_id' => 'order_555',
            'total_refunded' => '25.00',
            'note' => 'Duplicate test',
            'refund_line_items' => [],
        ];

        $secret = config('services.shopify.api_secret') ?? 'test_secret';
        config(['services.shopify.api_secret' => $secret]);
        $rawPayload = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));

        // First delivery
        $res1 = $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'unique_webhook_999',
        ])->postJson('/webhooks/refunds', $payload);

        $res1->assertStatus(200);

        // Duplicate delivery with same Webhook ID
        $res2 = $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'unique_webhook_999',
        ])->postJson('/webhooks/refunds', $payload);

        $res2->assertStatus(200);
        $res2->assertJson(['message' => 'Webhook already processed']);
        $this->assertEquals(1, Refund::where('shopify_refund_id', '888222')->count());
    }

    public function test_refund_webhook_tenant_isolation() {
        $otherShop = Shop::create([
            'shop_domain' => 'other-shop.myshopify.com',
            'access_token' => 'shpat_other_token',
        ]);

        $payload = [
            'id' => 777333,
            'order_id' => 'order_555',
            'total_refunded' => '10.00',
            'refund_line_items' => [],
        ];

        $secret = config('services.shopify.api_secret') ?? 'test_secret';
        config(['services.shopify.api_secret' => $secret]);
        $rawPayload = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));

        // Send for otherShop domain where order_555 does not belong to otherShop
        $res = $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Shop-Domain' => $otherShop->shop_domain,
            'X-Shopify-Webhook-Id' => 'isolation_webhook_123',
        ])->postJson('/webhooks/refunds', $payload);

        $res->assertStatus(200);
        // Assert that fallback order resolution created the order for otherShop and saved refund bound to otherShop
        $this->assertEquals(1, Refund::where('shop_id', $otherShop->id)->count());
    }

    public function test_refund_webhook_missing_order_fallback_resolution() {
        Http::fake([
            'https://' . $this->shop->shop_domain . '/admin/api/2026-07/orders/999444.json' => Http::response([
                'order' => [
                    'id' => 999444,
                    'name' => '#1005',
                    'financial_status' => 'partially_refunded',
                    'fulfillment_status' => 'unfulfilled',
                    'created_at' => '2026-08-18T10:00:00Z',
                    'currency' => 'USD',
                    'subtotal_price' => '40.00',
                    'total_price' => '40.00',
                    'customer' => [
                        'id' => 555111,
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'email' => 'john.doe@example.com',
                    ],
                    'line_items' => [
                        [
                            'id' => 111,
                            'title' => 'Sample Item',
                            'quantity' => 1,
                            'price' => '40.00',
                        ]
                    ]
                ]
            ], 200),
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response([
                'code' => 0,
                'contacts' => [
                    ['contact_id' => 'zoho_contact_fallback_123']
                ],
                'contact' => [
                    'contact_id' => 'zoho_contact_fallback_123',
                ],
            ], 200),
            'https://www.zohoapis.com/books/v3/creditnotes*' => Http::response([
                'code' => 0,
                'creditnote' => [
                    'creditnote_id' => 'zoho_cn_fallback_123',
                    'creditnote_number' => 'CN-FALLBACK-123',
                ],
            ], 200),
        ]);

        $payload = [
            'id' => 888777,
            'order_id' => '999444',
            'total_refunded' => '40.00',
            'note' => 'Fallback order test refund',
            'refund_line_items' => [],
        ];

        $secret = config('services.shopify.api_secret') ?? 'test_secret';
        config(['services.shopify.api_secret' => $secret]);
        $rawPayload = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));

        $this->shop->unsetRelation('zohoConnection');
        $res = $this->withHeaders([
            'X-Shopify-Hmac-Sha256' => $hmac,
            'X-Shopify-Shop-Domain' => $this->shop->shop_domain,
            'X-Shopify-Webhook-Id' => 'webhook_fallback_777',
        ])->postJson('/webhooks/refunds', $payload);

        $res->assertStatus(200);
        $res->assertJson(['message' => 'Refund webhook processed successfully.']);

        $createdOrder = Order::where('shop_id', $this->shop->id)->where('shopify_order_id', '999444')->first();
        $this->assertNotNull($createdOrder);

        $refund = Refund::where('shopify_refund_id', '888777')->first();
        $this->assertNotNull($refund);
        $this->assertEquals($createdOrder->id, $refund->order_id);
        $this->assertEquals('zoho_cn_fallback_123', $refund->zoho_creditnote_id);
    }

    public function test_find_zoho_credit_note_unauthorized_code_57_throws_and_does_not_create_duplicate()
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => Http::response([
                'code' => 57,
                'message' => 'You are not authorized to perform this operation',
            ], 400),
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => '998114754728',
            'shopify_order_id' => $this->order->shopify_order_id,
            'amount' => 299.00,
            'currency' => 'USD',
            'status' => 'completed',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);

        try {
            $zohoService->syncRefund($refund);
            $this->fail("Expected Exception was not thrown on code 57 authorization error.");
        } catch (\Throwable $e) {
            $this->assertStringContainsString("You are not authorized to perform this operation", $e->getMessage());
        }

        $this->assertNull($refund->fresh()->zoho_creditnote_id);
        $this->assertEquals('pending', $refund->fresh()->sync_status);
    }

    public function test_refund_sync_reproduces_order_1027_amount_reconciliation_inr()
    {
        $lastPayload = null;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => function (\Illuminate\Http\Client\Request $request) use (&$lastPayload) {
                if ($request->method() === 'POST') {
                    $lastPayload = $request->data();
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_1027',
                            'creditnote_number' => 'CN-1027',
                        ],
                    ], 200);
                }
                return Http::response(['creditnotes' => []], 200);
            },
        ]);

        $order1027 = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => '7482130202792',
            'order_number' => '1027',
            'currency' => 'INR',
            'total_price' => 1098.25,
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order1027->id,
            'shopify_refund_id' => '998258704552',
            'shopify_order_id' => '7482130202792',
            'amount' => 1098.25,
            'currency' => 'INR',
            'note' => 'Order canceled',
            'restock' => true,
            'refund_line_items' => [
                [
                    'line_item_id' => 16375282335912,
                    'variant_id' => 55882947330216,
                    'title' => 'Dekorly Artificial Potted Plants',
                    'quantity' => 1,
                    'price' => 11.18,
                    'restock_type' => 'cancel',
                ],
            ],
            'status' => 'completed',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertNotNull($lastPayload);
        $this->assertEquals('INR', $lastPayload['currency_code']);

        $lineSum = array_reduce($lastPayload['line_items'], function ($sum, $item) {
            return $sum + ($item['rate'] * $item['quantity']);
        }, 0.0);

        $this->assertEquals(1098.25, $lineSum);
    }

    public function test_refund_sync_handles_shipping_only_refund()
    {
        $lastPayload = null;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => function (\Illuminate\Http\Client\Request $request) use (&$lastPayload) {
                if ($request->method() === 'POST') {
                    $lastPayload = $request->data();
                    return Http::response([
                        'code' => 0,
                        'creditnote' => ['creditnote_id' => 'cn_ship', 'creditnote_number' => 'CN-SHIP'],
                    ], 200);
                }
                return Http::response(['creditnotes' => []], 200);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_ship_only',
            'shopify_order_id' => $this->order->shopify_order_id,
            'amount' => 30.00,
            'currency' => 'USD',
            'refund_line_items' => [],
            'status' => 'completed',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $lastPayload['line_items']);
        $this->assertEquals(30.00, $lastPayload['line_items'][0]['rate']);
    }

    public function test_refund_sync_handles_discounted_order_scaling()
    {
        $lastPayload = null;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => function (\Illuminate\Http\Client\Request $request) use (&$lastPayload) {
                if ($request->method() === 'POST') {
                    $lastPayload = $request->data();
                    return Http::response([
                        'code' => 0,
                        'creditnote' => ['creditnote_id' => 'cn_disc', 'creditnote_number' => 'CN-DISC'],
                    ], 200);
                }
                return Http::response(['creditnotes' => []], 200);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_disc',
            'shopify_order_id' => $this->order->shopify_order_id,
            'amount' => 70.00,
            'currency' => 'EUR',
            'refund_line_items' => [
                ['title' => 'Jacket', 'quantity' => 1, 'price' => 100.00],
            ],
            'status' => 'completed',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertEquals('EUR', $lastPayload['currency_code']);

        $lineSum = array_reduce($lastPayload['line_items'], function ($sum, $item) {
            return $sum + ($item['rate'] * $item['quantity']);
        }, 0.0);

        $this->assertEquals(70.00, $lineSum);
    }

    public function test_refund_sync_reconciles_order_1028_inr_amount()
    {
        $lastPayload = null;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => function (\Illuminate\Http\Client\Request $request) use (&$lastPayload) {
                if ($request->method() === 'POST' || $request->method() === 'PUT') {
                    $lastPayload = $request->data();
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => '4081216000000255073',
                            'creditnote_number' => 'CN-00012',
                        ],
                    ], 200);
                }
                return Http::response(['creditnotes' => []], 200);
            },
        ]);

        $order1028 = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => '7482158874792',
            'order_number' => '1028',
            'currency' => 'INR',
            'total_price' => 1749.42,
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order1028->id,
            'shopify_refund_id' => '998260506792',
            'shopify_order_id' => '7482158874792',
            'amount' => 1749.42,
            'currency' => 'INR',
            'refund_line_items' => [
                ['title' => 'SAUDEEP INDIA Artificial Green Leaf Money Plant Garland', 'quantity' => 1, 'price' => 10.16],
            ],
            'status' => 'completed',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertNotNull($lastPayload);
        $this->assertEquals('INR', $lastPayload['currency_code']);

        $lineSum = array_reduce($lastPayload['line_items'], function ($sum, $item) {
            return $sum + ($item['rate'] * $item['quantity']);
        }, 0.0);

        $this->assertEquals(1749.42, round($lineSum, 2));
    }

    public function test_existing_open_cn_with_wrong_amount_updated()
    {
        $putCalled = false;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes/cn_wrong*' => function (\Illuminate\Http\Client\Request $request) use (&$putCalled) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_wrong',
                            'creditnote_number' => 'CN-WRONG',
                            'total' => 10.16,
                            'status' => 'open',
                        ],
                    ], 200);
                }
                if ($request->method() === 'PUT') {
                    $putCalled = true;
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_wrong',
                            'creditnote_number' => 'CN-WRONG',
                            'total' => 100.00,
                            'status' => 'open',
                        ],
                    ], 200);
                }
                return Http::response([], 400);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_open_wrong',
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_creditnote_id' => 'cn_wrong',
            'creditnote_number' => 'CN-WRONG',
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertTrue($putCalled);
        $this->assertDatabaseHas('sync_histories', [
            'refund_id' => $refund->id,
            'action' => 'update',
            'status' => 'success',
            'message' => 'Credit Note reconciled/updated in Zoho Books.',
        ]);
    }

    public function test_existing_open_cn_with_correct_amount_no_op()
    {
        $putCalled = false;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes/cn_correct*' => function (\Illuminate\Http\Client\Request $request) use (&$putCalled) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_correct',
                            'creditnote_number' => 'CN-CORRECT',
                            'total' => 100.00,
                            'status' => 'open',
                        ],
                    ], 200);
                }
                if ($request->method() === 'PUT') {
                    $putCalled = true;
                    return Http::response([], 200);
                }
                return Http::response([], 400);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_open_correct',
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_creditnote_id' => 'cn_correct',
            'creditnote_number' => 'CN-CORRECT',
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertFalse($putCalled);
    }

    public function test_existing_credited_cn_must_not_update()
    {
        $putCalled = false;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes/cn_credited*' => function (\Illuminate\Http\Client\Request $request) use (&$putCalled) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_credited',
                            'creditnote_number' => 'CN-CREDITED',
                            'total' => 10.16,
                            'status' => 'credited',
                        ],
                    ], 200);
                }
                if ($request->method() === 'PUT') {
                    $putCalled = true;
                    return Http::response([], 200);
                }
                return Http::response([], 400);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_credited',
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_creditnote_id' => 'cn_credited',
            'creditnote_number' => 'CN-CREDITED',
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);

        try {
            $zohoService->syncRefund($refund);
            $this->fail("Expected exception was not thrown on non-open credit note.");
        } catch (\Throwable $e) {
            $this->assertStringContainsString("Cannot update Credit Note CN-CREDITED in Zoho: status is 'credited'", $e->getMessage());
        }

        $this->assertFalse($putCalled);
        $this->assertEquals('failed', $refund->fresh()->sync_status);
    }

    public function test_existing_refunded_cn_must_not_update()
    {
        $putCalled = false;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes/cn_refunded*' => function (\Illuminate\Http\Client\Request $request) use (&$putCalled) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_refunded',
                            'creditnote_number' => 'CN-REFUNDED',
                            'total' => 10.16,
                            'status' => 'refunded',
                        ],
                    ], 200);
                }
                if ($request->method() === 'PUT') {
                    $putCalled = true;
                    return Http::response([], 200);
                }
                return Http::response([], 400);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_refunded',
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_creditnote_id' => 'cn_refunded',
            'creditnote_number' => 'CN-REFUNDED',
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'sync_status' => 'synced',
        ]);

        $zohoService = new ZohoService($this->shop);

        try {
            $zohoService->syncRefund($refund);
            $this->fail("Expected exception was not thrown on refunded credit note.");
        } catch (\Throwable $e) {
            $this->assertStringContainsString("Cannot update Credit Note CN-REFUNDED in Zoho: status is 'refunded'", $e->getMessage());
        }

        $this->assertFalse($putCalled);
        $this->assertEquals('failed', $refund->fresh()->sync_status);
    }

    public function test_new_refund_creates_correct_amount()
    {
        $createdPayload = null;
        Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => function (\Illuminate\Http\Client\Request $request) use (&$createdPayload) {
                if ($request->method() === 'POST') {
                    $createdPayload = $request->data();
                    return Http::response([
                        'code' => 0,
                        'creditnote' => [
                            'creditnote_id' => 'cn_new_123',
                            'creditnote_number' => 'CN-NEW-123',
                        ],
                    ], 200);
                }
                return Http::response(['creditnotes' => []], 200);
            },
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_refund_id' => 'ref_new_123',
            'shopify_order_id' => $this->order->shopify_order_id,
            'amount' => 150.00,
            'currency' => 'USD',
            'refund_line_items' => [
                ['title' => 'Shirt', 'quantity' => 1, 'price' => 50.00],
            ],
            'status' => 'completed',
            'sync_status' => 'pending',
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncRefund($refund);

        $this->assertTrue($result['success']);
        $this->assertNotNull($createdPayload);

        $lineSum = array_reduce($createdPayload['line_items'], function ($sum, $item) {
            return $sum + ($item['rate'] * $item['quantity']);
        }, 0.0);

        $this->assertEquals(150.00, round($lineSum, 2));
        $this->assertEquals('cn_new_123', $refund->fresh()->zoho_creditnote_id);
    }

    public function test_historical_refund_backfill_creates_local_refunds()
    {
        $rawOrders = [
            [
                'id' => '7482130202799',
                'name' => '#1099',
                'created_at' => '2026-08-19T10:00:00Z',
                'currency' => 'EUR',
                'financial_status' => 'refunded',
                'fulfillment_status' => 'unfulfilled',
                'subtotal_price' => '100.00',
                'total_discounts' => '0.00',
                'total_tax' => '0.00',
                'total_price' => '100.00',
                'customer' => [
                    'id' => '555111',
                    'first_name' => 'Alice',
                    'last_name' => 'Smith',
                    'email' => 'alice@example.com',
                ],
                'line_items' => [
                    [
                        'id' => '9911',
                        'title' => 'Sample Item',
                        'quantity' => 2,
                        'price' => '50.00',
                        'variant_id' => 'var_202',
                    ]
                ],
                'refunds' => [
                    [
                        'id' => '998258704999',
                        'created_at' => '2026-08-19T11:00:00Z',
                        'note' => 'Backfill test refund',
                        'total_refunded' => 100.00,
                        'currency' => 'EUR',
                        'refund_line_items' => [
                            [
                                'line_item_id' => '9911',
                                'variant_id' => 'var_202',
                                'title' => 'Sample Item',
                                'quantity' => 2,
                                'price' => 50.00,
                                'restock_type' => 'return',
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $controller->ingestShopifyOrders($this->shop, $rawOrders);

        $order = Order::where('shopify_order_id', 'like', '%7482130202799')->first();
        $this->assertNotNull($order);
        $this->assertEquals('refunded', $order->financial_status);

        $refund = Refund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals('100.00', (string) $refund->amount);
        $this->assertEquals('EUR', $refund->currency);
        $this->assertTrue($refund->restock);
        $this->assertEquals(Refund::SYNC_STATUS_PENDING, $refund->sync_status);
    }

    public function test_partial_refund_updates_order_financial_status()
    {
        $rawOrders = [
            [
                'id' => '7482130202888',
                'name' => '#1088',
                'created_at' => '2026-08-19T10:00:00Z',
                'currency' => 'USD',
                'financial_status' => 'partially_refunded',
                'fulfillment_status' => 'unfulfilled',
                'subtotal_price' => '200.00',
                'total_price' => '200.00',
                'line_items' => [
                    ['id' => '8811', 'title' => 'Big Item', 'quantity' => 1, 'price' => '200.00']
                ],
                'refunds' => [
                    [
                        'id' => '998258704888',
                        'created_at' => '2026-08-19T11:00:00Z',
                        'total_refunded' => 50.00,
                        'currency' => 'USD',
                        'refund_line_items' => []
                    ]
                ]
            ]
        ];

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $controller->ingestShopifyOrders($this->shop, $rawOrders);

        $order = Order::where('shopify_order_id', 'like', '%7482130202888')->first();
        $this->assertNotNull($order);
        $this->assertEquals('partially_refunded', $order->financial_status);
        $this->assertEquals('partially_refunded', $order->payment_status);
    }

    public function test_cancelled_order_without_refund_does_not_create_refund_record()
    {
        $rawOrders = [
            [
                'id' => '7482130202777',
                'name' => '#1077',
                'created_at' => '2026-08-19T10:00:00Z',
                'cancelled_at' => '2026-08-19T12:00:00Z',
                'cancel_reason' => 'customer',
                'currency' => 'USD',
                'financial_status' => 'voided',
                'fulfillment_status' => 'unfulfilled',
                'subtotal_price' => '50.00',
                'total_price' => '50.00',
                'line_items' => [],
                'refunds' => []
            ]
        ];

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $controller->ingestShopifyOrders($this->shop, $rawOrders);

        $order = Order::where('shopify_order_id', 'like', '%7482130202777')->first();
        $this->assertNotNull($order);
        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals(0, Refund::where('order_id', $order->id)->count());
    }

    public function test_idempotent_backfill_zero_duplicates()
    {
        $rawOrders = [
            [
                'id' => '7482130202666',
                'name' => '#1066',
                'created_at' => '2026-08-19T10:00:00Z',
                'currency' => 'USD',
                'financial_status' => 'refunded',
                'subtotal_price' => '100.00',
                'total_price' => '100.00',
                'refunds' => [
                    [
                        'id' => '998258704666',
                        'created_at' => '2026-08-19T11:00:00Z',
                        'total_refunded' => 100.00,
                        'currency' => 'USD',
                        'refund_line_items' => []
                    ]
                ]
            ]
        ];

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);

        // Run ingestion twice
        $controller->ingestShopifyOrders($this->shop, $rawOrders);
        $controller->ingestShopifyOrders($this->shop, $rawOrders);

        $order = Order::where('shopify_order_id', 'like', '%7482130202666')->first();
        $this->assertNotNull($order);
        $this->assertEquals(1, Refund::where('order_id', $order->id)->count());
    }

    public function test_refund_sync_status_pending_when_zoho_disconnected()
    {
        // Disconnect Zoho
        ZohoConnection::where('shop_id', $this->shop->id)->delete();

        $rawOrders = [
            [
                'id' => '7482130202555',
                'name' => '#1055',
                'created_at' => '2026-08-19T10:00:00Z',
                'currency' => 'USD',
                'financial_status' => 'refunded',
                'subtotal_price' => '50.00',
                'total_price' => '50.00',
                'refunds' => [
                    [
                        'id' => '998258704555',
                        'created_at' => '2026-08-19T11:00:00Z',
                        'total_refunded' => 50.00,
                        'currency' => 'USD',
                        'refund_line_items' => []
                    ]
                ]
            ]
        ];

        $controller = app(\App\Http\Controllers\ZohoSyncController::class);
        $controller->ingestShopifyOrders($this->shop, $rawOrders);

        $refund = Refund::where('shopify_refund_id', '998258704555')->first();
        $this->assertNotNull($refund);
        $this->assertEquals(Refund::SYNC_STATUS_PENDING, $refund->sync_status);
        $this->assertNull($refund->zoho_creditnote_id);
    }
}
