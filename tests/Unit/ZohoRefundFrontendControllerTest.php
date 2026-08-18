<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Shop;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Customer;
use App\Models\ZohoConnection;
use App\Http\Middleware\ShopifyAuthenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ZohoRefundFrontendControllerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;
    private Shop $shopB;
    private ZohoConnection $connectionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ShopifyAuthenticate::class]);

        $this->shopA = Shop::create([
            'shop_domain' => 'refund-test-shop-a.myshopify.com',
            'access_token' => 'shpat_shop_a_token',
            'scope' => 'read_orders,write_orders',
        ]);

        $this->shopB = Shop::create([
            'shop_domain' => 'refund-test-shop-b.myshopify.com',
            'access_token' => 'shpat_shop_b_token',
            'scope' => 'read_orders,write_orders',
        ]);

        $this->connectionA = ZohoConnection::create([
            'shop_id' => $this->shopA->id,
            'is_active' => true,
            'organization_id' => '123456789',
            'access_token' => '1000.access_token_123',
            'refresh_token' => '1000.refresh_token_123',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_refunds_data_returns_refunds_for_shop_a(): void
    {
        $order = Order::create([
            'shop_id' => $this->shopA->id,
            'shopify_order_id' => 'gid://shopify/Order/1001',
            'order_number' => '1001',
            'total_price' => '100.00',
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $refundA = Refund::create([
            'shop_id' => $this->shopA->id,
            'order_id' => $order->id,
            'shopify_refund_id' => '99811',
            'shopify_order_id' => 'gid://shopify/Order/1001',
            'amount' => '40.00',
            'currency' => 'USD',
            'restock' => true,
            'refund_line_items' => [
                ['title' => 'Test Item', 'quantity' => 1, 'price' => 40.00]
            ],
            'sync_status' => 'pending',
        ]);

        // Create a refund for Shop B
        $orderB = Order::create([
            'shop_id' => $this->shopB->id,
            'shopify_order_id' => 'gid://shopify/Order/2001',
            'order_number' => '2001',
            'total_price' => '200.00',
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        Refund::create([
            'shop_id' => $this->shopB->id,
            'order_id' => $orderB->id,
            'shopify_refund_id' => '99822',
            'shopify_order_id' => 'gid://shopify/Order/2001',
            'amount' => '50.00',
            'currency' => 'USD',
            'restock' => false,
            'sync_status' => 'synced',
        ]);

        $response = $this->actingAsShop($this->shopA)
            ->getJson('/api/zoho/refunds');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'zohoConnected' => true,
            ]);

        $refunds = $response->json('refunds');
        $this->assertCount(1, $refunds);
        $this->assertEquals($refundA->id, $refunds[0]['id']);
        $this->assertEquals('99811', $refunds[0]['shopify_refund_id']);
    }

    public function test_refund_detail_returns_specific_refund_for_shop_a(): void
    {
        $order = Order::create([
            'shop_id' => $this->shopA->id,
            'shopify_order_id' => 'gid://shopify/Order/1002',
            'order_number' => '1002',
            'total_price' => '150.00',
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shopA->id,
            'order_id' => $order->id,
            'shopify_refund_id' => '99833',
            'shopify_order_id' => 'gid://shopify/Order/1002',
            'amount' => '150.00',
            'currency' => 'USD',
            'restock' => true,
            'refund_line_items' => [
                ['title' => 'T-Shirt', 'quantity' => 2, 'price' => 75.00]
            ],
            'sync_status' => 'failed',
            'error_message' => 'Authorization failed',
        ]);

        $response = $this->actingAsShop($this->shopA)
            ->getJson("/api/zoho/refunds/{$refund->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'refund' => [
                    'id' => $refund->id,
                    'shopify_refund_id' => '99833',
                    'sync_status' => 'failed',
                    'error_message' => 'Authorization failed',
                ],
            ]);
    }

    public function test_tenant_isolation_prevents_accessing_shop_b_refund(): void
    {
        $orderB = Order::create([
            'shop_id' => $this->shopB->id,
            'shopify_order_id' => 'gid://shopify/Order/2002',
            'order_number' => '2002',
            'total_price' => '300.00',
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $refundB = Refund::create([
            'shop_id' => $this->shopB->id,
            'order_id' => $orderB->id,
            'shopify_refund_id' => '99844',
            'shopify_order_id' => 'gid://shopify/Order/2002',
            'amount' => '300.00',
            'currency' => 'USD',
            'restock' => false,
            'sync_status' => 'pending',
        ]);

        // Attempt to fetch Shop B's refund using Shop A credentials
        $detailResponse = $this->actingAsShop($this->shopA)
            ->getJson("/api/zoho/refunds/{$refundB->id}");

        $detailResponse->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Refund record not found.',
            ]);

        // Attempt to sync Shop B's refund using Shop A credentials
        $syncResponse = $this->actingAsShop($this->shopA)
            ->postJson('/zoho/sync-refund', [
                'refund_id' => $refundB->id,
            ]);

        $syncResponse->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Refund record not found.',
            ]);
    }

    public function test_refund_retry_resolves_product_variant_via_product_relationship_with_both_numeric_and_gid_formats(): void
    {
        $product = Product::create([
            'shop_id' => $this->shopA->id,
            'shopify_product_id' => 'gid://shopify/Product/9001',
            'title' => 'Test T-Shirt',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/55878864699560',
            'title' => 'Default',
            'sku' => 'TEST-SKU-1',
            'price' => 40.00,
            'zoho_item_id' => '4081216000000100999',
        ]);

        $customer = Customer::create([
            'shop_id' => $this->shopA->id,
            'shopify_customer_id' => 'gid://shopify/Customer/9001',
            'zoho_contact_id' => '4081216000000100888',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
        ]);

        $order = Order::create([
            'shop_id' => $this->shopA->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/1003',
            'order_number' => '1003',
            'total_price' => '40.00',
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shopA->id,
            'order_id' => $order->id,
            'shopify_refund_id' => '99855',
            'shopify_order_id' => 'gid://shopify/Order/1003',
            'amount' => '40.00',
            'currency' => 'USD',
            'restock' => true,
            'refund_line_items' => [
                [
                    'variant_id' => '55878864699560',
                    'quantity' => 1,
                    'price' => 40.00,
                ]
            ],
            'sync_status' => 'failed',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://www.zohoapis.com/books/v3/creditnotes*' => function (\Illuminate\Http\Client\Request $request) {
                if ($request->method() === 'GET') {
                    return \Illuminate\Support\Facades\Http::response(['code' => 0, 'creditnotes' => []], 200);
                }
                return \Illuminate\Support\Facades\Http::response([
                    'code' => 0,
                    'creditnote' => [
                        'creditnote_id' => 'cn_zoho_99855',
                        'creditnote_number' => 'CN-99855',
                    ]
                ], 201);
            },
        ]);

        $response = $this->actingAsShop($this->shopA)
            ->postJson('/zoho/sync-refund', [
                'refund_id' => $refund->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $refund->refresh();
        $this->assertEquals('synced', $refund->sync_status);
        $this->assertEquals('cn_zoho_99855', $refund->zoho_creditnote_id);
    }

    public function test_full_refund_with_restock_and_multiple_refunded_items(): void
    {
        $order = Order::create([
            'shop_id' => $this->shopA->id,
            'shopify_order_id' => 'gid://shopify/Order/3001',
            'order_number' => '3001',
            'total_price' => '100.00',
            'currency' => 'USD',
            'order_date' => now(),
            'line_items' => [
                ['title' => 'Item Alpha', 'quantity' => 1, 'price' => 40.00],
                ['title' => 'Item Beta', 'quantity' => 2, 'price' => 30.00],
            ],
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shopA->id,
            'order_id' => $order->id,
            'shopify_refund_id' => '99901',
            'shopify_order_id' => 'gid://shopify/Order/3001',
            'amount' => '100.00',
            'currency' => 'USD',
            'restock' => true,
            'refund_line_items' => [
                ['title' => 'Item Alpha', 'sku' => 'SKU-A', 'quantity' => 1, 'price' => 40.00, 'restock_type' => 'return'],
                ['title' => 'Item Beta', 'sku' => 'SKU-B', 'quantity' => 2, 'price' => 30.00, 'restock_type' => 'return'],
            ],
            'sync_status' => 'synced',
            'zoho_creditnote_id' => 'CN-3001',
        ]);

        $response = $this->actingAsShop($this->shopA)
            ->getJson("/api/zoho/refunds/{$refund->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'refund' => [
                    'id' => $refund->id,
                    'amount' => '100.00',
                    'restock' => true,
                    'zoho_creditnote_id' => 'CN-3001',
                ],
            ]);

        $refundData = $response->json('refund');
        $this->assertCount(2, $refundData['refund_line_items']);
        $this->assertEquals('Item Alpha', $refundData['refund_line_items'][0]['title']);
        $this->assertEquals('Item Beta', $refundData['refund_line_items'][1]['title']);
    }

    public function test_partial_refund_without_restock(): void
    {
        $order = Order::create([
            'shop_id' => $this->shopA->id,
            'shopify_order_id' => 'gid://shopify/Order/3002',
            'order_number' => '3002',
            'total_price' => '200.00',
            'currency' => 'USD',
            'order_date' => now(),
            'line_items' => [
                ['title' => 'Item Gamma', 'quantity' => 2, 'price' => 100.00],
            ],
        ]);

        $refund = Refund::create([
            'shop_id' => $this->shopA->id,
            'order_id' => $order->id,
            'shopify_refund_id' => '99902',
            'shopify_order_id' => 'gid://shopify/Order/3002',
            'amount' => '100.00',
            'currency' => 'USD',
            'restock' => false,
            'refund_line_items' => [
                ['title' => 'Item Gamma', 'sku' => 'SKU-G', 'quantity' => 1, 'price' => 100.00, 'restock_type' => 'no_restock'],
            ],
            'sync_status' => 'pending',
        ]);

        $response = $this->actingAsShop($this->shopA)
            ->getJson("/api/zoho/refunds/{$refund->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'refund' => [
                    'id' => $refund->id,
                    'amount' => '100.00',
                    'restock' => false,
                    'sync_status' => 'pending',
                ],
            ]);

        $refundData = $response->json('refund');
        $this->assertCount(1, $refundData['refund_line_items']);
        $this->assertFalse($refundData['restock']);
    }

    private function actingAsShop(Shop $shop)
    {
        return $this->withHeaders([
            'X-Shop-Domain' => $shop->shop_domain,
            'Accept' => 'application/json',
        ])->withUnencryptedCookie('shop_domain', $shop->shop_domain);
    }
}
