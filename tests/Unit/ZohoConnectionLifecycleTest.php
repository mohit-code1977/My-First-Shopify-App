<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Services\ZohoDatacenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class ZohoConnectionLifecycleTest extends \Tests\TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shop_domain' => 'lifecycle-test.myshopify.com',
            'access_token' => 'shpat_test_token',
            'access_token_expires_at' => now()->addDays(30),
        ]);
    }

    public function test_soft_disconnect_preserves_row_and_clears_secrets()
    {
        $connection = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => true,
            'organization_id' => 'org_12345',
            'organization_name' => 'Test Org',
            'access_token' => 'secret_access_token',
            'refresh_token' => 'secret_refresh_token',
            'accounts_url' => 'https://accounts.zoho.eu',
            'api_url' => 'https://www.zohoapis.eu',
            'api_domain' => 'www.zohoapis.eu',
            'data_center' => 'eu',
            'scope' => 'ZohoBooks.settings.READ',
            'connected_at' => now()->subDays(5),
        ]);

        $this->assertNotNull($this->shop->zohoConnection);
        $this->assertEquals($connection->id, $this->shop->zohoConnection->id);

        // Perform soft disconnect logic
        $connection->update([
            'is_active' => false,
            'disconnected_at' => now(),
            'access_token' => null,
            'refresh_token' => null,
        ]);

        $this->shop->refresh();

        // 1. Connection row is NOT physically deleted
        $this->assertDatabaseHas('zoho_connections', [
            'id' => $connection->id,
            'shop_id' => $this->shop->id,
            'organization_id' => 'org_12345',
            'is_active' => false,
        ]);

        // 2. Relationship zohoConnection filters is_active=true, returning null
        $this->assertNull($this->shop->zohoConnection);

        // 3. allZohoConnections still holds historical row
        $this->assertCount(1, $this->shop->allZohoConnections);

        // 4. Tokens are cleared
        $rowInDb = ZohoConnection::find($connection->id);
        $this->assertNull($rowInDb->access_token);
        $this->assertNull($rowInDb->refresh_token);
        $this->assertNotNull($rowInDb->disconnected_at);
        $this->assertEquals('org_12345', $rowInDb->organization_id);
    }

    public function test_reconnect_reuses_existing_row_and_restores_active_state()
    {
        // Initial soft-disconnected state
        $existing = ZohoConnection::create([
            'shop_id' => $this->shop->id,
            'is_active' => false,
            'organization_id' => 'org_old',
            'access_token' => null,
            'refresh_token' => null,
            'accounts_url' => 'https://accounts.zoho.com',
            'api_url' => 'https://www.zohoapis.com',
            'disconnected_at' => now()->subDays(2),
        ]);

        // Simulate reconnect (OAuth callback updateOrCreate)
        $reconnected = ZohoConnection::updateOrCreate(
            [
                'shop_id' => $this->shop->id,
            ],
            [
                'is_active' => true,
                'organization_id' => 'org_new_777',
                'organization_name' => 'New Org Name',
                'access_token' => 'new_access_token',
                'refresh_token' => 'new_refresh_token',
                'accounts_url' => 'https://accounts.zoho.in',
                'api_url' => 'https://www.zohoapis.in',
                'api_domain' => 'www.zohoapis.in',
                'data_center' => 'in',
                'scope' => 'ZohoBooks.settings.READ',
                'connected_at' => now(),
                'disconnected_at' => null,
                'expires_at' => now()->addHour(),
            ]
        );

        // Exactly 1 row in table (no duplicates created!)
        $this->assertCount(1, ZohoConnection::where('shop_id', $this->shop->id)->get());
        $this->assertEquals($existing->id, $reconnected->id);

        $this->shop->refresh();
        $this->assertNotNull($this->shop->zohoConnection);
        $this->assertTrue($this->shop->zohoConnection->is_active);
        $this->assertNull($this->shop->zohoConnection->disconnected_at);
        $this->assertEquals('org_new_777', $this->shop->zohoConnection->organization_id);
        $this->assertEquals('new_access_token', $this->shop->zohoConnection->access_token);
    }

    public function test_multi_datacenter_validation_accepts_valid_and_rejects_untrusted()
    {
        // Valid Accounts URLs
        $this->assertEquals('https://accounts.zoho.com', ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.com'));
        $this->assertEquals('https://accounts.zoho.eu', ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.eu/'));
        $this->assertEquals('https://accounts.zoho.in', ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.in'));
        $this->assertEquals('https://accounts.zoho.com.au', ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.com.au'));
        $this->assertEquals('https://accounts.zoho.jp', ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.jp'));
        $this->assertEquals('https://accounts.zoho.ca', ZohoDatacenter::validateAccountsUrl('https://accounts.zoho.ca'));

        // Invalid or HTTP Accounts URLs rejected
        $this->assertNull(ZohoDatacenter::validateAccountsUrl('http://accounts.zoho.com'));
        $this->assertNull(ZohoDatacenter::validateAccountsUrl('https://malicious-zoho.com'));
        $this->assertNull(ZohoDatacenter::validateAccountsUrl('https://zoho.com'));

        // Valid API URLs
        $this->assertEquals('https://www.zohoapis.com', ZohoDatacenter::validateApiUrl('https://www.zohoapis.com'));
        $this->assertEquals('https://www.zohoapis.eu', ZohoDatacenter::validateApiUrl('https://www.zohoapis.eu'));
        $this->assertEquals('https://www.zohoapis.in', ZohoDatacenter::validateApiUrl('https://www.zohoapis.in'));

        // Invalid API URLs rejected
        $this->assertNull(ZohoDatacenter::validateApiUrl('http://www.zohoapis.com'));
        $this->assertNull(ZohoDatacenter::validateApiUrl('https://evil-zohoapis.com'));
    }
}
