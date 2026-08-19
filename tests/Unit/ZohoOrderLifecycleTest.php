<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Customer $customer;
    private ProductVariant $variant;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'lifecycle-test.myshopify.com',
            'access_token' => 'shpat_lifecycle_token',
            'tax_settings' => [
                'tax_mode' => 'exclusive',
                'default_tax_id' => 'zoho_tax_default_888',
            ],
        ]);

        ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'access_token' => 'zoho_access_token',
            'refresh_token' => 'zoho_refresh_token',
            'expires_at' => now()->addHour(),
            'organization_id' => '99887766',
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'data_center' => 'com',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/8001',
            'zoho_contact_id' => 'zoho_contact_8001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => 'gid://shopify/Product/8001',
            'title' => 'Lifecycle Product',
            'handle' => 'lifecycle-product',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/8001',
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/8001',
            'sku' => 'LC-SKU-01',
            'title' => 'Default Variant',
            'price' => '100.00',
            'inventory_quantity' => 50,
            'zoho_item_id' => 'zoho_item_8001',
        ]);

        $this->order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8001',
            'order_number' => '1099',
            'order_date' => now(),
            'currency' => 'INR',
            'subtotal' => '200.00',
            'discount_total' => '20.00',
            'shipping_total' => '30.00',
            'tax_total' => '0.00',
            'total_price' => '210.00',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'line_items' => [
                [
                    'variant_id' => 'gid://shopify/ProductVariant/8001',
                    'sku' => 'LC-SKU-01',
                    'name' => 'Default Variant',
                    'quantity' => 2,
                    'price' => 100.00,
                    'total_discount' => 20.00,
                ],
            ],
        ]);
    }

    private function fakeTaxEndpoint(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/settings/taxes*' => Http::response([
                'code' => 0,
                'taxes' => [
                    ['tax_id' => 'zoho_tax_default_888', 'tax_name' => 'GST 18%', 'tax_percentage' => 18, 'status' => 'Active'],
                ],
            ], 200),
        ]);
    }

    public function test_new_order_creates_sales_order_and_confirms_it(): void
    {
        $this->fakeTaxEndpoint();

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => function (Request $request) {
                if (str_contains($request->url(), '/status/confirmed')) {
                    return Http::response([
                        'code' => 0,
                        'message' => 'Sales Order confirmed',
                        'salesorder' => ['salesorder_id' => 'zoho_so_8001', 'status' => 'confirmed'],
                    ], 200);
                }
                return Http::response([
                    'code' => 0,
                    'salesorder' => ['salesorder_id' => 'zoho_so_8001', 'salesorder_number' => 'SO-08001'],
                ], 201);
            },
        ]);

        $zs = new ZohoService($this->shop);
        $result = $zs->syncOrder($this->order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_so_8001', $this->order->fresh()->zoho_sales_order_id);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/books/v3/salesorders/zoho_so_8001/status/confirmed');
        });
    }

    public function test_invoice_created_from_sales_order_via_fromsalesorder_endpoint(): void
    {
        $this->fakeTaxEndpoint();

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_8001', 'salesorder_number' => 'SO-08001'],
            ], 200),
            'https://www.zohoapis.com/books/v3/invoices/fromsalesorder*' => Http::response([
                'code' => 0,
                'invoice' => [
                    'invoice_id' => 'zoho_inv_8001',
                    'invoice_number' => 'INV-08001',
                    'salesorder_id' => 'zoho_so_8001',
                    'total' => 210.00,
                ],
            ], 201),
        ]);

        $this->order->update(['zoho_sales_order_id' => 'zoho_so_8001']);

        $zs = new ZohoService($this->shop);
        $result = $zs->syncInvoice($this->order);

        $this->assertTrue($result['success']);
        $this->assertEquals('zoho_inv_8001', $result['zoho_invoice_id']);

        $this->assertDatabaseHas('invoices', [
            'order_id' => $this->order->id,
            'zoho_invoice_id' => 'zoho_inv_8001',
        ]);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), '/books/v3/invoices/fromsalesorder') &&
                str_contains($request->url(), 'salesorder_id=zoho_so_8001');
        });
    }

    public function test_confirming_sales_order_is_idempotent_when_already_confirmed(): void
    {
        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders/zoho_so_8001/status/confirmed*' => Http::response([
                'code' => 1000,
                'message' => 'Sales Order status is already open/confirmed',
            ], 400),
        ]);

        $zs = new ZohoService($this->shop);
        $res = $zs->confirmSalesOrder('zoho_so_8001');

        $this->assertEquals('zoho_so_8001', $res['salesorder_id']);
        $this->assertEquals('confirmed', $res['status']);
    }

    public function test_payment_applied_against_linked_invoice(): void
    {
        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_8001',
            'invoice_number' => 'INV-08001',
            'status' => 'sent',
            'amount' => '210.00',
            'currency' => 'INR',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'txn_8001',
            'amount' => '210.00',
            'currency' => 'INR',
            'gateway' => 'shopify_payments',
            'status' => 'success',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/customerpayments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'customerpayments' => []], 200);
                }
                return Http::response([
                    'code' => 0,
                    'customerpayment' => ['payment_id' => 'zoho_pay_8001'],
                ], 201);
            },
            'https://www.zohoapis.com/books/v3/settings/paymentgateways*' => Http::response(['code' => 0, 'paymentgateways' => []], 200),
            'https://www.zohoapis.com/books/v3/chartofaccounts*' => Http::response(['code' => 0, 'account' => ['account_id' => 'acc_1']], 200),
        ]);

        $zs = new ZohoService($this->shop);
        $res = $zs->syncPayment($payment);

        $this->assertTrue($res['success']);
        $this->assertEquals('zoho_pay_8001', $payment->fresh()->zoho_payment_id);

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/customerpayments')) {
                return false;
            }
            $invoices = $request->data()['invoices'] ?? [];
            return count($invoices) === 1 && $invoices[0]['invoice_id'] === 'zoho_inv_8001';
        });
    }

    public function test_existing_draft_so_and_invoice_are_preserved_without_duplicate_creation(): void
    {
        $this->fakeTaxEndpoint();
        $this->order->update(['zoho_sales_order_id' => 'zoho_so_existing_draft']);

        Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_existing_draft',
            'invoice_number' => 'INV-EXISTING',
            'status' => 'draft',
            'amount' => '210.00',
            'currency' => 'INR',
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response(['code' => 0], 200),
            'https://www.zohoapis.com/books/v3/invoices/zoho_inv_existing_draft*' => Http::response([
                'code' => 0,
                'invoice' => ['invoice_id' => 'zoho_inv_existing_draft', 'status' => 'draft'],
            ], 200),
        ]);

        $zs = new ZohoService($this->shop);
        $res = $zs->syncInvoice($this->order);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['updated']);
        $this->assertFalse($res['created']);
        $this->assertEquals(1, Invoice::where('order_id', $this->order->id)->count());
    }

    public function test_order_confirmation_and_invoicing_does_not_trigger_duplicate_inventory_adjustments(): void
    {
        $this->fakeTaxEndpoint();

        Http::fake([
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response([
                'code' => 0,
                'salesorder' => ['salesorder_id' => 'zoho_so_no_adj', 'salesorder_number' => 'SO-NO-ADJ'],
            ], 201),
            'https://www.zohoapis.com/books/v3/invoices/fromsalesorder*' => Http::response([
                'code' => 0,
                'invoice' => ['invoice_id' => 'zoho_inv_no_adj', 'invoice_number' => 'INV-NO-ADJ'],
            ], 201),
        ]);

        $zs = new ZohoService($this->shop);
        $zs->syncOrder($this->order);
        $zs->syncInvoice($this->order);

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/inventoryadjustments');
        });
    }
}
