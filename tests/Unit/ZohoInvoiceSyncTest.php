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

class ZohoInvoiceSyncTest extends TestCase {
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;
    private ProductVariant $variant1;
    private Order $order;

    protected function setUp(): void {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'invoice-test.myshopify.com',
            'access_token' => 'shpat_invoice_token',
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
            'shopify_customer_id' => 'gid://shopify/Customer/3001',
            'zoho_contact_id' => 'zoho_contact_3001',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice.invoice@example.com',
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/4001',
            'title' => 'Invoice Product',
            'handle' => 'invoice-product',
        ]);

        $this->variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/5001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/6001',
            'sku' => 'SKU-INV-01',
            'title' => 'Standard Edition',
            'price' => '100.00',
            'inventory_quantity' => 20,
            'zoho_item_id' => 'zoho_item_5001',
        ]);

        $this->order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/9001',
            'order_number' => '#INV-1001',
            'zoho_sales_order_id' => 'zoho_so_9001',
            'zoho_sales_order_number' => 'SO-09001',
            'order_date' => now(),
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_total' => '10.00',
            'shipping_total' => '12.00',
            'tax_total' => '8.00',
            'total_price' => '110.00',
            'notes' => 'Deliver on weekday afternoons.',
            'coupon_code' => 'DISCOUNT10',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/5001',
                    'sku' => 'SKU-INV-01',
                    'name' => 'Standard Edition',
                    'quantity' => 1,
                    'price' => 100.00,
                    'total_discount' => 10.00,
                ],
            ],
        ]);
    }

    private function calculateHmac(string $payload, string $secret): string {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    public function test_invoice_creation_in_zoho_books() {
        Http::fake([
            'https://www.zohoapis.com/books/v3/invoices*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'invoices' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Invoice created',
                        'invoice' => [
                            'invoice_id' => 'zoho_inv_9001',
                            'invoice_number' => 'INV-00001',
                            'status' => 'sent',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInvoice($this->order);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created']);
        $this->assertEquals('zoho_inv_9001', $result['zoho_invoice_id']);
        $this->assertEquals('INV-00001', $result['invoice_number']);

        $this->assertDatabaseHas('invoices', [
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'zoho_invoice_id' => 'zoho_inv_9001',
            'invoice_number' => 'INV-00001',
        ]);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/books/v3/invoices') &&
                $request->data()['customer_id'] === 'zoho_contact_3001' &&
                $request->data()['salesorder_id'] === 'zoho_so_9001' &&
                $request->data()['reference_number'] === '#INV-1001' &&
                $request->data()['shipping_charge'] === 12.0 &&
                $request->data()['discount'] === 10.0;
        });
    }

    public function test_invoice_customer_and_sales_order_relationship() {
        Http::fake([
            'https://www.zohoapis.com/books/v3/invoices*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'invoices' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'invoice' => [
                            'invoice_id' => 'zoho_inv_rel_01',
                            'invoice_number' => 'INV-00002',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInvoice($this->order);

        $this->assertTrue($result['success']);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $data = $request->data();
            return ($data['customer_id'] ?? null) === 'zoho_contact_3001' &&
                ($data['salesorder_id'] ?? null) === 'zoho_so_9001';
        });
    }

    public function test_duplicate_invoice_prevention_local_and_zoho() {
        Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_existing_100',
            'invoice_number' => 'INV-00100',
            'status' => 'sent',
            'amount' => '110.00',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/invoices*' => function (Request $request) {
                if ($request->method() === 'PUT') {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Invoice updated',
                        'invoice' => [
                            'invoice_id' => 'zoho_inv_existing_100',
                            'invoice_number' => 'INV-00100',
                        ],
                    ], 200);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInvoice($this->order);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['updated']);
        $this->assertFalse($result['created']);
        $this->assertEquals(1, Invoice::where('order_id', $this->order->id)->count());

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' &&
                str_contains($request->url(), '/books/v3/invoices/zoho_inv_existing_100');
        });
    }

    public function test_invoice_sync_failure_unmapped_variant() {
        $unmappedOrder = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/9002',
            'order_number' => '#INV-1002',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/99999',
                    'sku' => 'SKU-UNMAPPED-INV',
                    'name' => 'Unmapped Item',
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Unmapped Shopify product variant/SKU 'SKU-UNMAPPED-INV'");

        $zohoService = new ZohoService($this->shop);
        $zohoService->syncInvoice($unmappedOrder);
    }

    public function test_invoice_tenant_isolation() {
        $otherShop = Shop::create([
            'shop_domain' => 'other-invoice-shop.myshopify.com',
            'access_token' => 'shpat_other_invoice_token',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("does not belong to shop");

        $zohoService = new ZohoService($otherShop);
        $zohoService->syncInvoice($this->order);
    }

    public function test_sync_history_recorded_for_invoice() {
        Http::fake([
            'https://www.zohoapis.com/books/v3/invoices*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'invoices' => []], 200);
                }
                if ($request->method() === 'POST') {
                    return Http::response([
                        'code' => 0,
                        'invoice' => [
                            'invoice_id' => 'zoho_inv_hist_88',
                            'invoice_number' => 'INV-00088',
                        ],
                    ], 201);
                }
                return Http::response(['code' => 0], 200);
            },
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInvoice($this->order);

        $this->assertTrue($result['success']);

        $this->assertDatabaseHas('sync_histories', [
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $result['invoice_id'],
            'zoho_invoice_id' => 'zoho_inv_hist_88',
            'status' => 'success',
            'action' => 'create',
        ]);
    }
}
