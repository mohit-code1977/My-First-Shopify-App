<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicationHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'test-duplication-shop.myshopify.com',
            'access_token' => 'shpat_test_token_123',
        ]);
    }

    /** @test */
    public function it_prevents_duplicate_customer_creation_across_gid_and_numeric_formats(): void
    {
        // 1. Create initial customer using numeric string
        $numericId = '9700999888777';
        $gidId = "gid://shopify/Customer/{$numericId}";

        $customer1 = Customer::create([
            'shop_id' => $this->shop->id,
            'shopify_customer_id' => $gidId,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'zoho_contact_id' => '4081216000000999999',
        ]);

        // 2. Perform lookup using candidate variations (as done in webhooks and controllers)
        $rawIncomingId = $numericId; // Webhook sends numeric string
        $candidateIds = array_filter(array_unique([
            $rawIncomingId,
            preg_replace('/[^0-9]/', '', $rawIncomingId),
            "gid://shopify/Customer/{$rawIncomingId}",
        ]));

        $found = Customer::where('shop_id', $this->shop->id)
            ->whereIn('shopify_customer_id', $candidateIds)
            ->first();

        $this->assertNotNull($found);
        $this->assertEquals($customer1->id, $found->id);

        // 3. Upsert using target ID preserves single canonical record
        $targetId = $found->shopify_customer_id;
        Customer::updateOrCreate(
            ['shop_id' => $this->shop->id, 'shopify_customer_id' => $targetId],
            ['first_name' => 'John Updated', 'email' => 'john.doe@example.com']
        );

        $this->assertEquals(1, Customer::where('shop_id', $this->shop->id)->count());
        $this->assertEquals('John Updated', $customer1->fresh()->first_name);
    }

    /** @test */
    public function it_prevents_duplicate_order_creation_across_gid_and_numeric_formats(): void
    {
        $numericId = '7476999888777';
        $gidId = "gid://shopify/Order/{$numericId}";

        $order1 = Order::create([
            'shop_id' => $this->shop->id,
            'shopify_order_id' => $gidId,
            'order_number' => '#1099',
            'total_price' => 150.00,
            'currency' => 'USD',
        ]);

        $incomingId = $numericId;
        $candidateIds = array_filter(array_unique([
            $incomingId,
            preg_replace('/[^0-9]/', '', $incomingId),
            "gid://shopify/Order/{$incomingId}",
        ]));

        $found = Order::where('shop_id', $this->shop->id)
            ->whereIn('shopify_order_id', $candidateIds)
            ->first();

        $this->assertNotNull($found);
        $this->assertEquals($order1->id, $found->id);

        Order::updateOrCreate(
            ['shop_id' => $this->shop->id, 'shopify_order_id' => $found->shopify_order_id],
            ['total_price' => 175.00]
        );

        $this->assertEquals(1, Order::where('shop_id', $this->shop->id)->count());
        $this->assertEquals(175.00, $order1->fresh()->total_price);
    }

    /** @test */
    public function it_prevents_duplicate_product_and_variant_creation(): void
    {
        $numProdId = '888000111222';
        $gidProdId = "gid://shopify/Product/{$numProdId}";

        $product = Product::create([
            'shop_id' => $this->shop->id,
            'shopify_product_id' => $gidProdId,
            'title' => 'Test Product',
        ]);

        $candidateProdIds = [
            $numProdId,
            $gidProdId,
        ];

        $foundProd = Product::where('shop_id', $this->shop->id)
            ->whereIn('shopify_product_id', $candidateProdIds)
            ->first();

        $this->assertNotNull($foundProd);
        $this->assertEquals($product->id, $foundProd->id);

        $numVarId = '999000111222';
        $gidVarId = "gid://shopify/ProductVariant/{$numVarId}";

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'shopify_variant_id' => $gidVarId,
            'title' => 'Default Title',
            'price' => 29.99,
        ]);

        $candidateVarIds = [
            $numVarId,
            $gidVarId,
        ];

        $foundVar = ProductVariant::where('product_id', $product->id)
            ->whereIn('shopify_variant_id', $candidateVarIds)
            ->first();

        $this->assertNotNull($foundVar);
        $this->assertEquals($variant->id, $foundVar->id);
    }
}
