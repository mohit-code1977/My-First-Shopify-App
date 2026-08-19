<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;
    protected ZohoConnection $zohoConnection;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'test-shipping-shop.myshopify.com',
            'access_token' => 'shpat_shipping_test123',
        ]);

        $this->zohoConnection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'organization_id' => '123456789',
            'access_token' => 'z_access_shipping_123',
            'refresh_token' => 'z_refresh_shipping_123',
            'expires_at' => now()->addHour(),
            'api_url' => 'https://www.zohoapis.com',
            'accounts_url' => 'https://accounts.zoho.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/8001',
            'first_name' => 'Shipping',
            'last_name' => 'Tester',
            'email' => 'shipping@example.com',
            'zoho_contact_id' => 'zoho_contact_ship_8001',
            'shipping_address' => [
                'address1' => '100 Default St',
                'city' => 'New York',
                'province' => 'NY',
                'zip' => '10001',
                'country' => 'United States',
            ],
        ]);
    }

    public function test_order_with_free_shipping_syncs_zero_shipping_charge(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_free_ship_101',
                            'salesorder_number' => 'SO-FREE-101',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/8881',
            'title' => 'Sample Item',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/88801',
            'sku' => 'FREE-SHIP-ITEM',
            'title' => 'Sample Item',
            'price' => '100.00',
            'zoho_item_id' => 'zoho_item_free1',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8101',
            'order_number' => '#8101',
            'currency' => 'USD',
            'subtotal' => 100.00,
            'discount_total' => 0.00,
            'shipping_total' => 0.00,
            'shipping_method' => 'Free Shipping',
            'tax_total' => 0.00,
            'total_price' => 100.00,
            'line_items' => [
                [
                    'sku' => 'FREE-SHIP-ITEM',
                    'title' => 'Sample Item',
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_so_free_ship_101', $result['zoho_sales_order_id']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            return isset($body['shipping_charge'])
                && (float) $body['shipping_charge'] === 0.00
                && ($body['delivery_method'] ?? null) === 'Free Shipping';
        });
    }

    public function test_order_with_paid_shipping_and_shipping_address_syncs_correctly(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_paid_ship_102',
                            'salesorder_number' => 'SO-PAID-102',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/8882',
            'title' => 'Sample Item 2',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/88802',
            'sku' => 'PAID-SHIP-ITEM',
            'title' => 'Sample Item 2',
            'price' => '200.00',
            'zoho_item_id' => 'zoho_item_paid2',
        ]);

        $shippingAddress = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '500 Express Way',
            'address2' => 'Suite 4B',
            'city' => 'Chicago',
            'province' => 'IL',
            'zip' => '60601',
            'country' => 'United States',
            'phone' => '555-0199',
        ];

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8102',
            'order_number' => '#8102',
            'currency' => 'USD',
            'subtotal' => 200.00,
            'discount_total' => 0.00,
            'shipping_total' => 15.50,
            'shipping_method' => 'FedEx Express',
            'shipping_address' => $shippingAddress,
            'tax_total' => 0.00,
            'total_price' => 215.50,
            'line_items' => [
                [
                    'sku' => 'PAID-SHIP-ITEM',
                    'title' => 'Sample Item 2',
                    'quantity' => 1,
                    'price' => 200.00,
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_so_paid_ship_102', $result['zoho_sales_order_id']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $addr = $body['shipping_address'] ?? '';
            return (float) ($body['shipping_charge'] ?? 0) === 15.50
                && ($body['delivery_method'] ?? null) === 'FedEx Express'
                && is_string($addr)
                && str_contains($addr, '500 Express Way')
                && str_contains($addr, 'Chicago');
        });
    }

    public function test_order_with_tracking_number_includes_tracking_in_notes(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_track_103',
                            'salesorder_number' => 'SO-TRACK-103',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/8883',
            'title' => 'Tracked Item',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/88803',
            'sku' => 'TRACKED-ITEM',
            'title' => 'Tracked Item',
            'price' => '50.00',
            'zoho_item_id' => 'zoho_item_track3',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8103',
            'order_number' => '#8103',
            'currency' => 'USD',
            'subtotal' => 50.00,
            'discount_total' => 0.00,
            'shipping_total' => 5.00,
            'shipping_method' => 'UPS Ground',
            'tracking_number' => '1Z9999999999999999',
            'tracking_company' => 'UPS',
            'tracking_url' => 'https://www.ups.com/track?loc=en_US&tracknum=1Z9999999999999999',
            'tax_total' => 0.00,
            'total_price' => 55.00,
            'line_items' => [
                [
                    'sku' => 'TRACKED-ITEM',
                    'title' => 'Tracked Item',
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncOrder($order);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $notes = $body['notes'] ?? '';
            return str_contains($notes, 'Tracking: #1Z9999999999999999 (Carrier: UPS)')
                && str_contains($notes, 'https://www.ups.com/track?loc=en_US&tracknum=1Z9999999999999999');
        });
    }

    public function test_order_without_tracking_information_syncs_cleanly(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_no_track_104',
                            'salesorder_number' => 'SO-NOTRACK-104',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/8884',
            'title' => 'Untracked Item',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/88804',
            'sku' => 'UNTRACKED-ITEM',
            'title' => 'Untracked Item',
            'price' => '30.00',
            'zoho_item_id' => 'zoho_item_untrack4',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8104',
            'order_number' => '#8104',
            'currency' => 'USD',
            'subtotal' => 30.00,
            'discount_total' => 0.00,
            'shipping_total' => 0.00,
            'tracking_number' => null,
            'tracking_company' => null,
            'tracking_url' => null,
            'tax_total' => 0.00,
            'total_price' => 30.00,
            'line_items' => [
                [
                    'sku' => 'UNTRACKED-ITEM',
                    'title' => 'Untracked Item',
                    'quantity' => 1,
                    'price' => 30.00,
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_so_no_track_104', $result['zoho_sales_order_id']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $notes = $body['notes'] ?? '';
            return !str_contains($notes, 'Tracking:');
        });
    }

    public function test_order_with_long_shipping_address_formats_under_character_limit(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order created',
                        'salesorder' => [
                            'salesorder_id' => 'zoho_so_long_addr_105',
                            'salesorder_number' => 'SO-LONGADDR-105',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/8885',
            'title' => 'Long Address Item',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/88805',
            'sku' => 'LONG-ADDR-ITEM',
            'title' => 'Long Address Item',
            'price' => '40.00',
            'zoho_item_id' => 'zoho_item_long5',
        ]);
        $longShippingAddress = [
            'first_name' => 'Alexander',
            'last_name' => 'Montgomery-Wellington the Third',
            'company' => 'International Consolidated Megacorp Enterprises Holdings & Logistics Division',
            'address1' => '99999 Extremely Long Boulevard Suite 4500 Building B North Wing Corporate Tower',
            'address2' => 'Care Of Global Supply Chain Receiving Floor 4 Room 402',
            'city' => 'Ahmedabad',
            'province' => 'Gujarat State Western Region',
            'province_code' => 'GJ',
            'zip' => '380015',
            'country' => 'Republic of India',
            'country_code' => 'IN',
            'phone' => '+91 98765 43210',
        ];

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/999104',
            'order_number' => '#104',
            'order_date' => now(),
            'currency' => 'INR',
            'subtotal' => 40.00,
            'discount_total' => 0.00,
            'shipping_total' => 10.00,
            'shipping_address' => $longShippingAddress,
            'tax_total' => 0.00,
            'total_price' => 50.00,
            'line_items' => [
                [
                    'sku' => 'LONG-ADDR-ITEM',
                    'title' => 'Long Address Item',
                    'quantity' => 1,
                    'price' => 40.00,
                ],
            ],
        ]);

        $service = new ZohoService($this->shop);

        // Verify local DB record retains full original address unchanged
        $this->assertEquals($longShippingAddress, $order->fresh()->shipping_address);

        // Verify formatZohoAddressString produces single text string strictly <= 100 characters
        $formattedStr = $service->formatZohoAddressString($longShippingAddress, $this->customer);
        $this->assertLessThanOrEqual(100, strlen($formattedStr));
        $this->assertStringContainsString('Ahmedabad', $formattedStr);

        $result = $service->syncOrder($order);
        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $addr = $body['shipping_address'] ?? '';
            return is_string($addr) && strlen($addr) <= 100 && str_contains($addr, 'Ahmedabad');
        });
    }

    public function test_format_zoho_shipping_address_handles_missing_optional_fields(): void
    {
        $service = new ZohoService($this->shop);
        $minimalAddress = [
            'address1' => '123 Main St',
            'city' => 'Austin',
            'country' => 'United States',
        ];

        $formatted = $service->formatZohoShippingAddress($minimalAddress, null);

        $this->assertEquals('123 Main St', $formatted['address']);
        $this->assertEquals('Austin', $formatted['city']);
        $this->assertArrayNotHasKey('attention', $formatted);
    }

    public function test_payment_sync_with_long_shipping_address_succeeds_and_omits_unnecessary_address_fields(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response([
                'code' => 0,
                'salesorders' => [],
                'salesorder' => ['salesorder_id' => 'zoho_so_pay_addr_105', 'salesorder_number' => 'SO-105'],
            ], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => Http::response([
                'code' => 0,
                'invoices' => [],
                'invoice' => ['invoice_id' => 'zoho_inv_pay_addr_105', 'invoice_number' => 'INV-105', 'status' => 'unpaid', 'balance' => 41.00],
            ], 201),
            'https://www.zohoapis.com/books/v3/customerpayments*' => Http::response([
                'code' => 0,
                'payment' => ['payment_id' => 'zoho_pay_addr_105', 'payment_number' => 'PAY-105'],
            ], 201),
        ]);

        $longAddress = [
            'first_name' => 'Jonathan',
            'last_name' => 'Alexander-Smith',
            'address1' => '1600 Amphitheatre Parkway Building 43 Second Floor Suite 200 Corporate Headquarters North Wing',
            'city' => 'Mountain View',
            'province' => 'California',
            'province_code' => 'CA',
            'zip' => '94043',
            'country' => 'United States',
            'country_code' => 'US',
        ];

        $product = \App\Models\Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/999105',
            'title' => 'Dekorly Potted Plant',
        ]);

        \App\Models\ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/9991051',
            'sku' => 'PAYMENT-ADDR-ITEM',
            'title' => 'Dekorly Potted Plant',
            'price' => '11.00',
            'zoho_item_id' => 'zoho_item_pay_addr_105',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/999105',
            'order_number' => '#105',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => 11.00,
            'discount_total' => 0.00,
            'shipping_total' => 30.00,
            'shipping_address' => $longAddress,
            'tax_total' => 0.00,
            'total_price' => 41.00,
            'line_items' => [
                [
                    'sku' => 'PAYMENT-ADDR-ITEM',
                    'title' => 'Dekorly Potted Plant',
                    'quantity' => 1,
                    'price' => 11.00,
                ],
            ],
        ]);

        $payment = \App\Models\Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/9991051',
            'payment_reference' => 'TXN-9991051',
            'amount' => 41.00,
            'currency' => 'USD',
            'payment_date' => now(),
            'payment_method' => 'bogus',
            'status' => 'paid',
            'sync_status' => \App\Models\Payment::SYNC_STATUS_PENDING,
        ]);

        $service = new ZohoService($this->shop);
        $result = $service->syncPayment($payment);

        $this->assertTrue($result['success']);
        $this->assertEquals(\App\Models\Payment::SYNC_STATUS_SYNCED, $payment->fresh()->sync_status);

        // Verify Customer Payment POST payload contains strictly required payment fields and omits shipping_address
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/customerpayments')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            return isset($body['customer_id'])
                && isset($body['amount'])
                && !isset($body['shipping_address'])
                && !isset($body['billing_address']);
        });
    }
}
