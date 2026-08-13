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

    public function test_authenticated_shopify_shop_can_start_zoho_oauth_with_hashed_state()
    {
        $controller = new ZohoAuthController();
        $request = Request::create('/api/zoho/connect', 'POST', ['host' => $this->validHostB64]);
        $request->attributes->set('shop', $this->shop1);

        $response = $controller->initiate($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['success']);
        $this->assertStringContainsString('/oauth/v2/auth', $content['redirect_url']);

        // Extract raw state from URL query
        parse_str(parse_url($content['redirect_url'], PHP_URL_QUERY), $queryParams);
        $rawState = $queryParams['state'] ?? null;
        $this->assertNotEmpty($rawState);

        // Verify state is stored as SHA-256 hash in database
        $expectedHash = hash('sha256', $rawState);
        $this->assertDatabaseHas('zoho_oauth_states', [
            'shop_id' => $this->shop1->id,
            'state' => $expectedHash,
            'host' => $this->validHostB64,
        ]);
    }

    public function test_unauthenticated_oauth_initiation_is_rejected()
    {
        $controller = new ZohoAuthController();
        $request = Request::create('/api/zoho/connect', 'POST', ['host' => $this->validHostB64]);
        // No shop set on attributes

        $response = $controller->initiate($request);

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('No Shopify shop installed.', $content['error']);
    }

    public function test_invalid_host_is_rejected_at_initiation()
    {
        $controller = new ZohoAuthController();
        $invalidHost = base64_encode('evil.com/malicious');
        $request = Request::create('/api/zoho/connect', 'POST', ['host' => $invalidHost]);
        $request->attributes->set('shop', $this->shop1);

        $response = $controller->initiate($request);

        $this->assertEquals(400, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid or missing Shopify host parameter.', $content['error']);
    }

    public function test_state_is_bound_to_correct_shop_id()
    {
        $controller = new ZohoAuthController();
        $request = Request::create('/api/zoho/connect', 'POST', ['host' => $this->validHostB64]);
        $request->attributes->set('shop', $this->shop2);

        $controller->initiate($request);

        $stateRecord = ZohoOauthState::latest('id')->first();
        $this->assertEquals($this->shop2->id, $stateRecord->shop_id);
    }

    public function test_valid_callback_resolves_correct_shop_with_hashed_state()
    {
        $accountsUrl = env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.com');
        $apiUrl = env('ZOHO_API_URL', 'https://www.zohoapis.com');

        Http::fake([
            $accountsUrl . '/oauth/v2/token' => Http::response([
                'access_token' => 'zoho_access_123',
                'refresh_token' => 'zoho_refresh_123',
                'expires_in' => 3600,
            ], 200),
            $apiUrl . '/books/v3/organizations' => Http::response([
                'organizations' => [
                    [
                        'organization_id' => 'zoho_org_999',
                        'is_default_org' => true,
                    ],
                ],
            ], 200),
        ]);

        $rawState = 'valid_raw_oauth_state_12345';
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
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(302, $response->getStatusCode());

        // Verify connection created for shop 1
        $connection = ZohoConnection::where('shop_id', $this->shop1->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals('zoho_org_999', $connection->organization_id);

        // Verify state is consumed
        $stateRecord = ZohoOauthState::where('state', $stateHash)->first();
        $this->assertNotNull($stateRecord->consumed_at);
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
            'expires_at' => now()->subMinutes(1), // expired
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
            'consumed_at' => now()->subMinutes(2), // already consumed
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

    public function test_callback_cannot_switch_tenant_by_modifying_shop()
    {
        $accountsUrl = env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.com');
        $apiUrl = env('ZOHO_API_URL', 'https://www.zohoapis.com');

        Http::fake([
            $accountsUrl . '/oauth/v2/token' => Http::response([
                'access_token' => 'zoho_access_123',
                'refresh_token' => 'zoho_refresh_123',
                'expires_in' => 3600,
            ], 200),
            $apiUrl . '/books/v3/organizations' => Http::response([
                'organizations' => [
                    [
                        'organization_id' => 'zoho_org_999',
                        'is_default_org' => true,
                    ],
                ],
            ], 200),
        ]);

        $rawState = 'tenant_bound_raw_state_123';
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $this->shop1->id, // Bound to shop 1
            'host' => $this->validHostB64,
            'expires_at' => now()->addMinutes(10),
        ]);

        $controller = new ZohoAuthController();
        // Attacker passes shop=store-two.myshopify.com in query string
        $request = Request::create('/zoho/callback', 'GET', [
            'code' => 'auth_code_xyz',
            'state' => $rawState,
            'shop' => 'store-two.myshopify.com',
        ]);

        $response = $controller->callback($request);

        $this->assertEquals(302, $response->getStatusCode());

        // Connection must still belong to shop 1, NOT shop 2
        $this->assertDatabaseHas('zoho_connections', [
            'shop_id' => $this->shop1->id,
            'organization_id' => 'zoho_org_999',
        ]);

        $this->assertDatabaseMissing('zoho_connections', [
            'shop_id' => $this->shop2->id,
        ]);
    }

    public function test_duplicate_zoho_connection_for_same_shop_id_is_prevented()
    {
        ZohoConnection::create([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_first',
            'access_token' => 'token1',
            'refresh_token' => 'ref1',
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempting direct raw insertion of a duplicate connection for shop1 must throw unique constraint exception
        ZohoConnection::insert([
            'shop_id' => $this->shop1->id,
            'organization_id' => 'org_second',
            'access_token' => 'token2',
            'refresh_token' => 'ref2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_post_api_zoho_connect_is_exempt_from_csrf_protection_and_accepts_bearer_token()
    {
        $apiSecret = 'test_api_secret_key_12345';
        $apiKey = 'test_api_key_67890';

        config([
            'services.shopify.api_secret' => $apiSecret,
            'services.shopify.api_key' => $apiKey,
        ]);

        $payload = [
            'iss' => 'https://store-one.myshopify.com/admin',
            'dest' => 'https://store-one.myshopify.com',
            'aud' => $apiKey,
            'exp' => time() + 3600,
            'nbf' => time() - 10,
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $apiSecret, true);
        $signatureB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $token = "{$headerB64}.{$payloadB64}.{$signatureB64}";

        // Make HTTP POST request without any CSRF token
        $response = $this->postJson('/api/zoho/connect', [
            'host' => $this->validHostB64,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Must NOT return 419 (CSRF Token Mismatch)
        $this->assertNotEquals(419, $response->getStatusCode());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->json('success'));
        $this->assertStringContainsString('/oauth/v2/auth', $response->json('redirect_url'));
    }
}
