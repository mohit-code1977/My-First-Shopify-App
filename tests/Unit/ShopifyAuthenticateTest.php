<?php

namespace Tests\Unit;

use App\Http\Middleware\ShopifyAuthenticate;
use App\Models\Shop;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ShopifyAuthenticateTest extends TestCase
{
    private string $apiSecret = 'test_api_secret_key_12345';
    private string $apiKey = 'test_api_key_67890';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shopify.api_secret' => $this->apiSecret,
            'services.shopify.api_key' => $this->apiKey,
        ]);
    }

    private function createJwtToken(array $payload, array $header = ['alg' => 'HS256', 'typ' => 'JWT'], ?string $secret = null): string
    {
        $secret = $secret ?? $this->apiSecret;
        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true);
        $signatureB64 = $this->base64UrlEncode($signature);

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function test_missing_authorization_header_returns_401()
    {
        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Shopify authorization token missing.', $content['message']);
    }

    public function test_malformed_jwt_returns_401()
    {
        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid.token.structure.extra');

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid Shopify token format.', $content['message']);
    }

    public function test_invalid_base64_returns_401()
    {
        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', 'Bearer !!!invalidbase64!!!.payload.signature');

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid base64url decoding.', $content['message']);
    }

    public function test_unsupported_algorithm_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
        ];
        $token = $this->createJwtToken($payload, ['alg' => 'RS256', 'typ' => 'JWT']);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Unsupported Shopify token algorithm.', $content['message']);
    }

    public function test_invalid_signature_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
        ];
        $token = $this->createJwtToken($payload, ['alg' => 'HS256', 'typ' => 'JWT'], 'wrong_secret');

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid Shopify token signature.', $content['message']);
    }

    public function test_wrong_audience_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => 'different_app_api_key',
            'exp' => time() + 3600,
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Shopify token audience mismatch.', $content['message']);
    }

    public function test_missing_exp_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Shopify token expiration claim missing.', $content['message']);
    }

    public function test_expired_token_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() - 3600,
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Shopify token has expired.', $content['message']);
    }

    public function test_invalid_nbf_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
            'nbf' => 'not_a_number',
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Shopify token not-before claim is invalid.', $content['message']);
    }

    public function test_future_nbf_returns_401()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
            'nbf' => time() + 7200,
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Shopify token is not active yet.', $content['message']);
    }

    public function test_invalid_destination_returns_401()
    {
        $payload = [
            'iss' => 'https://invalid-domain.com/admin',
            'dest' => 'https://invalid-domain.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid Shopify shop domain.', $content['message']);
    }

    public function test_issuer_mismatch_returns_401()
    {
        $payload = [
            'iss' => 'https://attacker-shop.myshopify.com/admin',
            'dest' => 'https://victim-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
        ];
        $token = $this->createJwtToken($payload);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not pass next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Shopify token issuer mismatch.', $content['message']);
    }

    public function test_valid_token_passes_middleware_and_sets_attributes()
    {
        $payload = [
            'iss' => 'https://test-shop.myshopify.com/admin',
            'dest' => 'https://test-shop.myshopify.com',
            'aud' => $this->apiKey,
            'exp' => time() + 3600,
            'nbf' => time() - 100,
        ];
        $token = $this->createJwtToken($payload);

        $mockShop = new Shop();
        $mockShop->id = 1;
        $mockShop->shop_domain = 'test-shop.myshopify.com';
        $mockShop->access_token = 'shpat_valid_token';
        $mockShop->access_token_expires_at = now()->addDays(30);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $shopifyService->shouldReceive('exchangeToken')
            ->with('test-shop.myshopify.com', $token)
            ->once()
            ->andReturn($mockShop);

        $middleware = new ShopifyAuthenticate($shopifyService);
        $request = Request::create('/api/zoho/sync', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $passed = false;
        $response = $middleware->handle($request, function ($req) use (&$passed, $payload) {
            $passed = true;
            $this->assertNotNull($req->attributes->get('shop'));
            $this->assertEquals('test-shop.myshopify.com', $req->attributes->get('shop')->shop_domain);
            $this->assertEquals($payload, $req->attributes->get('shopify_claims'));
            return response()->json(['success' => true]);
        });

        $this->assertTrue($passed);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
