<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\ShopifyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ShopifyAuthenticate
{
    public function __construct(
        private ShopifyService $shopifyService
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $authorization = $request->header('Authorization');

        if (
            !$authorization ||
            !str_starts_with($authorization, 'Bearer ')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Shopify authorization token missing.',
            ], 401);
        }

        $token = substr($authorization, 7);

        try {
            /*
            |--------------------------------------------------------------------------
            | 1. Decode & Validate Token Structure
            |--------------------------------------------------------------------------
            */

            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                throw new \Exception('Invalid Shopify token format.');
            }

            [$headerB64, $payloadB64, $signatureB64] = $parts;

            $header = json_decode(self::base64UrlDecode($headerB64), true);
            if (!is_array($header)) {
                throw new \Exception('Invalid Shopify token header.');
            }

            if (empty($header['alg']) || $header['alg'] !== 'HS256') {
                throw new \Exception('Unsupported Shopify token algorithm.');
            }

            $payload = json_decode(self::base64UrlDecode($payloadB64), true);
            if (!is_array($payload)) {
                throw new \Exception('Invalid Shopify token payload.');
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Cryptographic Signature Verification (HMAC-SHA256)
            |--------------------------------------------------------------------------
            */

            $apiSecret = config('services.shopify.api_secret') ?: env('SHOPIFY_API_SECRET');

            if (!$apiSecret) {
                throw new \Exception('Shopify API secret key is not configured.');
            }

            $expectedSignature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $apiSecret, true);
            $providedSignature = self::base64UrlDecode($signatureB64);

            if (!hash_equals($expectedSignature, $providedSignature)) {
                throw new \Exception('Invalid Shopify token signature.');
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Validate Audience Claim (aud)
            |--------------------------------------------------------------------------
            */

            $apiKey = config('services.shopify.api_key') ?: env('SHOPIFY_API_KEY');

            if ($apiKey && (!isset($payload['aud']) || $payload['aud'] !== $apiKey)) {
                throw new \Exception('Shopify token audience mismatch.');
            }

            /*
            |--------------------------------------------------------------------------
            | 4. Validate Expiration (exp) and Not-Before (nbf) Claims
            |--------------------------------------------------------------------------
            */

            $now = time();
            $leeway = 60;

            if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
                throw new \Exception('Shopify token expiration claim missing.');
            }

            if (($payload['exp'] + $leeway) < $now) {
                throw new \Exception('Shopify token has expired.');
            }

            if (array_key_exists('nbf', $payload)) {
                if (!is_numeric($payload['nbf'])) {
                    throw new \Exception('Shopify token not-before claim is invalid.');
                }

                if (($payload['nbf'] - $leeway) > $now) {
                    throw new \Exception('Shopify token is not active yet.');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Validate Destination (dest) and Issuer (iss) Claims
            |--------------------------------------------------------------------------
            */

            $destination = $payload['dest'] ?? null;

            if (!$destination) {
                throw new \Exception('Shopify shop information missing from token.');
            }

            $shopDomain = parse_url($destination, PHP_URL_HOST);

            if (!$shopDomain) {
                throw new \Exception('Invalid Shopify shop destination.');
            }

            if (
                !preg_match(
                    '/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/',
                    $shopDomain
                )
            ) {
                throw new \Exception('Invalid Shopify shop domain.');
            }

            $issuer = $payload['iss'] ?? null;

            if (!$issuer) {
                throw new \Exception('Shopify token issuer missing.');
            }

            $issuerHost = parse_url($issuer, PHP_URL_HOST);

            if (!$issuerHost || $issuerHost !== $shopDomain) {
                throw new \Exception('Shopify token issuer mismatch.');
            }

            /*
            |--------------------------------------------------------------------------
            | Find existing shop
            |--------------------------------------------------------------------------
            */

            try {
                $shop = Shop::where(
                    'shop_domain',
                    $shopDomain
                )->first();
            } catch (\Throwable $e) {
                $shop = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Exchange App Bridge ID token when necessary or when scopes missing
            |--------------------------------------------------------------------------
            */

            $hasRequiredScopes = $shop && $shop->scope &&
                str_contains($shop->scope, 'read_orders') &&
                str_contains($shop->scope, 'read_customers');

            if (
                !$shop ||
                !$shop->access_token ||
                !$hasRequiredScopes ||
                ($shop->access_token_expires_at && now()->gte($shop->access_token_expires_at))
            ) {
                try {
                    $shop = $this->shopifyService->exchangeToken(
                        $shopDomain,
                        $token
                    );
                } catch (\Throwable $e) {
                    Log::warning('Token exchange in middleware failed', ['error' => $e->getMessage()]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Make shop available to controllers
            |--------------------------------------------------------------------------
            */

            $request->attributes->set(
                'shop',
                $shop
            );

            $request->attributes->set(
                'shopify_claims',
                $payload
            );

            return $next($request);
        } catch (\Throwable $e) {

            Log::error(
                'Shopify authentication failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Decode base64url encoded string strictly.
     */
    private static function base64UrlDecode(string $input): string
    {
        if (trim($input) === '') {
            throw new \Exception('Invalid base64url input.');
        }

        $remainder = strlen($input) % 4;

        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        if ($decoded === false) {
            throw new \Exception('Invalid base64url decoding.');
        }

        return $decoded;
    }
}
