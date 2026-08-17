<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\SyncHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSynchronizationPreparationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private Order $order;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'payment-test.myshopify.com',
            'access_token' => 'shpat_payment_token',
        ]);

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => 'gid://shopify/Customer/7001',
            'zoho_contact_id' => 'zoho_contact_7001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.payment@example.com',
        ]);

        $this->order = Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'shopify_order_id' => 'gid://shopify/Order/8001',
            'order_number' => '#PAY-1001',
            'zoho_sales_order_id' => 'zoho_so_8001',
            'order_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'total_price' => '100.00',
        ]);

        $this->invoice = Invoice::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'zoho_invoice_id' => 'zoho_inv_8001',
            'invoice_number' => 'INV-08001',
            'status' => 'sent',
            'amount' => '100.00',
            'currency' => 'EUR',
            'sync_status' => 'synced',
        ]);
    }

    public function test_payment_belongs_to_shop()
    {
        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/101',
            'payment_reference' => 'TXN-101',
            'amount' => 100.00,
            'currency' => $this->order->currency,
        ]);

        $this->assertInstanceOf(Shop::class, $payment->shop);
        $this->assertEquals($this->shop->id, $payment->shop->id);
    }

    public function test_payment_belongs_to_order()
    {
        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/102',
            'payment_reference' => 'TXN-102',
            'amount' => 100.00,
            'currency' => $this->order->currency,
        ]);

        $this->assertInstanceOf(Order::class, $payment->order);
        $this->assertEquals($this->order->id, $payment->order->id);
    }

    public function test_payment_belongs_to_invoice_and_supports_nullable_invoice()
    {
        // Attached to invoice
        $paymentWithInvoice = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/103',
            'payment_reference' => 'TXN-103',
            'amount' => 50.00,
            'currency' => $this->order->currency,
        ]);

        $this->assertInstanceOf(Invoice::class, $paymentWithInvoice->invoice);
        $this->assertEquals($this->invoice->id, $paymentWithInvoice->invoice->id);

        // Nullable invoice
        $paymentWithoutInvoice = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => null,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/104',
            'payment_reference' => 'TXN-104',
            'amount' => 50.00,
            'currency' => $this->order->currency,
        ]);

        $this->assertNull($paymentWithoutInvoice->invoice);
    }

    public function test_order_has_many_payments()
    {
        $payment1 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/105',
            'payment_reference' => 'TXN-105',
            'amount' => 40.00,
            'currency' => $this->order->currency,
        ]);

        $payment2 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/106',
            'payment_reference' => 'TXN-106',
            'amount' => 60.00,
            'currency' => $this->order->currency,
        ]);

        $this->assertCount(2, $this->order->payments);
        $this->assertTrue($this->order->payments->contains($payment1));
        $this->assertTrue($this->order->payments->contains($payment2));
    }

    public function test_invoice_has_many_payments()
    {
        $payment1 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/107',
            'payment_reference' => 'TXN-107',
            'amount' => 30.00,
            'currency' => $this->order->currency,
        ]);

        $payment2 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/108',
            'payment_reference' => 'TXN-108',
            'amount' => 70.00,
            'currency' => $this->order->currency,
        ]);

        $this->assertCount(2, $this->invoice->payments);
        $this->assertTrue($this->invoice->payments->contains($payment1));
        $this->assertTrue($this->invoice->payments->contains($payment2));
    }

    public function test_duplicate_shopify_payment_identity_is_rejected()
    {
        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/DUP_201',
            'payment_reference' => 'TXN-DUP-201',
            'amount' => 100.00,
        ]);

        $this->expectException(QueryException::class);

        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/DUP_201',
            'payment_reference' => 'TXN-DUP-201-ALT',
            'amount' => 100.00,
        ]);
    }

    public function test_duplicate_zoho_payment_identity_is_rejected()
    {
        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/301',
            'payment_reference' => 'TXN-301',
            'zoho_payment_id' => 'zoho_pay_DUP_99',
            'amount' => 100.00,
        ]);

        $this->expectException(QueryException::class);

        Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/302',
            'payment_reference' => 'TXN-302',
            'zoho_payment_id' => 'zoho_pay_DUP_99',
            'amount' => 100.00,
        ]);
    }

    public function test_multiple_legitimate_payments_for_one_order_are_allowed()
    {
        $part1 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/PART_1',
            'payment_reference' => 'TXN-PART-1',
            'amount' => 60.00,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PARTIALLY_PAID,
        ]);

        $part2 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/PART_2',
            'payment_reference' => 'TXN-PART-2',
            'amount' => 40.00,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
        ]);

        $this->assertEquals(2, Payment::where('order_id', $this->order->id)->count());
        $totalPaid = Payment::where('order_id', $this->order->id)->sum('amount');
        $this->assertEquals(100.00, (float) $totalPaid);
    }

    public function test_partial_payment_amounts_are_supported()
    {
        $payment = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/401',
            'payment_reference' => 'TXN-401',
            'amount' => '33.33',
            'currency' => 'EUR',
            'status' => Payment::STATUS_PARTIALLY_PAID,
        ]);

        $this->assertSame('33.33', $payment->amount);
    }

    public function test_payment_status_and_sync_status_are_independent()
    {
        // 1. Paid locally, pending sync
        $p1 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/501',
            'payment_reference' => 'TXN-501',
            'amount' => 50.00,
            'status' => Payment::STATUS_PAID,
            'sync_status' => Payment::SYNC_STATUS_PENDING,
        ]);

        $this->assertEquals('paid', $p1->status);
        $this->assertEquals('pending', $p1->sync_status);

        // 2. Failed business status (failed gateway attempt), sync not applicable / failed
        $p2 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/502',
            'payment_reference' => 'TXN-502',
            'amount' => 50.00,
            'status' => Payment::STATUS_FAILED,
            'sync_status' => Payment::SYNC_STATUS_FAILED,
            'error_message' => 'Card declined by issuing bank',
        ]);

        $this->assertEquals('failed', $p2->status);
        $this->assertEquals('failed', $p2->sync_status);

        // 3. Paid business status, synced to Zoho
        $p3 = Payment::create([
            'shop_id' => $this->shop->id,
            'order_id' => $this->order->id,
            'shopify_order_id' => $this->order->shopify_order_id,
            'shopify_transaction_id' => 'gid://shopify/OrderTransaction/503',
            'payment_reference' => 'TXN-503',
            'zoho_payment_id' => 'zoho_pay_503',
            'amount' => 50.00,
            'status' => Payment::STATUS_PAID,
            'sync_status' => Payment::SYNC_STATUS_SYNCED,
            'synced_at' => now(),
        ]);

        $this->assertEquals('paid', $p3->status);
        $this->assertEquals('synced', $p3->sync_status);
    }
}
