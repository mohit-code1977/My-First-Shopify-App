<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class TaxMappingTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected Shop $shop;
    protected ZohoConnection $zohoConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([\App\Http\Middleware\ShopifyAuthenticate::class]);

        $this->shop = Shop::create([
            'shop_domain' => 'test-tax-shop.myshopify.com',
            'access_token' => 'shpat_test1234567890',
            'tax_settings' => [
                'tax_mode' => 'exclusive',
                'default_tax_id' => 'zoho_tax_default_999',
                'shipping_tax_mode' => 'use_order_tax',
                'discount_tax_mode' => 'before_tax',
                'tax_mappings' => [
                    [
                        'shopify_tax_name' => 'GST',
                        'shopify_rate' => 5,
                        'zoho_tax_id' => 'zoho_tax_gst_5',
                        'zoho_tax_name' => 'GST 5%',
                    ],
                    [
                        'shopify_tax_name' => 'VAT',
                        'shopify_rate' => 20,
                        'zoho_tax_id' => 'zoho_tax_vat_20',
                        'zoho_tax_name' => 'VAT 20%',
                    ],
                ],
            ],
        ]);

        $this->zohoConnection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => '123456789',
            'access_token' => 'z_access_token_123',
            'refresh_token' => 'z_refresh_token_123',
            'expires_at' => now()->addHour(),
            'api_url' => 'https://www.zohoapis.com',
            'accounts_url' => 'https://accounts.zoho.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);
    }

    public function test_tax_settings_persistence_on_shop_model(): void
    {
        $this->assertIsArray($this->shop->tax_settings);
        $this->assertEquals('exclusive', $this->shop->tax_settings['tax_mode']);
        $this->assertEquals('zoho_tax_gst_5', $this->shop->tax_settings['tax_mappings'][0]['zoho_tax_id']);

        $this->shop->update([
            'tax_settings' => array_merge($this->shop->tax_settings, ['tax_mode' => 'inclusive']),
        ]);

        $this->shop->refresh();
        $this->assertEquals('inclusive', $this->shop->tax_settings['tax_mode']);
    }

    public function test_sales_order_payload_includes_mapped_line_tax_and_tax_mode(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response([
                'code' => 0,
                'contacts' => [['contact_id' => 'zoho_contact_tax_1']],
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'salesorders' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_tax_101',
                            'salesorder_number' => 'SO-101',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/101',
            'first_name' => 'Tax',
            'last_name' => 'Tester',
            'email' => 'tax@example.com',
            'zoho_contact_id' => 'zoho_contact_tax_1',
        ]);

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/9991',
            'title' => 'Widget A',
            'handle' => 'widget-a',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/10001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/10001',
            'sku' => 'WIDGET-A',
            'title' => 'Widget A',
            'price' => '50.00',
            'inventory_quantity' => 50,
            'zoho_item_id' => 'zoho_item_w1',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7001',
            'order_number' => '#7001',
            'currency' => 'USD',
            'subtotal' => 100.00,
            'discount_total' => 0.00,
            'shipping_total' => 10.00,
            'tax_total' => 5.00,
            'total_price' => 115.00,
            'taxes_included' => false,
            'line_items' => [
                [
                    'line_item_id' => '10001',
                    'variant_id' => 'gid://shopify/ProductVariant/10001',
                    'title' => 'Widget A',
                    'quantity' => 2,
                    'price' => 50.00,
                    'sku' => 'WIDGET-A',
                    'tax_lines' => [
                        [
                            'title' => 'State GST',
                            'rate' => 0.05,
                            'price' => 5.00,
                        ],
                    ],
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_so_tax_101', $result['zoho_sales_order_id']);
        $this->assertEquals('zoho_so_tax_101', $order->fresh()->zoho_sales_order_id);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $lineItems = $body['line_items'] ?? [];
            return ($body['is_inclusive_tax'] === false)
                && ($body['is_discount_before_tax'] === true)
                && (($lineItems[0]['tax_id'] ?? null) === 'zoho_tax_gst_5')
                && (($body['tax_total'] ?? 0) == 5.00);
        });
    }

    public function test_invoice_payload_includes_inclusive_tax_flag_when_taxes_included(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response([
                'code' => 0,
                'contacts' => [['contact_id' => 'zoho_contact_tax_2']],
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response([
                'code' => 0,
                'message' => 'Sales Order created',
                'salesorder' => [
                    'salesorder_id' => 'zoho_so_tax_202',
                ],
            ], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'invoices' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Invoice created',
                        'invoice' => [
                            'invoice_id' => 'zoho_inv_tax_202',
                            'invoice_number' => 'INV-202',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/102',
            'first_name' => 'Inclusive',
            'last_name' => 'Buyer',
            'email' => 'inclusive@example.com',
            'zoho_contact_id' => 'zoho_contact_tax_2',
        ]);

        $product2 = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/9992',
            'title' => 'VAT Item',
            'handle' => 'vat-item',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product2->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/10002',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/10002',
            'sku' => 'VAT-ITEM',
            'title' => 'VAT Item',
            'price' => '120.00',
            'inventory_quantity' => 50,
            'zoho_item_id' => 'zoho_item_vat1',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7002',
            'order_number' => '#7002',
            'currency' => 'EUR',
            'subtotal' => 120.00,
            'discount_total' => 0.00,
            'shipping_total' => 0.00,
            'tax_total' => 20.00,
            'total_price' => 120.00,
            'taxes_included' => true,
            'line_items' => [
                [
                    'line_item_id' => '10002',
                    'variant_id' => 'gid://shopify/ProductVariant/10002',
                    'title' => 'VAT Item',
                    'quantity' => 1,
                    'price' => 120.00,
                    'sku' => 'VAT-ITEM',
                    'tax_lines' => [
                        [
                            'title' => 'EU VAT 20%',
                            'rate' => 0.20,
                            'price' => 20.00,
                        ],
                    ],
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncInvoice($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_inv_tax_202', $result['zoho_invoice_id']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/invoices')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $lineItems = $body['line_items'] ?? [];
            return ($body['is_inclusive_tax'] === true)
                && (($lineItems[0]['tax_id'] ?? null) === 'zoho_tax_vat_20')
                && (($body['tax_total'] ?? 0) == 20.00);
        });
    }

    public function test_zero_tax_orders_sync_cleanly_without_tax_id_error(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/contacts*' => Http::response([
                'code' => 0,
                'contacts' => [['contact_id' => 'zoho_contact_tax_3']],
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'salesorders' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_zerotax_303',
                            'salesorder_number' => 'SO-303',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        // Remove default_tax_id to test zero tax fallback
        $this->shop->update([
            'tax_settings' => array_merge($this->shop->tax_settings, ['default_tax_id' => '']),
        ]);

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/103',
            'first_name' => 'Exempt',
            'last_name' => 'Customer',
            'email' => 'exempt@example.com',
            'zoho_contact_id' => 'zoho_contact_tax_3',
        ]);

        $product3 = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/9993',
            'title' => 'Tax Free Item',
            'handle' => 'tax-free-item',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product3->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/10003',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/10003',
            'sku' => 'NO-TAX',
            'title' => 'Tax Free Item',
            'price' => '50.00',
            'inventory_quantity' => 50,
            'zoho_item_id' => 'zoho_item_notax',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7003',
            'order_number' => '#7003',
            'currency' => 'USD',
            'subtotal' => 50.00,
            'discount_total' => 0.00,
            'shipping_total' => 5.00,
            'tax_total' => 0.00,
            'total_price' => 55.00,
            'taxes_included' => false,
            'line_items' => [
                [
                    'line_item_id' => '10003',
                    'variant_id' => 'gid://shopify/ProductVariant/10003',
                    'title' => 'Tax Free Item',
                    'quantity' => 1,
                    'price' => 50.00,
                    'sku' => 'NO-TAX',
                    'tax_lines' => [],
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_so_zerotax_303', $result['zoho_sales_order_id']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $lineItems = $body['line_items'] ?? [];
            return !isset($lineItems[0]['tax_id']) && !isset($body['tax_total']);
        });
    }

    public function test_save_tax_settings_endpoint_validates_and_persists_settings(): void
    {
        $payload = [
            'tax_mode' => 'inclusive',
            'default_tax_id' => 'zoho_tax_default_100',
            'shipping_tax_mode' => 'use_order_tax',
            'discount_tax_mode' => 'before_tax',
            'tax_mappings' => [
                [
                    'shopify_tax_name' => 'State GST',
                    'shopify_rate' => 9,
                    'zoho_tax_id' => 'zoho_sgst_9',
                ],
            ],
        ];

        $response = $this->actingAsShop()
            ->postJson('/zoho/settings/tax', $payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->shop->refresh();
        $this->assertEquals('inclusive', $this->shop->tax_settings['tax_mode']);
        $this->assertEquals('zoho_sgst_9', $this->shop->tax_settings['tax_mappings'][0]['zoho_tax_id']);
    }

    public function test_save_tax_settings_rejects_more_than_50_mappings(): void
    {
        $mappings = [];
        for ($i = 1; $i <= 51; $i++) {
            $mappings[] = [
                'shopify_tax_name' => "Tax {$i}",
                'shopify_rate' => 5,
                'zoho_tax_id' => "zoho_tax_{$i}",
            ];
        }

        $payload = [
            'tax_mode' => 'exclusive',
            'default_tax_id' => 'zoho_tax_default_100',
            'shipping_tax_mode' => 'use_order_tax',
            'discount_tax_mode' => 'before_tax',
            'tax_mappings' => $mappings,
        ];

        $response = $this->actingAsShop()
            ->postJson('/zoho/settings/tax', $payload);

        $response->assertStatus(422);
    }

    public function test_save_tax_settings_rejects_duplicate_mappings(): void
    {
        $payload = [
            'tax_mode' => 'exclusive',
            'default_tax_id' => 'zoho_tax_default_100',
            'shipping_tax_mode' => 'use_order_tax',
            'discount_tax_mode' => 'before_tax',
            'tax_mappings' => [
                [
                    'shopify_tax_name' => 'GST',
                    'shopify_rate' => 18,
                    'zoho_tax_id' => 'zoho_gst_18',
                ],
                [
                    'shopify_tax_name' => 'GST',
                    'shopify_rate' => 18,
                    'zoho_tax_id' => 'zoho_gst_18_alt',
                ],
            ],
        ];

        $response = $this->actingAsShop()
            ->postJson('/zoho/settings/tax', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment(['success' => false]);
    }

    public function test_save_tax_settings_validates_tax_rate_bounds(): void
    {
        $payload = [
            'tax_mode' => 'exclusive',
            'default_tax_id' => 'zoho_tax_default_100',
            'shipping_tax_mode' => 'use_order_tax',
            'discount_tax_mode' => 'before_tax',
            'tax_mappings' => [
                [
                    'shopify_tax_name' => 'Invalid Tax',
                    'shopify_rate' => 150,
                    'zoho_tax_id' => 'zoho_tax_invalid',
                ],
            ],
        ];

        $response = $this->actingAsShop()
            ->postJson('/zoho/settings/tax', $payload);

        $response->assertStatus(422);
    }

    private function actingAsShop()
    {
        return $this->withHeaders([
            'X-Shop-Domain' => $this->shop->shop_domain,
            'Accept' => 'application/json',
        ])->withUnencryptedCookie('shop_domain', $this->shop->shop_domain);
    }
}
