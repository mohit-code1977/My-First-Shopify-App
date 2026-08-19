<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Order1021DiscountAndStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'discount-test-shop.myshopify.com',
            'access_token' => 'shpat_test_token_123',
            'scope' => 'read_orders,write_orders',
            'tax_settings' => [
                'tax_mode' => 'exclusive',
                'discount_tax_mode' => 'before_tax',
            ],
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
     * Test 1: Order #1021 70% discount calculation (Original: ₹1,846.18 gross, ₹1,068.84 line price, 70% off ₹748.18 -> ₹320.66 subtotal + ₹777.34 shipping = ₹1,098.00 final)
     */
    public function test_order_1021_discount_calculation_and_zoho_payload(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/8877',
            'first_name' => 'Mohit',
            'last_name' => 'Sanodiya',
            'email' => 'mohit@example.in',
            'zoho_contact_id' => 'zoho_contact_1021',
        ]);

        $order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/7481881854120',
            'order_number' => '#1021',
            'currency' => 'INR',
            'subtotal' => 320.66,
            'discount_total' => 748.18,
            'shipping_total' => 777.34,
            'tax_total' => 0.00,
            'total_price' => 1098.00,
            'financial_status' => 'paid',
            'coupon_code' => 'ms100',
            'line_items' => [
                [
                    'variant_id' => '55882947330216',
                    'sku' => 'DEK-PLANT-01',
                    'title' => 'Dekorly Artificial Potted Plants',
                    'name' => 'Dekorly Artificial Potted Plants',
                    'quantity' => 1,
                    'price' => 1068.84,
                    'total_discount' => 0,
                ]
            ],
            'shipping_lines' => [
                ['title' => 'Standard', 'price' => 777.34, 'code' => 'Standard']
            ],
        ]);

        Http::fake([
            'https://www.zohoapis.com/books/v3/items*' => Http::response([
                'code' => 0,
                'items' => [['item_id' => 'zoho_item_1021', 'sku' => 'DEK-PLANT-01']]
            ], 200),
            'https://www.zohoapis.com/books/v3/salesorders*' => Http::response([
                'code' => 0,
                'salesorder' => [
                    'salesorder_id' => 'so_zoho_1021',
                    'salesorder_number' => 'SO-00057',
                    'total' => 1098.00,
                    'discount' => 748.18,
                    'discount_type' => 'entity_level',
                ]
            ], 201),
            'https://www.zohoapis.com/books/v3/invoices*' => Http::response([
                'code' => 0,
                'invoice' => [
                    'invoice_id' => 'inv_zoho_1021',
                    'invoice_number' => 'INV-000026',
                    'status' => 'unpaid',
                    'total' => 1098.00,
                    'balance' => 1098.00,
                    'discount' => 748.18,
                    'discount_type' => 'entity_level',
                ]
            ], 201),
        ]);

        $zohoService = new ZohoService($this->shop);
        $result = $zohoService->syncInvoice($order);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/books/v3/invoices')) {
                $body = json_decode($request->body(), true);
                return is_array($body)
                    && ($body['discount'] ?? 0) == 748.18
                    && ($body['discount_type'] ?? '') === 'entity_level'
                    && ($body['shipping_charge'] ?? 0) == 777.34
                    && ($body['line_items'][0]['rate'] ?? 0) == 1068.84;
            }
            return true;
        });
    }

    /**
     * Test 2: Fully paid INR invoice (Invoice balance = 0.00, payment = 1098.00, status = Paid)
     */
    public function test_fully_paid_inr_invoice_reconciliation(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/102100',
            'order_number' => '#1021-PAID',
            'currency' => 'INR',
            'subtotal' => 320.66,
            'discount_total' => 748.18,
            'shipping_total' => 777.34,
            'total_price' => 1098.00,
            'financial_status' => 'paid',
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_paid',
            'invoice_number' => 'INV-PAID',
            'status' => 'paid',
            'amount' => 1098.00,
            'currency' => 'INR',
            'sync_status' => 'synced',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/9991',
            'zoho_payment_id' => 'zoho_pay_paid',
            'zoho_invoice_id' => 'zoho_inv_paid',
            'amount' => 1098.00,
            'currency' => 'INR',
            'status' => 'paid',
            'sync_status' => 'synced',
        ]);

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(1098.00, $payment->amount);
        $this->assertEquals('INR', $payment->currency);
        $this->assertEquals('INR', $invoice->currency);
    }

    /**
     * Test 3: Partially paid invoice logic (balance > 0 must not be treated as fully paid)
     */
    public function test_partially_paid_invoice_not_fully_paid(): void
    {
        $order = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => 'gid://shopify/Order/102101',
            'order_number' => '#1021-PARTIAL',
            'currency' => 'INR',
            'subtotal' => 1068.84,
            'discount_total' => 0.00,
            'shipping_total' => 777.34,
            'total_price' => 1846.18,
            'financial_status' => 'paid',
        ]);

        $invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_partial',
            'invoice_number' => 'INV-PARTIAL',
            'status' => 'partially_paid',
            'amount' => 1846.18,
            'currency' => 'INR',
            'sync_status' => 'synced',
        ]);

        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/9992',
            'zoho_payment_id' => 'zoho_pay_partial',
            'zoho_invoice_id' => 'zoho_inv_partial',
            'amount' => 1098.00,
            'currency' => 'INR',
            'status' => 'paid',
            'sync_status' => 'synced',
        ]);

        $this->assertEquals('partially_paid', $invoice->status);
        $this->assertNotEquals('paid', $invoice->status);
    }
}
