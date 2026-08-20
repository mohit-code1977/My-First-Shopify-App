<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentStatusTruthTest extends TestCase
{
    use RefreshDatabase;

    private function createShop(): Shop
    {
        return Shop::create([
            'shop_domain' => 'test-truth-' . uniqid() . '.myshopify.com',
            'access_token' => 'shpat_test_token',
        ]);
    }

    public function test_paid_shopify_order_without_local_payment_returns_paid()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1015',
            'order_number' => '#1015',
            'total_price' => 1379.20,
            'currency' => 'USD',
            'financial_status' => 'paid',
        ]);

        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('not_synced', $order->payment_sync_status);
        $this->assertNull($order->zoho_payment_id);
    }

    public function test_paid_shopify_order_with_zoho_disconnected_returns_paid()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1015_disc',
            'order_number' => '#1015_D',
            'total_price' => 500.00,
            'currency' => 'USD',
            'financial_status' => 'paid',
        ]);

        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('not_synced', $order->payment_sync_status);
    }

    public function test_unpaid_shopify_order_returns_pending()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1001',
            'order_number' => '#1001',
            'total_price' => 200.00,
            'currency' => 'USD',
            'financial_status' => 'pending',
        ]);

        $this->assertEquals('pending', $order->payment_status);
        $this->assertEquals('not_synced', $order->payment_sync_status);
    }

    public function test_partially_paid_shopify_order_returns_partially_paid()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1002',
            'order_number' => '#1002',
            'total_price' => 300.00,
            'currency' => 'USD',
            'financial_status' => 'partially_paid',
        ]);

        $this->assertEquals('partially_paid', $order->payment_status);
    }

    public function test_refunded_shopify_order_returns_refunded()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1003',
            'order_number' => '#1003',
            'total_price' => 150.00,
            'currency' => 'USD',
            'financial_status' => 'refunded',
        ]);

        $this->assertEquals('refunded', $order->payment_status);
    }

    public function test_partially_refunded_shopify_order_returns_partially_refunded()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1004',
            'order_number' => '#1004',
            'total_price' => 450.00,
            'currency' => 'USD',
            'financial_status' => 'partially_refunded',
        ]);

        $this->assertEquals('partially_refunded', $order->payment_status);
    }

    public function test_zoho_payment_absent_does_not_change_paid_to_unpaid()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1005',
            'order_number' => '#1005',
            'total_price' => 99.00,
            'currency' => 'USD',
            'financial_status' => 'paid',
        ]);

        $this->assertCount(0, $order->payments);
        $this->assertNotEquals('pending', $order->payment_status);
        $this->assertEquals('paid', $order->payment_status);
    }

    public function test_zoho_payment_synced_maintains_paid_and_updates_sync_status()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1006',
            'order_number' => '#1006',
            'total_price' => 120.00,
            'currency' => 'USD',
            'financial_status' => 'paid',
        ]);

        Payment::create([
            'shop_id' => $shop->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_payment_id' => 'gid://shopify/OrderTransaction/99',
            'amount' => 120.00,
            'currency' => 'USD',
            'status' => 'success',
            'sync_status' => 'synced',
            'zoho_payment_id' => '408121600000099999',
        ]);

        $order->load('payments');

        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('synced', $order->payment_sync_status);
        $this->assertEquals('408121600000099999', $order->zoho_payment_id);
    }

    public function test_payment_details_modal_separates_payment_local_and_zoho_status()
    {
        $shop = $this->createShop();
        $order = Order::create([
            'shop_id' => $shop->id,
            'shopify_order_id' => 'gid://shopify/Order/1015_sep',
            'order_number' => '#1015',
            'total_price' => 1379.20,
            'currency' => 'USD',
            'financial_status' => 'paid',
        ]);

        $orderArray = $order->toArray();

        $this->assertArrayHasKey('financial_status', $orderArray);
        $this->assertArrayHasKey('payment_status', $orderArray);
        $this->assertArrayHasKey('payment_sync_status', $orderArray);
        $this->assertArrayHasKey('zoho_payment_id', $orderArray);

        $this->assertEquals('paid', $orderArray['financial_status']);
        $this->assertEquals('paid', $orderArray['payment_status']);
        $this->assertEquals('not_synced', $orderArray['payment_sync_status']);
        $this->assertNull($orderArray['zoho_payment_id']);
    }
}
