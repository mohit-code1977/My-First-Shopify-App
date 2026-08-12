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
    ) {}

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
            | Decode Shopify App Bridge ID token
            |--------------------------------------------------------------------------
            */

            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                throw new \Exception(
                    'Invalid Shopify token format.'
                );
            }

            $payload = json_decode(
                base64_decode(
                    strtr($parts[1], '-_', '+/')
                ),
                true
            );

            if (!is_array($payload)) {
                throw new \Exception(
                    'Invalid Shopify token payload.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate token expiration
            |--------------------------------------------------------------------------
            */

            if (
                empty($payload['exp']) ||
                $payload['exp'] < time()
            ) {
                throw new \Exception(
                    'Shopify token has expired.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Get Shopify shop from token
            |--------------------------------------------------------------------------
            */

            $destination = $payload['dest'] ?? null;

            if (!$destination) {
                throw new \Exception(
                    'Shopify shop information missing from token.'
                );
            }

            $shopDomain = parse_url(
                $destination,
                PHP_URL_HOST
            );

            if (!$shopDomain) {
                throw new \Exception(
                    'Invalid Shopify shop destination.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Shopify domain
            |--------------------------------------------------------------------------
            */

            if (
                !preg_match(
                    '/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/',
                    $shopDomain
                )
            ) {
                throw new \Exception(
                    'Invalid Shopify shop domain.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Find existing shop
            |--------------------------------------------------------------------------
            */

            $shop = Shop::where(
                'shop_domain',
                $shopDomain
            )->first();

            /*
            |--------------------------------------------------------------------------
            | Exchange App Bridge ID token only when necessary
            |--------------------------------------------------------------------------
            |
            | If we don't have a valid stored Shopify token,
            | exchange the current App Bridge token.
            |
            */

            if (
                !$shop ||
                !$shop->access_token ||
                !$shop->access_token_expires_at ||
                now()->gte($shop->access_token_expires_at)
            ) {
                $shop = $this->shopifyService->exchangeToken(
                    $shopDomain,
                    $token
                );
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
}
