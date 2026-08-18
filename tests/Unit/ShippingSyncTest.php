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
            $addr = $body['shipping_address'] ?? [];
            return (float) ($body['shipping_charge'] ?? 0) === 15.50
                && ($body['delivery_method'] ?? null) === 'FedEx Express'
                && ($addr['address'] ?? null) === '500 Express Way'
                && ($addr['city'] ?? null) === 'Chicago'
                && ($addr['state'] ?? null) === 'IL';
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
            'first_name' => 'Shipping',
            'last_name' => 'Tester',
            'company' => 'Acme Global Enterprises & Technology Solutions Corporation Limited',
            'address1' => 'Flat 402, Building A, Sunrise Apartments, Opp. Commerce College Road, Near SV Patel Stadium',
            'address2' => 'Navrangpura Extension Commercial Hub Block B',
            'city' => 'Ahmedabad',
            'province' => 'Gujarat',
            'province_code' => 'GJ',
            'zip' => '380009',
            'country' => 'India',
            'country_code' => 'IN',
            'phone' => '+91 9876543210',
        ];

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8105',
            'order_number' => '#8105',
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

        // Verify formatZohoShippingAddress produces structured address under limit
        $formatted = $service->formatZohoShippingAddress($longShippingAddress, $this->customer);
        $totalJoined = implode(' ', array_values($formatted));
        $this->assertLessThanOrEqual(100, strlen($totalJoined));

        $result = $service->syncOrder($order);
        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/salesorders')) {
                return false;
            }
            $body = json_decode($request->body(), true) ?? [];
            $addr = $body['shipping_address'] ?? [];
            $totalLen = strlen(implode(' ', array_values($addr)));
            return $totalLen <= 100 && isset($addr['city']) && $addr['city'] === 'Ahmedabad';
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
        $this->assertEquals('United States', $formatted['country']);
        $this->assertArrayNotHasKey('street2', $formatted);
        $this->assertArrayNotHasKey('attention', $formatted);
    }
}
