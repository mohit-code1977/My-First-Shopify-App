<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\SyncHistory;
use App\Services\SyncLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_logger_records_standardized_history_event()
    {
        $shop = Shop::create([
            'shop_domain' => 'monitoring-test.myshopify.com',
            'access_token' => 'shpat_test123',
        ]);

        $startTime = now()->subSeconds(2);
        $endTime = now();

        $history = SyncLogger::record([
            'shop_id' => $shop->id,
            'entity' => 'order',
            'action' => 'CREATE',
            'trigger' => 'automatic',
            'trigger_subtype' => 'order_sync',
            'status' => 'SUCCESS',
            'shopify_id' => 'gid://shopify/Order/99001',
            'zoho_id' => '4081216000000999001',
            'started_at' => $startTime,
            'completed_at' => $endTime,
            'metadata' => [
                'order_number' => '#1099',
                'currency' => 'USD',
                'amount' => 150.00,
                'zoho_sales_order_id' => '4081216000000999001',
            ],
        ]);

        $this->assertInstanceOf(SyncHistory::class, $history);
        $this->assertEquals('order', $history->entity);
        $this->assertEquals('CREATE', $history->action);
        $this->assertEquals('automatic', $history->trigger);
        $this->assertEquals('order_sync', $history->trigger_subtype);
        $this->assertEquals('SUCCESS', $history->status);
        $this->assertEquals('gid://shopify/Order/99001', $history->shopify_id);
        $this->assertEquals('4081216000000999001', $history->zoho_id);
        $this->assertEquals('#1099', $history->metadata['order_number']);
        $this->assertGreaterThanOrEqual(1000, $history->duration_ms);
        $this->assertEquals('Automatic → Order Sync', $history->trigger_label);
    }

    public function test_sync_logger_handles_failures_with_error_code_and_details()
    {
        $shop = Shop::create([
            'shop_domain' => 'failure-test.myshopify.com',
            'access_token' => 'shpat_test456',
        ]);

        $history = SyncLogger::record([
            'shop_id' => $shop->id,
            'entity' => 'product',
            'action' => 'UPDATE',
            'trigger' => 'webhook',
            'status' => 'FAILED',
            'error_code' => '36023',
            'error_message' => 'you are not allowed to update or delete a product which is marked as invoiced',
            'metadata' => [
                'sku' => 'TEST-SKU-99',
                'retryable' => false,
            ],
        ]);

        $this->assertEquals('FAILED', $history->status);
        $this->assertEquals('36023', $history->error_code);
        $this->assertStringContainsString('invoiced', $history->error_message);
        $this->assertFalse($history->metadata['retryable']);
    }

    public function test_read_only_operations_do_not_create_sync_history()
    {
        $shop = Shop::create([
            'shop_domain' => 'readonly-test.myshopify.com',
            'access_token' => 'shpat_test789',
        ]);

        $initialCount = SyncHistory::where('shop_id', $shop->id)->count();

        $response = $this->withSession(['shop_domain' => $shop->shop_domain])
            ->get("/zoho/sync/history?shop={$shop->shop_domain}");

        $response->assertStatus(200);
        $this->assertEquals($initialCount, SyncHistory::where('shop_id', $shop->id)->count());
    }
}
