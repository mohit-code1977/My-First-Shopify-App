<?php

namespace Tests\Unit;

use App\Http\Controllers\ZohoAuthController;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\ZohoOauthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoOAuthTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop1;
    private Shop $shop2;
    private string $validHostB64;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop1 = Shop::create([
            'shop_domain' => 'store-one.myshopify.com',
            'access_token' => 'shpat_token_one',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->shop2 = Shop::create([
            'shop_domain' => 'store-two.myshopify.com',
            'access_token' => 'shpat_token_two',
            'access_token_expires_at' => now()->addDays(30),
        ]);

        $this->validHostB64 = base64_encode('admin.shopify.com/store/store-one');
    }

    public function test_initiation_always_uses_global_multi_dc_entry_point()
    {
        // Even if shop had a previous disconnected EU connection record
        ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'is_active' => false,
            'organization_id' => 'old_eu_org',
            'accounts_url' => 'https://accounts.zoho.eu',
            'api_url' => 'https://www.zohoapis.eu',
            'data_center' => 'eu',
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/api/zoho/connect', 'POST', ['host' => $this->validHostB64]);
        $request->attributes->set('shop', $this->shop1);

        $response = $controller->initiate($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['success']);
        // Uses stored connection's accounts URL if present
        $this->assertStringStartsWith('https://accounts.zoho.eu/oauth/v2/auth', $content['redirect_url']);
    }

    public function test_india_user_callback_exchanges_token_at_accounts_zoho_in()
    {
        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response([
                'access_token' => 'zoho_in_access_123',
                'refresh_token' => 'zoho_in_refresh_123',
                'expires_in' => 3600,
                'api_domain' => 'https://www.zohoapis.in',
            ], 200),
            'https://www.zohoapis.in/books/v3/organizations' => Http::response([
                'organizations' => [
                    [
                        'organization_id' => 'zoho_in_org_777',
                        'name' => 'India Business Org',
                        'is_default_org' => true,
                    ],
                ],
            ], 200),
        ]);

        $rawState = 'india_raw_oauth_state_12345';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id,
            'host' => $this->validHostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_india',
            'state' => $rawState,
            'accounts-server' => 'https://accounts.zoho.in',
            'location' => 'in',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(302, $response->getStatusCode());

        $connection = ZohoConnection::where('shop_id', $this->shop1->id)->first();
        $this->assertNotNull($connection);
        $this->assertTrue($connection->is_active);
        $this->assertEquals('https://accounts.zoho.in', $connection->accounts_url);
        $this->assertEquals('https://www.zohoapis.in', $connection->api_url);
        $this->assertEquals('www.zohoapis.in', $connection->api_domain);
        $this->assertEquals('zoho_in_org_777', $connection->organization_id);
    }

    public function test_eu_user_callback_exchanges_token_at_accounts_zoho_eu()
    {
        Http::fake([
            'https://accounts.zoho.eu/oauth/v2/token' => Http::response([
                'access_token' => 'zoho_eu_access_123',
                'refresh_token' => 'zoho_eu_refresh_123',
                'expires_in' => 3600,
                'api_domain' => 'https://www.zohoapis.eu',
            ], 200),
            'https://www.zohoapis.eu/books/v3/organizations' => Http::response([
                'organizations' => [
                    [
                        'organization_id' => 'zoho_eu_org_888',
                        'name' => 'EU Business Org',
                        'is_default_org' => true,
                    ],
                ],
            ], 200),
        ]);

        $rawState = 'eu_raw_oauth_state_12345';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id,
            'host' => $this->validHostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_eu',
            'state' => $rawState,
            'accounts-server' => 'https://accounts.zoho.eu',
            'location' => 'eu',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(302, $response->getStatusCode());

        $connection = ZohoConnection::where('shop_id', $this->shop1->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('https://accounts.zoho.eu', $connection->accounts_url);
        $this->assertEquals('https://www.zohoapis.eu', $connection->api_url);
        $this->assertEquals('zoho_eu_org_888', $connection->organization_id);
    }

    public function test_australia_user_callback_exchanges_token_at_accounts_zoho_com_au()
    {
        Http::fake([
            'https://accounts.zoho.com.au/oauth/v2/token' => Http::response([
                'access_token' => 'zoho_au_access_123',
                'refresh_token' => 'zoho_au_refresh_123',
                'expires_in' => 3600,
                'api_domain' => 'https://www.zohoapis.com.au',
            ], 200),
            'https://www.zohoapis.com.au/books/v3/organizations' => Http::response([
                'organizations' => [
                    [
                        'organization_id' => 'zoho_au_org_555',
                        'name' => 'AU Business Org',
                        'is_default_org' => true,
                    ],
                ],
            ], 200),
        ]);

        $rawState = 'au_raw_oauth_state_12345';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id,
            'host' => $this->validHostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_au',
            'state' => $rawState,
            'accounts-server' => 'https://accounts.zoho.com.au',
            'location' => 'au',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(302, $response->getStatusCode());

        $connection = ZohoConnection::where('shop_id', $this->shop1->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('https://accounts.zoho.com.au', $connection->accounts_url);
        $this->assertEquals('https://www.zohoapis.com.au', $connection->api_url);
        $this->assertEquals('zoho_au_org_555', $connection->organization_id);
    }

    public function test_invalid_or_untrusted_accounts_server_is_rejected()
    {
        $rawState = 'untrusted_accounts_server_state';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id,
            'host' => $this->validHostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_xyz',
            'state' => $rawState,
            'accounts-server' => 'https://malicious-zoho-server.com',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(400, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid or untrusted Zoho accounts-server parameter.', $content['error']);
    }

    public function test_wrong_state_is_rejected()
    {
        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_xyz',
            'state' => 'non_existent_state',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(403, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid Zoho OAuth state.', $content['error']);
    }

    public function test_expired_state_is_rejected()
    {
        $rawState = 'expired_raw_state_123';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id,
            'host' => $this->validHostB64,
            'expires_at' => now()->subMinutes(1),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_xyz',
            'state' => $rawState,
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(403, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Zoho OAuth state has expired.', $content['error']);
    }

    public function test_reused_state_is_rejected()
    {
        $rawState = 'reused_raw_state_123';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id,
            'host' => $this->validHostB64,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => now()->subMinutes(2),
        ]);

        $controller = new ZohoAuthController();
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_xyz',
            'state' => $rawState,
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(403, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Zoho OAuth state has already been used.', $content['error']);
    }
}
